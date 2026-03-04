<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260226024948 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE ai_objective_report (id INT AUTO_INCREMENT NOT NULL, objectif_id INT NOT NULL, content LONGTEXT NOT NULL, risk_score INT DEFAULT NULL, created_at DATETIME NOT NULL, INDEX IDX_62190510157D1AD4 (objectif_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE certification_results (id INT AUTO_INCREMENT NOT NULL, user_name VARCHAR(255) NOT NULL, user_email VARCHAR(255) NOT NULL, type VARCHAR(50) NOT NULL, certification_name VARCHAR(255) NOT NULL, score INT NOT NULL, total INT NOT NULL, percentage INT NOT NULL, passed TINYINT(1) NOT NULL, date DATETIME NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE quiz_results (id INT AUTO_INCREMENT NOT NULL, cours_id INT DEFAULT NULL, quiz_id INT DEFAULT NULL, user_name VARCHAR(255) NOT NULL, user_email VARCHAR(255) NOT NULL, score INT NOT NULL, total INT NOT NULL, percentage INT NOT NULL, passed TINYINT(1) NOT NULL, date DATETIME NOT NULL, INDEX IDX_8DF949B47ECF78B0 (cours_id), INDEX IDX_8DF949B4853CD175 (quiz_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE ai_objective_report ADD CONSTRAINT FK_62190510157D1AD4 FOREIGN KEY (objectif_id) REFERENCES objectif (id)');
        $this->addSql('ALTER TABLE quiz_results ADD CONSTRAINT FK_8DF949B47ECF78B0 FOREIGN KEY (cours_id) REFERENCES cours (id)');
        $this->addSql('ALTER TABLE quiz_results ADD CONSTRAINT FK_8DF949B4853CD175 FOREIGN KEY (quiz_id) REFERENCES quiz (id)');
        $this->addSql('ALTER TABLE cas_relles ADD CONSTRAINT FK_9383DCA3A76ED395 FOREIGN KEY (user_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE cas_relles ADD CONSTRAINT FK_9383DCA3AF9C32D8 FOREIGN KEY (imprevus_id) REFERENCES imprevus (id)');
        $this->addSql('ALTER TABLE cas_relles ADD CONSTRAINT FK_9383DCA36F45385D FOREIGN KEY (confirmed_by_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE cas_relles ADD CONSTRAINT FK_9383DCA334BE5894 FOREIGN KEY (financial_goal_id) REFERENCES financial_goal (id)');
        $this->addSql('ALTER TABLE cas_relles_audit CHANGE diffs diffs LONGTEXT DEFAULT NULL COMMENT \'(DC2Type:json)\'');
        $this->addSql('ALTER TABLE imprevus_audit CHANGE diffs diffs LONGTEXT DEFAULT NULL COMMENT \'(DC2Type:json)\'');
        $this->addSql('ALTER TABLE quiz CHANGE choix_reponses choix_reponses LONGTEXT NOT NULL COMMENT \'(DC2Type:json)\'');
        $this->addSql('ALTER TABLE reclamation DROP FOREIGN KEY FK_CE606404E75B4574');
        $this->addSql('DROP INDEX idx_ce606404e75b4574 ON reclamation');
        $this->addSql('CREATE INDEX IDX_CE6064044E78F836 ON reclamation (admin_responder_id)');
        $this->addSql('ALTER TABLE reclamation ADD CONSTRAINT FK_CE606404E75B4574 FOREIGN KEY (admin_responder_id) REFERENCES user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE user CHANGE roles roles LONGTEXT NOT NULL COMMENT \'(DC2Type:json)\', CHANGE face_id_enabled face_id_enabled TINYINT(1) NOT NULL, CHANGE face_plus_enabled face_plus_enabled TINYINT(1) NOT NULL, CHANGE email_verified_at email_verified_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE blocked_at blocked_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE ai_objective_report DROP FOREIGN KEY FK_62190510157D1AD4');
        $this->addSql('ALTER TABLE quiz_results DROP FOREIGN KEY FK_8DF949B47ECF78B0');
        $this->addSql('ALTER TABLE quiz_results DROP FOREIGN KEY FK_8DF949B4853CD175');
        $this->addSql('DROP TABLE ai_objective_report');
        $this->addSql('DROP TABLE certification_results');
        $this->addSql('DROP TABLE quiz_results');
        $this->addSql('ALTER TABLE cas_relles DROP FOREIGN KEY FK_9383DCA3A76ED395');
        $this->addSql('ALTER TABLE cas_relles DROP FOREIGN KEY FK_9383DCA3AF9C32D8');
        $this->addSql('ALTER TABLE cas_relles DROP FOREIGN KEY FK_9383DCA36F45385D');
        $this->addSql('ALTER TABLE cas_relles DROP FOREIGN KEY FK_9383DCA334BE5894');
        $this->addSql('ALTER TABLE cas_relles_audit CHANGE diffs diffs LONGTEXT DEFAULT NULL COLLATE `utf8mb4_bin`');
        $this->addSql('ALTER TABLE imprevus_audit CHANGE diffs diffs LONGTEXT DEFAULT NULL COLLATE `utf8mb4_bin`');
        $this->addSql('ALTER TABLE quiz CHANGE choix_reponses choix_reponses LONGTEXT NOT NULL COLLATE `utf8mb4_bin`');
        $this->addSql('ALTER TABLE reclamation DROP FOREIGN KEY FK_CE6064044E78F836');
        $this->addSql('DROP INDEX idx_ce6064044e78f836 ON reclamation');
        $this->addSql('CREATE INDEX IDX_CE606404E75B4574 ON reclamation (admin_responder_id)');
        $this->addSql('ALTER TABLE reclamation ADD CONSTRAINT FK_CE6064044E78F836 FOREIGN KEY (admin_responder_id) REFERENCES user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE user CHANGE roles roles LONGTEXT NOT NULL COLLATE `utf8mb4_bin`, CHANGE face_id_enabled face_id_enabled TINYINT(1) DEFAULT 0 NOT NULL, CHANGE face_plus_enabled face_plus_enabled TINYINT(1) DEFAULT 0 NOT NULL, CHANGE email_verified_at email_verified_at DATETIME DEFAULT NULL, CHANGE blocked_at blocked_at DATETIME DEFAULT NULL');
    }
}
