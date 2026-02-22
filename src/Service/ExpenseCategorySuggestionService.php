<?php

namespace App\Service;

use App\Entity\Expense;
use App\Entity\User;
use App\Repository\ExpenseRepository;

class ExpenseCategorySuggestionService
{
    /**
     * Keyword map: normalized keyword/phrase => category.
     *
     * @var array<string, string>
     */
    private const KEYWORD_CATEGORY_MAP = [
        // Transport
        'uber' => 'Transport',
        'taxi' => 'Transport',
        'bus' => 'Transport',
        'metro' => 'Transport',
        'train' => 'Transport',
        'fuel' => 'Transport',
        'essence' => 'Transport',
        'carburant' => 'Transport',
        'parking' => 'Transport',
        // Health
        'medicament' => 'Health',
        'pharmacie' => 'Health',
        'doctor' => 'Health',
        'medecin' => 'Health',
        'clinic' => 'Health',
        'hopital' => 'Health',
        'analysis' => 'Health',
        // Food
        'restaurant' => 'Food',
        'groceries' => 'Food',
        'courses' => 'Food',
        'food' => 'Food',
        'lunch' => 'Food',
        'dinner' => 'Food',
        'breakfast' => 'Food',
        'coffee' => 'Food',
        // Housing
        'rent' => 'Housing',
        'loyer' => 'Housing',
        'electricity' => 'Housing',
        'water bill' => 'Housing',
        'internet' => 'Housing',
        // Leisure
        'cinema' => 'Leisure',
        'netflix' => 'Leisure',
        'spotify' => 'Leisure',
        'game' => 'Leisure',
        'vacation' => 'Leisure',
        // Shopping
        'shopping' => 'Shopping',
        'clothes' => 'Shopping',
        'amazon' => 'Shopping',
        'mall' => 'Shopping',
    ];

    /** @var string[] */
    private const CATEGORIES = ['Food', 'Transport', 'Housing', 'Health', 'Leisure', 'Shopping', 'Other'];

    public function __construct(private readonly ExpenseRepository $expenseRepository)
    {
    }

    /**
     * @return array{
     *   category: string,
     *   confidence: float,
     *   breakdown: array<string, float>,
     *   alternatives: array<int, array{category: string, score: float}>
     * }
     */
    public function suggest(User $user, ?string $description, ?float $amount): array
    {
        $description = trim((string) $description);
        $amount = $amount !== null && $amount >= 0 ? $amount : null;

        $scores = array_fill_keys(self::CATEGORIES, 0.0);
        $keywordScores = array_fill_keys(self::CATEGORIES, 0.0);
        $historyScores = array_fill_keys(self::CATEGORIES, 0.0);
        $amountScores = array_fill_keys(self::CATEGORIES, 0.0);

        $normalizedDescription = $this->normalize($description);
        $tokens = $this->tokenize($normalizedDescription);

        // 1) Keyword logic
        foreach (self::KEYWORD_CATEGORY_MAP as $keyword => $category) {
            $kw = $this->normalize($keyword);
            if ($kw !== '' && str_contains($normalizedDescription, $kw)) {
                $keywordScores[$category] += strlen($kw) > 4 ? 1.0 : 0.7;
            }
        }

        // 2) Previous user behavior (description tokens + category frequency)
        /** @var Expense[] $history */
        $history = $this->expenseRepository->findBy(['user' => $user], ['id' => 'DESC'], 250);
        $categoryCount = array_fill_keys(self::CATEGORIES, 0);
        $tokenCategoryHits = [];
        $categoryAmountSums = array_fill_keys(self::CATEGORIES, 0.0);

        foreach ($history as $exp) {
            $category = (string) $exp->getCategory();
            if (!isset($categoryCount[$category])) {
                continue;
            }
            $categoryCount[$category]++;
            $categoryAmountSums[$category] += (float) ($exp->getAmount() ?? 0.0);

            $pastTokens = array_unique($this->tokenize($this->normalize((string) ($exp->getDescription() ?? ''))));
            foreach ($pastTokens as $tk) {
                $tokenCategoryHits[$tk][$category] = ($tokenCategoryHits[$tk][$category] ?? 0) + 1;
            }
        }

        foreach ($tokens as $tk) {
            if (!isset($tokenCategoryHits[$tk])) {
                continue;
            }
            foreach ($tokenCategoryHits[$tk] as $category => $hitCount) {
                if (isset($historyScores[$category])) {
                    $historyScores[$category] += (float) $hitCount;
                }
            }
        }

        // Add a light "global preference" prior from previous user behavior.
        $maxCategoryCount = max(1, ...array_values($categoryCount));
        foreach (self::CATEGORIES as $category) {
            $historyScores[$category] += 0.5 * ($categoryCount[$category] / $maxCategoryCount);
        }

        // 3) Amount heuristic based on user category averages
        if ($amount !== null) {
            foreach (self::CATEGORIES as $category) {
                if ($categoryCount[$category] === 0) {
                    continue;
                }
                $avg = $categoryAmountSums[$category] / $categoryCount[$category];
                $distance = abs($amount - $avg);
                $denominator = max($avg, 1.0);
                $amountScores[$category] = max(0.0, 1.0 - ($distance / $denominator));
            }
        }

        $keywordNorm = max(1.0, ...array_values($keywordScores));
        $historyNorm = max(1.0, ...array_values($historyScores));
        $amountNorm = max(1.0, ...array_values($amountScores));

        foreach (self::CATEGORIES as $category) {
            $k = $keywordScores[$category] / $keywordNorm;
            $h = $historyScores[$category] / $historyNorm;
            $a = $amountScores[$category] / $amountNorm;
            $scores[$category] = (0.55 * $k) + (0.35 * $h) + (0.10 * $a);
        }

        arsort($scores);
        $bestCategory = (string) array_key_first($scores);
        $bestScore = (float) reset($scores);

        // If nothing significant found, fallback to user-most-used category or Other.
        if ($bestScore < 0.12) {
            arsort($categoryCount);
            $bestCategory = (string) array_key_first($categoryCount);
            if ($bestCategory === '' || $categoryCount[$bestCategory] <= 0) {
                $bestCategory = 'Other';
            }
            $bestScore = 0.25;
        }

        $alternatives = [];
        $i = 0;
        foreach ($scores as $category => $score) {
            if ($category === $bestCategory) {
                continue;
            }
            $alternatives[] = ['category' => $category, 'score' => round($score, 4)];
            $i++;
            if ($i >= 2) {
                break;
            }
        }

        return [
            'category' => $bestCategory,
            'confidence' => round(min(0.99, max(0.0, $bestScore)), 4),
            'breakdown' => [
                'keywords' => round($keywordScores[$bestCategory] / $keywordNorm, 4),
                'history' => round($historyScores[$bestCategory] / $historyNorm, 4),
                'amount' => round($amountScores[$bestCategory] / $amountNorm, 4),
            ],
            'alternatives' => $alternatives,
        ];
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower($value);
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if (is_string($ascii) && $ascii !== '') {
            $value = $ascii;
        }
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return trim($value);
    }

    /**
     * @return string[]
     */
    private function tokenize(string $value): array
    {
        $parts = preg_split('/[^a-z0-9]+/i', $value) ?: [];
        $parts = array_values(array_filter($parts, static fn (string $t): bool => strlen($t) >= 3));

        return array_values(array_unique($parts));
    }
}

