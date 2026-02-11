<?php

namespace App\Controller;

use App\Entity\Cours;
use App\Entity\Quiz;
use App\Repository\CoursRepository;
use App\Repository\QuizRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/student', name: 'student_')]
class StudentController extends AbstractController
{
    // =========================
    // COURS - Consultation
    // =========================

    #[Route('/cours', name: 'cours_index', methods: ['GET'])]
    public function coursIndex(Request $request, CoursRepository $coursRepository): Response
    {
        $sortBy = $request->query->get('sort', CoursRepository::SORT_TITRE);
        $order = $request->query->get('order', 'ASC');

        $cours = $coursRepository->searchAndSort(null, $sortBy, $order);

        return $this->render('student/cours/index.html.twig', [
            'cours' => $cours,
            'sortBy' => $sortBy,
            'order' => $order,
        ]);
    }

    #[Route('/cours/{id}', name: 'cours_show', methods: ['GET'])]
    public function coursShow(Cours $cour): Response
    {
        return $this->render('student/cours/show.html.twig', [
            'cour' => $cour,
        ]);
    }

    // =========================
    // QUIZ - Passer les quiz
    // =========================

    #[Route('/quiz', name: 'quiz_index', methods: ['GET'])]
    public function quizIndex(Request $request, QuizRepository $quizRepository): Response
    {
        $sortBy = $request->query->get('sort', QuizRepository::SORT_QUESTION);
        $order = $request->query->get('order', 'ASC');

        $quizzes = $quizRepository->searchAndSort(null, $sortBy, $order);

        return $this->render('student/quiz/index.html.twig', [
            'quizzes' => $quizzes,
            'sortBy' => $sortBy,
            'order' => $order,
        ]);
    }

    #[Route('/quiz/{id}', name: 'quiz_show', methods: ['GET'])]
    public function quizShow(Quiz $quiz): Response
    {
        return $this->render('student/quiz/show.html.twig', [
            'quiz' => $quiz,
        ]);
    }

    #[Route('/quiz/{id}/passer', name: 'quiz_pass', methods: ['GET', 'POST'])]
    public function quizPass(Request $request, Quiz $quiz): Response
    {
        $score = null;
        $reponseUtilisateur = null;
        $isCorrect = false;

        if ($request->isMethod('POST')) {
            $reponseUtilisateur = $request->request->get('reponse');
            
            if ($reponseUtilisateur !== null) {
                $isCorrect = ($reponseUtilisateur === $quiz->getReponseCorrecte());
                $score = $isCorrect ? $quiz->getPointsValeur() : 0;
            }
        }

        return $this->render('student/quiz/passer.html.twig', [
            'quiz' => $quiz,
            'reponseUtilisateur' => $reponseUtilisateur,
            'isCorrect' => $isCorrect,
            'score' => $score,
        ]);
    }

    #[Route('/cours/{id}/quiz', name: 'cours_quiz', methods: ['GET'])]
    public function coursQuiz(Cours $cour): Response
    {
        return $this->render('student/quiz/cours_quiz.html.twig', [
            'cour' => $cour,
            'quizzes' => $cour->getQuizzes(),
        ]);
    }
}
