<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261109072449 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Long-range tickers';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE ticker (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(40) NOT NULL, created_at DATETIME NOT NULL, owner_id INT NOT NULL, INDEX IDX_7EC308967E3C61F9 (owner_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE ticker_member (id INT AUTO_INCREMENT NOT NULL, invited_at DATETIME NOT NULL, accepted_at DATETIME DEFAULT NULL, ticker_id INT NOT NULL, user_id INT NOT NULL, UNIQUE INDEX uniq_ticker_member (ticker_id, user_id), INDEX IDX_CCC99934556B180E (ticker_id), INDEX IDX_CCC99934A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE ticker_post (id INT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, created_at DATETIME NOT NULL, ticker_id INT NOT NULL, author_id INT DEFAULT NULL, INDEX idx_ticker_post_ticker (ticker_id, created_at), INDEX IDX_BE089AA8556B180E (ticker_id), INDEX IDX_BE089AA8F675F31B (author_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE ticker ADD CONSTRAINT FK_7EC308967E3C61F9 FOREIGN KEY (owner_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE ticker_member ADD CONSTRAINT FK_CCC99934556B180E FOREIGN KEY (ticker_id) REFERENCES ticker (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE ticker_member ADD CONSTRAINT FK_CCC99934A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE ticker_post ADD CONSTRAINT FK_BE089AA8556B180E FOREIGN KEY (ticker_id) REFERENCES ticker (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE ticker_post ADD CONSTRAINT FK_BE089AA8F675F31B FOREIGN KEY (author_id) REFERENCES `user` (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE ticker DROP FOREIGN KEY FK_7EC308967E3C61F9');
        $this->addSql('ALTER TABLE ticker_member DROP FOREIGN KEY FK_CCC99934556B180E');
        $this->addSql('ALTER TABLE ticker_member DROP FOREIGN KEY FK_CCC99934A76ED395');
        $this->addSql('ALTER TABLE ticker_post DROP FOREIGN KEY FK_BE089AA8556B180E');
        $this->addSql('ALTER TABLE ticker_post DROP FOREIGN KEY FK_BE089AA8F675F31B');
        $this->addSql('DROP TABLE ticker');
        $this->addSql('DROP TABLE ticker_member');
        $this->addSql('DROP TABLE ticker_post');
    }
}
