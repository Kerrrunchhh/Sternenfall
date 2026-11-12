<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261112075326 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Bounties on players and the hunters\' progress';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE bounty (id INT AUTO_INCREMENT NOT NULL, reward DOUBLE PRECISION NOT NULL, required_shield DOUBLE PRECISION NOT NULL, note LONGTEXT DEFAULT NULL, status VARCHAR(12) NOT NULL, created_at DATETIME NOT NULL, ends_at DATETIME NOT NULL, client_id INT NOT NULL, target_id INT NOT NULL, winner_id INT DEFAULT NULL, INDEX idx_bounty_status (status, ends_at), INDEX IDX_93BE2C8019EB6921 (client_id), INDEX IDX_93BE2C80158E0B66 (target_id), INDEX IDX_93BE2C805DFCD4B8 (winner_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE bounty_hunt (id INT AUTO_INCREMENT NOT NULL, shield DOUBLE PRECISION NOT NULL, bounty_id INT NOT NULL, hunter_id INT NOT NULL, UNIQUE INDEX uniq_bounty_hunter (bounty_id, hunter_id), INDEX IDX_B271838B929C2B06 (bounty_id), INDEX IDX_B271838BA7DC5C81 (hunter_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE bounty ADD CONSTRAINT FK_93BE2C8019EB6921 FOREIGN KEY (client_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE bounty ADD CONSTRAINT FK_93BE2C80158E0B66 FOREIGN KEY (target_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE bounty ADD CONSTRAINT FK_93BE2C805DFCD4B8 FOREIGN KEY (winner_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE bounty_hunt ADD CONSTRAINT FK_B271838B929C2B06 FOREIGN KEY (bounty_id) REFERENCES bounty (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE bounty_hunt ADD CONSTRAINT FK_B271838BA7DC5C81 FOREIGN KEY (hunter_id) REFERENCES `user` (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE bounty DROP FOREIGN KEY FK_93BE2C8019EB6921');
        $this->addSql('ALTER TABLE bounty DROP FOREIGN KEY FK_93BE2C80158E0B66');
        $this->addSql('ALTER TABLE bounty DROP FOREIGN KEY FK_93BE2C805DFCD4B8');
        $this->addSql('ALTER TABLE bounty_hunt DROP FOREIGN KEY FK_B271838B929C2B06');
        $this->addSql('ALTER TABLE bounty_hunt DROP FOREIGN KEY FK_B271838BA7DC5C81');
        $this->addSql('DROP TABLE bounty');
        $this->addSql('DROP TABLE bounty_hunt');
    }
}
