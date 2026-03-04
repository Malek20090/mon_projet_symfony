<?php

namespace App\Controller;

use App\Service\UserGeoContextResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class DebugGeoController extends AbstractController
{
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    #[Route('/debug/geo', name: 'app_debug_geo', methods: ['GET'])]
    public function __invoke(Request $request, UserGeoContextResolver $resolver): JsonResponse
    {
        $headers = [
            'cf-connecting-ip' => (string) $request->headers->get('CF-Connecting-IP', ''),
            'true-client-ip' => (string) $request->headers->get('True-Client-IP', ''),
            'x-forwarded-for' => (string) $request->headers->get('X-Forwarded-For', ''),
            'x-real-ip' => (string) $request->headers->get('X-Real-IP', ''),
            'cf-ipcountry' => (string) $request->headers->get('CF-IPCountry', ''),
        ];

        return $this->json([
            'route' => 'app_debug_geo',
            'client_ip_symfony' => $request->getClientIp(),
            'headers' => $headers,
            'resolved' => $resolver->resolve($request),
            'app_env' => $_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? 'unknown',
        ]);
    }
}

