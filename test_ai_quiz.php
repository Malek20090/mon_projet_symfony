<?php

require __DIR__ . '/vendor/autoload.php';

use App\Service\AIQuizGeneratorService;

$httpClient = new \Symfony\Component\HttpClient\CurlHttpClient();

$service = new AIQuizGeneratorService($httpClient);

echo "🔄 Test de génération de quiz IA...\n\n";

// Contenu de test
$content = "La finance personnelle est la gestion des ressources financières d'un individu. 
Elle comprend la budgétisation, l'épargne, l'investissement et la gestion des dettes.
Les objectifs principaux sont de Build un fonds d'urgence, préparer la retraite et atteindre ses rêves financiers.";

$topic = "Finance personnelle";

echo "📚 Sujet: $topic\n";
echo "📝 Contenu: " . substr($content, 0, 100) . "...\n\n";

try {
    $quizzes = $service->generateQuizzesFromContent($content, $topic, 3, 'easy');
    
    echo "✅ Quiz générés: " . count($quizzes) . "\n\n";
    
    foreach ($quizzes as $i => $quiz) {
        echo "--- Question " . ($i + 1) . " ---\n";
        echo "Q: " . $quiz['question'] . "\n";
        echo "A: " . $quiz['answer_a'] . "\n";
        echo "B: " . $quiz['answer_b'] . "\n";
        echo "C: " . $quiz['answer_c'] . "\n";
        echo "D: " . $quiz['answer_d'] . "\n";
        echo "✅ Réponse correcte: " . $quiz['correct_answer'] . "\n";
        if (!empty($quiz['explanation'])) {
            echo "💡 Explication: " . $quiz['explanation'] . "\n";
        }
        echo "\n";
    }
    
    echo "🎉 Test réussi!\n";
    
} catch (\Exception $e) {
    echo "❌ Erreur: " . $e->getMessage() . "\n";
}
