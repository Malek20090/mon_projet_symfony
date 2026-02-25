<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260224234000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add VichUploader fields to cas_relles (justificatif_file_name, updated_at).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cas_relles ADD justificatif_file_name VARCHAR(255) DEFAULT NULL, ADD updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cas_relles DROP justificatif_file_name, DROP updated_at');
    }
}

