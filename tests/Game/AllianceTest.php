<?php

namespace App\Tests\Game;

use App\Entity\Alliance;
use App\Entity\Fleet;
use App\Entity\User;
use App\Game\AllianceRank;
use App\Game\FounderElection;
use App\Game\LifeForm;
use PHPUnit\Framework\TestCase;

class AllianceTest extends TestCase
{
    public function testAllianceTypeFollowsFoundersLifeForm(): void
    {
        $now = new \DateTimeImmutable();
        self::assertSame('Orden', (new Alliance('A', 'AA', LifeForm::Guardian, $now))->getType());
        self::assertSame('Syndikat', (new Alliance('B', 'BB', LifeForm::Pirate, $now))->getType());
        self::assertSame('Handelsorganisation', (new Alliance('C', 'CC', LifeForm::Trader, $now))->getType());
        self::assertSame('Allianz', (new Alliance('D', 'DD', LifeForm::Settler, $now))->getType());
    }

    public function testRankPermissions(): void
    {
        self::assertTrue(AllianceRank::Founder->canManage());
        self::assertFalse(AllianceRank::Commander->canManage());

        self::assertTrue(AllianceRank::Founder->hasFleetRights());
        self::assertTrue(AllianceRank::Commander->hasFleetRights());
        self::assertFalse(AllianceRank::Member->hasFleetRights());
        self::assertFalse(AllianceRank::Prospect->hasFleetRights());

        self::assertTrue(AllianceRank::Member->canSeeBoard());
        self::assertFalse(AllianceRank::Prospect->canSeeBoard());
    }

    public function testFleetReleaseRules(): void
    {
        $alliance = new Alliance('Orden', 'ORD', LifeForm::Guardian, new \DateTimeImmutable());
        $owner = $this->member($alliance, 'owner', AllianceRank::Member);
        $commander = $this->member($alliance, 'commander', AllianceRank::Commander);
        $member = $this->member($alliance, 'member', AllianceRank::Member);
        $outsider = new User('outsider');

        $fleet = new Fleet($owner, 'Wache', 1, 1, 1, ['pfeil' => 5]);
        self::assertTrue($fleet->isControllableBy($owner));
        self::assertFalse($fleet->isControllableBy($commander), 'nothing released yet');

        $fleet->setReleasedToAlliance(true);
        self::assertTrue($fleet->isControllableBy($commander), 'commanders have fleet rights');
        self::assertFalse($fleet->isControllableBy($member), 'plain members have no fleet rights');
        self::assertFalse($fleet->isControllableBy($outsider));

        $fleet->releaseTo($member);
        self::assertTrue($fleet->isControllableBy($member), 'individual release');

        // Releases only count inside the same alliance.
        $member->leaveAlliance();
        self::assertFalse($fleet->isControllableBy($member));

        $fleet->revokeAllReleases();
        self::assertFalse($fleet->isControllableBy($commander));
        self::assertTrue($fleet->getReleasedTo()->isEmpty());
    }

    public function testFounderElectionNeedsMoreThanHalfOfAllVoters(): void
    {
        self::assertSame(1, FounderElection::votesNeeded(1));
        self::assertSame(2, FounderElection::votesNeeded(2));
        self::assertSame(2, FounderElection::votesNeeded(3));
        self::assertSame(3, FounderElection::votesNeeded(4));

        // 4 voters: 2 votes for candidate 7 are not enough, 3 are.
        self::assertNull(FounderElection::winner([7 => 2, 8 => 1], 4));
        self::assertSame(7, FounderElection::winner([7 => 3, 8 => 1], 4));
        self::assertNull(FounderElection::winner([], 0));

        // Only the rank directly below the founder votes.
        self::assertTrue(AllianceRank::Commander->canVoteOnFounder());
        self::assertFalse(AllianceRank::Member->canVoteOnFounder());
        self::assertFalse(AllianceRank::Prospect->canVoteOnFounder());
        self::assertFalse(AllianceRank::Founder->canVoteOnFounder());
    }

    private function member(Alliance $alliance, string $name, AllianceRank $rank): User
    {
        $user = new User($name);
        $user->joinAlliance($alliance, $rank);

        return $user;
    }
}
