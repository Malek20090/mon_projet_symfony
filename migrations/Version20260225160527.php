<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260225160527 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE ai_objective_report (id INT AUTO_INCREMENT NOT NULL, objectif_id INT NOT NULL, content LONGTEXT NOT NULL, risk_score INT DEFAULT NULL, created_at DATETIME NOT NULL, INDEX IDX_62190510157D1AD4 (objectif_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE cas_relles (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, imprevus_id INT DEFAULT NULL, confirmed_by_id INT DEFAULT NULL, financial_goal_id INT DEFAULT NULL, titre VARCHAR(150) NOT NULL, description LONGTEXT DEFAULT NULL, type VARCHAR(10) NOT NULL, categorie VARCHAR(40) DEFAULT NULL, montant DOUBLE PRECISION NOT NULL, solution VARCHAR(30) NOT NULL, date_effet DATE NOT NULL, resultat VARCHAR(20) DEFAULT NULL, raison_refus LONGTEXT DEFAULT NULL, confirmed_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', justificatif_file_name VARCHAR(255) DEFAULT NULL, updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_9383DCA3A76ED395 (user_id), INDEX IDX_9383DCA3AF9C32D8 (imprevus_id), INDEX IDX_9383DCA36F45385D (confirmed_by_id), INDEX IDX_9383DCA334BE5894 (financial_goal_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE cas_relles_audit (id INT UNSIGNED AUTO_INCREMENT NOT NULL, type VARCHAR(10) NOT NULL, object_id VARCHAR(255) NOT NULL, discriminator VARCHAR(255) DEFAULT NULL, transaction_hash VARCHAR(40) DEFAULT NULL, diffs JSON DEFAULT NULL, blame_id VARCHAR(255) DEFAULT NULL, blame_user VARCHAR(255) DEFAULT NULL, blame_user_fqdn VARCHAR(255) DEFAULT NULL, blame_user_firewall VARCHAR(100) DEFAULT NULL, ip VARCHAR(45) DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX type_05b1d6f74ecb471aee099bc1d1b6c77f_idx (type), INDEX object_id_05b1d6f74ecb471aee099bc1d1b6c77f_idx (object_id), INDEX discriminator_05b1d6f74ecb471aee099bc1d1b6c77f_idx (discriminator), INDEX transaction_hash_05b1d6f74ecb471aee099bc1d1b6c77f_idx (transaction_hash), INDEX blame_id_05b1d6f74ecb471aee099bc1d1b6c77f_idx (blame_id), INDEX created_at_05b1d6f74ecb471aee099bc1d1b6c77f_idx (created_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE certification_results (id INT AUTO_INCREMENT NOT NULL, user_name VARCHAR(255) NOT NULL, user_email VARCHAR(255) NOT NULL, type VARCHAR(50) NOT NULL, certification_name VARCHAR(255) NOT NULL, score INT NOT NULL, total INT NOT NULL, percentage INT NOT NULL, passed TINYINT(1) NOT NULL, date DATETIME NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE cours (id INT AUTO_INCREMENT NOT NULL, user_id INT DEFAULT NULL, titre VARCHAR(150) NOT NULL, contenu_texte LONGTEXT NOT NULL, type_media VARCHAR(10) NOT NULL, url_media VARCHAR(255) DEFAULT NULL, INDEX IDX_FDCA8C9CA76ED395 (user_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE crypto (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, symbol VARCHAR(255) NOT NULL, apiid VARCHAR(255) NOT NULL, currentprice DOUBLE PRECISION NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE expense (id INT AUTO_INCREMENT NOT NULL, revenue_id INT NOT NULL, user_id INT NOT NULL, amount DOUBLE PRECISION NOT NULL, category VARCHAR(100) NOT NULL, expense_date DATE NOT NULL, description LONGTEXT DEFAULT NULL, INDEX IDX_2D3A8DA6224718EB (revenue_id), INDEX IDX_2D3A8DA6A76ED395 (user_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE financial_goal (id INT AUTO_INCREMENT NOT NULL, saving_account_id INT NOT NULL, nom VARCHAR(255) NOT NULL, montant_cible DOUBLE PRECISION NOT NULL, montant_actuel DOUBLE PRECISION NOT NULL, date_limite DATE DEFAULT NULL, priorite INT DEFAULT NULL, INDEX IDX_2CB34D6A54BD4B2C (saving_account_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE imprevus (id INT AUTO_INCREMENT NOT NULL, titre VARCHAR(150) NOT NULL, type VARCHAR(10) NOT NULL, budget DOUBLE PRECISION NOT NULL, message_educatif LONGTEXT DEFAULT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE imprevus_audit (id INT UNSIGNED AUTO_INCREMENT NOT NULL, type VARCHAR(10) NOT NULL, object_id VARCHAR(255) NOT NULL, discriminator VARCHAR(255) DEFAULT NULL, transaction_hash VARCHAR(40) DEFAULT NULL, diffs JSON DEFAULT NULL, blame_id VARCHAR(255) DEFAULT NULL, blame_user VARCHAR(255) DEFAULT NULL, blame_user_fqdn VARCHAR(255) DEFAULT NULL, blame_user_firewall VARCHAR(100) DEFAULT NULL, ip VARCHAR(45) DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX type_0bdbc08707f065186713fe79e954c576_idx (type), INDEX object_id_0bdbc08707f065186713fe79e954c576_idx (object_id), INDEX discriminator_0bdbc08707f065186713fe79e954c576_idx (discriminator), INDEX transaction_hash_0bdbc08707f065186713fe79e954c576_idx (transaction_hash), INDEX blame_id_0bdbc08707f065186713fe79e954c576_idx (blame_id), INDEX created_at_0bdbc08707f065186713fe79e954c576_idx (created_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE investissement (id INT AUTO_INCREMENT NOT NULL, crypto_id INT DEFAULT NULL, objectif_id INT DEFAULT NULL, user_id INT DEFAULT NULL, amount_invested DOUBLE PRECISION NOT NULL, buy_price DOUBLE PRECISION NOT NULL, quantity DOUBLE PRECISION NOT NULL, created_at DATE NOT NULL, INDEX IDX_B8E64E01E9571A63 (crypto_id), INDEX IDX_B8E64E01157D1AD4 (objectif_id), INDEX IDX_B8E64E01A76ED395 (user_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE objectif (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, target_multiplier DOUBLE PRECISION NOT NULL, initial_amount DOUBLE PRECISION NOT NULL, target_amount DOUBLE PRECISION NOT NULL, is_completed TINYINT(1) NOT NULL, created_at DATE NOT NULL, UNIQUE INDEX UNIQ_E2F868515E237E06 (name), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE quiz (id INT AUTO_INCREMENT NOT NULL, cours_id INT DEFAULT NULL, user_id INT DEFAULT NULL, question LONGTEXT NOT NULL, choix_reponses JSON NOT NULL, reponse_correcte VARCHAR(100) NOT NULL, points_valeur INT NOT NULL, INDEX IDX_A412FA927ECF78B0 (cours_id), INDEX IDX_A412FA92A76ED395 (user_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE quiz_results (id INT AUTO_INCREMENT NOT NULL, cours_id INT DEFAULT NULL, quiz_id INT DEFAULT NULL, user_name VARCHAR(255) NOT NULL, user_email VARCHAR(255) NOT NULL, score INT NOT NULL, total INT NOT NULL, percentage INT NOT NULL, passed TINYINT(1) NOT NULL, date DATETIME NOT NULL, INDEX IDX_8DF949B47ECF78B0 (cours_id), INDEX IDX_8DF949B4853CD175 (quiz_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE recurring_transaction_rule (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, expense_revenue_id INT DEFAULT NULL, kind VARCHAR(10) NOT NULL, frequency VARCHAR(20) NOT NULL, amount DOUBLE PRECISION NOT NULL, label VARCHAR(255) NOT NULL, signature VARCHAR(80) NOT NULL, next_run_at DATE NOT NULL, is_active TINYINT(1) NOT NULL, confidence DOUBLE PRECISION DEFAULT NULL, expense_category VARCHAR(100) DEFAULT NULL, revenue_type VARCHAR(20) DEFAULT NULL, description LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, INDEX IDX_F0CE0F4DA76ED395 (user_id), INDEX IDX_F0CE0F4D925327E2 (expense_revenue_id), UNIQUE INDEX uniq_recurring_user_signature (user_id, signature), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE revenue (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, amount DOUBLE PRECISION NOT NULL, type VARCHAR(20) NOT NULL, received_at DATE NOT NULL, description VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, INDEX IDX_E9116C85A76ED395 (user_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE saving_account (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, sold DOUBLE PRECISION DEFAULT NULL, date_creation DATE DEFAULT NULL, taux_interet DOUBLE PRECISION DEFAULT NULL, INDEX IDX_EF4ED035A76ED395 (user_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE transaction (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, expense_id INT DEFAULT NULL, type VARCHAR(30) NOT NULL, montant DOUBLE PRECISION NOT NULL, date DATE NOT NULL, description LONGTEXT DEFAULT NULL, module_source VARCHAR(50) DEFAULT NULL, INDEX IDX_723705D1A76ED395 (user_id), INDEX IDX_723705D1F395DB7B (expense_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE user (id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(100) DEFAULT NULL, email VARCHAR(180) NOT NULL, password VARCHAR(255) NOT NULL, roles JSON NOT NULL, date_inscription DATE DEFAULT NULL, solde_total DOUBLE PRECISION NOT NULL, image VARCHAR(255) DEFAULT NULL, face_id_credential_id VARCHAR(255) DEFAULT NULL, face_id_enabled TINYINT(1) NOT NULL, face_plus_token VARCHAR(255) DEFAULT NULL, face_plus_enabled TINYINT(1) NOT NULL, email_verified TINYINT(1) DEFAULT 0 NOT NULL, email_verification_token VARCHAR(64) DEFAULT NULL, email_verified_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX UNIQ_8D93D649E7927C74 (email), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE user_notification (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, title VARCHAR(160) NOT NULL, message LONGTEXT NOT NULL, status VARCHAR(20) NOT NULL, is_read TINYINT(1) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_3F980AC8A76ED395 (user_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE messenger_messages (id BIGINT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, headers LONGTEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', available_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', delivered_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 (queue_name, available_at, delivered_at, id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE ai_objective_report ADD CONSTRAINT FK_62190510157D1AD4 FOREIGN KEY (objectif_id) REFERENCES objectif (id)');
        $this->addSql('ALTER TABLE cas_relles ADD CONSTRAINT FK_9383DCA3A76ED395 FOREIGN KEY (user_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE cas_relles ADD CONSTRAINT FK_9383DCA3AF9C32D8 FOREIGN KEY (imprevus_id) REFERENCES imprevus (id)');
        $this->addSql('ALTER TABLE cas_relles ADD CONSTRAINT FK_9383DCA36F45385D FOREIGN KEY (confirmed_by_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE cas_relles ADD CONSTRAINT FK_9383DCA334BE5894 FOREIGN KEY (financial_goal_id) REFERENCES financial_goal (id)');
        $this->addSql('ALTER TABLE cours ADD CONSTRAINT FK_FDCA8C9CA76ED395 FOREIGN KEY (user_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE expense ADD CONSTRAINT FK_2D3A8DA6224718EB FOREIGN KEY (revenue_id) REFERENCES revenue (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE expense ADD CONSTRAINT FK_2D3A8DA6A76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE financial_goal ADD CONSTRAINT FK_2CB34D6A54BD4B2C FOREIGN KEY (saving_account_id) REFERENCES saving_account (id)');
        $this->addSql('ALTER TABLE investissement ADD CONSTRAINT FK_B8E64E01E9571A63 FOREIGN KEY (crypto_id) REFERENCES crypto (id)');
        $this->addSql('ALTER TABLE investissement ADD CONSTRAINT FK_B8E64E01157D1AD4 FOREIGN KEY (objectif_id) REFERENCES objectif (id)');
        $this->addSql('ALTER TABLE investissement ADD CONSTRAINT FK_B8E64E01A76ED395 FOREIGN KEY (user_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE quiz ADD CONSTRAINT FK_A412FA927ECF78B0 FOREIGN KEY (cours_id) REFERENCES cours (id)');
        $this->addSql('ALTER TABLE quiz ADD CONSTRAINT FK_A412FA92A76ED395 FOREIGN KEY (user_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE quiz_results ADD CONSTRAINT FK_8DF949B47ECF78B0 FOREIGN KEY (cours_id) REFERENCES cours (id)');
        $this->addSql('ALTER TABLE quiz_results ADD CONSTRAINT FK_8DF949B4853CD175 FOREIGN KEY (quiz_id) REFERENCES quiz (id)');
        $this->addSql('ALTER TABLE recurring_transaction_rule ADD CONSTRAINT FK_F0CE0F4DA76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE recurring_transaction_rule ADD CONSTRAINT FK_F0CE0F4D925327E2 FOREIGN KEY (expense_revenue_id) REFERENCES revenue (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE revenue ADD CONSTRAINT FK_E9116C85A76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE saving_account ADD CONSTRAINT FK_EF4ED035A76ED395 FOREIGN KEY (user_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE transaction ADD CONSTRAINT FK_723705D1A76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE transaction ADD CONSTRAINT FK_723705D1F395DB7B FOREIGN KEY (expense_id) REFERENCES expense (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE user_notification ADD CONSTRAINT FK_3F980AC8A76ED395 FOREIGN KEY (user_id) REFERENCES user (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE ai_objective_report DROP FOREIGN KEY FK_62190510157D1AD4');
        $this->addSql('ALTER TABLE cas_relles DROP FOREIGN KEY FK_9383DCA3A76ED395');
        $this->addSql('ALTER TABLE cas_relles DROP FOREIGN KEY FK_9383DCA3AF9C32D8');
        $this->addSql('ALTER TABLE cas_relles DROP FOREIGN KEY FK_9383DCA36F45385D');
        $this->addSql('ALTER TABLE cas_relles DROP FOREIGN KEY FK_9383DCA334BE5894');
        $this->addSql('ALTER TABLE cours DROP FOREIGN KEY FK_FDCA8C9CA76ED395');
        $this->addSql('ALTER TABLE expense DROP FOREIGN KEY FK_2D3A8DA6224718EB');
        $this->addSql('ALTER TABLE expense DROP FOREIGN KEY FK_2D3A8DA6A76ED395');
        $this->addSql('ALTER TABLE financial_goal DROP FOREIGN KEY FK_2CB34D6A54BD4B2C');
        $this->addSql('ALTER TABLE investissement DROP FOREIGN KEY FK_B8E64E01E9571A63');
        $this->addSql('ALTER TABLE investissement DROP FOREIGN KEY FK_B8E64E01157D1AD4');
        $this->addSql('ALTER TABLE investissement DROP FOREIGN KEY FK_B8E64E01A76ED395');
        $this->addSql('ALTER TABLE quiz DROP FOREIGN KEY FK_A412FA927ECF78B0');
        $this->addSql('ALTER TABLE quiz DROP FOREIGN KEY FK_A412FA92A76ED395');
        $this->addSql('ALTER TABLE quiz_results DROP FOREIGN KEY FK_8DF949B47ECF78B0');
        $this->addSql('ALTER TABLE quiz_results DROP FOREIGN KEY FK_8DF949B4853CD175');
        $this->addSql('ALTER TABLE recurring_transaction_rule DROP FOREIGN KEY FK_F0CE0F4DA76ED395');
        $this->addSql('ALTER TABLE recurring_transaction_rule DROP FOREIGN KEY FK_F0CE0F4D925327E2');
        $this->addSql('ALTER TABLE revenue DROP FOREIGN KEY FK_E9116C85A76ED395');
        $this->addSql('ALTER TABLE saving_account DROP FOREIGN KEY FK_EF4ED035A76ED395');
        $this->addSql('ALTER TABLE transaction DROP FOREIGN KEY FK_723705D1A76ED395');
        $this->addSql('ALTER TABLE transaction DROP FOREIGN KEY FK_723705D1F395DB7B');
        $this->addSql('ALTER TABLE user_notification DROP FOREIGN KEY FK_3F980AC8A76ED395');
        $this->addSql('DROP TABLE ai_objective_report');
        $this->addSql('DROP TABLE cas_relles');
        $this->addSql('DROP TABLE cas_relles_audit');
        $this->addSql('DROP TABLE certification_results');
        $this->addSql('DROP TABLE cours');
        $this->addSql('DROP TABLE crypto');
        $this->addSql('DROP TABLE expense');
        $this->addSql('DROP TABLE financial_goal');
        $this->addSql('DROP TABLE imprevus');
        $this->addSql('DROP TABLE imprevus_audit');
        $this->addSql('DROP TABLE investissement');
        $this->addSql('DROP TABLE objectif');
        $this->addSql('DROP TABLE quiz');
        $this->addSql('DROP TABLE quiz_results');
        $this->addSql('DROP TABLE recurring_transaction_rule');
        $this->addSql('DROP TABLE revenue');
        $this->addSql('DROP TABLE saving_account');
        $this->addSql('DROP TABLE transaction');
        $this->addSql('DROP TABLE user');
        $this->addSql('DROP TABLE user_notification');
        $this->addSql('DROP TABLE messenger_messages');
    }
}
