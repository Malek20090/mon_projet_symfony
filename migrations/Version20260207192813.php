<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260207192813 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE cas_relles (id INT AUTO_INCREMENT NOT NULL, titre VARCHAR(150) NOT NULL, description LONGTEXT DEFAULT NULL, type VARCHAR(10) NOT NULL, montant DOUBLE PRECISION NOT NULL, solution VARCHAR(30) NOT NULL, date_effet DATE NOT NULL, resultat VARCHAR(20) DEFAULT NULL, user_id INT NOT NULL, imprevus_id INT DEFAULT NULL, epargne_id INT DEFAULT NULL, INDEX IDX_9383DCA3A76ED395 (user_id), INDEX IDX_9383DCA3AF9C32D8 (imprevus_id), INDEX IDX_9383DCA3E55AE86D (epargne_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE expense (id INT AUTO_INCREMENT NOT NULL, montant DOUBLE PRECISION NOT NULL, categorie VARCHAR(100) NOT NULL, date DATE NOT NULL, description LONGTEXT DEFAULT NULL, revenue_id INT DEFAULT NULL, INDEX IDX_2D3A8DA6224718EB (revenue_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE financial_goal (id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(255) DEFAULT NULL, montant_cible DOUBLE PRECISION DEFAULT NULL, montant_actuel DOUBLE PRECISION DEFAULT NULL, date_limite DATE DEFAULT NULL, priorite INT DEFAULT NULL, saving_account_id INT DEFAULT NULL, INDEX IDX_2CB34D6A54BD4B2C (saving_account_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE imprevus (id INT AUTO_INCREMENT NOT NULL, titre VARCHAR(150) NOT NULL, type VARCHAR(10) NOT NULL, budget DOUBLE PRECISION NOT NULL, message_educatif LONGTEXT DEFAULT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE revenue (id INT AUTO_INCREMENT NOT NULL, amount DOUBLE PRECISION NOT NULL, type VARCHAR(20) NOT NULL, received_at DATE NOT NULL, description VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, user_id INT NOT NULL, INDEX IDX_E9116C85A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE saving_account (id INT AUTO_INCREMENT NOT NULL, sold DOUBLE PRECISION DEFAULT NULL, date_creation DATE DEFAULT NULL, taux_interet DOUBLE PRECISION DEFAULT NULL, user_id INT NOT NULL, INDEX IDX_EF4ED035A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE transaction (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(30) DEFAULT NULL, montant DOUBLE PRECISION DEFAULT NULL, date DATE DEFAULT NULL, description LONGTEXT DEFAULT NULL, module_source VARCHAR(50) DEFAULT NULL, user_id INT DEFAULT NULL, INDEX IDX_723705D1A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE user (id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(100) DEFAULT NULL, email VARCHAR(150) DEFAULT NULL, password VARCHAR(255) DEFAULT NULL, role VARCHAR(20) DEFAULT NULL, date_inscription DATE DEFAULT NULL, solde_total DOUBLE PRECISION DEFAULT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE messenger_messages (id BIGINT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, headers LONGTEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL, INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 (queue_name, available_at, delivered_at, id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE cas_relles ADD CONSTRAINT FK_9383DCA3A76ED395 FOREIGN KEY (user_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE cas_relles ADD CONSTRAINT FK_9383DCA3AF9C32D8 FOREIGN KEY (imprevus_id) REFERENCES imprevus (id)');
        $this->addSql('ALTER TABLE cas_relles ADD CONSTRAINT FK_9383DCA3E55AE86D FOREIGN KEY (epargne_id) REFERENCES saving_account (id)');
        $this->addSql('ALTER TABLE expense ADD CONSTRAINT FK_2D3A8DA6224718EB FOREIGN KEY (revenue_id) REFERENCES revenue (id)');
        $this->addSql('ALTER TABLE financial_goal ADD CONSTRAINT FK_2CB34D6A54BD4B2C FOREIGN KEY (saving_account_id) REFERENCES saving_account (id)');
        $this->addSql('ALTER TABLE revenue ADD CONSTRAINT FK_E9116C85A76ED395 FOREIGN KEY (user_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE saving_account ADD CONSTRAINT FK_EF4ED035A76ED395 FOREIGN KEY (user_id) REFERENCES saving_account (id)');
        $this->addSql('ALTER TABLE transaction ADD CONSTRAINT FK_723705D1A76ED395 FOREIGN KEY (user_id) REFERENCES user (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE cas_relles DROP FOREIGN KEY FK_9383DCA3A76ED395');
        $this->addSql('ALTER TABLE cas_relles DROP FOREIGN KEY FK_9383DCA3AF9C32D8');
        $this->addSql('ALTER TABLE cas_relles DROP FOREIGN KEY FK_9383DCA3E55AE86D');
        $this->addSql('ALTER TABLE expense DROP FOREIGN KEY FK_2D3A8DA6224718EB');
        $this->addSql('ALTER TABLE financial_goal DROP FOREIGN KEY FK_2CB34D6A54BD4B2C');
        $this->addSql('ALTER TABLE revenue DROP FOREIGN KEY FK_E9116C85A76ED395');
        $this->addSql('ALTER TABLE saving_account DROP FOREIGN KEY FK_EF4ED035A76ED395');
        $this->addSql('ALTER TABLE transaction DROP FOREIGN KEY FK_723705D1A76ED395');
        $this->addSql('DROP TABLE cas_relles');
        $this->addSql('DROP TABLE expense');
        $this->addSql('DROP TABLE financial_goal');
        $this->addSql('DROP TABLE imprevus');
        $this->addSql('DROP TABLE revenue');
        $this->addSql('DROP TABLE saving_account');
        $this->addSql('DROP TABLE transaction');
        $this->addSql('DROP TABLE user');
        $this->addSql('DROP TABLE messenger_messages');
    }
}
