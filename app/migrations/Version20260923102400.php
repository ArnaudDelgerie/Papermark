<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260923102400 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the ai_request table: the durable state of one AI request';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE ai_request (id VARCHAR(36) NOT NULL, status VARCHAR(16) NOT NULL, created_at DATETIME NOT NULL, provider VARCHAR(255) DEFAULT NULL, model VARCHAR(255) DEFAULT NULL, prompt_tokens INTEGER DEFAULT NULL, completion_tokens INTEGER DEFAULT NULL, PRIMARY KEY(id))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE ai_request');
    }
}
