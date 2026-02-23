<?php

namespace App\Controller;

use App\Service\GoogleCalendarService;
use App\Service\GoogleMapsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class AleaGoogleController extends AbstractController
{
    #[Route('/alea/google/calendar-link', name: 'app_alea_google_calendar_link', methods: ['GET'])]
    public function googleCalendarLink(Request $request, GoogleCalendarService $calendarService): JsonResponse
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $title = trim((string) $request->query->get('title', 'Rappel imprevu'));
        $category = trim((string) $request->query->get('category', 'AUTRE'));
        $days = max(1, (int) $request->query->get('days', 7));

        $start = (new \DateTimeImmutable('now'))->modify('+' . $days . ' days')->setTime(9, 0);
        $end = $start->modify('+1 hour');

        $details = sprintf('Categorie: %s. Rappel automatique genere depuis le module imprevus.', $category);
        $url = $calendarService->generateEventUrl($title, $details, $start, $end);

        return $this->json([
            'success' => true,
            'url' => $url,
        ]);
    }

    #[Route('/alea/google/nearby', name: 'app_alea_google_nearby', methods: ['GET'])]
    public function googleNearby(Request $request, GoogleMapsService $mapsService): JsonResponse
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $lat = (float) $request->query->get('lat', 0);
        $lng = (float) $request->query->get('lng', 0);
        $category = strtoupper((string) $request->query->get('category', 'AUTRE'));

        if ($lat === 0.0 && $lng === 0.0) {
            return $this->json([
                'success' => false,
                'message' => 'Coordonnees invalides.',
            ], 400);
        }

        $keyword = match ($category) {
            'VOITURE' => 'garage automobile',
            'SANTE' => 'pharmacie hopital',
            'PANNE_MAISON' => 'reparation electromenager',
            'EDUCATION' => 'ecole librairie papeterie',
            default => 'service urgence',
        };

        try {
            $result = $mapsService->findNearbyWithMeta($keyword, $lat, $lng, 5000);

            return $this->json([
                'success' => true,
                'keyword' => $keyword,
                'source' => $result['source'],
                'message' => $result['message'],
                'places' => $result['places'],
            ]);
        } catch (\Throwable $e) {
            return $this->json([
                'success' => false,
                'message' => 'Erreur Google Maps: ' . $e->getMessage(),
            ], 500);
        }
    }
}
