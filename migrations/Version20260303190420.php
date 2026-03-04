<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260303190420 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE user_behavior_profile (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, score INT NOT NULL, profile_type VARCHAR(60) NOT NULL, strengths LONGTEXT NOT NULL COMMENT \'(DC2Type:json)\', weaknesses LONGTEXT NOT NULL COMMENT \'(DC2Type:json)\', next_actions LONGTEXT NOT NULL COMMENT \'(DC2Type:json)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX UNIQ_BC7D64DCA76ED395 (user_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE user_behavior_profile ADD CONSTRAINT FK_BC7D64DCA76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE user_behavior_profile DROP FOREIGN KEY FK_BC7D64DCA76ED395');
        $this->addSql('DROP TABLE user_behavior_profile');
    }
}
