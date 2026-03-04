<?php

namespace App\Tests\Service;

use App\Entity\FinancialGoal;
use App\Entity\SavingAccount;
use App\Service\SavingsGoalStatsService;
use PHPUnit\Framework\TestCase;

class SavingsGoalStatsServiceTest extends TestCase
{
    public function testBuildStatsEmptyGoalsLowBalanceZeroInterest(): void
    {
        $service = new SavingsGoalStatsService();
        $account = (new SavingAccount())
            ->setSold(100)
            ->setTauxInteret(0);

        $stats = $service->buildStats($account, []);

        self::assertSame(100.0, $stats['balance']);
        self::assertSame(0, $stats['activeGoals']);
        self::assertSame(0, $stats['avgProgress']);
        self::assertSame('--/--/----', $stats['nearestDeadline']);

        self::assertCount(1, $stats['alerts']);
        self::assertSame('danger', $stats['alerts'][0]['level']);
        self::assertSame('Low savings buffer', $stats['alerts'][0]['title']);

        self::assertCount(2, $stats['insights']);
        self::assertSame('Interest Tip', $stats['insights'][0]['title']);
        self::assertSame('Goal Tip', $stats['insights'][1]['title']);

        self::assertCount(1, $stats['badges']);
        self::assertStringContainsString('Starter', $stats['badges'][0]);
    }

    public function testBuildStatsWithGoalsAverageProgressAndNearestDeadline(): void
    {
        $service = new SavingsGoalStatsService();
        $account = (new SavingAccount())->setSold(500)->setTauxInteret(2);

        $today = new \DateTime('today');
        $nearest = (clone $today)->modify('+5 days');
        $later = (clone $today)->modify('+20 days');

        $goalA = $this->makeGoal('Laptop', 100, 50, 3, $later);
        $goalB = $this->makeGoal('Trip', 200, 100, 2, $nearest);

        $stats = $service->buildStats($account, [$goalA, $goalB]);

        self::assertSame(2, $stats['activeGoals']);
        self::assertSame(50, $stats['avgProgress']);
        self::assertSame($nearest->format('Y-m-d'), $stats['nearestDeadline']);
    }

    public function testBuildStatsHighPriorityLowProgressAlert(): void
    {
        $service = new SavingsGoalStatsService();
        $account = (new SavingAccount())->setSold(500)->setTauxInteret(2);

        $goal = $this->makeGoal('Emergency', 100, 10, 4, null);

        $stats = $service->buildStats($account, [$goal]);
        $titles = array_column($stats['alerts'], 'title');

        self::assertContains('High priority needs action', $titles);
    }

    public function testBuildStatsUpcomingDeadlineAlert(): void
    {
        $service = new SavingsGoalStatsService();
        $account = (new SavingAccount())->setSold(500)->setTauxInteret(2);

        $deadline = (new \DateTime('today'))->modify('+7 days');
        $goal = $this->makeGoal('New Phone', 300, 150, 2, $deadline);

        $stats = $service->buildStats($account, [$goal]);
        $titles = array_column($stats['alerts'], 'title');

        self::assertContains('Upcoming deadline', $titles);
    }

    public function testBuildGoalHealthDashboardCalculatesStatusesAndAlerts(): void
    {
        $service = new SavingsGoalStatsService();

        $rows = [
            [
                'id' => 1,
                'nom' => 'Overdue',
                'montantCible' => 1000,
                'montantActuel' => 100,
                'progressRatio' => 0.1,
                'dailyNeeded' => 5,
                'monthlyNeeded' => 150,
                'daysLeft' => -3,
                'urgencyScore' => 50,
                'remainingAmount' => 900,
                'priorite' => 3,
            ],
            [
                'id' => 2,
                'nom' => 'DueSoon',
                'montantCible' => 1000,
                'montantActuel' => 100,
                'progressRatio' => 0.1,
                'dailyNeeded' => 10,
                'monthlyNeeded' => 300,
                'daysLeft' => 5,
                'urgencyScore' => 40,
                'remainingAmount' => 900,
                'priorite' => 3,
            ],
            [
                'id' => 3,
                'nom' => 'HighPriorityLow',
                'montantCible' => 1000,
                'montantActuel' => 300,
                'progressRatio' => 0.3,
                'dailyNeeded' => 6,
                'monthlyNeeded' => 180,
                'daysLeft' => 30,
                'urgencyScore' => 20,
                'remainingAmount' => 700,
                'priorite' => 4,
            ],
        ];

        $dashboard = $service->buildGoalHealthDashboard($rows, 500);

        self::assertSame(1, $dashboard['summary']['criticalCount']);
        self::assertSame(1, $dashboard['summary']['warningCount']);
        self::assertSame(1, $dashboard['summary']['overdueCount']);

        $messages = array_column($dashboard['alerts'], 'message');
        self::assertTrue($this->messageContains($messages, 'overdue'));
        self::assertTrue($this->messageContains($messages, 'due in 5 days'));
        self::assertTrue($this->messageContains($messages, 'High-priority goal'));
    }

    public function testBuildGoalHealthDashboardRecommendationsAndFeasibility(): void
    {
        $service = new SavingsGoalStatsService();

        $rows = [
            [
                'id' => 7,
                'nom' => 'Vacation',
                'montantCible' => 2000,
                'montantActuel' => 200,
                'progressRatio' => 0.1,
                'dailyNeeded' => 0,
                'monthlyNeeded' => 200,
                'daysLeft' => 20,
                'urgencyScore' => 10,
                'remainingAmount' => 1800,
                'priorite' => 3,
            ],
        ];

        $dashboard = $service->buildGoalHealthDashboard($rows, 100);

        self::assertSame('at_risk', $dashboard['summary']['feasibilityStatus']);
        self::assertSame(50.0, $dashboard['summary']['coverageRawPct']);
        self::assertSame(220.0, $dashboard['summary']['recommendedMonthlyContribution']);
        self::assertSame(55.0, $dashboard['summary']['recommendedWeeklyContribution']);

        self::assertCount(2, $dashboard['recommendations']);
        self::assertStringContainsString('Contribute at least 220.00 TND/month', $dashboard['recommendations'][0]);
        self::assertSame('Reorder priorities and focus contributions on top urgent goals first.', $dashboard['recommendations'][1]);
    }

    private function makeGoal(
        string $name,
        float $target,
        float $current,
        ?int $priority,
        ?\DateTime $deadline
    ): FinancialGoal {
        return (new FinancialGoal())
            ->setNom($name)
            ->setMontantCible($target)
            ->setMontantActuel($current)
            ->setPriorite($priority)
            ->setDateLimite($deadline);
    }

    /**
     * @param array<int, string> $messages
     */
    private function messageContains(array $messages, string $needle): bool
    {
        foreach ($messages as $message) {
            if (stripos($message, $needle) !== false) {
                return true;
            }
        }
        return false;
    }
}
