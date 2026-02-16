<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260216092418 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE cas_relles CHANGE resultat resultat VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE cours CHANGE url_media url_media VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE financial_goal CHANGE date_limite date_limite DATE DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_E2F868515E237E06 ON objectif (name)');
        $this->addSql('ALTER TABLE quiz CHANGE choix_reponses choix_reponses JSON NOT NULL');
        $this->addSql('ALTER TABLE revenue CHANGE description description VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE saving_account CHANGE sold sold DOUBLE PRECISION DEFAULT NULL, CHANGE date_creation date_creation DATE DEFAULT NULL, CHANGE taux_interet taux_interet DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE transaction CHANGE module_source module_source VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE user ADD image VARCHAR(255) DEFAULT NULL, CHANGE nom nom VARCHAR(100) DEFAULT NULL, CHANGE roles roles JSON NOT NULL, CHANGE date_inscription date_inscription DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE messenger_messages CHANGE delivered_at delivered_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE cas_relles CHANGE resultat resultat VARCHAR(20) DEFAULT \'NULL\'');
        $this->addSql('ALTER TABLE cours CHANGE url_media url_media VARCHAR(255) DEFAULT \'NULL\'');
        $this->addSql('ALTER TABLE financial_goal CHANGE date_limite date_limite DATE DEFAULT \'NULL\'');
        $this->addSql('ALTER TABLE messenger_messages CHANGE delivered_at delivered_at DATETIME DEFAULT \'NULL\'');
        $this->addSql('DROP INDEX UNIQ_E2F868515E237E06 ON objectif');
        $this->addSql('ALTER TABLE quiz CHANGE choix_reponses choix_reponses LONGTEXT NOT NULL COLLATE `utf8mb4_bin`');
        $this->addSql('ALTER TABLE revenue CHANGE description description VARCHAR(255) DEFAULT \'NULL\'');
        $this->addSql('ALTER TABLE saving_account CHANGE sold sold DOUBLE PRECISION DEFAULT \'NULL\', CHANGE date_creation date_creation DATE DEFAULT \'NULL\', CHANGE taux_interet taux_interet DOUBLE PRECISION DEFAULT \'NULL\'');
        $this->addSql('ALTER TABLE transaction CHANGE module_source module_source VARCHAR(50) DEFAULT \'NULL\'');
        $this->addSql('ALTER TABLE user DROP image, CHANGE nom nom VARCHAR(100) DEFAULT \'NULL\', CHANGE roles roles LONGTEXT NOT NULL COLLATE `utf8mb4_bin`, CHANGE date_inscription date_inscription DATE DEFAULT \'NULL\'');
    }
}
