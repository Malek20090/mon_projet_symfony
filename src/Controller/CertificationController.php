<?php

namespace App\Controller;

use App\Service\QuizApiService;
use App\Service\StatsStorageService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ETUDIANT')]
#[Route('/certification', name: 'certification_')]
class CertificationController extends AbstractController
{
    // Liste des certifications disponibles
    private const CERTIFICATIONS = [
        'crypto' => [
            'name' => 'Certification Crypto',
            'description' => 'Testez vos connaissances sur les cryptomonnaies et la blockchain',
            'icon' => 'fa-bitcoin',
            'color' => 'warning',
            'questions' => 10,
            'passing_score' => 70,
        ],
        'finance' => [
            'name' => 'Certification Finance',
            'description' => 'Testez vos connaissances en finance personnelle et gestion d\'argent',
            'icon' => 'fa-coins',
            'color' => 'success',
            'questions' => 10,
            'passing_score' => 70,
        ],
        'investissement' => [
            'name' => 'Certification Investissement',
            'description' => 'Testez vos connaissances sur les investissements et la gestion de patrimoine',
            'icon' => 'fa-chart-line',
            'color' => 'info',
            'questions' => 10,
            'passing_score' => 70,
        ],
    ];

    #[Route('', name: 'index')]
    public function index(): Response
    {
        return $this->render('certification/index.html.twig', [
            'certifications' => self::CERTIFICATIONS,
        ]);
    }

    #[Route('/{type}', name: 'start')]
    public function start(
        string $type,
        Request $request,
        QuizApiService $quizApiService,
        SessionInterface $session
    ): Response {
        if (!isset(self::CERTIFICATIONS[$type])) {
            throw $this->createNotFoundException('Certification non trouvée');
        }

        $certification = self::CERTIFICATIONS[$type];
        
        // Récupérer les quiz selon le type de certification
        switch ($type) {
            case 'crypto':
                $quizzes = $quizApiService->getCryptoQuizzes($certification['questions']);
                break;
            case 'investissement':
                $quizzes = $quizApiService->getInvestissementQuizzes($certification['questions']);
                break;
            case 'finance':
            default:
                $quizzes = $quizApiService->getFinanceQuizzes($certification['questions']);
                break;
        }

        // Stocker les quiz en session pour le suivi
        $session->set('certification_' . $type, [
            'quizzes' => $quizzes,
            'current_index' => 0,
            'score' => 0,
            'total_questions' => count($quizzes),
            'type' => $type,
            'answers' => [],
            'user_name' => $this->getUser()->getNom() ?? $this->getUser()->getEmail(),
            'user_email' => $this->getUser()->getEmail(),
        ]);

        return $this->render('certification/quiz.html.twig', [
            'certification' => $certification,
            'quizzes' => $quizzes,
            'current_index' => 0,
            'type' => $type,
        ]);
    }

    #[Route('/{type}/answer/{answer}', name: 'answer')]
    public function answer(
        string $type,
        string $answer,
        SessionInterface $session
    ): Response {
        if (!isset(self::CERTIFICATIONS[$type])) {
            throw $this->createNotFoundException('Certification non trouvée');
        }

        $certData = $session->get('certification_' . $type);
        
        if (!$certData) {
            return $this->redirectToRoute('certification_start', ['type' => $type]);
        }

        $currentIndex = $certData['current_index'];
        $quizzes = $certData['quizzes'];
        
        // Vérifier si on a terminé
        if ($currentIndex >= count($quizzes)) {
            return $this->redirectToRoute('certification_result', ['type' => $type]);
        }

        $currentQuiz = $quizzes[$currentIndex];
        
        // Vérifier la réponse
        $isCorrect = false;
        if ($answer && isset($currentQuiz['correct_answer'])) {
            $isCorrect = ($answer === $currentQuiz['correct_answer']);
            
            // Mettre à jour le score
            $certData['score'] += $isCorrect ? 1 : 0;
            
            // Stocker le texte de la réponse de l'utilisateur
            $userAnswerText = $currentQuiz['answers'][$answer] ?? $answer;
            // Stocker le texte de la réponse correcte
            $correctAnswerText = $currentQuiz['answers'][$currentQuiz['correct_answer']] ?? $currentQuiz['correct_answer'];
            
            $certData['answers'][$currentIndex] = [
                'user_answer' => $userAnswerText,
                'correct' => $isCorrect,
                'correct_answer' => $correctAnswerText,
            ];
        }

        // Passer à la question suivante
        $certData['current_index'] = $currentIndex + 1;
        $session->set('certification_' . $type, $certData);

        // Si c'était la dernière question, rediriger vers le résultat
        if ($certData['current_index'] >= count($quizzes)) {
            return $this->redirectToRoute('certification_result', ['type' => $type]);
        }

        // Afficher la question suivante
        $certification = self::CERTIFICATIONS[$type];
        
        return $this->render('certification/quiz.html.twig', [
            'certification' => $certification,
            'quizzes' => $quizzes,
            'current_index' => $certData['current_index'],
            'type' => $type,
            'previous_correct' => $isCorrect,
        ]);
    }

    #[Route('/{type}/result', name: 'result')]
    public function result(
        string $type,
        SessionInterface $session,
        StatsStorageService $statsStorage
    ): Response {
        if (!isset(self::CERTIFICATIONS[$type])) {
            throw $this->createNotFoundException('Certification non trouvée');
        }

        $certData = $session->get('certification_' . $type);
        
        if (!$certData) {
            return $this->redirectToRoute('certification_index');
        }

        $certification = self::CERTIFICATIONS[$type];
        
        // Calculer le score
        $score = $certData['score'];
        $total = $certData['total_questions'];
        $percentage = $total > 0 ? round(($score / $total) * 100) : 0;
        $passed = $percentage >= $certification['passing_score'];

        // Sauvegarder le résultat dans le fichier JSON
        $resultData = [
            'date' => (new \DateTime())->format('Y-m-d H:i:s'),
            'type' => $type,
            'certification_name' => $certification['name'],
            'score' => $score,
            'total' => $total,
            'percentage' => $percentage,
            'passed' => $passed,
            'user_name' => $certData['user_name'] ?? 'Unknown',
            'user_email' => $certData['user_email'] ?? 'Unknown',
        ];
        
        $statsStorage->addCertificationResult($resultData);

        // Stocker le résultat en session pour affichage
        $session->set('certification_result_' . $type, [
            'score' => $score,
            'total' => $total,
            'percentage' => $percentage,
            'passed' => $passed,
            'date' => new \DateTime(),
        ]);

        return $this->render('certification/result.html.twig', [
            'certification' => $certification,
            'score' => $score,
            'total' => $total,
            'percentage' => $percentage,
            'passed' => $passed,
            'type' => $type,
            'answers' => $certData['answers'] ?? [],
        ]);
    }

    #[Route('/{type}/certificate', name: 'certificate')]
    public function certificate(
        string $type,
        SessionInterface $session
    ): Response {
        if (!isset(self::CERTIFICATIONS[$type])) {
            throw $this->createNotFoundException('Certification non trouvée');
        }

        $result = $session->get('certification_result_' . $type);
        
        if (!$result || !$result['passed']) {
            return $this->redirectToRoute('certification_index');
        }

        $certification = self::CERTIFICATIONS[$type];
        $user = $this->getUser();

        return $this->render('certification/certificate.html.twig', [
            'certification' => $certification,
            'user' => $user,
            'score' => $result['score'],
            'total' => $result['total'],
            'percentage' => $result['percentage'],
            'date' => $result['date'],
            'type' => $type,
        ]);
    }
}
