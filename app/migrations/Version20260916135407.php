<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260916135407 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Setting.default_mode (single/dir), defaulting existing rows to single';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TEMPORARY TABLE __temp__setting AS SELECT id, selected_provider_id FROM setting');
        $this->addSql('DROP TABLE setting');
        $this->addSql("CREATE TABLE setting (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, selected_provider_id INTEGER DEFAULT NULL, default_mode VARCHAR(16) NOT NULL DEFAULT 'single', CONSTRAINT FK_9F74B89833679B2C FOREIGN KEY (selected_provider_id) REFERENCES provider (id) ON UPDATE NO ACTION ON DELETE NO ACTION NOT DEFERRABLE INITIALLY IMMEDIATE)");
        $this->addSql("INSERT INTO setting (id, selected_provider_id, default_mode) SELECT id, selected_provider_id, 'single' FROM __temp__setting");
        $this->addSql('DROP TABLE __temp__setting');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_9F74B89833679B2C ON setting (selected_provider_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TEMPORARY TABLE __temp__setting AS SELECT id, selected_provider_id FROM setting');
        $this->addSql('DROP TABLE setting');
        $this->addSql('CREATE TABLE setting (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, selected_provider_id INTEGER DEFAULT NULL, CONSTRAINT FK_9F74B89833679B2C FOREIGN KEY (selected_provider_id) REFERENCES provider (id) ON UPDATE NO ACTION ON DELETE NO ACTION NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO setting (id, selected_provider_id) SELECT id, selected_provider_id FROM __temp__setting');
        $this->addSql('DROP TABLE __temp__setting');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_9F74B89833679B2C ON setting (selected_provider_id)');
    }
}
