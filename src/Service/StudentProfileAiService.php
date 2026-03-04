<?php

namespace App\Service;

use App\Entity\Cours;
use App\Entity\Quiz;

class StudentProfileAiService
{
    /**
     * @param Cours[] $courses
     * @param Quiz[] $quizzes
     *
     * @return array{
     *   total_courses: int,
     *   total_quizzes: int,
     *   media_breakdown: array<string, int>,
     *   avg_quiz_points: float,
     *   estimated_progress: float,
     *   recommendations: string[],
     *   ai_summary: string
     * }
     */
    public function buildInsights(array $courses, array $quizzes): array
    {
        $totalCourses = count($courses);
        $totalQuizzes = count($quizzes);

        $mediaBreakdown = [
            'VIDEO' => 0,
            'TEXT' => 0,
            'OTHER' => 0,
        ];

        foreach ($courses as $course) {
            $media = strtoupper(trim((string) $course->getTypeMedia()));
            if (str_contains($media, 'VIDEO')) {
                $mediaBreakdown['VIDEO']++;
            } elseif (str_contains($media, 'TEXT')) {
                $mediaBreakdown['TEXT']++;
            } else {
                $mediaBreakdown['OTHER']++;
            }
        }

        $sumPoints = 0.0;
        foreach ($quizzes as $quiz) {
            $sumPoints += (float) ($quiz->getPointsValeur() ?? 0);
        }
        $avgQuizPoints = $totalQuizzes > 0 ? $sumPoints / $totalQuizzes : 0.0;

        $progress = $this->estimateProgress($totalCourses, $totalQuizzes);
        $recommendations = $this->buildRecommendations($mediaBreakdown, $avgQuizPoints, $progress, $totalCourses, $totalQuizzes);
        $summary = $this->buildSummary($totalCourses, $totalQuizzes, $progress, $avgQuizPoints, $mediaBreakdown, $recommendations);

        return [
            'total_courses' => $totalCourses,
            'total_quizzes' => $totalQuizzes,
            'media_breakdown' => $mediaBreakdown,
            'avg_quiz_points' => round($avgQuizPoints, 2),
            'estimated_progress' => round($progress, 2),
            'recommendations' => $recommendations,
            'ai_summary' => $summary,
        ];
    }

    private function estimateProgress(int $totalCourses, int $totalQuizzes): float
    {
        if ($totalCourses <= 0 && $totalQuizzes <= 0) {
            return 0.0;
        }

        $coursePart = min(100.0, $totalCourses * 8.0);
        $quizPart = min(100.0, $totalQuizzes * 5.0);

        return min(100.0, ($coursePart * 0.6) + ($quizPart * 0.4));
    }

    /**
     * @param array<string, int> $mediaBreakdown
     * @return string[]
     */
    private function buildRecommendations(
        array $mediaBreakdown,
        float $avgQuizPoints,
        float $progress,
        int $totalCourses,
        int $totalQuizzes
    ): array {
        $recommendations = [];

        if ($totalCourses < 4) {
            $recommendations[] = 'Start with at least 4 core courses to build a stable learning foundation.';
        }
        if ($totalQuizzes < 3) {
            $recommendations[] = 'Practice more quizzes this week to improve retention and exam speed.';
        }
        if ($avgQuizPoints < 5) {
            $recommendations[] = 'Review course summaries before each quiz to raise your average score.';
        } else {
            $recommendations[] = 'Your quiz level is solid. Keep consistency with short daily revision sessions.';
        }

        $video = (int) ($mediaBreakdown['VIDEO'] ?? 0);
        $text = (int) ($mediaBreakdown['TEXT'] ?? 0);
        if ($video > ($text * 2)) {
            $recommendations[] = 'Balance your learning style by adding more text-based courses and note-taking.';
        } elseif ($text > ($video * 2)) {
            $recommendations[] = 'Add more video-based modules to diversify understanding and memory anchors.';
        }

        if ($progress >= 75) {
            $recommendations[] = 'Great momentum. Move to advanced quizzes and timed challenge sessions.';
        } elseif ($progress < 35) {
            $recommendations[] = 'Focus on one clear weekly plan: 2 courses + 2 quizzes minimum.';
        }

        return array_values(array_unique($recommendations));
    }

    /**
     * @param array<string, int> $mediaBreakdown
     * @param string[] $recommendations
     */
    private function buildSummary(
        int $totalCourses,
        int $totalQuizzes,
        float $progress,
        float $avgQuizPoints,
        array $mediaBreakdown,
        array $recommendations
    ): string {
        $topMedia = 'mixed';
        $max = -1;
        foreach ($mediaBreakdown as $media => $count) {
            if ($count > $max) {
                $max = $count;
                $topMedia = strtolower($media);
            }
        }

        $firstRecommendation = $recommendations[0] ?? 'Keep a regular study rhythm.';

        return sprintf(
            'Learning profile: %d courses, %d quizzes, estimated progress %.1f%%, average quiz points %.2f. Dominant content type: %s. Priority: %s',
            $totalCourses,
            $totalQuizzes,
            $progress,
            $avgQuizPoints,
            $topMedia,
            $firstRecommendation
        );
    }
}

