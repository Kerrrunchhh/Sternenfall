<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261114104236 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Players choose a ticker shown below the black board (user.board_ticker_id)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE `user` ADD board_ticker_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE `user` ADD CONSTRAINT FK_8D93D649E746E425 FOREIGN KEY (board_ticker_id) REFERENCES ticker (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_8D93D649E746E425 ON `user` (board_ticker_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE `user` DROP FOREIGN KEY FK_8D93D649E746E425');
        $this->addSql('DROP INDEX IDX_8D93D649E746E425 ON `user`');
        $this->addSql('ALTER TABLE `user` DROP board_ticker_id');
    }
}
