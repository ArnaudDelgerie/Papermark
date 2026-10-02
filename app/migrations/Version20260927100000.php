<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260927100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the two autosave settings, off by default';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE setting ADD COLUMN autosave BOOLEAN NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE setting ADD COLUMN autosave_after_ai BOOLEAN NOT NULL DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE setting DROP COLUMN autosave');
        $this->addSql('ALTER TABLE setting DROP COLUMN autosave_after_ai');
    }
}
