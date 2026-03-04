<?php

namespace App\Controller\Api;

use App\Service\GoogleMapsService;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

#[Route('/api/salary', name: 'api_salary_')]
class SalaryNearbyController extends AbstractController
{
    public function __construct(
        private readonly CacheItemPoolInterface $cache,
        private readonly LoggerInterface $logger,
        private readonly string $geoApiKey = ''
    ) {
    }

    #[Route('/nearby-distributors', name: 'nearby_distributors', methods: ['GET'])]
    #[Route('/geolocation/nearby-distributors', name: 'geolocation_nearby_distributors', methods: ['GET'])]
    public function nearbyDistributors(Request $request, GoogleMapsService $mapsService): JsonResponse
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');
        $this->assertRoleAccess();
        $this->assertApiKey($request);
        if (!$this->consumeRateLimit($request, 20, 60)) {
            return $this->error('rate_limited', 'Too many geolocation requests. Please retry in a minute.', 429);
        }

        $lat = (float) $request->query->get('lat', 0);
        $lng = (float) $request->query->get('lng', 0);
        $radius = max(300, min((int) $request->query->get('radius', 4000), 10000));
        if (!$this->isValidLatLng($lat, $lng)) {
            return $this->error('invalid_coordinates', 'Latitude/longitude values are invalid.', 422, [
                'lat' => $lat,
                'lng' => $lng,
            ]);
        }

        $this->logger->info('Salary geolocation request', [
            'user_id' => method_exists($this->getUser(), 'getId') ? $this->getUser()?->getId() : null,
            'lat' => $lat,
            'lng' => $lng,
            'radius' => $radius,
        ]);

        try {
            $result = $mapsService->findNearbyWithMeta(
                'ATM bank distributeur cash withdrawal banque',
                $lat,
                $lng,
                $radius
            );

            $places = [];
            foreach ($result['places'] as $place) {
                $distanceKm = $this->distanceKm(
                    $lat,
                    $lng,
                    (float) ($place['lat'] ?? 0),
                    (float) ($place['lng'] ?? 0)
                );

                $places[] = [
                    'name' => (string) ($place['name'] ?? 'ATM'),
                    'address' => (string) ($place['address'] ?? ''),
                    'lat' => (float) ($place['lat'] ?? 0),
                    'lng' => (float) ($place['lng'] ?? 0),
                    'rating' => isset($place['rating']) ? (float) $place['rating'] : null,
                    'types' => is_array($place['types'] ?? null) ? $place['types'] : [],
                    'distance_km' => round($distanceKm, 2),
                ];
            }

            usort(
                $places,
                static fn (array $a, array $b): int => $a['distance_km'] <=> $b['distance_km']
            );

            return $this->success([
                'success' => true,
                'source' => $result['source'],
                'message' => $result['message'],
                'radius' => $radius,
                'places' => array_slice($places, 0, 8),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Salary geolocation failure', [
                'message' => $e->getMessage(),
            ]);
            return $this->error('nearby_distributors_failed', 'Nearby distributors error: ' . $e->getMessage(), 500);
        }
    }

    #[Route('/geolocation/distance', name: 'geolocation_distance', methods: ['GET'])]
    public function distance(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');
        $this->assertRoleAccess();
        $this->assertApiKey($request);
        if (!$this->consumeRateLimit($request, 40, 60)) {
            return $this->error('rate_limited', 'Too many geolocation requests. Please retry in a minute.', 429);
        }

        $fromLat = (float) $request->query->get('from_lat', 0);
        $fromLng = (float) $request->query->get('from_lng', 0);
        $toLat = (float) $request->query->get('to_lat', 0);
        $toLng = (float) $request->query->get('to_lng', 0);

        if (!$this->isValidLatLng($fromLat, $fromLng) || !$this->isValidLatLng($toLat, $toLng)) {
            return $this->error('invalid_coordinates', 'Invalid coordinates for distance calculation.', 422);
        }

        $km = $this->distanceKm($fromLat, $fromLng, $toLat, $toLng);

        return $this->success([
            'distance_km' => round($km, 3),
            'distance_m' => (int) round($km * 1000),
            'from' => ['lat' => $fromLat, 'lng' => $fromLng],
            'to' => ['lat' => $toLat, 'lng' => $toLng],
        ]);
    }

    private function distanceKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadiusKm = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadiusKm * $c;
    }

    private function isValidLatLng(float $lat, float $lng): bool
    {
        return $lat >= -90.0 && $lat <= 90.0 && $lng >= -180.0 && $lng <= 180.0 && !($lat == 0.0 && $lng == 0.0);
    }

    private function assertRoleAccess(): void
    {
        if (!$this->isGranted('ROLE_SALARY') && !$this->isGranted('ROLE_ADMIN')) {
            $this->logger->warning('Salary geolocation forbidden by role');
            throw new AccessDeniedException('ROLE_SALARY or ROLE_ADMIN is required.');
        }
    }

    private function assertApiKey(Request $request): void
    {
        $configured = trim($this->geoApiKey);
        if ($configured === '') {
            return;
        }

        $sent = trim((string) $request->headers->get('X-GEO-KEY', ''));
        if (!hash_equals($configured, $sent)) {
            $this->logger->warning('Salary geolocation invalid API key');
            throw new AccessDeniedException('Invalid geolocation API key.');
        }
    }

    private function consumeRateLimit(Request $request, int $max, int $windowSeconds): bool
    {
        $userId = method_exists($this->getUser(), 'getId') ? (int) ($this->getUser()?->getId() ?? 0) : 0;
        $ip = (string) ($request->getClientIp() ?? 'unknown');
        $bucket = (int) floor(time() / max(1, $windowSeconds));
        $key = sprintf('geo_rl_%d_%s_%d', $userId, md5($ip), $bucket);

        $item = $this->cache->getItem($key);
        $count = (int) ($item->isHit() ? $item->get() : 0);
        if ($count >= $max) {
            return false;
        }

        $item->set($count + 1);
        $item->expiresAfter($windowSeconds + 5);
        $this->cache->save($item);

        return true;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function success(array $payload, int $status = 200): JsonResponse
    {
        $payload['timestamp'] = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        $payload['request_id'] = bin2hex(random_bytes(8));
        return $this->json($payload, $status);
    }

    /**
     * @param array<string,mixed> $details
     */
    private function error(string $code, string $message, int $status, array $details = []): JsonResponse
    {
        return $this->json([
            'success' => false,
            'message' => $message,
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => $details,
            ],
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'request_id' => bin2hex(random_bytes(8)),
        ], $status);
    }
}
