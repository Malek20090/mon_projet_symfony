<?php

namespace App\Controller;

use App\Entity\Cours;
use App\Entity\Quiz;
use App\Form\CoursType;
use App\Form\QuizType;
use App\Repository\CoursRepository;
use App\Repository\QuizRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin')]
class AdminController extends AbstractController
{
    // =========================
    // COURS CRUD
    // =========================

    #[Route('/cours', name: 'admin_cours_index', methods: ['GET'])]
    public function coursIndex(Request $request, CoursRepository $coursRepository): Response
    {
        $search = $request->query->get('q');
        $sortBy = $request->query->get('sort', CoursRepository::SORT_TITRE);
        $order = $request->query->get('order', 'ASC');

        $cours = $coursRepository->searchAndSort($search, $sortBy, $order);

        return $this->render('admin/cours/index.html.twig', [
            'cours' => $cours,
            'search' => $search,
            'sortBy' => $sortBy,
            'order' => $order,
        ]);
    }

    #[Route('/cours/new', name: 'admin_cours_new', methods: ['GET', 'POST'])]
    public function coursNew(Request $request, EntityManagerInterface $entityManager): Response
    {
        $cour = new Cours();
        $form = $this->createForm(CoursType::class, $cour);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($cour);
            $entityManager->flush();

            $this->addFlash('success', 'Le cours a été créé avec succès.');
            return $this->redirectToRoute('admin_cours_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin/cours/new.html.twig', [
            'cour' => $cour,
            'form' => $form,
        ]);
    }

    #[Route('/cours/{id}', name: 'admin_cours_show', methods: ['GET'])]
    public function coursShow(Cours $cour): Response
    {
        return $this->render('admin/cours/show.html.twig', [
            'cour' => $cour,
        ]);
    }

    #[Route('/cours/{id}/edit', name: 'admin_cours_edit', methods: ['GET', 'POST'])]
    public function coursEdit(Request $request, Cours $cour, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(CoursType::class, $cour);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            $this->addFlash('success', 'Le cours a été modifié avec succès.');
            return $this->redirectToRoute('admin_cours_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin/cours/edit.html.twig', [
            'cour' => $cour,
            'form' => $form,
        ]);
    }

    #[Route('/cours/{id}', name: 'admin_cours_delete', methods: ['POST'])]
    public function coursDelete(Request $request, Cours $cour, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('delete'.$cour->getId(), $request->getPayload()->getString('_token'))) {
            $entityManager->remove($cour);
            $entityManager->flush();
            $this->addFlash('success', 'Le cours a été supprimé avec succès.');
        }

        return $this->redirectToRoute('admin_cours_index', [], Response::HTTP_SEE_OTHER);
    }

    // =========================
    // QUIZ CRUD
    // =========================

    #[Route('/quiz', name: 'admin_quiz_index', methods: ['GET'])]
    public function quizIndex(Request $request, QuizRepository $quizRepository): Response
    {
        $search = $request->query->get('q');
        $sortBy = $request->query->get('sort', QuizRepository::SORT_QUESTION);
        $order = $request->query->get('order', 'ASC');

        $quizzes = $quizRepository->searchAndSort($search, $sortBy, $order);

        return $this->render('admin/quiz/index.html.twig', [
            'quizzes' => $quizzes,
            'search' => $search,
            'sortBy' => $sortBy,
            'order' => $order,
        ]);
    }

    #[Route('/quiz/new', name: 'admin_quiz_new', methods: ['GET', 'POST'])]
    public function quizNew(Request $request, EntityManagerInterface $entityManager): Response
    {
        $quiz = new Quiz();
        $form = $this->createForm(QuizType::class, $quiz);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($quiz);
            $entityManager->flush();

            $this->addFlash('success', 'Le quiz a été créé avec succès.');
            return $this->redirectToRoute('admin_quiz_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin/quiz/new.html.twig', [
            'quiz' => $quiz,
            'form' => $form,
        ]);
    }

    #[Route('/quiz/{id}', name: 'admin_quiz_show', methods: ['GET'])]
    public function quizShow(Quiz $quiz): Response
    {
        return $this->render('admin/quiz/show.html.twig', [
            'quiz' => $quiz,
        ]);
    }

    #[Route('/quiz/{id}/edit', name: 'admin_quiz_edit', methods: ['GET', 'POST'])]
    public function quizEdit(Request $request, Quiz $quiz, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(QuizType::class, $quiz);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            $this->addFlash('success', 'Le quiz a été modifié avec succès.');
            return $this->redirectToRoute('admin_quiz_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin/quiz/edit.html.twig', [
            'quiz' => $quiz,
            'form' => $form,
        ]);
    }

    #[Route('/quiz/{id}', name: 'admin_quiz_delete', methods: ['POST'])]
    public function quizDelete(Request $request, Quiz $quiz, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('delete'.$quiz->getId(), $request->getPayload()->getString('_token'))) {
            $entityManager->remove($quiz);
            $entityManager->flush();
            $this->addFlash('success', 'Le quiz a été supprimé avec succès.');
        }

        return $this->redirectToRoute('admin_quiz_index', [], Response::HTTP_SEE_OTHER);
    }

    // =========================
    // DASHBOARD
    // =========================

    #[Route('/', name: 'admin_dashboard', methods: ['GET'])]
    public function dashboard(CoursRepository $coursRepository, QuizRepository $quizRepository): Response
    {
        return $this->render('admin/dashboard.html.twig', [
            'cours_count' => count($coursRepository->findAll()),
            'quiz_count' => count($quizRepository->findAll()),
            'recent_cours' => $coursRepository->findBy([], ['id' => 'DESC'], 5),
            'recent_quizzes' => $quizRepository->findBy([], ['id' => 'DESC'], 5),
        ]);
    }
}
