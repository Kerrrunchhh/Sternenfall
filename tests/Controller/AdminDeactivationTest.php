<?php

namespace App\Tests\Controller;

use App\Entity\User;
use App\Game\AccountDeletion;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class AdminDeactivationTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $schemaTool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
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

        $this->client->request('GET', '/highscores');
        self::assertStringNotContainsString('Opfer', (string) $this->client->getResponse()->getContent());
        $this->client->request('GET', '/spieler', ['q' => 'Opf']);
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
    }

    private function register(string $username): void
    {
        $this->client->request('GET', '/register');
        $this->client->submitForm('Kolonie gründen', [
            'registration_form[username]' => $username,
            'registration_form[plainPassword][first]' => 'geheim12345',
            'registration_form[plainPassword][second]' => 'geheim12345',
            'registration_form[agreeTerms]' => '1',
        ]);
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
