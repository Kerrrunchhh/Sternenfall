<?php

namespace App\Tests\Game;

use App\Entity\Alliance;
use App\Entity\AllianceRequest;
use App\Entity\User;
use App\Game\AllianceRank;
use App\Game\AllianceService;
use App\Game\GameException;
use App\Game\ImageStore;
use App\Game\Inbox;
use App\Game\LifeForm;
use App\Repository\AllianceRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Validator\Validation;

/** An application needs a letter, goes to one alliance at a time and keeps its status for the applicant. */
class AllianceApplicationTest extends TestCase
{
    private MockClock $clock;
    private User $applicant;
    private Alliance $north;
    private Alliance $south;
    private User $northFounder;
    /** @var list<AllianceRequest> what the fake database holds */
    private array $requests = [];

    protected function setUp(): void
    {
        $now = new \DateTimeImmutable('2026-11-15 10:00:00');
        $this->clock = new MockClock($now);
        $this->applicant = $this->user('Bewerberin', 1);
        $this->northFounder = $this->user('Nordlicht', 2);
        $this->north = $this->alliance('Nordbund', 'NRD', 10);
        $this->south = $this->alliance('Suedbund', 'SUD', 11);
        $this->northFounder->joinAlliance($this->north, AllianceRank::Founder);
    }

    public function testNoApplicationWithoutALetter(): void
    {
        $service = $this->service();

        $this->assertRefused(fn () => $service->apply($this->applicant, $this->north, '   '));
        $this->assertRefused(fn () => $service->apply($this->applicant, $this->north, str_repeat('x', AllianceRequest::MAX_MESSAGE + 1)), 'too long');
        self::assertSame([], $this->requests);

        $service->apply($this->applicant, $this->north, '  Ich baue gern Schiffe. ');
        self::assertCount(1, $this->requests);
        self::assertSame('Ich baue gern Schiffe.', $this->requests[0]->getMessage());
        self::assertTrue($this->requests[0]->isOpen());
    }

    public function testOnlyOneOpenApplicationAtATime(): void
    {
        $service = $this->service();
        $service->apply($this->applicant, $this->north, 'Hallo Nord');

        $this->assertRefused(fn () => $service->apply($this->applicant, $this->south, 'Hallo Süd'), 'already applied to the north');
        $this->assertRefused(fn () => $service->apply($this->applicant, $this->north, 'Nochmal'), 'already applied here');
        self::assertCount(1, $this->requests);

        $service->withdrawApplication($this->applicant);
        self::assertSame([], $this->requests);
        $service->apply($this->applicant, $this->south, 'Hallo Süd');
        self::assertSame('Suedbund', $this->requests[0]->getAlliance()->getName());
    }

    public function testADeclinedApplicationStaysVisibleAndFreesTheWay(): void
    {
        $service = $this->service();
        $service->apply($this->applicant, $this->north, 'Hallo Nord');
        $application = $this->requests[0];

        $this->clock->modify('+1 hour');
        $service->decline($this->northFounder, $application);

        self::assertSame([$application], $this->requests, 'the applicant still sees the answer');
        self::assertFalse($application->isOpen());
        self::assertEquals($this->clock->now(), $application->getDeclinedAt());
        $this->assertRefused(fn () => $service->accept($this->northFounder, $application), 'a declined application is done');

        $service->apply($this->applicant, $this->south, 'Hallo Süd');
        self::assertCount(1, $this->requests, 'the declined one made room');
        self::assertSame('Suedbund', $this->requests[0]->getAlliance()->getName());
        self::assertTrue($this->requests[0]->isOpen());
    }

    private function service(): AllianceService
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $entity): void {
            if ($entity instanceof AllianceRequest) {
                $this->requests[] = $entity;
            }
        });
        $em->method('remove')->willReturnCallback(function (object $entity): void {
            $this->requests = array_values(array_filter($this->requests, fn (AllianceRequest $r): bool => $r !== $entity));
        });
        $alliances = $this->createStub(AllianceRepository::class);
        $alliances->method('findApplicationOf')->willReturnCallback(fn (User $user): ?AllianceRequest => array_values(array_filter($this->requests, fn (AllianceRequest $r): bool => !$r->isInvitation() && $r->getUser() === $user))[0] ?? null);
        $alliances->method('findRequest')->willReturnCallback(fn (Alliance $alliance, User $user): ?AllianceRequest => array_values(array_filter($this->requests, fn (AllianceRequest $r): bool => $r->getAlliance() === $alliance && $r->getUser() === $user))[0] ?? null);

        return new AllianceService($em, $alliances, $this->createStub(UserRepository::class), new Inbox($em, $this->clock), new LockFactory(new InMemoryStore()), $this->clock, new ImageStore(Validation::createValidator(), sys_get_temp_dir()));
    }

    private function user(string $name, int $id): User
    {
        $user = new User($name, $this->clock->now());
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);

        return $user;
    }

    private function alliance(string $name, string $tag, int $id): Alliance
    {
        $alliance = new Alliance($name, $tag, LifeForm::Settler, $this->clock->now());
        (new \ReflectionProperty(Alliance::class, 'id'))->setValue($alliance, $id);

        return $alliance;
    }

    private function assertRefused(callable $action, string $message = ''): void
    {
        try {
            $action();
            self::fail('expected a refusal: '.$message);
        } catch (GameException) {
            $this->addToAssertionCount(1);
        }
    }
}
