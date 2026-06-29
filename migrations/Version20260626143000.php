<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260626143000 extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Adds external sync jobs for scheduled upsert and delta imports from JTL, plentymarkets, and Xentral.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'This migration targets MySQL/MariaDB.',
        );

        $this->addSql('CREATE TABLE external_sync_job (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(190) NOT NULL, system VARCHAR(255) NOT NULL, mode VARCHAR(255) NOT NULL, source_url VARCHAR(255) DEFAULT NULL, source_file_path VARCHAR(255) DEFAULT NULL, request_method VARCHAR(10) NOT NULL, request_headers JSON NOT NULL, request_body JSON NOT NULL, interval_minutes INT NOT NULL, enabled TINYINT(1) NOT NULL, last_run_at DATETIME DEFAULT NULL, last_status VARCHAR(40) DEFAULT NULL, last_message LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE external_sync_job');
    }
}
