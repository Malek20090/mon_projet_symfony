<?php

namespace App\Controller;

use App\Repository\QuizRepository;
use App\Repository\CoursRepository;
use App\Service\QuizApiService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ETUDIANT')]
#[Route('/game', name: 'game_')]
class GameController extends AbstractController
{
    #[Route('', name: 'index')]
    public function index(): Response
    {
        return $this->render('game/index.html.twig');
    }

    #[Route('/arcade', name: 'arcade')]
    public function arcade(
        QuizRepository $quizRepository,
        SessionInterface $session
    ): Response {
        $quizzes = $quizRepository->findAll();
        
        $gameData = [];
        
        // If no quizzes in database, use default financial questions
        if (empty($quizzes)) {
            $gameData = $this->getDefaultQuizzes();
        } else {
            foreach ($quizzes as $quiz) {
                $choixReponses = $quiz->getChoixReponses();
                if (is_array($choixReponses) && !empty($choixReponses)) {
                    $gameData[] = [
                        'id' => $quiz->getId(),
                        'question' => $quiz->getQuestion(),
                        'answers' => $choixReponses,
                        'correct' => $quiz->getReponseCorrecte(),
                        'points' => $quiz->getPointsValeur() ?? 10,
                    ];
                }
            }
            
            // If still empty after database check, use defaults
            if (empty($gameData)) {
                $gameData = $this->getDefaultQuizzes();
            }
        }

        $session->set('arcade_quiz_data', $gameData);
        $session->set('arcade_score', 0);
        $session->set('arcade_combo', 0);
        $session->set('arcade_question_index', 0);

        return $this->render('game/arcade.html.twig', [
            'total_questions' => count($gameData),
            'game_data_json' => json_encode($gameData, JSON_UNESCAPED_UNICODE),
        ]);
    }

    #[Route('/arcade/data', name: 'arcade_data')]
    public function getArcadeData(SessionInterface $session): Response
    {
        $gameData = $session->get('arcade_quiz_data', []);
        return $this->json($gameData);
    }

    #[Route('/wordsearch', name: 'wordsearch')]
    public function wordsearch(SessionInterface $session): Response
    {
        $words = ['EPARGNE', 'BUDGET', 'INVESTISSEMENT', 'BOURSE', 'ACTION', 'OBLIGATION', 'CRYPTO', 'BITCOIN', 'DIVIDENDE', 'RENDEMENT', 'RISQUE', 'LIQUIDITE', 'CREDIT', 'EMPRUNT', 'TAUX', 'ASSURANCE'];
        shuffle($words);
        $selectedWords = array_slice($words, 0, 8);
        $gridSize = 12;
        $grid = $this->generateWordSearchGrid($gridSize, $selectedWords);

        $session->set('wordsearch_found', []);
        $session->set('wordsearch_words', $selectedWords);
        $session->set('wordsearch_score', 0);

        return $this->render('game/wordsearch.html.twig', [
            'grid' => $grid,
            'words' => $selectedWords,
            'grid_size' => $gridSize,
        ]);
    }

    #[Route('/crossword', name: 'crossword')]
    public function crossword(SessionInterface $session): Response
    {
        $crosswordData = [
            'horizontal' => [
                ['word' => 'EPARGNE', 'clue' => 'Argent mis de côté pour le futur'],
                ['word' => 'TAUX', 'clue' => 'Pourcentage appliqué à un crédit'],
                ['word' => 'BOURSE', 'clue' => 'Lieu où s\'achètent les actions'],
            ],
            'vertical' => [
                ['word' => 'BUDGET', 'clue' => 'Plan de gestion des revenus'],
                ['word' => 'RISQUE', 'clue' => 'Possibilité de perte financière'],
                ['word' => 'CREDIT', 'clue' => ' Somme d\'argent empruntée'],
            ]
        ];

        return $this->render('game/crossword.html.twig', [
            'crossword' => $crosswordData,
        ]);
    }

    #[Route('/save-score', name: 'save_score', methods: ['POST'])]
    public function saveScore(Request $request, SessionInterface $session): Response
    {
        $gameType = $request->request->get('game_type');
        $score = $request->request->getInt('score', 0);
        
        $scores = $session->get('game_scores', []);
        $scores[$gameType] = max($scores[$gameType] ?? 0, $score);
        $session->set('game_scores', $scores);

        return $this->json(['success' => true, 'high_score' => $scores[$gameType]]);
    }

    private function generateWordSearchGrid(int $size, array $words): array
    {
        $grid = array_fill(0, $size, array_fill(0, $size, ''));

        foreach ($words as $word) {
            $placed = false;
            $attempts = 0;
            
            while (!$placed && $attempts < 100) {
                $direction = rand(0, 3);
                $row = rand(0, $size - 1);
                $col = rand(0, $size - 1);
                
                if ($this->canPlaceWord($grid, $word, $row, $col, $direction, $size)) {
                    $this->placeWord($grid, $word, $row, $col, $direction);
                    $placed = true;
                }
                $attempts++;
            }
        }

        $letters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        for ($i = 0; $i < $size; $i++) {
            for ($j = 0; $j < $size; $j++) {
                if ($grid[$i][$j] === '') {
                    $grid[$i][$j] = $letters[rand(0, strlen($letters) - 1)];
                }
            }
        }

        return $grid;
    }

    private function canPlaceWord(array $grid, string $word, int $row, int $col, int $dir, int $size): bool
    {
        $len = strlen($word);
        $directions = [[0, 1], [1, 0], [1, 1], [-1, 1]];
        $dRow = $directions[$dir][0];
        $dCol = $directions[$dir][1];

        $endRow = $row + ($len - 1) * $dRow;
        $endCol = $col + ($len - 1) * $dCol;

        if ($endRow < 0 || $endRow >= $size || $endCol < 0 || $endCol >= $size) {
            return false;
        }

        for ($i = 0; $i < $len; $i++) {
            $r = $row + $i * $dRow;
            $c = $col + $i * $dCol;
            if ($grid[$r][$c] !== '' && $grid[$r][$c] !== $word[$i]) {
                return false;
            }
        }

        return true;
    }

    private function placeWord(array &$grid, string $word, int $row, int $col, int $dir): void
    {
        $len = strlen($word);
        $directions = [[0, 1], [1, 0], [1, 1], [-1, 1]];
        $dRow = $directions[$dir][0];
        $dCol = $directions[$dir][1];

        for ($i = 0; $i < $len; $i++) {
            $grid[$row + $i * $dRow][$col + $i * $dCol] = $word[$i];
        }
    }

    private function getDefaultQuizzes(): array
    {
        return [
            [
                'id' => 1,
                'question' => 'Qu\'est-ce que l\'épargne?',
                'answers' => [
                    'a' => 'Dépenser tout son argent',
                    'b' => 'Mettre de côté une partie de ses revenus',
                    'c' => 'Investir en bourse',
                    'd' => 'Faire des prêts',
                ],
                'correct' => 'b',
                'points' => 100,
            ],
            [
                'id' => 2,
                'question' => 'Qu\'est-ce qu\'un budget?',
                'answers' => [
                    'a' => 'Un compte bancaire',
                    'b' => 'Un plan de dépenses et de revenus',
                    'c' => 'Un prêt',
                    'd' => 'Un investissement',
                ],
                'correct' => 'b',
                'points' => 100,
            ],
            [
                'id' => 3,
                'question' => 'Qu\'est-ce que la diversification?',
                'answers' => [
                    'a' => 'Mettre tout son argent dans un seul placement',
                    'b' => 'Répartir ses investissements sur différents actifs',
                    'c' => 'Garder tout son argent en banque',
                    'd' => 'N\'investir que dans l\'immobilier',
                ],
                'correct' => 'b',
                'points' => 100,
            ],
            [
                'id' => 4,
                'question' => 'Qu\'est-ce qu\'une action en bourse?',
                'answers' => [
                    'a' => 'Un prêt bancaire',
                    'b' => 'Une partie du capital d\'une entreprise',
                    'c' => 'Un compte d\'épargne',
                    'd' => 'Une obligation',
                ],
                'correct' => 'b',
                'points' => 100,
            ],
            [
                'id' => 5,
                'question' => 'Qu\'est-ce que le Bitcoin?',
                'answers' => [
                    'a' => 'Une devise traditionnelle',
                    'b' => 'Une cryptomonnaie décentralisée',
                    'c' => 'Une action technologique',
                    'd' => 'Un compte bancaire',
                ],
                'correct' => 'b',
                'points' => 100,
            ],
            [
                'id' => 6,
                'question' => 'Qu\'est-ce qu\'un dividende?',
                'answers' => [
                    'a' => 'Un impôt sur les revenus',
                    'b' => 'Une часть des bénéfices distribuée aux actionnaires',
                    'c' => 'Un taux d\'intérêt',
                    'd' => 'Une pénalité bancaire',
                ],
                'correct' => 'b',
                'points' => 100,
            ],
            [
                'id' => 7,
                'question' => 'Qu\'est-ce que le risque financier?',
                'answers' => [
                    'a' => 'La garantie de gains',
                    'b' => 'La possibilité de perdre de l\'argent',
                    'c' => 'Un type d\'investissement',
                    'd' => 'Une assurance',
                ],
                'correct' => 'b',
                'points' => 100,
            ],
            [
                'id' => 8,
                'question' => 'Qu\'est-ce qu\'un crédit?',
                'answers' => [
                    'a' => 'Une épargne',
                    'b' => 'De l\'argent emprunté à rembourser',
                    'c' => 'Un investissement',
                    'd' => 'Un revenu',
                ],
                'correct' => 'b',
                'points' => 100,
            ],
            [
                'id' => 9,
                'question' => 'Qu\'est-ce que l\'inflation?',
                'answers' => [
                    'a' => 'La diminution des prix',
                    'b' => 'L\'augmentation générale des prix',
                    'c' => 'Un type d\'impôt',
                    'd' => 'Un taux d\'intérêt',
                ],
                'correct' => 'b',
                'points' => 100,
            ],
            [
                'id' => 10,
                'question' => 'Qu\'est-ce qu\'une obligation?',
                'answers' => [
                    'a' => 'Une action en bourse',
                    'b' => 'Un prêt effectué par un État ou une entreprise',
                    'c' => 'Un compte courant',
                    'd' => 'Une cryptomonnaie',
                ],
                'correct' => 'b',
                'points' => 100,
            ],
        ];
    }
}
