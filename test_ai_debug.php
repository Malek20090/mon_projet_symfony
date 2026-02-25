<?php

require __DIR__ . '/vendor/autoload.php';

use App\Service\AIQuizGeneratorService;

$httpClient = new \Symfony\Component\HttpClient\CurlHttpClient();

$service = new AIQuizGeneratorService($httpClient);

echo "Test avec contenu detaille...\n\n";

$content = "La budjetisation est le processus de creation d'un plan pour gerer votre argent.
Elle implique de suivre vos revenus et vos depenses.
Les etapes principales sont: 1) Calculer vos revenus mensuels, 2) Lister vos depenses fixes, 
3) Identifier vos depenses variables, 4) Definir des objectifs d'epargne.";

$topic = "Budjetisation et gestion du budget";

echo "Sujet: $topic\n\n";

try {
    $prompt = $service->buildPrompt($content, $topic, 3, 'easy');
    
    $apiKey = $_ENV['OPENAI_API_KEY'] ?? getenv('OPENAI_API_KEY') ?: '';
    
    $response = $httpClient->request('POST', 'https://api.openai.com/v1/chat/completions', [
        'headers' => [
            'Authorization' => 'Bearer ' . $apiKey,
            'Content-Type' => 'application/json',
        ],
        'json' => [
            'model' => 'gpt-4o-mini',
            'messages' => [
                ['role' => 'system', 'content' => 'Tu generes des quiz educatifs en francais.'],
                ['role' => 'user', 'content' => $prompt]
            ],
            'temperature' => 0.7,
            'max_tokens' => 2000,
        ],
        'timeout' => 60,
    ]);
    
    $data = $response->toArray();
    $aiResponse = $data['choices'][0]['message']['content'];
    
    echo "Reponse brute de l'IA:\n";
    echo "---DEBUT---\n";
    echo $aiResponse;
    echo "\n---FIN---\n\n";
    
    $quizzes = $service->generateQuizzesFromContent($content, $topic, 3, 'easy');
    
    echo "Quiz generes: " . count($quizzes) . "\n\n";
    
    foreach ($quizzes as $i => $quiz) {
        echo "Question " . ($i + 1) . ":\n";
        echo "Q: " . $quiz['question'] . "\n";
        echo "A: " . $quiz['answer_a'] . "\n";
        echo "B: " . $quiz['answer_b'] . "\n";
        echo "C: " . $quiz['answer_c'] . "\n";
        echo "D: " . $quiz['answer_d'] . "\n";
        echo "Correcte: " . $quiz['correct_answer'] . "\n\n";
    }
    
} catch (\Exception $e) {
    echo "Erreur: " . $e->getMessage() . "\n";
}
