<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Repository\TransactionRepository;
use App\Repository\UserRepository;
use App\Service\ExchangeRateService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Security;

#[Route('/api/devise', name: 'api_devise_')]
class DeviseController extends AbstractController
{
    private const BASE_CURRENCY = 'TND';

    #[Route('/taux', name: 'taux', methods: ['GET'])]
    public function taux(Request $request, ExchangeRateService $exchangeRateService): JsonResponse
    {
        $from = $this->normalizeCurrency((string) $request->query->get('from', self::BASE_CURRENCY));
        $to = $this->normalizeCurrency((string) $request->query->get('to', 'EUR'));
        $amount = (float) $request->query->get('amount', 1);

        if ($from === null || $to === null) {
            return $this->json([
                'success' => false,
                'message' => 'Invalid currency code. Use ISO 3 letters (example: TND, EUR, USD).',
            ], 422);
        }

        $rate = $exchangeRateService->getRate($from, $to);
        if ($rate === null) {
            return $this->json([
                'success' => false,
                'message' => 'Unable to fetch exchange rate at the moment.',
            ], 503);
        }

        return $this->json([
            'success' => true,
            'from' => $from,
            'to' => $to,
            'rate' => $rate,
            'amount' => $amount,
            'converted_amount' => round($amount * $rate, 4),
            'source' => 'frankfurter.app',
        ]);
    }

    #[Route('/user/{id}/resume', name: 'user_resume', methods: ['GET'])]
    public function userResume(
        int $id,
        Request $request,
        Security $security,
        UserRepository $userRepository,
        TransactionRepository $transactionRepository,
        ExchangeRateService $exchangeRateService
    ): JsonResponse {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $actor = $this->getUser();
        $user = $userRepository->find($id);
        if (!$user instanceof User) {
            return $this->json(['success' => false, 'message' => 'User not found.'], 404);
        }

        $isOwner = $actor instanceof User && $actor->getId() === $user->getId();
        $isAdmin = $security->isGranted('ROLE_ADMIN');
        if (!$isOwner && !$isAdmin) {
            return $this->json(['success' => false, 'message' => 'Access denied.'], 403);
        }

        $to = $this->normalizeCurrency((string) $request->query->get('to', 'EUR'));
        if ($to === null) {
            return $this->json([
                'success' => false,
                'message' => 'Invalid target currency code.',
            ], 422);
        }

        $limit = (int) $request->query->get('limit', 50);
        $limit = max(1, min($limit, 200));

        $rate = $exchangeRateService->getRate(self::BASE_CURRENCY, $to);
        if ($rate === null) {
            return $this->json([
                'success' => false,
                'message' => 'Unable to fetch exchange rate at the moment.',
            ], 503);
        }

        $transactions = $transactionRepository->createQueryBuilder('t')
            ->where('t.user = :user')
            ->setParameter('user', $user)
            ->orderBy('t.date', 'DESC')
            ->addOrderBy('t.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        $items = [];
        foreach ($transactions as $tx) {
            $amountBase = (float) $tx->getMontant();
            $items[] = [
                'id' => $tx->getId(),
                'type' => $tx->getType(),
                'date' => $tx->getDate()->format('Y-m-d'),
                'description' => $tx->getDescription(),
                'amount_base' => round($amountBase, 4),
                'amount_target' => round($amountBase * $rate, 4),
            ];
        }

        $balanceBase = (float) $user->getSoldeTotal();

        return $this->json([
            'success' => true,
            'base_currency' => self::BASE_CURRENCY,
            'target_currency' => $to,
            'rate' => $rate,
            'user' => [
                'id' => $user->getId(),
                'name' => $user->getNom(),
                'email' => $user->getEmail(),
                'balance_base' => round($balanceBase, 4),
                'balance_target' => round($balanceBase * $rate, 4),
            ],
            'transactions' => $items,
        ]);
    }

    private function normalizeCurrency(string $currency): ?string
    {
        $value = strtoupper(trim($currency));

        return preg_match('/^[A-Z]{3}$/', $value) ? $value : null;
    }
}

