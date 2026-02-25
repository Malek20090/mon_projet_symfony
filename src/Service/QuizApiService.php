<?php

namespace App\Service;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Service pour interagir avec l'API QuizAPI
 * https://quizapi.io/ - API gratuite pour les quiz
 * 
 * Améliorations : Plus de catégories et questions de qualité
 */
class QuizApiService
{
    private HttpClientInterface $client;
    private CacheItemPoolInterface $cachePool;
    
    // Clé API QuizAPI - Inscription gratuite sur https://quizapi.io/
    // Pour production, obtenir votre propre clé API gratuite
    private const API_KEY = ''; // Laissez vide pour utiliser les quiz par défaut
    private const API_BASE_URL = 'https://quizapi.io/api/v1';
    
    // Catégories disponibles
    private const CATEGORIES = [
        'crypto' => 19,      // Cryptocurrencies
        'finance' => 17,    // Business
        'banking' => 17,    // Business
        'investissement' => 17,     // Business
    ];
    
    private const CACHE_TTL = 3600; // 1 heure

    public function __construct(HttpClientInterface $client, CacheItemPoolInterface $cachePool)
    {
        $this->client = $client;
        $this->cachePool = $cachePool;
    }

    /**
     * Récupère les quiz par catégorie
     */
    public function getQuizzesByCategory(string $category, int $limit = 10): array
    {
        $categoryId = self::CATEGORIES[$category] ?? self::CATEGORIES['finance'];
        
        $cacheKey = 'quizapi_' . $category . '_' . $limit;
        $cacheItem = $this->cachePool->getItem($cacheKey);
        
        if ($cacheItem->isHit()) {
            return $cacheItem->get();
        }

        try {
            $response = $this->client->request('GET', self::API_BASE_URL . '/questions', [
                'query' => [
                    'category' => $categoryId,
                    'limit' => $limit,
                    'difficulty' => 'easy,medium',
                ],
                'headers' => [
                    'X-Api-Key' => self::API_KEY,
                    'Accept' => 'application/json',
                ],
                'timeout' => 10,
            ]);

            $data = $response->toArray();
            $quizzes = $this->formatQuizzes($data);
            
            // Si l'API ne retourne pas assez de questions, compléter avec les quizzes par défaut
            if (count($quizzes) < $limit) {
                $defaultQuizzes = $this->getDefaultQuizzes($category);
                $quizzes = array_merge($quizzes, array_slice($defaultQuizzes, 0, $limit - count($quizzes)));
            }
            
            $cacheItem->set($quizzes);
            $cacheItem->expiresAfter(self::CACHE_TTL);
            $this->cachePool->save($cacheItem);
            
            return $quizzes;
            
        } catch (\Exception $e) {
            // En cas d'erreur, retourner des quiz par défaut
            return $this->getDefaultQuizzes($category);
        }
    }

    /**
     * Récupère les quiz pour la certification Crypto
     */
    public function getCryptoQuizzes(int $limit = 10): array
    {
        return $this->getQuizzesByCategory('crypto', $limit);
    }

    /**
     * Récupère les quiz pour la certification Finance
     */
    public function getFinanceQuizzes(int $limit = 10): array
    {
        return $this->getQuizzesByCategory('finance', $limit);
    }

    /**
     * Récupère les quiz pour la certification Investissement
     */
    public function getInvestissementQuizzes(int $limit = 10): array
    {
        return $this->getQuizzesByCategory('investissement', $limit);
    }

    /**
     * Formate les données de l'API pour le format interne
     */
    private function formatQuizzes(array $data): array
    {
        $quizzes = [];
        
        foreach ($data as $item) {
            $quiz = [
                'id' => $item['id'] ?? uniqid(),
                'question' => strip_tags($item['question'] ?? ''),
                'difficulty' => $item['difficulty'] ?? 'medium',
                'category' => $item['category'] ?? 'General',
            ];
            
            // Extraire les réponses
            $answers = [];
            if (isset($item['answers'])) {
                foreach ($item['answers'] as $key => $answer) {
                    if ($answer !== null) {
                        $answers[$key] = strip_tags($answer);
                    }
                }
            }
            $quiz['answers'] = $answers;
            
            // Extraire la réponse correcte
            if (isset($item['correct_answers'])) {
                foreach ($item['correct_answers'] as $key => $isCorrect) {
                    if ($isCorrect === 'true') {
                        $quiz['correct_answer'] = str_replace('_answer', '', $key);
                        break;
                    }
                }
            }
            
            $quizzes[] = $quiz;
        }
        
        return $quizzes;
    }

    /**
     * Quiz par défaut améliorés - Questions de qualité en français
     */
    private function getDefaultQuizzes(string $category): array
    {
        // Quiz Crypto complets
        if ($category === 'crypto') {
            return [
                [
                    'id' => 'crypto_1',
                    'question' => 'Quelle est la première cryptomonnaie créée ?',
                    'answers' => [
                        'answer_a' => 'Ethereum',
                        'answer_b' => 'Bitcoin',
                        'answer_c' => 'Litecoin',
                        'answer_d' => 'Ripple',
                    ],
                    'correct_answer' => 'answer_b',
                    'difficulty' => 'easy',
                    'category' => 'Cryptocurrency',
                ],
                [
                    'id' => 'crypto_2',
                    'question' => 'Qu\'est-ce que la blockchain ?',
                    'answers' => [
                        'answer_a' => 'Une cryptomonnaie',
                        'answer_b' => 'Une technologie de registre distribué',
                        'answer_c' => 'Une banque centrale',
                        'answer_d' => 'Un gouvernement numérique',
                    ],
                    'correct_answer' => 'answer_b',
                    'difficulty' => 'easy',
                    'category' => 'Blockchain',
                ],
                [
                    'id' => 'crypto_3',
                    'question' => 'Qu\'est-ce qu\'un wallet crypto ?',
                    'answers' => [
                        'answer_a' => 'Un portefeuille physique',
                        'answer_b' => 'Un logiciel de stockage de clés privées',
                        'answer_c' => 'Une banque en ligne',
                        'answer_d' => 'Un navigateur web',
                    ],
                    'correct_answer' => 'answer_b',
                    'difficulty' => 'medium',
                    'category' => 'Wallet',
                ],
                [
                    'id' => 'crypto_4',
                    'question' => 'Qu\'est-ce que le minage de cryptomonnaies ?',
                    'answers' => [
                        'answer_a' => 'L\'extraction de métaux précieux',
                        'answer_b' => 'Le processus de validation des transactions',
                        'answer_c' => 'L\'achat de cryptos',
                        'answer_d' => 'La création de nouvelles banques',
                    ],
                    'correct_answer' => 'answer_b',
                    'difficulty' => 'medium',
                    'category' => 'Mining',
                ],
                [
                    'id' => 'crypto_5',
                    'question' => 'Qu\'est-ce que le Bitcoin ?',
                    'answers' => [
                        'answer_a' => 'Une devise fiduciaire',
                        'answer_b' => 'Une cryptomonnaie décentralisée',
                        'answer_c' => 'Une action boursière',
                        'answer_d' => 'Un prêt bancaire',
                    ],
                    'correct_answer' => 'answer_b',
                    'difficulty' => 'easy',
                    'category' => 'Cryptocurrency',
                ],
                [
                    'id' => 'crypto_6',
                    'question' => 'Qu\'est-ce qu\'une clé privée ?',
                    'answers' => [
                        'answer_a' => 'Un mot de passe bancaire',
                        'answer_b' => 'Une suite de caractères permettant d\'accéder à ses fonds',
                        'answer_c' => 'Un identifiant utilisateur',
                        'answer_d' => 'Un code PIN de carte bancaire',
                    ],
                    'correct_answer' => 'answer_b',
                    'difficulty' => 'medium',
                    'category' => 'Security',
                ],
                [
                    'id' => 'crypto_7',
                    'question' => 'Qu\'est-ce que Ethereum ?',
                    'answers' => [
                        'answer_a' => 'Une simple cryptomonnaie',
                        'answer_b' => 'Une plateforme de smart contracts',
                        'answer_c' => 'Une banque en ligne',
                        'answer_d' => 'Un réseau social',
                    ],
                    'correct_answer' => 'answer_b',
                    'difficulty' => 'medium',
                    'category' => 'Blockchain',
                ],
                [
                    'id' => 'crypto_8',
                    'question' => 'Qu\'est-ce que le Proof of Work (PoW) ?',
                    'answers' => [
                        'answer_a' => 'Un protocole de sécurité internet',
                        'answer_b' => 'Un mécanisme de consensus pour valider les transactions',
                        'answer_c' => 'Un type de portefeuille',
                        'answer_d' => 'Un échange de cryptomonnaies',
                    ],
                    'correct_answer' => 'answer_b',
                    'difficulty' => 'hard',
                    'category' => 'Consensus',
                ],
                [
                    'id' => 'crypto_9',
                    'question' => 'Qu\'est-ce qu\'une ICO ?',
                    'answers' => [
                        'answer_a' => 'Une introduction en bourse traditionnelle',
                        'answer_b' => 'Une offre initiale de cryptomonnaies',
                        'answer_c' => 'Une institution financière classique',
                        'answer_d' => 'Un organisme de régulation',
                    ],
                    'correct_answer' => 'answer_b',
                    'difficulty' => 'medium',
                    'category' => 'Investment',
                ],
                [
                    'id' => 'crypto_10',
                    'question' => 'Qu\'est-ce que la volatilité ?',
                    'answers' => [
                        'answer_a' => 'La stabilité totale du marché',
                        'answer_b' => 'La mesure des variations de prix',
                        'answer_c' => 'Le volume des transactions',
                        'answer_d' => 'Le nombre d\'utilisateurs',
                    ],
                    'correct_answer' => 'answer_b',
                    'difficulty' => 'medium',
                    'category' => 'Trading',
                ],
                [
                    'id' => 'crypto_11',
                    'question' => 'Qu\'est-ce qu\'un exchange crypto ?',
                    'answers' => [
                        'answer_a' => 'Un portefeuille physique',
                        'answer_b' => 'Une plateforme d\'échange de cryptomonnaies',
                        'answer_c' => 'Un gouvernement',
                        'answer_d' => 'Une banque centrale',
                    ],
                    'correct_answer' => 'answer_b',
                    'difficulty' => 'easy',
                    'category' => 'Exchange',
                ],
                [
                    'id' => 'crypto_12',
                    'question' => 'Qu\'est-ce que le Halving du Bitcoin ?',
                    'answers' => [
                        'answer_a' => 'Une augmentation des rewards de minage',
                        'answer_b' => 'Une réduction de moitié des rewards de minage',
                        'answer_c' => 'Un hack de plateforme',
                        'answer_d' => 'Une regulation gouvernementale',
                    ],
                    'correct_answer' => 'answer_b',
                    'difficulty' => 'hard',
                    'category' => 'Mining',
                ],
            ];
        }
        
        // Quiz Investissement
        if ($category === 'investissement') {
            return [
                [
                    'id' => 'invest_1',
                    'question' => 'Qu\'est-ce qu\'une action en bourse ?',
                    'answers' => [
                        'answer_a' => 'Un prêt bancaire',
                        'answer_b' => 'Une partie du capital d\'une entreprise',
                        'answer_c' => 'Un compte épargne',
                        'answer_d' => 'Une obligation fiscale',
                    ],
                    'correct_answer' => 'answer_b',
                    'difficulty' => 'medium',
                    'category' => 'Stock',
                ],
                [
                    'id' => 'invest_2',
                    'question' => 'Qu\'est-ce qu\'une obligation ?',
                    'answers' => [
                        'answer_a' => 'Une part d\'entreprise',
                        'answer_b' => 'Un titre de créance sur une entité',
                        'answer_c' => 'Un compte courant',
                        'answer_d' => 'Une cryptomonnaie',
                    ],
                    'correct_answer' => 'answer_b',
                    'difficulty' => 'medium',
                    'category' => 'Bond',
                ],
                [
                    'id' => 'invest_3',
                    'question' => 'Qu\'est-ce que la diversification ?',
                    'answers' => [
                        'answer_a' => 'Mettre tout son argent dans un seul placement',
                        'answer_b' => 'Répartir ses investissements sur différents actifs',
                        'answer_c' => 'Garder tout son argent en banque',
                        'answer_d' => 'N\'investir que dans l\'immobilier',
                    ],
                    'correct_answer' => 'answer_b',
                    'difficulty' => 'medium',
                    'category' => 'Investment',
                ],
                [
                    'id' => 'invest_4',
                    'question' => 'Qu\'est-ce qu\'un fonds d\'investissement ?',
                    'answers' => [
                        'answer_a' => 'Un compte bancaire classique',
                        'answer_b' => 'Une poche collective qui investit dans plusieurs actifs',
                        'answer_c' => 'Un prêt bancaire',
                        'answer_d' => 'Une assurance vie',
                    ],
                    'correct_answer' => 'answer_b',
                    'difficulty' => 'medium',
                    'category' => 'Investment',
                ],
                [
                    'id' => 'invest_5',
                    'question' => 'Qu\'est-ce que le risque systémique ?',
                    'answers' => [
                        'answer_a' => 'Le risque propre à une entreprise',
                        'answer_b' => 'Un risque qui affecte tout le système financier',
                        'answer_c' => 'Un risque négligeable',
                        'answer_d' => 'Un risque diversifiable',
                    ],
                    'correct_answer' => 'answer_b',
                    'difficulty' => 'hard',
                    'category' => 'Risk',
                ],
                [
                    'id' => 'invest_6',
                    'question' => 'Qu\'est-ce que l\'effet de levier ?',
                    'answers' => [
                        'answer_a' => 'Emprunter pour investir',
                        'answer_b' => 'Garder son argent liquide',
                        'answer_c' => 'Investir uniquement ses propres fonds',
                        'answer_d' => 'Éviter les investissements',
                    ],
                    'correct_answer' => 'answer_a',
                    'difficulty' => 'medium',
                    'category' => 'Trading',
                ],
                [
                    'id' => 'invest_7',
                    'question' => 'Qu\'est-ce qu\'un ETF ?',
                    'answers' => [
                        'answer_a' => 'Un fonds qui replicate un indice boursier',
                        'answer_b' => 'Une action individuelle',
                        'answer_c' => 'Un compte épargne',
                        'answer_d' => 'Une obligation',
                    ],
                    'correct_answer' => 'answer_a',
                    'difficulty' => 'medium',
                    'category' => 'Investment',
                ],
                [
                    'id' => 'invest_8',
                    'question' => 'Qu\'est-ce que la liquidité d\'un actif ?',
                    'answers' => [
                        'answer_a' => 'Sa rentabilité',
                        'answer_b' => 'Sa facilité de conversion en argent',
                        'answer_c' => 'Sa durabilité',
                        'answer_d' => 'Sa volatilité',
                    ],
                    'correct_answer' => 'answer_b',
                    'difficulty' => 'medium',
                    'category' => 'Investment',
                ],
                [
                    'id' => 'invest_9',
                    'question' => 'Qu\'est-ce que le profil de risque ?',
                    'answers' => [
                        'answer_a' => 'Le montant de l\'investissement',
                        'answer_b' => 'La capacité et la volonté de prendre des risques',
                        'answer_c' => 'L\'âge de l\'investisseur',
                        'answer_d' => 'Le type de compte bancaire',
                    ],
                    'correct_answer' => 'answer_b',
                    'difficulty' => 'easy',
                    'category' => 'Risk',
                ],
                [
                    'id' => 'invest_10',
                    'question' => 'Qu\'est-ce qu\'un dividende ?',
                    'answers' => [
                        'answer_a' => 'Une taxe sur les investissements',
                        'answer_b' => 'Une part des bénéfices distribués aux actionnaires',
                        'answer_c' => 'Un frais de gestion',
                        'answer_d' => 'Une perte financière',
                    ],
                    'correct_answer' => 'answer_b',
                    'difficulty' => 'easy',
                    'category' => 'Stock',
                ],
                [
                    'id' => 'invest_11',
                    'question' => 'Qu\'est-ce que l\'allocation d\'actifs ?',
                    'answers' => [
                        'answer_a' => 'La répartition de son patrimoine entre différents types d\'investissements',
                        'answer_b' => 'L\'achat d\'actions',
                        'answer_c' => 'La vente de biens',
                        'answer_d' => 'Le choix d\'une banque',
                    ],
                    'correct_answer' => 'answer_a',
                    'difficulty' => 'medium',
                    'category' => 'Investment',
                ],
                [
                    'id' => 'invest_12',
                    'question' => 'Qu\'est-ce qu\'un warrant ?',
                    'answers' => [
                        'answer_a' => 'Un titre de créance simple',
                        'answer_b' => 'Un produit dérivé donnant le droit d\'acheter ou vendre un actif',
                        'answer_c' => 'Un compte épargne',
                        'answer_d' => 'Une action gratuite',
                    ],
                    'correct_answer' => 'answer_b',
                    'difficulty' => 'hard',
                    'category' => 'Derivatives',
                ],
            ];
        }
        
        // Quiz Finance par défaut
        return [
            [
                'id' => 'finance_1',
                'question' => 'Qu\'est-ce que la finance personnelle ?',
                'answers' => [
                    'answer_a' => 'La gestion des finances d\'une entreprise',
                    'answer_b' => 'La gestion des ressources financières d\'un individu',
                    'answer_c' => 'La gestion des comptes gouvernementaux',
                    'answer_d' => 'La gestion des investissements bancaires',
                ],
                'correct_answer' => 'answer_b',
                'difficulty' => 'easy',
                'category' => 'Finance',
            ],
            [
                'id' => 'finance_2',
                'question' => 'Qu\'est-ce qu\'un budget ?',
                'answers' => [
                    'answer_a' => 'Un compte bancaire',
                    'answer_b' => 'Un plan de dépenses et de revenus',
                    'answer_c' => 'Un prêt',
                    'answer_d' => 'Un investissement',
                ],
                'correct_answer' => 'answer_b',
                'difficulty' => 'easy',
                'category' => 'Budget',
            ],
            [
                'id' => 'finance_3',
                'question' => 'Qu\'est-ce que l\'épargne ?',
                'answers' => [
                    'answer_a' => 'Dépenser tout son argent',
                    'answer_b' => 'Mettre de côté une partie de ses revenus',
                    'answer_c' => 'Investir en bourse',
                    'answer_d' => 'Faire des prêts',
                ],
                'correct_answer' => 'answer_b',
                'difficulty' => 'easy',
                'category' => 'Savings',
            ],
            [
                'id' => 'finance_4',
                'question' => 'Qu\'est-ce qu\'un investissement ?',
                'answers' => [
                    'answer_a' => 'Mettre son argent sous le matelas',
                    'answer_b' => 'Placer de l\'argent pour générer des rendements',
                    'answer_c' => 'Dépenser pour des biens de consommation',
                    'answer_d' => 'Garder son argent en liquide',
                ],
                'correct_answer' => 'answer_b',
                'difficulty' => 'medium',
                'category' => 'Investment',
            ],
            [
                'id' => 'finance_5',
                'question' => 'Qu\'est-ce que la diversification ?',
                'answers' => [
                    'answer_a' => 'Mettre tout son argent dans un seul placement',
                    'answer_b' => 'Répartir ses investissements sur différents actifs',
                    'answer_c' => 'Garder tout son argent en banque',
                    'answer_d' => 'N\'investir que dans l\'immobilier',
                ],
                'correct_answer' => 'answer_b',
                'difficulty' => 'medium',
                'category' => 'Investment',
            ],
            [
                'id' => 'finance_6',
                'question' => 'Qu\'est-ce qu\'un compte épargne ?',
                'answers' => [
                    'answer_a' => 'Un compte pour dépenses quotidiennes',
                    'answer_b' => 'Un compte qui génère des intérêts sur les dépôts',
                    'answer_c' => 'Un compte pour les investissements risqués',
                    'answer_d' => 'Un compte courant sans intérêts',
                ],
                'correct_answer' => 'answer_b',
                'difficulty' => 'easy',
                'category' => 'Savings',
            ],
            [
                'id' => 'finance_7',
                'question' => 'Qu\'est-ce que l\'intérêt composé ?',
                'answers' => [
                    'answer_a' => 'Un intérêt fixe',
                    'answer_b' => 'Des intérêts qui génèrent des intérêts',
                    'answer_c' => 'Un frais bancaire',
                    'answer_d' => 'Une pénalité',
                ],
                'correct_answer' => 'answer_b',
                'difficulty' => 'medium',
                'category' => 'Interest',
            ],
            [
                'id' => 'finance_8',
                'question' => 'Qu\'est-ce que la retraite par capitalisation ?',
                'answers' => [
                    'answer_a' => 'Un système où les cotisations sont investies',
                    'answer_b' => 'Un système de retraite par répartition',
                    'answer_c' => 'Une aide sociale',
                    'answer_d' => 'Un complément alimentaire',
                ],
                'correct_answer' => 'answer_a',
                'difficulty' => 'hard',
                'category' => 'Retirement',
            ],
            [
                'id' => 'finance_9',
                'question' => 'Qu\'est-ce qu\'un Plan d\'Épargne Retraite (PER) ?',
                'answers' => [
                    'answer_a' => 'Un compte courant',
                    'answer_b' => 'Un produit d\'épargne pour la retraite',
                    'answer_c' => 'Un crédit immobilier',
                    'answer_d' => 'Une assurance auto',
                ],
                'correct_answer' => 'answer_b',
                'difficulty' => 'medium',
                'category' => 'Retirement',
            ],
            [
                'id' => 'finance_10',
                'question' => 'Qu\'est-ce que l\'inflation ?',
                'answers' => [
                    'answer_a' => 'Une diminution des prix',
                    'answer_b' => 'Une augmentation générale des prix',
                    'answer_c' => 'Une stabilité des prix',
                    'answer_d' => 'Une récession',
                ],
                'correct_answer' => 'answer_b',
                'difficulty' => 'easy',
                'category' => 'Economy',
            ],
            [
                'id' => 'finance_11',
                'question' => 'Qu\'est-ce que le risk management ?',
                'answers' => [
                    'answer_a' => 'Éliminer tous les risques',
                    'answer_b' => 'Identifier, évaluer et réduire les risques financiers',
                    'answer_c' => 'Investir dans des produits risqués',
                    'answer_d' => 'Éviter d\'épargner',
                ],
                'correct_answer' => 'answer_b',
                'difficulty' => 'medium',
                'category' => 'Risk',
            ],
            [
                'id' => 'finance_12',
                'question' => 'Qu\'est-ce qu\'un crédit immobilier ?',
                'answers' => [
                    'answer_a' => 'Un prêt pour acheter un véhicule',
                    'answer_b' => 'Un prêt pour acheter un bien immobilier',
                    'answer_c' => 'Un découvert bancaire',
                    'answer_d' => 'Un crédit à la consommation',
                ],
                'correct_answer' => 'answer_b',
                'difficulty' => 'easy',
                'category' => 'Credit',
            ],
        ];
    }

    /**
     * Vérifie si une réponse est correcte
     */
    public function checkAnswer(array $quiz, string $answer): bool
    {
        return isset($quiz['correct_answer']) && 
               $quiz['correct_answer'] === $answer;
    }
}
