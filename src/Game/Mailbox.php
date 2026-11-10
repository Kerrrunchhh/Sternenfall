<?php

namespace App\Game;

use App\Entity\PlayerMessage;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

use function Symfony\Component\Translation\t;

/**
 * Letters between players ("Post"): write, reply, read and delete.
 */
final readonly class Mailbox
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserRepository $users,
        private ClockInterface $clock,
        private ?GameMailer $gameMailer = null,
    ) {
    }

    public function send(User $sender, string $recipientName, string $subject, string $body): PlayerMessage
    {
        $recipientName = trim($recipientName);
        // "[TAG]" writes to an alliance: its founder gets the letter and answers it.
        if (preg_match('/^\[([A-Za-z0-9]{2,6})\]$/', $recipientName, $m)) {
            $alliance = $this->em->getRepository(\App\Entity\Alliance::class)->findOneBy(['tag' => strtoupper($m[1])]);
            $founder = null === $alliance ? null : $alliance->getMembers()->findFirst(static fn (int $k, User $u): bool => \App\Game\AllianceRank::Founder === $u->getAllianceRank());
            if (null === $founder) {
                throw new GameException(t('Eine Allianz {recipientName} gibt es nicht.', ['{recipientName}' => $recipientName]));
            }
            $recipientName = $founder->getUsername();
            $subject = '['.$alliance->getTag().'] '.$subject;
        }
        $recipient = '' === $recipientName ? null : $this->users->findOneBy(['username' => $recipientName]);
        if (null === $recipient) {
            throw new GameException(t('Einen Spieler „{recipientName}“ gibt es nicht.', ['{recipientName}' => $recipientName]));
        }
        if ($recipient->isSameAs($sender)) {
            throw new GameException(t('Du kannst dir nicht selbst schreiben.'));
        }
        $subject = trim($subject);
        $body = trim($body);
        if ('' === $body) {
            throw new GameException(t('Die Nachricht ist leer.'));
        }
        if (mb_strlen($body) > PlayerMessage::MAX_BODY) {
            throw new GameException(t('Eine Nachricht darf höchstens {value} Zeichen lang sein.', ['{value}' => PlayerMessage::MAX_BODY]));
        }

        $message = new PlayerMessage($sender, $recipient, '' === $subject ? '(kein Betreff)' : $subject, $body, \DateTimeImmutable::createFromInterface($this->clock->now()));
        $this->em->persist($message);
        $this->em->flush();
        $this->gameMailer?->notify($recipient, 'mail', t('Neue Nachricht von {player}', ['{player}' => $sender->getUsername()]), t("{player} hat dir geschrieben: „{subject}“\n\nLesen und antworten kannst du im Spiel unter Post.", ['{player}' => $sender->getUsername(), '{subject}' => $message->getSubject()]));

        return $message;
    }

    /** Opens a letter: only sender and recipient may; the recipient reading it marks it read. */
    public function open(PlayerMessage $message, User $user): void
    {
        if (!$message->isVisibleTo($user)) {
            throw new GameException(t('Diese Nachricht gibt es nicht (mehr).'));
        }
        if ($message->isReceivedBy($user) && !$message->isRead()) {
            $message->markRead();
            $this->em->flush();
        }
    }

    /**
     * Deletes letters for the player; letters nobody sees any more are removed for good.
     *
     * @param iterable<PlayerMessage> $messages
     *
     * @return int how many were deleted
     */
    public function delete(iterable $messages, User $user): int
    {
        $count = 0;
        foreach ($messages as $message) {
            if (!$message->isVisibleTo($user)) {
                continue;
            }
            if ($message->deleteFor($user)) {
                $this->em->remove($message);
            }
            ++$count;
        }
        $this->em->flush();

        return $count;
    }

    /** Subject and quoted text for an answer to a letter. @return array{string, \Symfony\Contracts\Translation\TranslatableInterface} */
    public static function replyDraft(PlayerMessage $message): array
    {
        $subject = $message->getSubject();
        if (!str_starts_with($subject, 'Re: ')) {
            $subject = 'Re: '.$subject;
        }
        $quote = implode("\n", array_map(static fn (string $line): string => '> '.$line, explode("\n", $message->getBody())));
        $from = $message->getSender()?->getUsername() ?? 'Unbekannt';

        return [mb_substr($subject, 0, PlayerMessage::MAX_SUBJECT), t("\n\n{from} schrieb am {value}:\n{quote}", ['{from}' => $from, '{value}' => $message->getCreatedAt()->setTimezone(new \DateTimeZone('Europe/Berlin'))->format('d.m.Y H:i'), '{quote}' => $quote])];
    }
}
