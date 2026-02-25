<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Service de génération automatique de quiz avec OpenAI
 * Utilise GPT-4 pour générer des questions à choix multiples
 */
class AIQuizGeneratorService
{
    private HttpClientInterface $httpClient;
    private string $apiKey;
    private const API_URL = 'https://api.openai.com/v1/chat/completions';
    
    // Modèle par défaut
    private const DEFAULT_MODEL = 'gpt-4o-mini';
    
    public function __construct(HttpClientInterface $httpClient)
    {
        $this->httpClient = $httpClient;
        // Clé API OpenAI - utiliser la variable d'environnement OPENAI_API_KEY
        $this->apiKey = $_ENV['OPENAI_API_KEY'] ?? getenv('OPENAI_API_KEY') ?: '';
    }

    /**
     * Génère des quiz à partir du contenu d'un cours
     * 
     * @param string $content Le contenu du cours
     * @param string $topic Le sujet/titre du cours
     * @param int $count Nombre de questions à générer
     * @param string $difficulty Difficulté: easy, medium, hard
     * @return array Tableau de questions
     */
    public function generateQuizzesFromContent(
        string $content, 
        string $topic, 
        int $count = 5, 
        string $difficulty = 'medium'
    ): array {
        $prompt = $this->buildPrompt($content, $topic, $count, $difficulty);
        
        try {
            $response = $this->httpClient->request('POST', self::API_URL, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => self::DEFAULT_MODEL,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => 'Tu es un expert en création de quiz éducatifs. Tu génères des questions à choix multiples de haute qualité en français.'
                        ],
                        [
                            'role' => 'user',
                            'content' => $prompt
                        ]
                    ],
                    'temperature' => 0.7,
                    'max_tokens' => 2000,
                ],
                'timeout' => 60,
            ]);
            
            $data = $response->toArray();
            
            if (isset($data['choices'][0]['message']['content'])) {
                return $this->parseAIResponse($data['choices'][0]['message']['content']);
            }
            
            return $this->getFallbackQuizzes($topic, $count);
            
        } catch (\Exception $e) {
            // En cas d'erreur, retourner des quiz par défaut
            return $this->getFallbackQuizzes($topic, $count);
        }
    }

    /**
     * Construit le prompt pour OpenAI
     */
    private function buildPrompt(string $content, string $topic, int $count, string $difficulty): string
    {
        return <<<PROMPT
Tu es un expert en création de quiz éducatifs pour une plateforme de finance personnelle.

Génère exactement $count questions à choix multiples en français sur le sujet: "$topic"

Le contenu de référence est:
---
$content
---

RÈGLES STRICTES:
1. Chaque question doit avoir EXACTEMENT 4 réponses (answer_a, answer_b, answer_c, answer_d)
2. Une seule réponse correcte par question
3. La difficulté doit être: $difficulty
4. Les questions doivent être basées sur le contenu fourni ci-dessus
5. Réponds UNIQUEMENT avec un tableau JSON valide, sans texte avant ou après
6. Le format EXACT doit être:

[{"question":"...","answer_a":"...","answer_b":"...","answer_c":"...","answer_d":"...","correct_answer":"answer_a","explanation":"..."}]

Aucun autre format n'est accepté. Génère maintenant les $count questions:
PROMPT;
    }

    /**
     * Parse la réponse JSON d'OpenAI
     */
    private function parseAIResponse(string $response): array
    {
        // Nettoyer la réponse - supprimer les blocs code markdown
        $cleanedResponse = $response;
        $cleanedResponse = preg_replace('/^
```
json\s*/', '', $cleanedResponse);
        $cleanedResponse = preg_replace('/\s*
```
$/', '', $cleanedResponse);
        $cleanedResponse = trim($cleanedResponse);
        
        // Essayer de parser directement
        $quizzes = json_decode($cleanedResponse, true);
        
        if (json_last_error() === JSON_ERROR_NONE && is_array($quizzes)) {
            return $this->validateAndFormatQuizzes($quizzes);
        }
        
        // Essayer d'extraire le JSON avec une regex plus permissive
        $jsonMatch = [];
        if (preg_match('/\[[\s\S]*\]/', $response, $jsonMatch)) {
            $jsonString = $jsonMatch[0];
            $quizzes = json_decode($jsonString, true);
            
            if (json_last_error() === JSON_ERROR_NONE && is_array($quizzes)) {
                return $this->validateAndFormatQuizzes($quizzes);
            }
        }
        
        // Si le parsing échoue, retourner les quiz de secours
        return [];
    }

    /**
     * Valide et formate les quiz générés
     */
    private function validateAndFormatQuizzes(array $quizzes): array
    {
        $validated = [];
        
        foreach ($quizzes as $quiz) {
            // Vérifier que la question a tous les champs requis
            if (isset($quiz['question']) && 
                isset($quiz['answer_a']) && 
                isset($quiz['answer_b']) && 
                isset($quiz['answer_c']) && 
                isset($quiz['answer_d']) && 
                isset($quiz['correct_answer'])) {
                
                $validated[] = [
                    'id' => 'ai_' . uniqid(),
                    'question' => $quiz['question'],
                    'answer_a' => $quiz['answer_a'],
                    'answer_b' => $quiz['answer_b'],
                    'answer_c' => $quiz['answer_c'],
                    'answer_d' => $quiz['answer_d'],
                    'correct_answer' => $quiz['correct_answer'],
                    'difficulty' => $quiz['difficulty'] ?? 'medium',
                    'explanation' => $quiz['explanation'] ?? '',
                ];
            }
        }
        
        return $validated;
    }

    /**
     * Quiz de secours en cas d'erreur API
     */
    private function getFallbackQuizzes(string $topic, int $count): array
    {
        // Quiz génériques basés sur le sujet
        $fallbackQuizzes = [
            [
                'question' => "Quel est le concept principal de $topic ?",
                'answer_a' => 'Un concept financier de base',
                'answer_b' => 'Un type d\'investissement',
                'answer_c' => 'Une stratégie de gestion',
                'answer_d' => 'Un outil de planification',
                'correct_answer' => 'answer_a',
                'difficulty' => 'easy',
                'explanation' => 'Il s\'agit du concept fondamental.',
            ],
            [
                'question' => "Pourquoi $topic est-il important dans la finance personnelle ?",
                'answer_a' => 'Pour gérer son budget',
                'answer_b' => 'Pour investissements',
                'answer_c' => 'Pour la retraite',
                'answer_d' => 'Toutes ces raisons',
                'correct_answer' => 'answer_d',
                'difficulty' => 'medium',
                'explanation' => 'Tous ces aspects sont importants.',
            ],
            [
                'question' => "Quel est le risque associé à $topic ?",
                'answer_a' => 'Aucun risque',
                'answer_b' => 'Perte potentielle',
                'answer_c' => 'Gains garantis',
                'answer_d' => 'Risque zéro',
                'correct_answer' => 'answer_b',
                'difficulty' => 'medium',
                'explanation' => 'Tout investissement comporte des risques.',
            ],
            [
                'question' => "Comment évaluer $topic ?",
                'answer_a' => 'Par les résultats',
                'answer_b' => 'Par les indicateurs',
                'answer_c' => 'Par la performance',
                'answer_d' => 'Toutes ces méthodes',
                'correct_answer' => 'answer_d',
                'difficulty' => 'hard',
                'explanation' => 'Plusieurs méthodes d\'évaluation existent.',
            ],
            [
                'question' => "Quelle est la meilleure approche pour $topic ?",
                'answer_a' => 'Approche conservative',
                'answer_b' => 'Approche équilibrée',
                'answer_c' => 'Approche agressive',
                'answer_d' => 'Dépend du profil',
                'correct_answer' => 'answer_d',
                'difficulty' => 'medium',
                'explanation' => 'Le choix dépend du profil de risque.',
            ],
        ];
        
        return array_slice($fallbackQuizzes, 0, $count);
    }

    /**
     * Teste la connexion à l'API OpenAI
     * 
     * @return array Statut de la connexion
     */
    public function testConnection(): array
    {
        try {
            $response = $this->httpClient->request('GET', 'https://api.openai.com/v1/models', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                ],
                'timeout' => 10,
            ]);
            
            if ($response->getStatusCode() === 200) {
                return [
                    'success' => true,
                    'message' => 'Connexion à OpenAI réussie!'
                ];
            }
            
            return [
                'success' => false,
                'message' => 'Erreur de connexion: Code ' . $response->getStatusCode()
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Erreur: ' . $e->getMessage()
            ];
        }
    }
}
