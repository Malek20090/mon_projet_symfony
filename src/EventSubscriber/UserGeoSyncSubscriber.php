<?php

namespace App\EventSubscriber;

use App\Entity\User;
use App\Service\UserGeoContextResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

class UserGeoSyncSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
        private readonly UserGeoContextResolver $geoContextResolver,
        private readonly EntityManagerInterface $em
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', -10],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $token = $this->tokenStorage->getToken();
        $user = $token?->getUser();
        if (!$user instanceof User || $user->getId() === null) {
            return;
        }

        $request = $event->getRequest();
        $currentIp = $this->resolveRequestIp($request);
        if (!$this->shouldRefresh($user, $currentIp)) {
            return;
        }

        $ctx = $this->geoContextResolver->resolve($request);

        $user->setGeoDetectedIp($ctx['ip']);
        $user->setGeoCountryCode($ctx['country_code']);
        $user->setGeoCountryName($ctx['country_name']);
        $user->setGeoRegionName($ctx['region_name']);
        $user->setGeoCityName($ctx['city_name']);
        $user->setGeoVpnSuspected($ctx['vpn_suspected']);
        $user->setGeoLastCheckedAt(new \DateTimeImmutable());

        $this->em->flush();
    }

    private function shouldRefresh(User $user, ?string $currentIp): bool
    {
        if (is_string($currentIp) && $currentIp !== '' && $currentIp !== $user->getGeoDetectedIp()) {
            return true;
        }

        $last = $user->getGeoLastCheckedAt();
        if ($last === null) {
            return true;
        }

        // If location is still incomplete, retry more aggressively.
        if ($user->getGeoRegionName() === null || $user->getGeoCityName() === null) {
            $quickRetryThreshold = (new \DateTimeImmutable())->modify('-10 minutes');
            if ($last < $quickRetryThreshold) {
                return true;
            }
        }

        $hours = max(1, min(24, $this->refreshHours()));
        $threshold = (new \DateTimeImmutable())->modify('-' . $hours . ' hours');

        return $last < $threshold;
    }

    private function refreshHours(): int
    {
        $raw = $_ENV['GEO_REFRESH_HOURS'] ?? $_SERVER['GEO_REFRESH_HOURS'] ?? getenv('GEO_REFRESH_HOURS');
        if (!is_scalar($raw) || !is_numeric((string) $raw)) {
            return 6;
        }

        return (int) $raw;
    }

    private function resolveRequestIp(\Symfony\Component\HttpFoundation\Request $request): ?string
    {
        $candidates = [
            (string) $request->headers->get('CF-Connecting-IP', ''),
            (string) $request->headers->get('True-Client-IP', ''),
            (string) $request->headers->get('X-Forwarded-For', ''),
            (string) $request->headers->get('X-Real-IP', ''),
        ];

        foreach ($candidates as $raw) {
            $parts = array_map('trim', explode(',', $raw));
            foreach ($parts as $ip) {
                if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        $ip = $request->getClientIp();
        return is_string($ip) && $ip !== '' ? $ip : null;
    }
}
