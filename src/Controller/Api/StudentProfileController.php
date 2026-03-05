<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Repository\CoursRepository;
use App\Repository\QuizRepository;
use App\Repository\UserRepository;
use App\Service\StudentProfileAiService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Security;

#[Route('/api/student/profile', name: 'api_student_profile_')]
class StudentProfileController extends AbstractController
{
    #[Route('/{id}/resume', name: 'resume', methods: ['GET'])]
    public function resume(
        int $id,
        Security $security,
        UserRepository $userRepository,
        CoursRepository $coursRepository,
        QuizRepository $quizRepository,
        StudentProfileAiService $studentProfileAiService
    ): JsonResponse {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $actor = $this->getUser();
        $user = $userRepository->find($id);
        if (!$user instanceof User) {
            return $this->json(['success' => false, 'message' => 'User not found.'], 404);
        }

        $isOwner = $actor instanceof User && $actor->getId() === $user->getId();
        $isAdmin = $security->isGranted('ROLE_ADMIN');
        if (!$isOwner && !$isAdmin) {
            return $this->json(['success' => false, 'message' => 'Access denied.'], 403);
        }

        $coursesCount = $coursRepository->count([]);
        $quizzesCount = $quizRepository->count([]);
        $latestCourses = $coursRepository->findBy([], ['id' => 'DESC'], 6);
        $latestQuizzes = $quizRepository->findBy([], ['id' => 'DESC'], 6);
        $insights = $studentProfileAiService->buildInsights($latestCourses, $latestQuizzes);

        return $this->json([
            'success' => true,
            'user' => [
                'id' => $user->getId(),
                'name' => $user->getNom(),
                'email' => $user->getEmail(),
            ],
            'courses_count' => $coursesCount,
            'quizzes_count' => $quizzesCount,
            'latest_courses' => array_map(
                static fn ($course): array => [
                    'id' => $course->getId(),
                    'title' => (string) ($course->getTitre() ?? ''),
                    'media_type' => (string) ($course->getTypeMedia() ?? ''),
                ],
                $latestCourses
            ),
            'latest_quizzes' => array_map(
                static fn ($quiz): array => [
                    'id' => $quiz->getId(),
                    'question' => (string) ($quiz->getQuestion() ?? ''),
                    'points' => (int) ($quiz->getPointsValeur() ?? 0),
                    'course_id' => $quiz->getCours()?->getId(),
                ],
                $latestQuizzes
            ),
            'ai_insights' => $insights,
        ]);
    }
}
