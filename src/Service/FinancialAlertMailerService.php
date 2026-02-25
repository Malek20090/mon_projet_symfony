<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class FinancialAlertMailerService
{
    public function __construct(
        private readonly MailerInterface $mailer,
        #[Autowire('%env(MAILER_FROM_ADDRESS)%')]
        private readonly string $fromAddress,
        #[Autowire('%env(MAILER_FROM_NAME)%')]
        private readonly string $fromName,
        #[Autowire('%env(FINANCE_ALERT_TO)%')]
        private readonly string $defaultRecipient
    ) {
    }

    public function sendOverspendingAlert(float $totalIncome, float $totalExpenses): void
    {
        $ratio = $totalIncome > 0 ? ($totalExpenses / $totalIncome) : 999.0;
        $isCritical = $totalIncome <= 0 || $ratio > 1.0;
        $level = $isCritical ? 'CRITICAL' : 'WARNING';

        $subject = $isCritical
            ? 'Critical Budget Alert - Expenses exceeded income'
            : 'Budget Warning - Expenses exceeded 80% of income';

        $email = (new Email())
            ->from(new Address($this->fromAddress, $this->fromName))
            ->to(new Address($this->defaultRecipient))
            ->subject($subject)
            ->text($this->buildOverspendingText($totalIncome, $totalExpenses, $ratio, $level))
            ->html($this->buildOverspendingHtml($totalIncome, $totalExpenses, $ratio, $level));

        $this->mailer->send($email);
    }

    public function sendMonthlySummary(float $totalIncome, float $totalExpenses, float $netBalance): void
    {
        $ratio = $totalIncome > 0 ? ($totalExpenses / $totalIncome) : null;

        $email = (new Email())
            ->from(new Address($this->fromAddress, $this->fromName))
            ->to(new Address($this->defaultRecipient))
            ->subject('Monthly Finance Summary - Revenues & Expenses')
            ->text($this->buildMonthlySummaryText($totalIncome, $totalExpenses, $netBalance, $ratio))
            ->html($this->buildMonthlySummaryHtml($totalIncome, $totalExpenses, $netBalance, $ratio));

        $this->mailer->send($email);
    }

    private function buildOverspendingText(float $totalIncome, float $totalExpenses, float $ratio, string $level): string
    {
        return implode("\n", [
            sprintf('Budget %s Alert', $level),
            '',
            sprintf('Total income: %.2f TND', $totalIncome),
            sprintf('Total expenses: %.2f TND', $totalExpenses),
            sprintf('Expense ratio: %.1f%%', $ratio * 100),
            '',
            'Recommended actions:',
            '- Review the largest expense categories',
            '- Delay non-essential expenses',
            '- Set a strict weekly spending cap',
        ]);
    }

    private function buildOverspendingHtml(float $totalIncome, float $totalExpenses, float $ratio, string $level): string
    {
        return sprintf(
            '<h2>Budget %s Alert</h2><p>Your expenses have crossed the configured threshold.</p><ul><li><strong>Total income:</strong> %.2f TND</li><li><strong>Total expenses:</strong> %.2f TND</li><li><strong>Expense ratio:</strong> %.1f%%</li></ul><p><strong>Recommended actions:</strong></p><ul><li>Review the largest expense categories</li><li>Delay non-essential expenses</li><li>Set a strict weekly spending cap</li></ul>',
            htmlspecialchars($level, ENT_QUOTES),
            $totalIncome,
            $totalExpenses,
            $ratio * 100
        );
    }

    private function buildMonthlySummaryText(float $totalIncome, float $totalExpenses, float $netBalance, ?float $ratio): string
    {
        return implode("\n", [
            'Monthly Financial Summary',
            '',
            sprintf('Total income: %.2f TND', $totalIncome),
            sprintf('Total expenses: %.2f TND', $totalExpenses),
            sprintf('Net balance: %.2f TND', $netBalance),
            sprintf('Expense ratio: %s', $ratio !== null ? number_format($ratio * 100, 1) . '%' : 'N/A'),
        ]);
    }

    private function buildMonthlySummaryHtml(float $totalIncome, float $totalExpenses, float $netBalance, ?float $ratio): string
    {
        return sprintf(
            '<h2>Monthly Financial Summary</h2><p>Here is the latest summary of your Revenues & Expenses.</p><ul><li><strong>Total income:</strong> %.2f TND</li><li><strong>Total expenses:</strong> %.2f TND</li><li><strong>Net balance:</strong> %.2f TND</li><li><strong>Expense ratio:</strong> %s</li></ul>',
            $totalIncome,
            $totalExpenses,
            $netBalance,
            $ratio !== null ? number_format($ratio * 100, 1) . '%' : 'N/A'
        );
    }
}
