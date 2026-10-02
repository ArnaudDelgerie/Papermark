<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260915210005 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Setting (images folder + selected provider), drop Provider.selected';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE setting (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, image_folder VARCHAR(255) DEFAULT NULL, selected_provider_id INTEGER DEFAULT NULL, CONSTRAINT FK_9F74B89833679B2C FOREIGN KEY (selected_provider_id) REFERENCES provider (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_9F74B89833679B2C ON setting (selected_provider_id)');
        $this->addSql('CREATE TEMPORARY TABLE __temp__provider AS SELECT id, model, name FROM provider');
        $this->addSql('DROP TABLE provider');
        $this->addSql('CREATE TABLE provider (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, model VARCHAR(255) DEFAULT NULL, name VARCHAR(255) NOT NULL)');
        $this->addSql('INSERT INTO provider (id, model, name) SELECT id, model, name FROM __temp__provider');
        $this->addSql('DROP TABLE __temp__provider');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_92C4739C5E237E06 ON provider (name)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE setting');
        $this->addSql('ALTER TABLE provider ADD COLUMN selected BOOLEAN NOT NULL');
    }
}
