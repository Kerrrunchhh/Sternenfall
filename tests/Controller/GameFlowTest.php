<?php

namespace App\Tests\Controller;

use App\Entity\Planet;
use App\Entity\User;
use App\Game\BuildingType;
use App\Game\ResearchType;
use App\Game\Resources;
use App\Game\ShipType;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

class GameFlowTest extends WebTestCase
{
    use ClockSensitiveTrait;

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

    public function testRegistrationCreatesHomePlanetAndLogsIn(): void
    {
        $this->register('Kommandant');

        self::assertResponseRedirects('/overview');
        $this->client->followRedirect();
        self::assertSelectorTextContains('h1', 'Heimatwelt');
        self::assertSelectorTextContains('.flash-success', 'Willkommen');

        $user = static::getContainer()->get(UserRepository::class)->findOneBy(['username' => 'Kommandant']);
        self::assertNotNull($user);
        self::assertNotNull($user->getTermsAcceptedAt(), 'the terms were accepted at sign-up');
        self::assertCount(1, $user->getPlanets());
        self::assertSame(1, $user->getPlanets()->first()->getBuildingLevel(BuildingType::CommandCenter));
        self::assertSame(1, $user->getPlanets()->first()->getBuildingLevel(BuildingType::BioCell));
        self::assertSame(100.0, $user->getPlanets()->first()->getPopulation());
    }

    public function testDuplicateUsernameIsRejected(): void
    {
        $this->register('Doppelt');
        $this->client->request('GET', '/logout');

        $this->register('Doppelt');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.field-error', 'bereits vergeben');
    }

    public function testSearchEnginesGetRobotsSitemapAndMetaTags(): void
    {
        $this->client->request('GET', '/robots.txt');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Disallow: /', (string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('Sitemap: http://localhost/sitemap.xml', (string) $this->client->getResponse()->getContent());

        $this->client->request('GET', '/sitemap.xml');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('<loc>http://localhost/status</loc>', (string) $this->client->getResponse()->getContent());

        $this->client->request('GET', '/');
        self::assertSelectorExists('meta[name="description"]');
        self::assertSelectorExists('link[rel="canonical"]');
        self::assertSelectorExists('meta[property="og:image"]');
        self::assertSelectorExists('script[type="application/ld+json"]');

        $this->client->request('GET', '/login');
        self::assertSelectorExists('meta[name="robots"][content="noindex, nofollow"]');
    }

    public function testGameRequiresLogin(): void
    {
        $this->client->request('GET', '/buildings');

        self::assertResponseRedirects('/login');
    }

    public function testLogin(): void
    {
        $this->register('Pilotin');
        $this->client->request('GET', '/logout');

        $this->client->request('GET', '/login');
        $this->client->submitForm('Anmelden', ['_username' => 'Pilotin', '_password' => 'geheim12345']);

        self::assertResponseRedirects('/overview');
    }

    public function testBuildingDeductsResourcesAndCompletesOverTime(): void
    {
        $clock = static::mockTime('2026-01-01 12:00:00');
        $this->register('Baumeister');

        $this->client->request('GET', '/buildings');
        $this->client->submitForm('build-iron_mine');
        self::assertResponseRedirects('/buildings');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.queue', 'Eisenmine → Stufe 1');

        $planet = $this->planet('Baumeister');
        self::assertTrue($planet->isConstructing());
        self::assertEqualsWithDelta(500 - 60, $planet->getResources()->iron, 0.001);

        // A second upgrade of the same building is queued behind the first one, at the next level.
        $this->client->request('POST', '/buildings/iron_mine/build', ['_token' => $this->buildToken()]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'eingereiht');
        self::assertSelectorTextContains('.queue', 'Eisenmine → Stufe 2');
        self::assertCount(2, $this->planet('Baumeister')->getBuildingQueue());

        $clock->sleep(86400);
        $this->client->request('GET', '/overview');

        $planet = $this->planet('Baumeister');
        self::assertFalse($planet->isConstructing());
        self::assertSame(2, $planet->getBuildingLevel(BuildingType::IronMine));
        // Level 2 was the second entry of the queue: 90 iron + 30 % surcharge = 117.
        self::assertGreaterThan(500 - 60 - 117, $planet->getResources()->iron);
    }

    public function testQueueCanBeReordered(): void
    {
        static::mockTime('2026-01-01 12:00:00');
        $this->register('Planerin');

        foreach (['iron_mine', 'water_pump', 'farm'] as $type) {
            $this->client->request('POST', "/buildings/$type/build", ['_token' => $this->buildToken()]);
        }
        // Only two upgrades fit into the building queue; the third was rejected.
        $queue = $this->planet('Planerin')->getBuildingQueue();
        self::assertCount(2, $queue);
        $ids = array_map(static fn ($order) => $order->getId(), $queue);

        $crawler = $this->client->request('GET', '/buildings');
        $token = $crawler->filter('.queue-form input[name="_token"]')->attr('value');
        $planetId = $queue[0]->getPlanet()->getId();
        $this->client->request('POST', "/planet/$planetId/queue/buildings/reorder", [
            '_token' => $token,
            'to' => 'app_buildings',
            'order' => [$ids[1], $ids[0]],
        ]);
        self::assertResponseRedirects('/buildings');

        $queue = $this->planet('Planerin')->getBuildingQueue();
        self::assertSame([BuildingType::WaterPump, BuildingType::IronMine], array_map(static fn ($o) => $o->getBuildingType(), $queue));
        self::assertTrue($queue[0]->isStarted());
        self::assertFalse($queue[1]->isStarted());
    }

    public function testCancelRefundsResources(): void
    {
        static::mockTime('2026-01-01 12:00:00');
        $this->register('Zauderer');

        $this->client->request('GET', '/buildings');
        $this->client->submitForm('build-iron_mine');
        $order = $this->planet('Zauderer')->getBuildingQueue()[0];
        $crawler = $this->client->request('GET', '/buildings');
        $token = $crawler->filter('.queue-form input[name="_token"]')->attr('value');
        $this->client->request('POST', \sprintf('/planet/%d/queue/buildings/%d/cancel', $order->getPlanet()->getId(), $order->getId()), ['_token' => $token, 'to' => 'app_buildings']);
        $this->client->followRedirect();

        self::assertSelectorTextContains('.flash-success', 'abgebrochen');
        $planet = $this->planet('Zauderer');
        self::assertFalse($planet->isConstructing());
        self::assertEqualsWithDelta(500, $planet->getResources()->iron, 0.001);
    }

    public function testMissingRequirementsAndInvalidTokenAreRejected(): void
    {
        $this->register('Ungeduld');

        $this->client->request('POST', '/buildings/shipyard/build', ['_token' => $this->buildToken()]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'Voraussetzungen fehlen');

        // An invalid CSRF token is an authentication failure: nothing happens, the player is sent to the login.
        $this->client->request('POST', '/buildings/iron_mine/build', ['_token' => 'falsch']);
        self::assertResponseRedirects('/login');
        self::assertFalse($this->planet('Ungeduld')->isConstructing());
    }

    public function testResearchAndShipyard(): void
    {
        $clock = static::mockTime('2026-01-01 12:00:00');
        $this->register('Admiralin');

        // Fast-forward the colony: lab, shipyard and plenty of resources.
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $planet = $this->planet('Admiralin');
        $planet->setBuildingLevel(BuildingType::ResearchLab, 1)->setBuildingLevel(BuildingType::Shipyard, 1);
        $planet->setResources(new Resources(iron: 100_000, titanium: 100_000, food: 1000, water: 1000, hydrogen: 10_000));
        $em->flush();

        $this->client->request('GET', '/research');
        $this->client->submitForm('research-solid_drive');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.construction', 'Feststoffantrieb (Stufe 1)');

        $clock->sleep(86400);
        $this->client->request('GET', '/shipyard');
        $this->client->submitForm('order-freighter', ['quantity' => 3]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', '3 × Frachter');
        self::assertSelectorTextContains('.queue', 'Frachter');

        $planet = $this->planet('Admiralin');
        self::assertSame(1, $planet->getOwner()->getResearchLevel(ResearchType::SolidDrive));
        $populationAfterOrder = $planet->getPopulation();

        $clock->sleep(86400);
        $this->client->request('GET', '/overview');
        self::assertSelectorTextContains('body', 'Frachter');

        $planet = $this->planet('Admiralin');
        self::assertSame(3, $planet->getShipCount(ShipType::Freighter));
        self::assertCount(0, $planet->getShipyardOrders());
        self::assertLessThan(250, $populationAfterOrder, 'crew of 3 × 3 was taken from the population');
    }

    public function testShipOrderNeedsEnoughPopulation(): void
    {
        $this->register('Kleinstadt');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $planet = $this->planet('Kleinstadt');
        $planet->setBuildingLevel(BuildingType::Shipyard, 1);
        $planet->getOwner()->setResearchLevel(ResearchType::SolidDrive, 1);
        $planet->setResources(new Resources(iron: 1_000_000, titanium: 1_000_000, food: 1000, water: 1000));
        $em->flush();

        $this->client->request('GET', '/shipyard');
        $this->client->submitForm('order-freighter', ['quantity' => 1000]);
        $this->client->followRedirect();

        self::assertSelectorTextContains('.flash-error', 'Nicht genügend Bevölkerung');
        self::assertSame(0, $this->planet('Kleinstadt')->getShipCount(ShipType::Freighter));
    }

    public function testColonizeAndReturn(): void
    {
        $clock = static::mockTime('2026-01-01 12:00:00');
        $this->register('Siedlerin');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $home = $this->planet('Siedlerin');
        $home->addShips(ShipType::ColonyShip, 1)->addShips(ShipType::Freighter, 1);
        $home->setResources(new Resources(iron: 50_000, titanium: 50_000, food: 5000, water: 5000, hydrogen: 50_000));
        $em->flush();

        // Ships only move as a fleet: form one first.
        $this->client->request('GET', '/fleet');
        $this->client->submitForm('fleet-create', [
            'name' => 'Siedlerzug',
            'ships[colony_ship]' => 1,
            'ships[freighter]' => 1,
        ]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Siedlerzug');
        $fleet = static::getContainer()->get(\App\Repository\FleetRepository::class)->findOneBy(['name' => 'Siedlerzug']);
        self::assertSame(0, $this->planet('Siedlerin')->getShipCount(ShipType::ColonyShip), 'ships left the hangar');

        // A free slot right next to the home planet.
        $position = $home->getPosition() > 1 ? $home->getPosition() - 1 : $home->getPosition() + 1;
        $this->client->request('GET', '/fleet?mission=colonize&position='.$position);
        $this->client->submitForm('fleet-'.$fleet->getId().'-send', ['cargo[iron]' => 1000]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Kolonisieren');

        $clock->sleep(86400);
        $this->client->request('GET', '/overview');

        $em->clear();
        $user = $em->getRepository(User::class)->findOneBy(['username' => 'Siedlerin']);
        self::assertCount(2, $user->getPlanets());
        $colony = $user->getPlanets()->last();
        self::assertSame($position, $colony->getPosition());
        self::assertSame(1000.0, $colony->getResources()->iron);
        self::assertSame(100.0, $colony->getPopulation());

        // The colony ship was used up; the freighter is back home as the (still named) fleet.
        $fleet = $em->getRepository(\App\Entity\Fleet::class)->findOneBy(['name' => 'Siedlerzug']);
        self::assertTrue($fleet->isIdle());
        self::assertSame(['freighter' => 1], $fleet->getShips());
        self::assertSame($home->getCoordinates(), $fleet->getCoordinates());

        $this->client->request('GET', '/messages');
        self::assertSelectorTextContains('.message', 'Neue Kolonie');
    }

    public function testNewPlayersSeeTheWelcomeNewsUntilTheyMarkItRead(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $welcome = new \App\Entity\News(new \DateTimeImmutable('-1 year'));
        $welcome->setTitle('Willkommen!');
        $welcome->setBody('Schön, dass du da bist.');
        $welcome->setWelcome(true);
        $old = new \App\Entity\News(new \DateTimeImmutable('-1 year'));
        $old->setTitle('Alte Meldung');
        $old->setBody('Von vor deiner Zeit.');
        $em->persist($welcome);
        $em->persist($old);
        $em->flush();

        $this->register('Neuling2');
        $crawler = $this->client->request('GET', '/overview');
        self::assertSelectorTextContains('.news-unread h2', 'Willkommen!');
        self::assertSelectorNotExists('#news-'.$old->getId().'.news-unread', 'news from before joining are not pushed');

        $this->client->submit($crawler->filter('#news-read-'.$welcome->getId())->form());
        $this->client->followRedirect();
        self::assertSelectorNotExists('.news-unread');

        $this->client->request('GET', '/neuigkeiten');
        self::assertSelectorTextContains('#news-'.$welcome->getId().' h2', 'Willkommen!', 'read news stay in the archive');
    }

    public function testEmpirePageListsEveryPlanetWithTotals(): void
    {
        $this->register('Imperator');
        $home = $this->planet('Imperator');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $colony = new Planet($home->getOwner(), 'Kolonie', 9, 9, 9, new \DateTimeImmutable());
        $colony->setBuildingLevel(BuildingType::IronMine, 3);
        $em->persist($colony);
        $em->flush();

        $this->client->request('GET', '/imperium');

        self::assertResponseIsSuccessful();
        self::assertSelectorCount(6, 'tbody th.empire-planet', 'two planets in each of the three tables');
        self::assertSelectorTextContains('.empire-buildings tbody', 'Kolonie');
        self::assertSelectorTextContains('.empire-buildings tfoot', (string) (3 + $home->getBuildingLevel(BuildingType::IronMine)));
    }

    public function testGuideShowsQuickStartAndTheEarlyTechTree(): void
    {
        $this->register('Lehrling');

        $this->client->request('GET', '/spielhilfe');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.guide-steps li');
        self::assertSelectorTextContains('.tech-tree', 'Raumwerft');
        self::assertSelectorExists('.tech-node.tech-locked', 'a new player still misses things');
    }

    public function testTransportRequestCostsAFixedPrice(): void
    {
        $this->register('Neuling');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $player = $this->planet('Neuling')->getOwner();
        $before = $player->getCredits();

        $this->client->request('GET', '/transportgesuche');
        self::assertSelectorNotExists('input[name="offer"]');
        $this->client->submitForm('transport-request-create', ['goods[iron]' => 500, 'goods[water]' => 205]);
        self::assertResponseRedirects('/transportgesuche');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Transportgesuch eingestellt');

        $em->clear();
        $player = $em->getRepository(User::class)->findOneBy(['username' => 'Neuling']);
        self::assertEqualsWithDelta($before - 71, $player->getCredits(), 0.001, '705 resources cost 71 credits');
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

    private function buildToken(): string
    {
        $crawler = $this->client->request('GET', '/buildings');

        return $crawler->filter('form[action$="/build"] input[name="_token"]')->first()->attr('value');
    }

    private function planet(string $username): Planet
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $user = $em->getRepository(User::class)->findOneBy(['username' => $username]);

        return $user->getPlanets()->first();
    }
}
