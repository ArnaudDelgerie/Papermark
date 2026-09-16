<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260916212604 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Setting.themeMode';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TEMPORARY TABLE __temp__setting AS SELECT id, selected_provider_id, default_mode FROM setting');
        $this->addSql('DROP TABLE setting');
        $this->addSql('CREATE TABLE setting (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, selected_provider_id INTEGER DEFAULT NULL, default_mode VARCHAR(16) NOT NULL, theme_mode VARCHAR(16) DEFAULT \'dark\' NOT NULL, CONSTRAINT FK_9F74B89833679B2C FOREIGN KEY (selected_provider_id) REFERENCES provider (id) ON UPDATE NO ACTION ON DELETE NO ACTION NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO setting (id, selected_provider_id, default_mode, theme_mode) SELECT id, selected_provider_id, default_mode, \'dark\' FROM __temp__setting');
        $this->addSql('DROP TABLE __temp__setting');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_9F74B89833679B2C ON setting (selected_provider_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TEMPORARY TABLE __temp__setting AS SELECT id, default_mode, selected_provider_id FROM setting');
        $this->addSql('DROP TABLE setting');
        $this->addSql('CREATE TABLE setting (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, default_mode VARCHAR(16) DEFAULT \'single\' NOT NULL, selected_provider_id INTEGER DEFAULT NULL, CONSTRAINT FK_9F74B89833679B2C FOREIGN KEY (selected_provider_id) REFERENCES provider (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO setting (id, default_mode, selected_provider_id) SELECT id, default_mode, selected_provider_id FROM __temp__setting');
        $this->addSql('DROP TABLE __temp__setting');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_9F74B89833679B2C ON setting (selected_provider_id)');
    }
}
