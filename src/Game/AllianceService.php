<?php

namespace App\Game;

use App\Entity\Alliance;
use App\Entity\AlliancePost;
use App\Entity\AllianceRequest;
use App\Entity\AllianceVote;
use App\Entity\Fleet;
use App\Entity\User;
use App\Repository\AllianceRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Lock\LockFactory;

/**
 * Alliances: founding, invitations and applications, ranks, the black board,
 * leaving and dissolving. Membership changes run under one lock so a player can
 * never end up in two alliances.
 */
final readonly class AllianceService
{
    public function __construct(
        private EntityManagerInterface $em,
        private AllianceRepository $alliances,
        private UserRepository $users,
        private Inbox $inbox,
        private LockFactory $lockFactory,
        private ClockInterface $clock,
        private ImageStore $images,
    ) {
    }

    public const int TEXT_LIMIT = 5000;

    public function found(User $founder, string $name, string $tag): Alliance
    {
        $name = trim($name);
        $tag = strtoupper(trim($tag));
        if (mb_strlen($name) < 3 || mb_strlen($name) > 40) {
            throw new GameException('Der Name muss 3 bis 40 Zeichen lang sein.');
        }
        if (!preg_match('/^[A-Z0-9]{2,6}$/', $tag)) {
            throw new GameException('Das Kürzel muss aus 2 bis 6 Buchstaben oder Ziffern bestehen.');
        }

        return $this->locked(function () use ($founder, $name, $tag): Alliance {
            $this->em->refresh($founder);
            if (null !== $founder->getAlliance()) {
                throw new GameException('Du bist bereits in einer Allianz.');
            }
            if (null !== $this->alliances->findOneBy(['name' => $name]) || null !== $this->alliances->findOneBy(['tag' => $tag])) {
                throw new GameException('Name oder Kürzel ist bereits vergeben.');
            }

            $alliance = new Alliance($name, $tag, $founder->getLifeForm(), $this->now());
            $this->em->persist($alliance);
            $founder->joinAlliance($alliance, AllianceRank::Founder);
            $this->removeRequestsOf($founder);
            $this->em->flush();

            return $alliance;
        });
    }

    public function invite(User $actor, string $username): void
    {
        $this->locked(function () use ($actor, $username): void {
            $alliance = $this->managedAlliance($actor);
            $user = $this->users->findOneBy(['username' => trim($username)]) ?? throw new GameException('Diesen Spieler gibt es nicht.');
            if (null !== $user->getAlliance()) {
                throw new GameException(\sprintf('%s ist bereits in einer Allianz.', $user->getUsername()));
            }
            $this->assertSameLifeForm($alliance, $user);

            $existing = $this->alliances->findRequest($alliance, $user);
            if (null !== $existing && !$existing->isInvitation()) {
                // They already applied: an invitation means yes.
                $this->admit($existing);

                return;
            }
            if (null !== $existing) {
                throw new GameException(\sprintf('%s wurde bereits eingeladen.', $user->getUsername()));
            }

            $this->em->persist(new AllianceRequest($alliance, $user, true, $this->now()));
            $this->inbox->send($user, \sprintf('Einladung: %s [%s]', $alliance->getName(), $alliance->getTag()), \sprintf('%s lädt dich in „%s“ (%s) ein. Du kannst die Einladung auf der Allianzseite annehmen oder ablehnen.', $actor->getUsername(), $alliance->getName(), $alliance->getType()));
            $this->em->flush();
        });
    }

    public function apply(User $user, Alliance $alliance): void
    {
        $this->locked(function () use ($user, $alliance): void {
            $this->em->refresh($user);
            if (null !== $user->getAlliance()) {
                throw new GameException('Du bist bereits in einer Allianz.');
            }
            $this->assertSameLifeForm($alliance, $user);
            $existing = $this->alliances->findRequest($alliance, $user);
            if (null !== $existing && $existing->isInvitation()) {
                $this->admit($existing);

                return;
            }
            if (null !== $existing) {
                throw new GameException('Du hast dich dort bereits beworben.');
            }

            $this->em->persist(new AllianceRequest($alliance, $user, false, $this->now()));
            foreach ($alliance->getMembers() as $member) {
                if ($member->getAllianceRank()?->canManage()) {
                    $this->inbox->send($member, 'Neue Bewerbung von '.$user->getUsername(), \sprintf('%s möchte „%s“ beitreten. Du kannst die Bewerbung auf der Allianzseite annehmen oder ablehnen.', $user->getUsername(), $alliance->getName()));
                }
            }
            $this->em->flush();
        });
    }

    /** Accepts an invitation (as the invited player) or an application (as founder). */
    public function accept(User $actor, AllianceRequest $request): void
    {
        $this->locked(function () use ($actor, $request): void {
            $this->assertMayAnswer($actor, $request);
            $this->admit($request);
        });
    }

    public function decline(User $actor, AllianceRequest $request): void
    {
        $this->locked(function () use ($actor, $request): void {
            $this->assertMayAnswer($actor, $request);
            $this->em->remove($request);
            $this->em->flush();
        });
    }

    public function setRank(User $actor, User $member, AllianceRank $rank): void
    {
        $this->locked(function () use ($actor, $member, $rank): void {
            $alliance = $this->managedAlliance($actor);
            $this->assertMember($alliance, $member);
            if ($member->isSameAs($actor) || !\in_array($rank, AllianceRank::assignable(), true)) {
                throw new GameException('Dieser Rang kann so nicht vergeben werden.');
            }
            $this->assertNoRunningVote($alliance, 'Solange über deine Absetzung abgestimmt wird, kannst du keine Ränge ändern.');
            $member->setAllianceRank($rank);
            $this->inbox->send($member, 'Neuer Rang: '.$rank->label(), \sprintf('Du bist jetzt %s in „%s“.', $rank->label(), $alliance->getName()));
            $this->em->flush();
            $this->cleanVotes($alliance);
        });
    }

    /** The founder hands the alliance over and becomes a commander. */
    public function transferFounder(User $actor, User $member): void
    {
        $this->locked(function () use ($actor, $member): void {
            $alliance = $this->managedAlliance($actor);
            $this->assertMember($alliance, $member);
            if ($member->isSameAs($actor)) {
                throw new GameException('Du bist bereits Gründer.');
            }
            $member->setAllianceRank(AllianceRank::Founder);
            $actor->setAllianceRank(AllianceRank::Commander);
            $this->inbox->send($member, 'Du bist jetzt Gründer', \sprintf('%s hat dir die Führung von „%s“ übergeben.', $actor->getUsername(), $alliance->getName()));
            // A new founder ends any running vote.
            foreach ($this->em->getRepository(AllianceVote::class)->findBy(['alliance' => $alliance]) as $vote) {
                $this->em->remove($vote);
            }
            $this->em->flush();
        });
    }

    public function kick(User $actor, User $member): void
    {
        $this->locked(function () use ($actor, $member): void {
            $alliance = $this->managedAlliance($actor);
            $this->assertMember($alliance, $member);
            if ($member->isSameAs($actor)) {
                throw new GameException('Du kannst dich nicht selbst ausschließen.');
            }
            if ($member->getAllianceRank()?->canVoteOnFounder()) {
                $this->assertNoRunningVote($alliance, 'Solange über deine Absetzung abgestimmt wird, kannst du keine Kommandanten ausschließen.');
            }
            $this->removeMember($member);
            $this->inbox->send($member, 'Aus der Allianz ausgeschlossen', \sprintf('Du wurdest aus „%s“ ausgeschlossen.', $alliance->getName()));
            $this->em->flush();
            $this->cleanVotes($alliance);
        });
    }

    public function leave(User $actor): void
    {
        $this->locked(function () use ($actor): void {
            $this->em->refresh($actor);
            $alliance = $actor->getAlliance() ?? throw new GameException('Du bist in keiner Allianz.');
            if ($actor->getAllianceRank()?->canManage() && $alliance->getMembers()->count() > 1) {
                throw new GameException('Übergib zuerst die Gründerrolle oder löse die Allianz auf.');
            }
            $this->removeMember($actor);
            if ($alliance->getMembers()->isEmpty()) {
                $this->images->delete($alliance->getImage(), 'alliances');
                $this->em->remove($alliance);
                $this->em->flush();

                return;
            }
            $this->em->flush();
            $this->cleanVotes($alliance);
        });
    }

    public function dissolve(User $actor): void
    {
        $this->locked(function () use ($actor): void {
            $alliance = $this->managedAlliance($actor);
            foreach ($alliance->getMembers()->toArray() as $member) {
                $this->removeMember($member);
                if (!$member->isSameAs($actor)) {
                    $this->inbox->send($member, 'Allianz aufgelöst', \sprintf('„%s“ wurde von %s aufgelöst.', $alliance->getName(), $actor->getUsername()));
                }
            }
            $this->images->delete($alliance->getImage(), 'alliances');
            $this->em->remove($alliance);
            $this->em->flush();
        });
    }

    /**
     * Votes to depose the founder in favour of $candidate (null withdraws the vote).
     *
     * @return bool true if the founder was deposed by this vote
     */
    public function voteFounder(User $voter, ?User $candidate): bool
    {
        return $this->locked(function () use ($voter, $candidate): bool {
            $this->em->refresh($voter);
            $alliance = $voter->getAlliance() ?? throw new GameException('Du bist in keiner Allianz.');
            if (!$voter->getAllianceRank()?->canVoteOnFounder()) {
                throw new GameException('Nur Kommandanten können über den Gründer abstimmen.');
            }

            $existing = $this->em->getRepository(AllianceVote::class)->findOneBy(['alliance' => $alliance, 'voter' => $voter]);
            if (null === $candidate) {
                if (null !== $existing) {
                    $this->em->remove($existing);
                    $this->em->flush();
                }

                return false;
            }
            if (!$candidate->isAllyOf($voter) || !$candidate->getAllianceRank()?->canVoteOnFounder()) {
                throw new GameException('Zur Wahl stehen nur Kommandanten deiner Allianz.');
            }

            if (null === $existing) {
                $this->em->persist(new AllianceVote($alliance, $voter, $candidate, $this->now()));
            } else {
                $existing->setCandidate($candidate, $this->now());
            }
            $this->em->flush();

            return $this->tally($alliance);
        });
    }

    /**
     * Current state of the founder election.
     *
     * @return array{eligible: int, needed: int, candidates: list<array{user: User, votes: int}>, myVote: ?User}
     */
    public function election(Alliance $alliance, User $viewer): array
    {
        $eligible = \count($this->eligibleVoters($alliance));
        $counts = [];
        $myVote = null;
        foreach ($this->em->getRepository(AllianceVote::class)->findBy(['alliance' => $alliance]) as $vote) {
            $id = $vote->getCandidate()->getId();
            $counts[$id] ??= ['user' => $vote->getCandidate(), 'votes' => 0];
            ++$counts[$id]['votes'];
            if ($vote->getVoter()->isSameAs($viewer)) {
                $myVote = $vote->getCandidate();
            }
        }
        usort($counts, static fn (array $a, array $b): int => $b['votes'] <=> $a['votes']);

        return ['eligible' => $eligible, 'needed' => FounderElection::votesNeeded($eligible), 'candidates' => $counts, 'myVote' => $myVote];
    }

        /** The founder sets picture, internal text (members) and external text (everybody). */
    public function present(User $actor, string $internal, string $external, ?UploadedFile $image, bool $removeImage): void
    {
        $alliance = $this->managedAlliance($actor);
        $alliance->setInternalText(mb_substr(trim($internal), 0, self::TEXT_LIMIT));
        $alliance->setExternalText(mb_substr(trim($external), 0, self::TEXT_LIMIT));

        if (null !== $image) {
            $stored = $this->images->store($image, 'alliances');
            $this->images->delete($alliance->getImage(), 'alliances');
            $alliance->setImage($stored);
        } elseif ($removeImage) {
            $this->images->delete($alliance->getImage(), 'alliances');
            $alliance->setImage(null);
        }
        $this->em->flush();
    }

    public function post(User $author, string $body): void
    {
        $body = trim($body);
        $alliance = $author->getAlliance() ?? throw new GameException('Du bist in keiner Allianz.');
        if (!$author->getAllianceRank()?->canSeeBoard()) {
            throw new GameException('Anwärter haben keinen Zugang zum schwarzen Brett.');
        }
        if ('' === $body || mb_strlen($body) > AlliancePost::MAX_LENGTH) {
            throw new GameException(\sprintf('Ein Beitrag muss 1 bis %d Zeichen lang sein.', AlliancePost::MAX_LENGTH));
        }
        $this->em->persist(new AlliancePost($alliance, $author, $body, $this->now()));
        $this->em->flush();
        $this->trimBoard($alliance);
    }

    public function setTax(User $actor, int $rate): void
    {
        $this->locked(function () use ($actor, $rate): void {
            $alliance = $this->managedAlliance($actor);
            if ($rate < 0 || $rate > Alliance::MAX_TAX) {
                throw new GameException(\sprintf('Der Steuersatz liegt zwischen 0 und %d %%.', Alliance::MAX_TAX));
            }
            $alliance->setTaxRate($rate);
            foreach ($alliance->getMembers() as $member) {
                if (!$member->isSameAs($actor)) {
                    $this->inbox->send($member, 'Allianzsteuer: '.$rate.' %', \sprintf('%s hat die Steuer auf %d %% eures Credit-Einkommens gesetzt. Sie fließt in die Allianzkasse.', $actor->getUsername(), $rate));
                }
            }
            $this->em->flush();
        });
    }

    /** The founder pays credits from the treasury to a member. */
    public function payOut(User $actor, User $member, float $amount, string $note): void
    {
        $this->locked(function () use ($actor, $member, $amount, $note): void {
            $alliance = $this->managedAlliance($actor);
            $this->assertMember($alliance, $member);
            $amount = floor($amount);
            if ($amount < 1) {
                throw new GameException('Gib einen Betrag von mindestens 1 Credit an.');
            }
            $paid = $this->em->getConnection()->executeStatement('UPDATE alliance SET treasury = treasury - ? WHERE id = ? AND treasury >= ?', [$amount, $alliance->getId(), $amount]);
            if (0 === $paid) {
                $this->em->refresh($alliance);
                throw new GameException(\sprintf('In der Allianzkasse sind nur %s Credits.', number_format($alliance->getTreasury(), 0, ',', '.')));
            }
            $this->em->getConnection()->executeStatement('UPDATE `user` SET credits = credits + ? WHERE id = ?', [$amount, $member->getId()]);
            $this->inbox->send($member, 'Auszahlung aus der Allianzkasse', trim(\sprintf("%s hat dir %s Credits aus der Kasse von „%s“ ausgezahlt.\n%s", $actor->getUsername(), number_format($amount, 0, ',', '.'), $alliance->getName(), trim($note))));
            $this->em->flush();
        });
    }

    /** The black board keeps at most 20 posts and none older than a month. */
    public const int BOARD_MAX_POSTS = 20;

    public function trimBoard(Alliance $alliance): void
    {
        $posts = $this->em->getRepository(AlliancePost::class)->findBy(['alliance' => $alliance], ['createdAt' => 'DESC', 'id' => 'DESC']);
        $limit = $this->now()->modify('-1 month');
        foreach ($posts as $i => $post) {
            if ($i >= self::BOARD_MAX_POSTS || $post->getCreatedAt() < $limit) {
                $this->em->remove($post);
            }
        }
        $this->em->flush();
    }

    public function deletePost(User $actor, AlliancePost $post): void
    {
        $sameAlliance = $actor->getAlliance()?->getId() === $post->getAlliance()->getId();
        $own = null !== $post->getAuthor() && $post->getAuthor()->isSameAs($actor);
        if (!$sameAlliance || (!$own && !$actor->getAllianceRank()?->canManage())) {
            throw new GameException('Diesen Beitrag darfst du nicht löschen.');
        }
        $this->em->remove($post);
        $this->em->flush();
    }

    private function admit(AllianceRequest $request): void
    {
        $user = $request->getUser();
        $alliance = $request->getAlliance();
        $this->em->refresh($user);
        if (null !== $user->getAlliance()) {
            $this->em->remove($request);
            $this->em->flush();
            throw new GameException(\sprintf('%s ist inzwischen in einer anderen Allianz.', $user->getUsername()));
        }
        if (!$alliance->admits($user)) {
            $this->em->remove($request);
            $this->em->flush();
            throw new GameException(\sprintf('%s gehört inzwischen zu den %s – in „%s“ sind nur %s.', $user->getUsername(), $user->getLifeForm()->label(), $alliance->getName(), $alliance->getLifeForm()->label()));
        }

        $user->joinAlliance($alliance, AllianceRank::Prospect);
        $this->removeRequestsOf($user);
        $this->inbox->send($user, 'Willkommen in '.$alliance->getName(), \sprintf('Du bist „%s“ [%s] (%s) als Anwärter beigetreten.', $alliance->getName(), $alliance->getTag(), $alliance->getType()));
        $this->em->flush();
    }

    private function assertSameLifeForm(Alliance $alliance, User $user): void
    {
        if (!$alliance->admits($user)) {
            throw new GameException(\sprintf('In „%s“ (%s) sind nur %s. %s gehört zu den %s.', $alliance->getName(), $alliance->getType(), $alliance->getLifeForm()->label(), $user->getUsername(), $user->getLifeForm()->label()));
        }
    }

    /** Leaving ends every fleet release from and to this player. */
    private function removeMember(User $member): void
    {
        foreach ($this->em->getRepository(Fleet::class)->findBy(['owner' => $member]) as $fleet) {
            $fleet->revokeAllReleases();
        }
        $released = $this->em->createQuery('SELECT f FROM App\Entity\Fleet f JOIN f.releasedTo u WHERE u = :member')
            ->setParameter('member', $member)
            ->getResult();
        foreach ($released as $fleet) {
            $fleet->revokeFrom($member);
        }
        foreach ($this->em->getRepository(AllianceVote::class)->findBy(['voter' => $member]) as $vote) {
            $this->em->remove($vote);
        }
        foreach ($this->em->getRepository(AllianceVote::class)->findBy(['candidate' => $member]) as $vote) {
            $this->em->remove($vote);
        }
        $member->leaveAlliance();
    }

    /** Deposes the founder if a successor has the majority. */
    private function tally(Alliance $alliance): bool
    {
        $votes = $this->em->getRepository(AllianceVote::class)->findBy(['alliance' => $alliance]);
        $byCandidate = [];
        $candidates = [];
        foreach ($votes as $vote) {
            $id = $vote->getCandidate()->getId();
            $byCandidate[$id] = ($byCandidate[$id] ?? 0) + 1;
            $candidates[$id] = $vote->getCandidate();
        }

        $winnerId = FounderElection::winner($byCandidate, \count($this->eligibleVoters($alliance)));
        if (null === $winnerId) {
            return false;
        }

        $successor = $candidates[$winnerId];
        $deposed = [];
        foreach ($alliance->getMembers() as $member) {
            if ($member->getAllianceRank()?->canManage()) {
                $deposed[] = $member;
                $member->setAllianceRank(AllianceRank::Member);
                $this->inbox->send($member, 'Als Gründer abgesetzt', \sprintf('Die Mehrheit von „%s“ hat dich als Gründer abgesetzt. Neuer Gründer ist %s, du bist jetzt Mitglied.', $alliance->getName(), $successor->getUsername()));
            }
        }
        $successor->setAllianceRank(AllianceRank::Founder);
        foreach ($votes as $vote) {
            $this->em->remove($vote);
        }
        foreach ($alliance->getMembers() as $member) {
            if (!$member->getAllianceRank()?->canManage() && !\in_array($member, $deposed, true)) {
                $this->inbox->send($member, 'Neuer Gründer: '.$successor->getUsername(), \sprintf('Per Mehrheitsbeschluss ist %s jetzt Gründer von „%s“.', $successor->getUsername(), $alliance->getName()));
            }
        }
        $this->inbox->send($successor, 'Du bist jetzt Gründer', \sprintf('Die Mehrheit von „%s“ hat dich zum neuen Gründer gewählt.', $alliance->getName()));
        $this->em->flush();

        return true;
    }

    /** The founder can't change who votes while a vote to depose them is running. */
    private function assertNoRunningVote(Alliance $alliance, string $message): void
    {
        if ($this->em->getRepository(AllianceVote::class)->count(['alliance' => $alliance]) > 0) {
            throw new GameException($message);
        }
    }

    /** @return list<User> */
    private function eligibleVoters(Alliance $alliance): array
    {
        return array_values(array_filter(
            $alliance->getMembers()->toArray(),
            static fn (User $member): bool => true === $member->getAllianceRank()?->canVoteOnFounder(),
        ));
    }

    /** Drops votes by or for players who left or lost the right to vote, then recounts. */
    private function cleanVotes(Alliance $alliance): void
    {
        foreach ($this->em->getRepository(AllianceVote::class)->findBy(['alliance' => $alliance]) as $vote) {
            foreach ([$vote->getVoter(), $vote->getCandidate()] as $user) {
                if ($user->getAlliance()?->getId() !== $alliance->getId() || !$user->getAllianceRank()?->canVoteOnFounder()) {
                    $this->em->remove($vote);
                    break;
                }
            }
        }
        $this->em->flush();
        $this->tally($alliance);
    }

    private function removeRequestsOf(User $user): void
    {
        foreach ($this->em->getRepository(AllianceRequest::class)->findBy(['user' => $user]) as $request) {
            $this->em->remove($request);
        }
    }

    private function assertMayAnswer(User $actor, AllianceRequest $request): void
    {
        $allowed = $request->isInvitation()
            ? $request->getUser()->isSameAs($actor)
            : $actor->getAlliance()?->getId() === $request->getAlliance()->getId() && $actor->getAllianceRank()?->canManage();
        if (!$allowed) {
            throw new GameException('Darauf kannst du nicht antworten.');
        }
    }

    private function managedAlliance(User $actor): Alliance
    {
        $this->em->refresh($actor);
        $alliance = $actor->getAlliance();
        if (null === $alliance || !$actor->getAllianceRank()?->canManage()) {
            throw new GameException('Das darf nur der Gründer.');
        }

        return $alliance;
    }

    private function assertMember(Alliance $alliance, User $member): void
    {
        if ($member->getAlliance()?->getId() !== $alliance->getId()) {
            throw new GameException(\sprintf('%s ist nicht in deiner Allianz.', $member->getUsername()));
        }
    }

    /**
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    private function locked(callable $operation): mixed
    {
        $lock = $this->lockFactory->createLock('alliance_membership');
        $lock->acquire(true);
        try {
            return $operation();
        } finally {
            $lock->release();
        }
    }

    private function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now());
    }
}
