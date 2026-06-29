<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260624130000 extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Initial CMS to Commerce Hub schema for products, sources, media, listings, publication runs, and messenger queue storage.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            ! $this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'This migration targets MySQL/MariaDB.',
        );

        $this->addSql('CREATE TABLE channel_listing (id INT AUTO_INCREMENT NOT NULL, channel VARCHAR(255) NOT NULL, external_id VARCHAR(190) DEFAULT NULL, status VARCHAR(255) NOT NULL, title VARCHAR(255) NOT NULL, bullet_points JSON NOT NULL, description LONGTEXT DEFAULT NULL, technical_attributes JSON NOT NULL, search_terms JSON NOT NULL, quality_score INT DEFAULT NULL, quality_notes LONGTEXT DEFAULT NULL, last_synced_at DATETIME DEFAULT NULL, sync_error LONGTEXT DEFAULT NULL, product_id INT NOT NULL, INDEX IDX_7C4377D54584665A (product_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE product (id INT AUTO_INCREMENT NOT NULL, public_id BINARY(16) NOT NULL, name VARCHAR(255) NOT NULL, slug VARCHAR(255) DEFAULT NULL, brand VARCHAR(120) DEFAULT NULL, category_path VARCHAR(255) DEFAULT NULL, status VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_D34A04ADB5B48B91 (public_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE product_asset (id INT AUTO_INCREMENT NOT NULL, asset_type VARCHAR(255) NOT NULL, filename VARCHAR(190) NOT NULL, original_name VARCHAR(190) NOT NULL, mime_type VARCHAR(120) NOT NULL, storage_path VARCHAR(255) NOT NULL, position INT NOT NULL, alt_text VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, product_id INT NOT NULL, INDEX IDX_A3F321004584665A (product_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE product_source (id INT AUTO_INCREMENT NOT NULL, source_type VARCHAR(255) NOT NULL, cms_system VARCHAR(80) DEFAULT NULL, external_reference VARCHAR(190) DEFAULT NULL, raw_payload LONGTEXT NOT NULL, language_code VARCHAR(10) NOT NULL, imported_at DATETIME NOT NULL, product_id INT NOT NULL, INDEX IDX_3DF63ED74584665A (product_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE product_variant (id INT AUTO_INCREMENT NOT NULL, sku VARCHAR(120) NOT NULL, option_summary JSON NOT NULL, ean VARCHAR(20) DEFAULT NULL, price_gross NUMERIC(10, 2) DEFAULT NULL, currency VARCHAR(3) NOT NULL, stock INT DEFAULT NULL, enabled TINYINT(1) NOT NULL, created_at DATETIME NOT NULL, product_id INT NOT NULL, INDEX IDX_209AA41D4584665A (product_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE publication_run (id INT AUTO_INCREMENT NOT NULL, channel VARCHAR(255) NOT NULL, action VARCHAR(80) NOT NULL, status VARCHAR(255) NOT NULL, payload JSON NOT NULL, summary LONGTEXT DEFAULT NULL, started_at DATETIME NOT NULL, finished_at DATETIME DEFAULT NULL, product_id INT DEFAULT NULL, INDEX IDX_7328480F4584665A (product_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE messenger_messages (id BIGINT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, headers LONGTEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL, INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 (queue_name, available_at, delivered_at, id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE channel_listing ADD CONSTRAINT FK_7C4377D54584665A FOREIGN KEY (product_id) REFERENCES product (id)');
        $this->addSql('ALTER TABLE product_asset ADD CONSTRAINT FK_A3F321004584665A FOREIGN KEY (product_id) REFERENCES product (id)');
        $this->addSql('ALTER TABLE product_source ADD CONSTRAINT FK_3DF63ED74584665A FOREIGN KEY (product_id) REFERENCES product (id)');
        $this->addSql('ALTER TABLE product_variant ADD CONSTRAINT FK_209AA41D4584665A FOREIGN KEY (product_id) REFERENCES product (id)');
        $this->addSql('ALTER TABLE publication_run ADD CONSTRAINT FK_7328480F4584665A FOREIGN KEY (product_id) REFERENCES product (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE channel_listing DROP FOREIGN KEY FK_7C4377D54584665A');
        $this->addSql('ALTER TABLE product_asset DROP FOREIGN KEY FK_A3F321004584665A');
        $this->addSql('ALTER TABLE product_source DROP FOREIGN KEY FK_3DF63ED74584665A');
        $this->addSql('ALTER TABLE product_variant DROP FOREIGN KEY FK_209AA41D4584665A');
        $this->addSql('ALTER TABLE publication_run DROP FOREIGN KEY FK_7328480F4584665A');
        $this->addSql('DROP TABLE channel_listing');
        $this->addSql('DROP TABLE messenger_messages');
        $this->addSql('DROP TABLE product_asset');
        $this->addSql('DROP TABLE product_source');
        $this->addSql('DROP TABLE product_variant');
        $this->addSql('DROP TABLE publication_run');
        $this->addSql('DROP TABLE product');
    }
}
