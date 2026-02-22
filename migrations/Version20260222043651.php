<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260222043651 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE ai_objective_report (id INT AUTO_INCREMENT NOT NULL, objectif_id INT NOT NULL, content LONGTEXT NOT NULL, risk_score INT DEFAULT NULL, created_at DATETIME NOT NULL, INDEX IDX_62190510157D1AD4 (objectif_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE messenger_messages (id BIGINT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, headers LONGTEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', available_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', delivered_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 (queue_name, available_at, delivered_at, id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE ai_objective_report ADD CONSTRAINT FK_62190510157D1AD4 FOREIGN KEY (objectif_id) REFERENCES objectif (id)');
        $this->addSql('ALTER TABLE cas_relles CHANGE resultat resultat VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE cours CHANGE url_media url_media VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE financial_goal CHANGE date_limite date_limite DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE quiz CHANGE choix_reponses choix_reponses JSON NOT NULL');
        $this->addSql('ALTER TABLE revenue CHANGE description description VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE saving_account CHANGE sold sold DOUBLE PRECISION DEFAULT NULL, CHANGE date_creation date_creation DATE DEFAULT NULL, CHANGE taux_interet taux_interet DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE transaction CHANGE module_source module_source VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE user CHANGE nom nom VARCHAR(100) DEFAULT NULL, CHANGE roles roles JSON NOT NULL, CHANGE date_inscription date_inscription DATE DEFAULT NULL, CHANGE image image VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE ai_objective_report DROP FOREIGN KEY FK_62190510157D1AD4');
        $this->addSql('DROP TABLE ai_objective_report');
        $this->addSql('DROP TABLE messenger_messages');
        $this->addSql('ALTER TABLE cas_relles CHANGE resultat resultat VARCHAR(20) DEFAULT \'NULL\'');
        $this->addSql('ALTER TABLE cours CHANGE url_media url_media VARCHAR(255) DEFAULT \'NULL\'');
        $this->addSql('ALTER TABLE financial_goal CHANGE date_limite date_limite DATE DEFAULT \'NULL\'');
        $this->addSql('ALTER TABLE quiz CHANGE choix_reponses choix_reponses LONGTEXT NOT NULL COLLATE `utf8mb4_bin`');
        $this->addSql('ALTER TABLE revenue CHANGE description description VARCHAR(255) DEFAULT \'NULL\'');
        $this->addSql('ALTER TABLE saving_account CHANGE sold sold DOUBLE PRECISION DEFAULT \'NULL\', CHANGE date_creation date_creation DATE DEFAULT \'NULL\', CHANGE taux_interet taux_interet DOUBLE PRECISION DEFAULT \'NULL\'');
        $this->addSql('ALTER TABLE transaction CHANGE module_source module_source VARCHAR(50) DEFAULT \'NULL\'');
        $this->addSql('ALTER TABLE user CHANGE nom nom VARCHAR(100) DEFAULT \'NULL\', CHANGE roles roles LONGTEXT NOT NULL COLLATE `utf8mb4_bin`, CHANGE date_inscription date_inscription DATE DEFAULT \'NULL\', CHANGE image image VARCHAR(255) DEFAULT \'NULL\'');
    }
}
