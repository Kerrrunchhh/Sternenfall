<?php

namespace App\Tests\Game;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\PasswordReset;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** "Passwort vergessen": the link works for one hour and only once. */
final class PasswordResetTest extends TestCase
{
    public function testTheLinkWorksOnlyOnceAndOnlyForAnHour(): void
    {
        $clock = new MockClock('2026-11-13 10:00:00');
        $user = new User('vergesslich');
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, 7);
        $user->setPassword('$2y$13$alterHash');

        $users = $this->createStub(UserRepository::class);
        $users->method('find')->willReturnCallback(static fn (int $id): ?User => 7 === $id ? $user : null);
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturnCallback(static fn (string $route, array $p): string => 'https://example.org/passwort/neu?'.http_build_query($p));
        $signer = new UriSigner('geheim', clock: $clock);
        $reset = new PasswordReset($signer, $urls, $users);

        $link = $reset->link($user);
        self::assertSame($user, $reset->userFor(Request::create($link)), 'a fresh link is valid');
        self::assertNull($reset->userFor(Request::create(str_replace('id=7', 'id=8', $link))), 'a forged link is not');

        $clock->sleep(3601);
        self::assertNull($reset->userFor(Request::create($link)), 'after an hour it has expired');

        $clock->sleep(-3601);
        $user->setPassword('$2y$13$neuerHash');
        self::assertNull($reset->userFor(Request::create($link)), 'once the password is changed the link is used up');
    }
}
