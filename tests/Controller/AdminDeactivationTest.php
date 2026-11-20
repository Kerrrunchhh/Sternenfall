<?php

namespace App\Tests\Controller;

use App\Entity\User;
use App\Game\AccountDeletion;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class AdminDeactivationTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient([], ['HTTP_ACCEPT_LANGUAGE' => 'de']);
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');

        // The schema comes from the migrations; every test is rolled back afterwards (DAMA DoctrineTestBundle).
    }

    public function testAdminDeactivatesAndReactivatesAPlayer(): void
    {
        $this->register('Opfer');
        $this->client->request('GET', '/logout');
        $this->register('Chefin');
        $this->client->request('GET', '/logout');
        $admin = $this->user('Chefin');
        $admin->setRoles(['ROLE_ADMIN']);
        $this->em()->flush();
        $home = $this->user('Opfer')->getPlanets()->first();
        $homeId = $home->getId();

        // A session the player still has open ends with his next click.
        $this->client->loginUser($this->user('Opfer'));
        $this->client->request('GET', '/overview');
        self::assertResponseIsSuccessful();

        $this->client->loginUser($this->user('Chefin'));
        // The profile leads admins to the player in the admin area, opened right away.
        $crawler = $this->client->request('GET', '/player/'.$this->user('Opfer')->getId());
        $crawler = $this->client->click($crawler->filter('#profile-admin')->link());
        self::assertCount(1, $crawler->filter('details.admin-user[open]'));
        self::assertCount(1, $crawler->filter('details[open] input[name="do"][value="deactivate"]'));

        $this->act('Opfer', ['do' => 'deactivate', 'planets' => 'leave']);

        $victim = $this->user('Opfer');
        self::assertTrue($victim->isDeactivated());
        self::assertCount(0, $victim->getPlanets());
        self::assertSame(AccountDeletion::NOBODY, $this->em()->find(\App\Entity\Planet::class, $homeId)->getOwner()->getUsername());
        $status = static::getContainer()->get(\App\Game\GameStats::class)->status();
        self::assertSame(1, $status['players'], 'only Chefin is left');
        self::assertSame(1, $status['planets'], 'planets of players only');
        self::assertSame(1, $status['ownerless']);

        $this->client->request('GET', '/highscores');
        self::assertStringNotContainsString('Opfer', (string) $this->client->getResponse()->getContent());
        $this->client->request('GET', '/suche', ['q' => 'Opf']);
        self::assertStringNotContainsString('Opfer', (string) $this->client->getResponse()->getContent());

        $this->client->loginUser($this->user('Opfer'));
        $this->client->request('GET', '/overview');
        self::assertResponseRedirects('/login');

        $this->client->request('GET', '/login');
        $this->client->submitForm('Anmelden', ['_username' => 'Opfer', '_password' => 'geheim12345']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Dieses Konto wurde von der Spielleitung deaktiviert.');

        $this->client->loginUser($this->user('Chefin'));
        $this->act('Opfer', ['do' => 'reactivate']);

        $victim = $this->user('Opfer');
        self::assertFalse($victim->isDeactivated());
        self::assertCount(1, $victim->getPlanets());
        self::assertNotSame($homeId, $victim->getPlanets()->first()->getId());

        $this->client->request('GET', '/logout');
        $this->client->request('GET', '/login');
        $this->client->submitForm('Anmelden', ['_username' => 'Opfer', '_password' => 'geheim12345']);
        self::assertResponseRedirects('/overview');
    }

    /** @param array<string, string> $fields */
    private function act(string $username, array $fields): void
    {
        $crawler = $this->client->request('GET', '/admin/benutzer', ['q' => $username]);
        $token = $crawler->filter('input[name="_token"]')->first()->attr('value');
        $this->client->request('POST', '/admin/benutzer/'.$this->user($username)->getId(), ['_token' => $token] + $fields);
        self::assertResponseRedirects();
        // Shows the confirmation, so it doesn't turn up on the next page.
        $this->client->followRedirect();
    }

    /** Signs up, confirms the e-mail address (that creates the home world) and logs in – as a new player does. */
    private function register(string $username): void
    {
        $this->signUp($username);
        $this->confirmAndLogIn($username);
    }

    /** Only the sign-up form; playing starts after the confirmation (confirmAndLogIn). */
    private function signUp(string $username, array $extra = []): void
    {
        $this->client->request('GET', '/register');
        $this->client->submitForm('Kolonie gründen', [
            'registration_form[username]' => $username,
            'registration_form[email]' => strtolower($username).'@example.com',
            'registration_form[plainPassword][first]' => 'geheim12345',
            'registration_form[plainPassword][second]' => 'geheim12345',
            'registration_form[agreeTerms]' => '1',
        ] + $extra);
    }

    /** Opens the link from the confirmation mail, then logs in with the sign-up password. */
    private function confirmAndLogIn(string $username): void
    {
        $user = static::getContainer()->get(\App\Repository\UserRepository::class)->findOneBy(['username' => $username]);
        $link = static::getContainer()->get(\Symfony\Component\HttpFoundation\UriSigner::class)->sign(
            static::getContainer()->get('router')->generate('app_verify_email', ['id' => $user->getId(), 'email' => $user->getEmail()], \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL),
            new \DateInterval('P2D'),
        );
        $this->client->request('GET', $link);
        $this->client->request('GET', '/login');
        $this->client->submitForm('Anmelden', ['_username' => $username, '_password' => 'geheim12345']);
    }

    private function user(string $username): User
    {
        $this->em()->clear();

        return $this->em()->getRepository(User::class)->findOneBy(['username' => $username]);
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
