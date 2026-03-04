<?php

namespace App\Controller;

use App\Entity\Cours;
use App\Entity\Quiz;
use App\Entity\User;
use App\Entity\QuizResult;
use App\Form\SalaryProfileType;
use App\Service\CoursPdfService;
use App\Service\StatsStorageService;
use App\Repository\CoursRepository;
use App\Repository\QuizRepository;
use App\Service\StudentProfileAiService;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
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
    public function coursIndex(Request $request, CoursRepository $coursRepository, PaginatorInterface $paginator): Response
    {
        $search = $request->query->get('q');
        $typeMedia = $request->query->get('typeMedia');
        $sortBy = $request->query->get('sort', CoursRepository::SORT_TITRE);
        $order = $request->query->get('order', 'ASC');

        $allCours = $coursRepository->searchAndSort($search, $typeMedia, $sortBy, $order);

        // Pagination with KnpPaginator
        $cours = $paginator->paginate(
            $allCours,
            $request->query->getInt('page', 1),
            9 // Items per page (3x3 grid)
        );

        return $this->render('student/cours/index.html.twig', [
            'cours' => $cours,
            'search' => $search,
            'typeMedia' => $typeMedia,
            'sortBy' => $sortBy,
            'order' => $order,
        ]);
    }

    #[Route('/cours/{id}', name: 'cours_show', methods: ['GET'])]
    public function coursShow(Cours $cour, SessionInterface $session): Response
    {
        // Get quiz results from session
        $quizResults = $session->get('quiz_results', []);
        
        return $this->render('student/cours/show.html.twig', [
            'cour' => $cour,
            'quizResults' => $quizResults,
        ]);
    }

    #[Route('/cours/{id}/export-pdf', name: 'cours_export_pdf', methods: ['GET'])]
    public function coursExportPdf(Cours $cour, CoursPdfService $coursPdfService): Response
    {
        $pdfContent = $coursPdfService->renderCoursPdf($cour);
        
        $filename = preg_replace('/[^a-zA-Z0-9_-]/', '_', $cour->getTitre()) . '.pdf';
        
        return new Response($pdfContent, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    // =========================
    // QUIZ - Passer les quiz
    // =========================

    #[Route('/quiz', name: 'quiz_index', methods: ['GET'])]
    public function quizIndex(
        Request $request,
        QuizRepository $quizRepository,
        CoursRepository $coursRepository,
        PaginatorInterface $paginator
    ): Response
    {
        $search = $request->query->get('q');
        $sortBy = $request->query->get('sort', QuizRepository::SORT_QUESTION);
        $order = $request->query->get('order', 'ASC');

        $allQuizzes = $quizRepository->searchAndSort($search, null, null, null, $sortBy, $order);
        
        // Pagination with KnpPaginator
        $quizzes = $paginator->paginate(
            $allQuizzes,
            $request->query->getInt('page', 1),
            9 // Items per page
        );

        $recommendedCourses = array_slice($this->mergeUniqueById(
            $coursRepository->searchAndSort('finance'),
            $coursRepository->searchAndSort('investissement'),
            $coursRepository->searchAndSort('epargne')
        ), 0, 4);
        $recommendedQuizzes = array_slice($this->mergeUniqueById(
            $quizRepository->searchAndSort('finance'),
            $quizRepository->searchAndSort('investissement'),
            $quizRepository->searchAndSort('epargne')
        ), 0, 4);

        return $this->render('student/quiz/index.html.twig', [
            'quizzes' => $quizzes,
            'search' => $search,
            'sortBy' => $sortBy,
            'order' => $order,
            'recommendedCourses' => $recommendedCourses,
            'recommendedQuizzes' => $recommendedQuizzes,
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
    public function quizPass(
        Request $request, 
        Quiz $quiz, 
        SessionInterface $session, 
        EntityManagerInterface $em,
        StatsStorageService $statsStorage
    ): Response
    {
        $score = null;
        $reponseUtilisateur = null;
        $isCorrect = false;
        $timeOut = false;

        if ($request->isMethod('POST')) {
            $reponseUtilisateur = $request->request->get('reponse');
            
            // Check if it's a timeout (no answer provided)
            if ($reponseUtilisateur === null || $reponseUtilisateur === '') {
                $timeOut = true;
                $isCorrect = false;
                $score = 0;
            } else {
                // Trim whitespace and compare case-insensitively
                $reponseUtilisateur = trim($reponseUtilisateur);
                $reponseCorrecte = trim($quiz->getReponseCorrecte());
                
                $isCorrect = (strcasecmp($reponseUtilisateur, $reponseCorrecte) === 0);
                $score = $isCorrect ? $quiz->getPointsValeur() : 0;
            }
            
            // Save result in session
            $quizResults = $session->get('quiz_results', []);
            $quizResults[$quiz->getId()] = [
                'score' => $score,
                'maxScore' => $quiz->getPointsValeur(),
                'isCorrect' => $isCorrect,
                'timeOut' => $timeOut,
                'date' => new \DateTime(),
            ];
            $session->set('quiz_results', $quizResults);
            
            // Get user info
            $user = $this->getUser();
            $userName = $user instanceof User ? ($user->getNom() ?? $user->getEmail()) : 'Unknown';
            $userEmail = $user instanceof User ? $user->getEmail() : 'Unknown';
            
            $percentage = $quiz->getPointsValeur() > 0 ? round(($score / $quiz->getPointsValeur()) * 100) : 0;
            
            // Save result to database
            $quizResult = new QuizResult();
            $quizResult->setUserName($userName);
            $quizResult->setUserEmail($userEmail);
            $quizResult->setCours($quiz->getCours());
            $quizResult->setQuiz($quiz);
            $quizResult->setScore($score);
            $quizResult->setTotal($quiz->getPointsValeur());
            $quizResult->setPercentage($percentage);
            $quizResult->setPassed($isCorrect);
            $quizResult->markDate(new \DateTime());
            
            $em->persist($quizResult);
            $em->flush();
            
            // Also save to JSON for backward compatibility
            $quizResultData = [
                'date' => (new \DateTime())->format('Y-m-d H:i:s'),
                'user_name' => $userName,
                'user_email' => $userEmail,
                'quiz_id' => $quiz->getId(),
                'quiz_title' => $quiz->getQuestion(),
                'cours_id' => $quiz->getCours() ? $quiz->getCours()->getId() : null,
                'cours_title' => $quiz->getCours() ? $quiz->getCours()->getTitre() : null,
                'score' => $score,
                'total' => $quiz->getPointsValeur(),
                'percentage' => $percentage,
                'passed' => $isCorrect,
            ];
            
            $statsStorage->addQuizResult($quizResultData);
        }

        // Get results from session for this quiz
        $quizResults = $session->get('quiz_results', []);
        $previousResult = $quizResults[$quiz->getId()] ?? null;

        $response = $this->render('student/quiz/passer.html.twig', [
            'quiz' => $quiz,
            'reponseUtilisateur' => $reponseUtilisateur,
            'isCorrect' => $isCorrect,
            'score' => $score,
            'previousResult' => $previousResult,
        ]);
        
        // Prevent caching to ensure timer starts immediately
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');
        
        return $response;
    }

    #[Route('/cours/{id}/quiz', name: 'cours_quiz', methods: ['GET'])]
    public function coursQuiz(
        Cours $cour,
        QuizRepository $quizRepository,
        CoursRepository $coursRepository
    ): Response
    {
        $recommendedCourses = array_slice($this->mergeUniqueById(
            $coursRepository->searchAndSort('finance'),
            $coursRepository->searchAndSort('investissement'),
            $coursRepository->searchAndSort('epargne')
        ), 0, 4);
        $recommendedQuizzes = array_slice($this->mergeUniqueById(
            $quizRepository->searchAndSort('finance'),
            $quizRepository->searchAndSort('investissement'),
            $quizRepository->searchAndSort('epargne')
        ), 0, 4);

        return $this->render('student/quiz/cours_quiz.html.twig', [
            'cour' => $cour,
            'quizzes' => $cour->getQuizzes(),
            'recommendedCourses' => $recommendedCourses,
            'recommendedQuizzes' => $recommendedQuizzes,
        ]);
    }

    #[Route('/profile', name: 'profile', methods: ['GET', 'POST'])]
    public function profile(
        Request $request,
        EntityManagerInterface $em,
        SluggerInterface $slugger,
        UserPasswordHasherInterface $passwordHasher,
        CoursRepository $coursRepository,
        QuizRepository $quizRepository,
        StudentProfileAiService $studentProfileAiService
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

        $courses = $coursRepository->findBy([], ['id' => 'DESC']);
        $quizzes = $quizRepository->findBy([], ['id' => 'DESC']);
        $aiInsights = $studentProfileAiService->buildInsights($courses, $quizzes);

        return $this->render('student/profile.html.twig', [
            'user' => $user,
            'form' => $form->createView(),
            'coursesCount' => count($courses),
            'quizzesCount' => count($quizzes),
            'latestCourses' => array_slice($courses, 0, 6),
            'latestQuizzes' => array_slice($quizzes, 0, 6),
            'aiInsights' => $aiInsights,
        ]);
    }

    private function mergeUniqueById(array ...$lists): array
    {
        $seen = [];
        $merged = [];

        foreach ($lists as $list) {
            foreach ($list as $item) {
                if (!method_exists($item, 'getId')) {
                    continue;
                }

                $id = $item->getId();
                if ($id === null || isset($seen[$id])) {
                    continue;
                }

                $seen[$id] = true;
                $merged[] = $item;
            }
        }

        return $merged;
    }
}
