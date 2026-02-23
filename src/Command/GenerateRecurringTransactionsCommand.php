<?php

namespace App\Command;

use App\Entity\Expense;
use App\Entity\RecurringTransactionRule;
use App\Entity\Revenue;
use App\Entity\Transaction;
use App\Repository\RecurringTransactionRuleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:recurring:generate',
    description: 'Generate due recurring revenues/expenses and move their next run date.',
)]
class GenerateRecurringTransactionsCommand extends Command
{
    public function __construct(
        private readonly RecurringTransactionRuleRepository $ruleRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'date',
            null,
            InputOption::VALUE_REQUIRED,
            'Run generation up to this date (YYYY-MM-DD). Default: today.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dateOption = (string) $input->getOption('date');

        if ($dateOption !== '') {
            $targetDate = \DateTimeImmutable::createFromFormat('Y-m-d', $dateOption);
            if (!$targetDate) {
                $io->error('Invalid --date format. Expected YYYY-MM-DD.');
                return Command::INVALID;
            }
        } else {
            $targetDate = new \DateTimeImmutable('today');
        }

        $rules = $this->ruleRepository->findDueActive($targetDate);
        if ($rules === []) {
            $io->success('No due recurring rules.');
            return Command::SUCCESS;
        }

        $createdRevenues = 0;
        $createdExpenses = 0;
        $disabledRules = 0;

        foreach ($rules as $rule) {
            $nextRunAt = $rule->getNextRunAt();
            $user = $rule->getUser();
            if (!$nextRunAt || !$user) {
                continue;
            }

            $iterations = 0;
            while ($nextRunAt <= $targetDate && $iterations < 24) {
                if ($rule->getKind() === RecurringTransactionRule::KIND_REVENUE) {
                    $revenue = new Revenue();
                    $revenue->setUser($user);
                    $revenue->setAmount($rule->getAmount());
                    $revenue->setType($rule->getRevenueType() ?: 'FIXE');
                    $revenue->setDescription($rule->getDescription() ?: $rule->getLabel());
                    $revenue->setReceivedAt(\DateTime::createFromInterface($nextRunAt));
                    $revenue->setCreatedAt(new \DateTimeImmutable());

                    $this->entityManager->persist($revenue);
                    $user->setSoldeTotal($user->getSoldeTotal() + $rule->getAmount());
                    $createdRevenues++;
                } else {
                    $linkedRevenue = $rule->getExpenseRevenue();
                    if (!$linkedRevenue) {
                        $rule->setIsActive(false)->touch();
                        $disabledRules++;
                        $io->warning(sprintf('Rule #%d disabled: missing linked revenue for recurring expense.', $rule->getId()));
                        break;
                    }

                    $expense = new Expense();
                    $expense->setUser($user);
                    $expense->setAmount($rule->getAmount());
                    $expense->setCategory($rule->getExpenseCategory() ?: 'Other');
                    $expense->setDescription($rule->getDescription() ?: $rule->getLabel());
                    $expense->setExpenseDate(\DateTime::createFromInterface($nextRunAt));
                    $expense->setRevenue($linkedRevenue);
                    $this->entityManager->persist($expense);

                    $transaction = new Transaction();
                    $transaction->setType('EXPENSE');
                    $transaction->setMontant($rule->getAmount());
                    $transaction->setDate(\DateTime::createFromInterface($nextRunAt));
                    $transaction->setDescription($rule->getDescription() ?: $rule->getLabel());
                    $transaction->setModuleSource('RECURRING_ENGINE');
                    $transaction->setUser($user);
                    $transaction->setExpense($expense);
                    $this->entityManager->persist($transaction);

                    $user->setSoldeTotal($user->getSoldeTotal() - $rule->getAmount());
                    $createdExpenses++;
                }

                $nextRunAt = $this->nextDate($nextRunAt, $rule->getFrequency());
                $rule->setNextRunAt(\DateTime::createFromInterface($nextRunAt))->touch();
                $iterations++;
            }
        }

        $this->entityManager->flush();

        $io->success(sprintf(
            'Recurring generation done. Revenues: %d, Expenses: %d, Disabled rules: %d.',
            $createdRevenues,
            $createdExpenses,
            $disabledRules
        ));

        return Command::SUCCESS;
    }

    private function nextDate(\DateTimeInterface $date, string $frequency): \DateTimeImmutable
    {
        $base = \DateTimeImmutable::createFromInterface($date);
        return match ($frequency) {
            RecurringTransactionRule::FREQ_WEEKLY => $base->modify('+7 days'),
            default => $base->modify('+1 month'),
        };
    }
}

