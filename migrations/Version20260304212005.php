<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260304212005 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE ai_objective_report CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE crypto CHANGE currentprice currentprice NUMERIC(20, 8) NOT NULL');
        $this->addSql('ALTER TABLE expense CHANGE amount amount NUMERIC(12, 2) NOT NULL');
        $this->addSql('ALTER TABLE financial_goal DROP FOREIGN KEY FK_2CB34D6A54BD4B2C');
        $this->addSql('ALTER TABLE financial_goal ADD CONSTRAINT FK_2CB34D6A54BD4B2C FOREIGN KEY (saving_account_id) REFERENCES saving_account (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE investissement CHANGE amount_invested amount_invested NUMERIC(12, 2) NOT NULL, CHANGE buy_price buy_price NUMERIC(20, 8) NOT NULL, CHANGE created_at created_at DATE NOT NULL COMMENT \'(DC2Type:date_immutable)\'');
        $this->addSql('ALTER TABLE objectif CHANGE initial_amount initial_amount NUMERIC(12, 2) NOT NULL, CHANGE target_amount target_amount NUMERIC(12, 2) NOT NULL, CHANGE created_at created_at DATE NOT NULL COMMENT \'(DC2Type:date_immutable)\'');
        $this->addSql('ALTER TABLE recurring_transaction_rule CHANGE amount amount NUMERIC(12, 2) NOT NULL');
        $this->addSql('ALTER TABLE revenue CHANGE amount amount NUMERIC(12, 2) NOT NULL');
        $this->addSql('ALTER TABLE user CHANGE solde_total solde_total NUMERIC(12, 2) DEFAULT \'0.00\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE ai_objective_report CHANGE created_at created_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE crypto CHANGE currentprice currentprice DOUBLE PRECISION NOT NULL');
        $this->addSql('ALTER TABLE expense CHANGE amount amount DOUBLE PRECISION NOT NULL');
        $this->addSql('ALTER TABLE financial_goal DROP FOREIGN KEY FK_2CB34D6A54BD4B2C');
        $this->addSql('ALTER TABLE financial_goal ADD CONSTRAINT FK_2CB34D6A54BD4B2C FOREIGN KEY (saving_account_id) REFERENCES saving_account (id)');
        $this->addSql('ALTER TABLE investissement CHANGE amount_invested amount_invested DOUBLE PRECISION NOT NULL, CHANGE buy_price buy_price DOUBLE PRECISION NOT NULL, CHANGE created_at created_at DATE NOT NULL');
        $this->addSql('ALTER TABLE objectif CHANGE initial_amount initial_amount DOUBLE PRECISION NOT NULL, CHANGE target_amount target_amount DOUBLE PRECISION NOT NULL, CHANGE created_at created_at DATE NOT NULL');
        $this->addSql('ALTER TABLE recurring_transaction_rule CHANGE amount amount DOUBLE PRECISION NOT NULL');
        $this->addSql('ALTER TABLE revenue CHANGE amount amount DOUBLE PRECISION NOT NULL');
        $this->addSql('ALTER TABLE user CHANGE solde_total solde_total DOUBLE PRECISION NOT NULL');
    }
}
