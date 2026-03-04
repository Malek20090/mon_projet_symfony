<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260303192408 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE user ADD geo_country_code VARCHAR(8) DEFAULT NULL, ADD geo_country_name VARCHAR(120) DEFAULT NULL, ADD geo_region_name VARCHAR(120) DEFAULT NULL, ADD geo_city_name VARCHAR(120) DEFAULT NULL, ADD geo_detected_ip VARCHAR(45) DEFAULT NULL, ADD geo_vpn_suspected TINYINT(1) DEFAULT 0 NOT NULL, ADD geo_last_checked_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE user DROP geo_country_code, DROP geo_country_name, DROP geo_region_name, DROP geo_city_name, DROP geo_detected_ip, DROP geo_vpn_suspected, DROP geo_last_checked_at');
    }
}
