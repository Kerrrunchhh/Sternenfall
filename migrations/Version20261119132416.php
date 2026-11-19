<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Message folder "Handel & Transport": existing general messages about trade and transport move there (by subject, German or English). */
final class Version20261119132416 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Message folder for trade and transport';
    }

    public function up(Schema $schema): void
    {
        $subjects = [
            'Die Handelsgilde liefert%',
            'Lieferung der Handelsgilde gescheitert%',
            'Lieferung angekommen%',
            'Fusionatoren an % verkauft',
            '% Fusionator(en) gekauft',
            'Transportgesuch erfüllt%',
            'Handelsstützpunkt verloren%',
            'Handelsstützpunkt umgezogen%',
            'Flotte „%“ weggeschickt',
            'Handel mit % an %',
            'Transport nach % gescheitert',
            'Transport nach % angekommen',
            'Lieferung von %',
            'Rohstoffe von % abgeholt',
            'Belieferung von % gescheitert',
            '% beliefert',
            'Handel an % gescheitert',
            '% am Handelsstützpunkt %',
            'The trade guild delivers%',
            'Trade guild delivery failed%',
            'Delivery arrived%',
            'Fusionators sold to %',
            '% fusionator(s) bought',
            'Transport request fulfilled%',
            'Trade post lost%',
            'Trade post moved%',
            'Fleet “%” sent away',
            'Trade with % at %',
            'Transport to % failed',
            'Transport to % arrived',
            'Delivery from %',
            'Resources collected from %',
            'Supplying % failed',
            '% supplied',
            'Trade at % failed',
            '% at the trade post %',
        ];
        $this->addSql("UPDATE message SET folder = 'trade' WHERE folder = 'general' AND (".implode(' OR ', array_fill(0, \count($subjects), 'subject LIKE ?')).')', $subjects);
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE message SET folder = 'general' WHERE folder = 'trade'");
    }
}
