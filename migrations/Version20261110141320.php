<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261110141320 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE contract (id INT AUTO_INCREMENT NOT NULL, kind VARCHAR(12) NOT NULL, galaxy SMALLINT NOT NULL, solar_system SMALLINT NOT NULL, position SMALLINT NOT NULL, planet_name VARCHAR(30) NOT NULL, reward DOUBLE PRECISION NOT NULL, days SMALLINT NOT NULL, note LONGTEXT DEFAULT NULL, status VARCHAR(12) NOT NULL, created_at DATETIME NOT NULL, ends_at DATETIME DEFAULT NULL, client_id INT NOT NULL, contractor_id INT DEFAULT NULL, INDEX idx_contract_status (status, ends_at), INDEX IDX_E98F285919EB6921 (client_id), INDEX IDX_E98F2859B0265DC7 (contractor_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE contract_application (id INT AUTO_INCREMENT NOT NULL, message LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, contract_id INT NOT NULL, applicant_id INT NOT NULL, UNIQUE INDEX uniq_contract_applicant (contract_id, applicant_id), INDEX IDX_E6F0040C2576E0FD (contract_id), INDEX IDX_E6F0040C97139001 (applicant_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE transport_request (id INT AUTO_INCREMENT NOT NULL, galaxy SMALLINT NOT NULL, solar_system SMALLINT NOT NULL, position SMALLINT NOT NULL, planet_name VARCHAR(30) NOT NULL, goods JSON NOT NULL, offer DOUBLE PRECISION NOT NULL, status VARCHAR(10) NOT NULL, created_at DATETIME NOT NULL, requester_id INT NOT NULL, trader_id INT DEFAULT NULL, fleet_id INT DEFAULT NULL, INDEX idx_transport_request_status (status), INDEX IDX_AF404F6EED442CF4 (requester_id), INDEX IDX_AF404F6E1273968F (trader_id), INDEX IDX_AF404F6E4B061DF9 (fleet_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE contract ADD CONSTRAINT FK_E98F285919EB6921 FOREIGN KEY (client_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE contract ADD CONSTRAINT FK_E98F2859B0265DC7 FOREIGN KEY (contractor_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE contract_application ADD CONSTRAINT FK_E6F0040C2576E0FD FOREIGN KEY (contract_id) REFERENCES contract (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE contract_application ADD CONSTRAINT FK_E6F0040C97139001 FOREIGN KEY (applicant_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE transport_request ADD CONSTRAINT FK_AF404F6EED442CF4 FOREIGN KEY (requester_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE transport_request ADD CONSTRAINT FK_AF404F6E1273968F FOREIGN KEY (trader_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE transport_request ADD CONSTRAINT FK_AF404F6E4B061DF9 FOREIGN KEY (fleet_id) REFERENCES fleet (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE contract DROP FOREIGN KEY FK_E98F285919EB6921');
        $this->addSql('ALTER TABLE contract DROP FOREIGN KEY FK_E98F2859B0265DC7');
        $this->addSql('ALTER TABLE contract_application DROP FOREIGN KEY FK_E6F0040C2576E0FD');
        $this->addSql('ALTER TABLE contract_application DROP FOREIGN KEY FK_E6F0040C97139001');
        $this->addSql('ALTER TABLE transport_request DROP FOREIGN KEY FK_AF404F6EED442CF4');
        $this->addSql('ALTER TABLE transport_request DROP FOREIGN KEY FK_AF404F6E1273968F');
        $this->addSql('ALTER TABLE transport_request DROP FOREIGN KEY FK_AF404F6E4B061DF9');
        $this->addSql('DROP TABLE contract');
        $this->addSql('DROP TABLE contract_application');
        $this->addSql('DROP TABLE transport_request');
    }
}
