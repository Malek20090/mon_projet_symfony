<?php

namespace App\Controller;

use App\Entity\Cours;
use App\Entity\Quiz;
use App\Entity\User;
use App\Form\SalaryProfileType;
use App\Repository\CoursRepository;
use App\Repository\QuizRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\SluggerInterface;

#[IsGranted('ROLE_ETUDIANT')]
#[Route('/student', name: 'student_')]
class StudentController extends AbstractController
{
    // =========================
    // COURS - Consultation
    // =========================

    #[Route('/cours', name: 'cours_index', methods: ['GET'])]
    public function coursIndex(Request $request, CoursRepository $coursRepository): Response
    {
        $search = $request->query->get('q');
        $typeMedia = $request->query->get('typeMedia');
        $sortBy = $request->query->get('sort', CoursRepository::SORT_TITRE);
        $order = $request->query->get('order', 'ASC');

        $cours = $coursRepository->searchAndSort($search, $typeMedia, $sortBy, $order);

        return $this->render('student/cours/index.html.twig', [
            'cours' => $cours,
            'search' => $search,
            'typeMedia' => $typeMedia,
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
        $search = $request->query->get('q');
        $sortBy = $request->query->get('sort', QuizRepository::SORT_QUESTION);
        $order = $request->query->get('order', 'ASC');

        $quizzes = $quizRepository->searchAndSort($search, null, null, null, $sortBy, $order);

        return $this->render('student/quiz/index.html.twig', [
            'quizzes' => $quizzes,
            'search' => $search,
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

    #[Route('/profile', name: 'profile', methods: ['GET', 'POST'])]
    public function profile(
        Request $request,
        EntityManagerInterface $em,
        SluggerInterface $slugger,
        UserPasswordHasherInterface $passwordHasher
    ): Response {
        /** @var User|null $user */
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('User session is required.');
        }

        $form = $this->createForm(SalaryProfileType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $plainPassword = (string) $form->get('plainPassword')->getData();
            if (trim($plainPassword) !== '') {
                $user->setPassword($passwordHasher->hashPassword($user, $plainPassword));
            }

            /** @var UploadedFile|null $imageFile */
            $imageFile = $form->get('image')->getData();
            if ($imageFile) {
                $safeFilename = $slugger->slug(pathinfo($imageFile->getClientOriginalName(), PATHINFO_FILENAME));
                $newFilename = $safeFilename . '-' . uniqid() . '.' . $imageFile->guessExtension();

                $imageFile->move(
                    $this->getParameter('user_images_directory'),
                    $newFilename
                );

                $user->setImage($newFilename);
            }

            $em->flush();
            $this->addFlash('success', 'Profile updated successfully.');

            return $this->redirectToRoute('student_profile');
        }

        return $this->render('student/profile.html.twig', [
            'user' => $user,
            'form' => $form->createView(),
        ]);
    }
}
