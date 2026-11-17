<?php

namespace App\Game;

use App\Entity\Planet;
use App\Entity\User;
use App\I18n\Lines;
use App\I18n\Num;
use Doctrine\ORM\EntityManagerInterface;

use function Symfony\Component\Translation\t;

/**
 * A player pays another one credits, with a message if he likes. The pushing protection applies
 * as for resource gifts, and every payment shows up for the admins (multi accounts).
 */
final readonly class CreditTransfer
{
    public const int MAX_MESSAGE = 1000;

    public function __construct(
        private PlanetService $planets,
        private PushingGuard $guard,
        private Inbox $inbox,
        private EntityManagerInterface $em,
    ) {
    }

    /** $planet: one of the payer's planets (his credits are brought up to date first). */
    public function pay(Planet $planet, User $receiver, int $amount, string $message): void
    {
        $sender = $planet->getOwner();
        $message = trim($message);
        if ($sender->isSameAs($receiver)) {
            throw new GameException(t('An dich selbst kannst du nichts zahlen.'));
        }
        if ($receiver->isSystem() || $receiver->isDeactivated() || null !== $receiver->getDeleteAt()) {
            throw new GameException(t('Diesem Konto kannst du nichts zahlen.'));
        }
        if ($amount < 1) {
            throw new GameException(t('Gib einen Betrag von mindestens 1 Credit an.'));
        }
        if (mb_strlen($message) > self::MAX_MESSAGE) {
            throw new GameException(t('Die Nachricht darf höchstens {max} Zeichen haben.', ['{max}' => self::MAX_MESSAGE]));
        }

        $this->planets->transaction($planet, function (Planet $planet, \DateTimeImmutable $now) use ($sender, $receiver, $amount, $message): void {
            if ($sender->getCredits() < $amount) {
                throw new GameException(t('So viele Credits hast du nicht (du hast {credits}).', ['{credits}' => new Num(floor($sender->getCredits()))]));
            }
            $this->guard->checkAndRecordCredits($sender, $receiver, $amount, $now);
            $sender->addCredits(-$amount);
            // In one statement: the receiver's own updates (production, under his lock) don't overwrite it.
            $this->em->getConnection()->executeStatement('UPDATE `user` SET credits = credits + ? WHERE id = ?', [$amount, $receiver->getId()]);
            $this->inbox->send($receiver, t('💰 {player} hat dir {amount} Credits gezahlt', ['{player}' => $sender->getUsername(), '{amount}' => new Num($amount)]), new Lines([
                t('{player} hat dir {amount} Credits gezahlt. Sie sind schon auf deinem Konto.', ['{player}' => $sender->getUsername(), '{amount}' => new Num($amount)]),
                '' === $message ? null : '',
                '' === $message ? null : t('Nachricht von {player}:', ['{player}' => $sender->getUsername()]),
                '' === $message ? null : $message,
            ]));
        });
    }
}
