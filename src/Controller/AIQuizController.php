<?php

namespace App\Controller;

use App\Entity\Cours;
use App\Entity\Quiz;
use App\Repository\CoursRepository;
use App\Service\AIQuizGeneratorService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/ai-quiz', name: 'admin_ai_quiz_')]
class AIQuizController extends AbstractController
{
    #[Route('', name: 'index')]
    public function index(CoursRepository $coursRepository): Response
    {
        return $this->render('admin/ai-quiz/index.html.twig', [
            'cours_list' => $coursRepository->findAll(),
        ]);
    }

    #[Route('/generate/{coursId}', name: 'generate', methods: ['POST'])]
    public function generate(
        int $coursId,
        Request $request,
        AIQuizGeneratorService $aiQuizGenerator,
        EntityManagerInterface $em
    ): Response {
        $cours = $em->getRepository(Cours::class)->find($coursId);
        
        if (!$cours) {
            $this->addFlash('error', 'Cours non trouvé.');
            return $this->redirectToRoute('admin_cours_index');
        }

        $count = min(max($request->request->getInt('count', 5), 1), 20);
        $difficulty = $request->request->get('difficulty', 'medium');

        $generatedQuizzes = $aiQuizGenerator->generateQuizzesFromContent(
            $cours->getContenuTexte() ?? '',
            $cours->getTitre() ?? 'Sujet général',
            $count,
            $difficulty
        );

        $savedCount = 0;
        foreach ($generatedQuizzes as $quizData) {
            $quiz = new Quiz();
            $quiz->setQuestion($quizData['question']);
            $quiz->setChoixReponses([
                'a' => $quizData['answer_a'],
                'b' => $quizData['answer_b'],
                'c' => $quizData['answer_c'],
                'd' => $quizData['answer_d'],
            ]);
            
            $correctAnswer = $quizData['correct_answer'];
            $letter = str_replace('answer_', '', $correctAnswer);
            $quiz->setReponseCorrecte($letter);
            $quiz->setPointsValeur(10);
            $quiz->setCours($cours);
            
            $em->persist($quiz);
            $savedCount++;
        }

        $em->flush();
        $this->addFlash('success', "$savedCount quiz générés pour: " . $cours->getTitre());

        return $this->redirectToRoute('admin_quiz_index', ['cours_id' => $coursId]);
    }

    #[Route('/test', name: 'test')]
    public function testConnection(AIQuizGeneratorService $aiQuizGenerator): Response
    {
        $result = $aiQuizGenerator->testConnection();
        
        if ($result['success']) {
            $this->addFlash('success', $result['message']);
        } else {
            $this->addFlash('error', $result['message']);
        }

        return $this->redirectToRoute('admin_ai_quiz_index');
    }
}
