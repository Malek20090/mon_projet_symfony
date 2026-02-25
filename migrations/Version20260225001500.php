<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260225001500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add categorie column to cas_relles for AI text classification.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cas_relles ADD categorie VARCHAR(40) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cas_relles DROP categorie');
    }
}

