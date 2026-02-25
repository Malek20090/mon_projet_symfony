<?php

namespace App\Service;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\RequestStack;

class StatsStorageService
{
    private string $storagePath;
    private Filesystem $filesystem;
    
    public function __construct(RequestStack $requestStack)
    {
        $this->filesystem = new Filesystem();
        $this->storagePath = dirname(__DIR__, 2) . '/var/stats_data.json';
        
        // Ensure the file exists
        if (!$this->filesystem->exists($this->storagePath)) {
            $this->filesystem->dumpFile($this->storagePath, json_encode([
                'certifications' => [],
                'quizzes' => []
            ]));
        }
    }
    
    private function readData(): array
    {
        $content = file_get_contents($this->storagePath);
        return json_decode($content, true) ?? ['certifications' => [], 'quizzes' => []];
    }
    
    private function writeData(array $data): void
    {
        $this->filesystem->dumpFile($this->storagePath, json_encode($data, JSON_PRETTY_PRINT));
    }
    
    // Certification methods
    public function addCertificationResult(array $result): void
    {
        $data = $this->readData();
        $data['certifications'][] = $result;
        $this->writeData($data);
    }
    
    public function getCertificationResults(): array
    {
        $data = $this->readData();
        return $data['certifications'] ?? [];
    }
    
    public function getCertificationStats(): array
    {
        $results = $this->getCertificationResults();
        $total = count($results);
        $passed = count(array_filter($results, fn($r) => $r['passed'] ?? false));
        
        $averageScore = 0;
        if ($total > 0) {
            $totalScore = array_sum(array_map(fn($r) => $r['percentage'] ?? 0, $results));
            $averageScore = round($totalScore / $total, 1);
        }
        
        return [
            'total' => $total,
            'passed' => $passed,
            'failed' => $total - $passed,
            'averageScore' => $averageScore,
        ];
    }
    
    // Quiz methods
    public function addQuizResult(array $result): void
    {
        $data = $this->readData();
        $data['quizzes'][] = $result;
        $this->writeData($data);
    }
    
    public function getQuizResults(): array
    {
        $data = $this->readData();
        return $data['quizzes'] ?? [];
    }
    
    public function getQuizStats(): array
    {
        $results = $this->getQuizResults();
        $total = count($results);
        $passed = count(array_filter($results, fn($r) => $r['passed'] ?? false));
        
        $averageScore = 0;
        if ($total > 0) {
            $totalScore = array_sum(array_map(fn($r) => $r['percentage'] ?? 0, $results));
            $averageScore = round($totalScore / $total, 1);
        }
        
        return [
            'total' => $total,
            'passed' => $passed,
            'failed' => $total - $passed,
            'averageScore' => $averageScore,
        ];
    }
}
