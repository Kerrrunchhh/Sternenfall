<?php

namespace App\Game;

use App\Entity\Poll;
use App\Entity\PollVote;
use App\Entity\User;
use App\Repository\PollRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use function Symfony\Component\Translation\t;

/**
 * Polls for all players: the admins ask, every player answers once (with a reason if he likes),
 * and when the time is up everybody sees the result – without names. Admins see who voted what.
 */
final readonly class PollService
{
    public const int MAX_HOURS = 24 * 30;

    public function __construct(
        private EntityManagerInterface $em,
        private PollRepository $polls,
        private UserRepository $users,
        private Inbox $inbox,
        private ClockInterface $clock,
        private ?UrlGeneratorInterface $urls = null,
        private ?GameMailer $mailer = null,
    ) {
    }

    /**
     * Bilingual: question and answers in German and in English (same number of answers, same order).
     *
     * @param list<string> $options   one answer per entry (empty ones are dropped)
     * @param list<string> $optionsEn the same answers in English
     */
    public function create(User $admin, string $question, array $options, bool $multiple, int $hours, string $questionEn = '', array $optionsEn = []): Poll
    {
        [$question, $options] = self::clean($question, $options);
        [$questionEn, $optionsEn] = self::clean($questionEn, $optionsEn);
        if ('' === $questionEn || \count($optionsEn) !== \count($options)) {
            throw new GameException(t('Die Umfrage braucht auch die englische Fassung: die Frage und gleich viele Antworten in derselben Reihenfolge.'));
        }
        if ($hours < 1 || $hours > self::MAX_HOURS) {
            throw new GameException(t('Die Laufzeit muss zwischen 1 Stunde und {days} Tagen liegen.', ['{days}' => intdiv(self::MAX_HOURS, 24)]));
        }

        $now = $this->now();
        $poll = new Poll($question, $options, $multiple, $now, $now->modify("+{$hours} hours"), $admin);
        $poll->translate($questionEn, $optionsEn);
        $this->em->persist($poll);
        $this->em->flush();

        // Everybody who plays gets a message with a button to vote.
        $url = $this->urls?->generate('app_polls', ['_fragment' => 'umfrage-'.$poll->getId()]);
        foreach ($this->users->findAll() as $player) {
            if ($player->isSystem() || $player->isDeactivated() || null !== $player->getDeleteAt()) {
                continue;
            }
            $this->inbox->send(
                $player,
                t('Umfrage: {question}', ['{question}' => mb_strimwidth($poll->questionIn($player->getLocale()), 0, 100, '…')]),
                t("Die Spielleitung möchte deine Meinung wissen:\n\n{question}\n\nAbstimmen kannst du bis {endsAt} unter „Umfragen“. Danach siehst du dort das Ergebnis.", ['{question}' => $poll->questionIn($player->getLocale()), '{endsAt}' => new \App\I18n\When($poll->getEndsAt(), 'd.m.Y H:i')]),
                $url,
                null === $url ? null : t('Abstimmen →'),
            );
        }
        $this->em->flush();

        return $poll;
    }

    /**
     * Announces the poll by e-mail to everybody who subscribed to news about the game, each in
     * his language, with a link to it.
     *
     * @return int how many players it went to
     */
    public function mailAnnouncement(Poll $poll): int
    {
        if (null === $this->mailer) {
            return 0;
        }
        $link = $this->urls?->generate('app_polls', ['_fragment' => 'umfrage-'.$poll->getId()], UrlGeneratorInterface::ABSOLUTE_URL) ?? '';
        $players = array_filter($this->users->findAll(), static fn (User $p): bool => !$p->isSystem() && !$p->isDeactivated() && null === $p->getDeleteAt());

        return $this->mailer->broadcast(
            $players,
            'news',
            static fn (string $locale) => t('Umfrage: {question}', ['{question}' => mb_strimwidth($poll->questionIn($locale), 0, 100, '…')]),
            static fn (string $locale) => t("Die Spielleitung möchte deine Meinung wissen:\n\n{question}\n\n{options}\n\nAbstimmen kannst du bis {endsAt}:\n{link}", [
                '{question}' => $poll->questionIn($locale),
                '{options}' => implode("\n", array_map(static fn (string $o): string => '• '.$o, $poll->optionsIn($locale))),
                '{endsAt}' => new \App\I18n\When($poll->getEndsAt(), 'd.m.Y H:i'),
                '{link}' => $link,
            ]),
        );
    }

    /** @param list<int|string> $choices indexes of the ticked answers */
    public function vote(Poll $poll, User $player, array $choices, string $reason): void
    {
        if (!$poll->isRunning($this->now())) {
            throw new GameException(t('Diese Umfrage ist schon beendet.'));
        }
        if (null !== $this->polls->voteOf($poll, $player)) {
            throw new GameException(t('Du hast bei dieser Umfrage schon abgestimmt.'));
        }
        $picked = [];
        foreach ($choices as $choice) {
            $index = (int) $choice;
            if (isset($poll->getOptions()[$index]) && !\in_array($index, $picked, true)) {
                $picked[] = $index;
            }
        }
        sort($picked);
        if ([] === $picked) {
            throw new GameException(t('Wähle mindestens eine Antwort.'));
        }
        if (!$poll->isMultiple() && \count($picked) > 1) {
            throw new GameException(t('Bei dieser Umfrage gibt es nur eine Antwort.'));
        }
        if (mb_strlen(trim($reason)) > PollVote::MAX_REASON) {
            throw new GameException(t('Die Begründung darf höchstens {max} Zeichen haben.', ['{max}' => PollVote::MAX_REASON]));
        }

        $this->em->persist(new PollVote($poll, $player, $picked, $reason, $this->now()));
        $this->em->flush();
    }

    /**
     * The question trimmed and checked, the answers without empty or doubled ones.
     *
     * @param list<string> $options
     *
     * @return array{string, list<string>}
     */
    private static function clean(string $question, array $options): array
    {
        $question = trim($question);
        $options = array_values(array_unique(array_filter(array_map(static fn (string $o): string => mb_substr(trim($o), 0, Poll::MAX_OPTION), $options), static fn (string $o): bool => '' !== $o)));
        if ('' === $question || mb_strlen($question) > Poll::MAX_QUESTION) {
            throw new GameException(t('Die Frage darf nicht leer sein und höchstens {max} Zeichen haben.', ['{max}' => Poll::MAX_QUESTION]));
        }
        if (\count($options) < Poll::MIN_OPTIONS || \count($options) > Poll::MAX_OPTIONS) {
            throw new GameException(t('Eine Umfrage braucht {min} bis {max} verschiedene Antworten.', ['{min}' => Poll::MIN_OPTIONS, '{max}' => Poll::MAX_OPTIONS]));
        }

        return [$question, $options];
    }

    public function close(Poll $poll): void
    {
        $poll->close($this->now());
        $this->em->flush();
    }

    /**
     * Votes per answer, voters and reasons.
     *
     * @param list<PollVote> $votes
     *
     * @return array{voters: int, counts: list<int>, reasons: list<array{text: string, choices: list<int>, user: ?User}>}
     */
    public static function result(Poll $poll, array $votes): array
    {
        $counts = array_fill(0, \count($poll->getOptions()), 0);
        $reasons = [];
        foreach ($votes as $vote) {
            foreach ($vote->getChoices() as $choice) {
                if (isset($counts[$choice])) {
                    ++$counts[$choice];
                }
            }
            if (null !== $vote->getReason()) {
                $reasons[] = ['text' => $vote->getReason(), 'choices' => $vote->getChoices(), 'user' => $vote->getUser()];
            }
        }

        return ['voters' => \count($votes), 'counts' => $counts, 'reasons' => $reasons];
    }

    private function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now());
    }
}
