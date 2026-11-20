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
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

class GameFlowTest extends WebTestCase
{
    use ClockSensitiveTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient([], ['HTTP_ACCEPT_LANGUAGE' => 'de']);
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');

        // The schema comes from the migrations; every test is rolled back afterwards (DAMA DoctrineTestBundle).
    }

    public function testRegistrationCreatesHomePlanetAndLogsIn(): void
    {
        $this->signUp('Kommandant');
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->user('Kommandant')->getPlanets()->toArray(), 'the home world waits for the confirmed address');

        $this->confirmAndLogIn('Kommandant');
        self::assertResponseRedirects();
        $this->client->request('GET', '/overview');
        self::assertSelectorTextContains('h1', 'Heimatwelt');
        // Phones: the most used pages sit in a tab bar at the top, "Menü" opens the whole Imperium menu.
        self::assertSelectorExists('.mobile-tabs a[href="/fleet"]');
        self::assertSelectorExists('.mobile-tabs a[href="/trade"]');
        self::assertSelectorExists('.mobile-tabs a[href="#imperium"][data-menu-open]');
        self::assertSelectorExists('nav#imperium [data-menu-close]');

        $user = static::getContainer()->get(UserRepository::class)->findOneBy(['username' => 'Kommandant']);
        self::assertNotNull($user);
        self::assertNotNull($user->getTermsAcceptedAt(), 'the terms were accepted at sign-up');
        self::assertCount(1, $user->getPlanets());
        self::assertSame(1, $user->getPlanets()->first()->getBuildingLevel(BuildingType::CommandCenter));
        self::assertSame(1, $user->getPlanets()->first()->getBuildingLevel(BuildingType::BioCell));
        self::assertEqualsWithDelta(100.0, $user->getPlanets()->first()->getPopulation(), 1.0, 'it starts growing at once');
    }

    public function testDuplicateUsernameIsRejected(): void
    {
        $this->register('Doppelt');
        $this->client->request('GET', '/logout');

        $this->signUp('Doppelt');

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

    public function testTheBestFighterOfTheWeekGetsTheBronzeArrowInTheNewspaperAndHisProfile(): void
    {
        self::mockTime('2026-11-12 12:00:00');
        $this->register('Kriegerin');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist(new \App\Entity\ExperienceGain($this->user('Kriegerin'), 1234, new \DateTimeImmutable('2026-11-12 12:00:00')));
        $em->flush();

        self::mockTime('2026-11-17 12:00:00'); // the week 09.–16.11. is over
        $crawler = $this->client->request('GET', '/zeitung');

        self::assertSelectorTextContains('#auszeichnungen', 'Bronzener Pfeil');
        self::assertSelectorTextContains('#auszeichnungen', 'Kriegerin');
        $this->client->request('GET', '/player/'.$this->user('Kriegerin')->getId());
        self::assertSelectorTextContains('#profile-awards', 'Bronzener Pfeil KW 46/2026');
    }

    public function testAPlayerShowsATickerOnTheOverviewAndWritesInIt(): void
    {
        $this->register('Pilotin');
        $this->client->request('GET', '/ticker');
        $this->client->submitForm('ticker-open', ['name' => 'Alte Allianz']);
        $this->client->request('GET', '/alliance');
        $this->client->submitForm('alliance-found', ['name' => 'Sternenbund', 'tag' => 'STB']);

        // The alliance page has its black board; the personal ticker is only on the overview.
        $this->client->request('GET', '/alliance');
        self::assertSelectorNotExists('#ticker');

        $crawler = $this->client->request('GET', '/overview');
        $ticker = static::getContainer()->get(\App\Repository\TickerRepository::class)->findJoined($this->user('Pilotin'))[0];
        $this->client->submit($crawler->filter('#board-ticker-save')->form(['ticker' => (string) $ticker->getId()]));
        self::assertResponseRedirects('/overview#ticker');
        self::assertSame($ticker->getId(), $this->user('Pilotin')->getBoardTicker()?->getId());

        $crawler = $this->client->request('GET', '/overview');
        $this->client->submit($crawler->filter('#board-ticker-send')->form(['body' => 'Hallo ihr Alten!']));
        self::assertResponseRedirects('/overview#ticker');
        $this->client->followRedirect();
        self::assertSelectorTextContains('#ticker', 'Hallo ihr Alten!');
        $crawler = $this->client->request('GET', '/overview');
        $this->client->submit($crawler->filter('#board-ticker-send')->form(['body' => 'Grüße von der Übersicht']));
        self::assertResponseRedirects('/overview#ticker');

        // Own posts can be edited afterwards; they are then marked as edited.
        $crawler = $this->client->request('GET', '/overview');
        $this->client->submit($crawler->filter('#ticker .post-edit form')->first()->form(['body' => 'Grüße, nachträglich geändert']));
        self::assertResponseRedirects('/overview#ticker');
        $this->client->followRedirect();
        self::assertSelectorTextContains('#ticker', 'Grüße, nachträglich geändert');
        self::assertSelectorTextContains('#ticker', 'bearbeitet');
    }

    public function testTheOverviewLeavesTheTickerBoxOutForPlayersInNoTicker(): void
    {
        $this->register('Pilotin');

        $this->client->request('GET', '/overview');

        self::assertSelectorNotExists('#ticker');
    }

    public function testPlayersGetTheTimeZoneOfTheirDeviceAndCanChangeIt(): void
    {
        $this->signUp('Pilotin', ['timezone' => 'Asia/Tokyo']);
        self::assertSame('Asia/Tokyo', $this->user('Pilotin')->getTimezone());
        $this->confirmAndLogIn('Pilotin');

        $crawler = $this->client->request('GET', '/konto');
        self::assertSame('Asia/Tokyo', $crawler->filter('select[name="timezone"] option[selected]')->attr('value'));
        $this->client->submit($crawler->filter('#timezone-save')->form(['timezone' => 'America/New_York']));
        self::assertResponseRedirects();
        self::assertSame('America/New_York', $this->user('Pilotin')->getTimezone());

        $crawler = $this->client->request('GET', '/konto');
        $token = $crawler->filter('#zeitzone input[name="_token"]')->attr('value');
        $this->client->request('POST', '/konto/zeitzone', ['_token' => $token, 'timezone' => 'Mars/Olympus_Mons']);
        self::assertSame('America/New_York', $this->user('Pilotin')->getTimezone(), 'unknown zones are refused');
    }

    public function testOverviewAndTopBarShowWhatThePopulationEatsAndDrinks(): void
    {
        $this->register('Pilotin');

        $crawler = $this->client->request('GET', '/overview');

        // 100 inhabitants à 0,05 food and water per hour
        self::assertSelectorTextContains('[data-fold="overview-population"] table', 'Verbrauch Nahrung');
        self::assertStringContainsString('−5/h', $crawler->filterXPath('//tr[td[1][normalize-space()="Verbrauch Nahrung"]]')->text());
        self::assertStringContainsString('−5/h', $crawler->filterXPath('//tr[td[1][normalize-space()="Verbrauch Wasser"]]')->text());
        self::assertStringContainsString('Verbrauch der Bevölkerung −5/h', (string) $crawler->filter('.res-food .res-rate')->attr('title'));
        self::assertNull($crawler->filter('.res-iron .res-rate')->attr('title'), 'nobody eats iron');
    }

    public function testResetPasswordPageLinksBackToLoginAndLogoToHome(): void
    {
        $this->register('Pilotin');
        $this->client->request('GET', '/logout');
        $user = static::getContainer()->get(UserRepository::class)->findOneBy(['username' => 'Pilotin']);
        $link = static::getContainer()->get(\App\Security\PasswordReset::class)->link($user);

        $crawler = $this->client->request('GET', $link);

        self::assertResponseIsSuccessful();
        self::assertSame('/login', $crawler->selectLink('Zur Anmeldung')->attr('href'));
        self::assertSame('/', $crawler->filter('h1.logo-hero a')->attr('href'));
    }

    public function testBuildingDeductsResourcesAndCompletesOverTime(): void
    {
        $clock = static::mockTime('2026-01-01 12:00:00');
        $this->register('Baumeister');
        // Start resources plus the starter bonus for the confirmed address.
        $start = $this->planet('Baumeister')->getResources()->iron;

        $this->client->request('GET', '/buildings');
        $this->client->submitForm('build-iron_mine');
        self::assertResponseRedirects('/buildings');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.queue', 'Eisenmine → Stufe 1');

        $planet = $this->planet('Baumeister');
        self::assertTrue($planet->isConstructing());
        self::assertEqualsWithDelta($start - 60, $planet->getResources()->iron, 0.001);

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
        self::assertGreaterThan($start - 60 - 117, $planet->getResources()->iron);
    }

    public function testQueueCanBeReordered(): void
    {
        static::mockTime('2026-01-01 12:00:00');
        $this->register('Planerin');

        // With a full queue the page shows no build buttons, so the token is taken once.
        $token = $this->buildToken();
        foreach (['iron_mine', 'water_pump', 'farm'] as $type) {
            $this->client->request('POST', "/buildings/$type/build", ['_token' => $token]);
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
        $start = $this->planet('Zauderer')->getResources()->iron;

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
        self::assertEqualsWithDelta($start, $planet->getResources()->iron, 0.001);
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
        // Freighters are civil ships: their own tab of the shipyard.
        $this->client->request('GET', '/shipyard?tab=civil');
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

        // Freighters are civil ships: their own tab of the shipyard.
        $this->client->request('GET', '/shipyard?tab=civil');
        $this->client->submitForm('order-freighter', ['quantity' => 1000]);
        $this->client->followRedirect();

        self::assertSelectorTextContains('.flash-error', 'Nicht genügend Bevölkerung');
        self::assertSame(0, $this->planet('Kleinstadt')->getShipCount(ShipType::Freighter));
    }

    public function testFleetsAreFormedAndColonyShipsStartFromTheHangar(): void
    {
        $clock = static::mockTime('2026-01-01 12:00:00');
        $this->register('Siedlerin');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $home = $this->planet('Siedlerin');
        $home->addShips(ShipType::ColonyShip, 1)->addShips(ShipType::Freighter, 1);
        // Fleets are steered from a fleet control center.
        $home->setBuildingLevel(BuildingType::FleetControl, 1);
        $home->setResources(new Resources(iron: 50_000, titanium: 50_000, food: 5000, water: 5000, hydrogen: 50_000));
        // A second planet slot.
        $home->getOwner()->setResearchLevel(ResearchType::Colonization, 1);
        $em->flush();

        // Ships move as a fleet …
        $this->client->request('GET', '/fleet');
        $this->client->submitForm('fleet-create', ['name' => 'Frachtzug', 'ships[freighter]' => 1]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Frachtzug');
        self::assertSame(0, $this->planet('Siedlerin')->getShipCount(ShipType::Freighter), 'the freighter left the hangar');

        // … but a colony ship starts from the hangar: "Kolonisieren" at a free slot in the universe.
        $position = $home->getPosition() > 1 ? $home->getPosition() - 1 : $home->getPosition() + 1;
        $this->client->request('GET', '/galaxy');
        $this->client->submitForm('colonize-'.$position);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Kolonie');
        self::assertSame(0, $this->planet('Siedlerin')->getShipCount(ShipType::ColonyShip), 'the colony ship is on its way');

        $clock->sleep(86400);
        $this->client->request('GET', '/overview');

        $em->clear();
        $user = $em->getRepository(User::class)->findOneBy(['username' => 'Siedlerin']);
        self::assertCount(2, $user->getPlanets());
        $colony = array_values(array_filter($user->getPlanets()->toArray(), static fn (Planet $p): bool => !$p->isHomeworld()))[0];
        self::assertSame($position, $colony->getPosition());
        self::assertGreaterThan(0, $colony->getPopulation());

        $this->client->request('GET', '/messages');
        self::assertSelectorTextContains('body', 'Neue Kolonie');
    }

    public function testTheUniverseColoursPlanetsByAllianceAndTreaty(): void
    {
        $this->register('Kundschafterin');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $me = $this->user('Kundschafterin');
        $home = $me->getPlanets()->first();
        $now = new \DateTimeImmutable();
        $mine = new \App\Entity\Alliance('Sternenwacht', 'SW', \App\Game\LifeForm::Guardian, $now);
        $friends = new \App\Entity\Alliance('Freunde', 'FR', \App\Game\LifeForm::Settler, $now);
        $foes = new \App\Entity\Alliance('Feinde', 'FE', \App\Game\LifeForm::Pirate, $now);
        foreach ([$mine, $friends, $foes] as $alliance) {
            $em->persist($alliance);
        }
        $me->joinAlliance($mine, \App\Game\AllianceRank::Founder);
        $em->persist(new \App\Entity\DiplomaticRelation($mine, $friends, \App\Game\TreatyKind::Bond, $now));
        $em->persist(new \App\Entity\DiplomaticRelation($mine, $foes, \App\Game\TreatyKind::War, $now));

        // Four neighbours in the home system: alliance mate, bond partner, war enemy, nobody special.
        $free = array_values(array_diff(range(1, \App\Game\Universe::planetsInSystem($home->getGalaxy(), $home->getSystem())), [$home->getPosition()]));
        $slots = [];
        foreach (['Kamerad' => $mine, 'Partner' => $friends, 'Gegner' => $foes, 'Fremder' => null] as $name => $alliance) {
            $user = new User($name, $now);
            $user->setEmail(strtolower($name).'@example.com');
            $user->setPassword('x');
            if (null !== $alliance) {
                $user->joinAlliance($alliance, \App\Game\AllianceRank::Member);
            }
            $em->persist($user);
            $slots[$name] = array_shift($free);
            $em->persist(new Planet($user, $name.'s Welt', $home->getGalaxy(), $home->getSystem(), $slots[$name], $now));
        }
        $em->flush();

        $crawler = $this->client->request('GET', '/galaxy');

        self::assertResponseIsSuccessful();
        $row = static fn (string $name) => $crawler->filter('#slot-'.$slots[$name]);
        self::assertStringContainsString('rel-alliance', $row('Kamerad')->attr('class'));
        self::assertStringContainsString('rel-ally', $row('Partner')->attr('class'));
        self::assertStringContainsString('rel-war', $row('Gegner')->attr('class'));
        self::assertStringNotContainsString('rel-', $row('Fremder')->attr('class'));
        self::assertStringContainsString('own', $crawler->filter('#slot-'.$home->getPosition())->attr('class'));
        // Mouse-over: player, alliance and how they stand to the player.
        self::assertSame('Gegner [FE] · Krieg · Noobschutz', $row('Gegner')->attr('title'), 'one planet, no fleet control center');
        self::assertSame('Fremder · Neutral · Noobschutz', $row('Fremder')->attr('title'));
        self::assertSame('war', $row('Gegner')->attr('data-rel'));
        self::assertCount(4, $crawler->filter('[data-galaxy-filter]'), 'own alliance, allies, enemies, trade posts');
        // The graphical system view gets the same relation.
        $view = json_decode((string) $crawler->filter('[data-system-view]')->attr('data-config'), true);
        $byPosition = array_column($view['slots'], null, 'position');
        self::assertSame('war', $byPosition[$slots['Gegner']]['relation']);
        self::assertSame('FE', $byPosition[$slots['Gegner']]['alliance']);
    }

    public function testOnlyFleetsAtSomebodyElsesTradePostAreShownAsWaitingToTrade(): void
    {
        $this->register('Kauffrau');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $me = $this->user('Kauffrau');
        $now = new \DateTimeImmutable();
        $colony = new Planet($me, 'Basar', 9, 9, 9, $now);
        $em->persist($colony);
        $em->persist(\App\Entity\TradePost::onPlanet($colony, 'Dönerladen', $now));
        $rival = new User('Konkurrenz', $now);
        $rival->setEmail('konkurrenz@example.com');
        $rival->setPassword('x');
        $em->persist($rival);
        $market = new Planet($rival, 'Markt', 9, 9, 10, $now);
        $em->persist($market);
        $em->persist(\App\Entity\TradePost::onPlanet($market, 'Fremdbörse', $now));
        // One fleet at the own post, one at the rival's.
        $em->persist(new \App\Entity\Fleet($me, 'Zuhause', 9, 9, 9, ['freighter' => 1]));
        $em->persist(new \App\Entity\Fleet($me, 'Einkauf', 9, 9, 10, ['freighter' => 1]));
        $em->flush();

        $this->client->request('GET', '/overview');

        self::assertSelectorCount(1, '.trade-waiting-row');
        self::assertSelectorTextContains('.trade-waiting', 'Fremdbörse');
        self::assertSelectorTextNotContains('.trade-waiting', 'Dönerladen');
    }

    public function testNewPlayersSeeTheWelcomeNewsUntilTheyMarkItRead(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        // Only the news of this test (a migration brings the game's real welcome news along).
        $em->createQuery('DELETE FROM '.\App\Entity\News::class.' n')->execute();
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
        $colony->addShips(\App\Game\ShipType::Pfeil, 7);
        $colony->addShips(\App\Game\ShipType::LaserTurret, 2);
        $em->persist($colony);
        $em->flush();

        $this->client->request('GET', '/imperium');

        self::assertResponseIsSuccessful();
        self::assertSelectorCount(10, 'tbody th.empire-planet', 'two planets in each of the five tables (ships and turrets included)');
        self::assertSelectorTextContains('.empire-buildings tbody', 'Kolonie');
        self::assertSelectorTextContains('.empire-buildings tfoot', (string) (3 + $home->getBuildingLevel(BuildingType::IronMine)));
        // Ships and turrets: one column per type the player has, summed at the bottom.
        $hangar = $this->client->getCrawler()->filter('.empire-hangar');
        self::assertCount(2, $hangar, 'ships and turrets');
        self::assertStringContainsString('Pfeil', $hangar->eq(0)->filter('thead')->text());
        self::assertStringContainsString('7', $hangar->eq(0)->filter('tfoot')->text());
        self::assertStringContainsString('Laser', $hangar->eq(1)->filter('thead')->text());
    }

    public function testGuideShowsQuickStartAndTheEarlyTechTree(): void
    {
        $this->register('Lehrling');

        $this->client->request('GET', '/spielhilfe');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.guide-steps li');
        self::assertSelectorTextContains('.tech-tree', 'Raumwerft');
        self::assertSelectorExists('.tech-node.tech-locked', 'a new player still misses things');
        // How a battle works, with the four sample battles.
        self::assertSelectorTextContains('#kampf', 'bis zu 3 Runden');
        foreach (['/kampfbericht/beispiel', '/kampfbericht/beispiel/verband', '/kampfbericht/beispiel/angriff', '/kampfbericht/beispiel/verteidigung'] as $link) {
            self::assertSelectorExists('#kampf a[href="'.$link.'"]');
        }
    }

    public function testTransportRequestCostsAFixedPrice(): void
    {
        // Frozen time: no credits are earned between the two readings.
        static::mockTime('2026-01-01 12:00:00');
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

    public function testAnApplicationNeedsALetterGoesToOneAllianceAtATimeAndShowsItsStatus(): void
    {
        foreach (['Nordlicht' => ['Nordbund', 'NRD'], 'Suedwind' => ['Suedbund', 'SUD']] as $founder => [$name, $tag]) {
            $this->register($founder);
            $this->client->request('GET', '/alliance');
            $this->client->submitForm('alliance-found', ['name' => $name, 'tag' => $tag]);
            $this->client->request('GET', '/logout');
        }
        $repository = static::getContainer()->get(\App\Repository\AllianceRepository::class);
        $north = $repository->findOneBy(['tag' => 'NRD'])->getId();
        $south = $repository->findOneBy(['tag' => 'SUD'])->getId();
        $this->register('Bewerberin');

        $crawler = $this->client->request('GET', '/alliances/'.$north);
        $this->client->submit($crawler->filter('#alliance-apply')->form(['message' => '   ']));
        self::assertNull($repository->findApplicationOf($this->user('Bewerberin')), 'no application without a letter');

        $crawler = $this->client->request('GET', '/alliances/'.$north);
        $this->client->submit($crawler->filter('#alliance-apply')->form(['message' => 'Ich baue gern Schiffe.']));
        self::assertResponseRedirects('/alliance#bewerbung');
        $this->client->followRedirect();
        self::assertSelectorTextContains('#bewerbung', 'Nordbund');
        self::assertSelectorTextContains('#application-status', 'offen');
        self::assertSelectorTextContains('#bewerbung', 'Ich baue gern Schiffe.');
        self::assertSelectorTextContains('#application-lock', 'Nordbund');
        self::assertSelectorNotExists('a.btn[href$="#apply-form"]', 'every apply button is disabled');

        $this->client->request('GET', '/alliances/'.$south);
        self::assertSelectorNotExists('#alliance-apply');
        self::assertSelectorTextContains('.apply-box', 'Nordbund');
        $this->client->request('POST', '/alliance/'.$south.'/apply', ['_token' => $this->allianceToken(), 'message' => 'Dann eben ihr.']);
        self::assertSame('Nordbund', $repository->findApplicationOf($this->user('Bewerberin'))->getAlliance()->getName(), 'only one application at a time');

        // The founder reads the letter and declines.
        $this->client->loginUser($this->user('Nordlicht'));
        $crawler = $this->client->request('GET', '/alliance');
        self::assertStringContainsString('Ich baue gern Schiffe.', $crawler->text());
        $this->client->submit($crawler->filter('form[action$="/decline"] button')->form());

        $this->client->loginUser($this->user('Bewerberin'));
        $this->client->request('GET', '/alliance');
        self::assertSelectorTextContains('#application-status', 'abgelehnt');
        $crawler = $this->client->request('GET', '/alliances/'.$south);
        $this->client->submit($crawler->filter('#alliance-apply')->form(['message' => 'Dann eben ihr.']));
        $this->client->followRedirect();
        self::assertSelectorTextContains('#bewerbung', 'Suedbund');

        $this->client->submitForm('application-withdraw');
        self::assertNull($repository->findApplicationOf($this->user('Bewerberin')), 'withdrawn');
    }

    public function testAQuickContactOpensAMenuForAMessageOrTheirPlanets(): void
    {
        $this->register('Kurierin');
        $this->client->request('GET', '/logout');
        $this->register('Funkerin');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        // user() clears the entity manager, so the second one is loaded without it.
        $her = $this->user('Kurierin');
        $me = $em->getRepository(User::class)->findOneBy(['username' => 'Funkerin']);
        $contact = new \App\Entity\Contact($me, $her, new \DateTimeImmutable());
        $contact->accept(new \DateTimeImmutable());
        $em->persist($contact);
        $em->flush();

        $crawler = $this->client->request('GET', '/overview');
        self::assertSame('/mail/new?to=Kurierin', $crawler->filter('#contact-write-'.$her->getId())->attr('href'));
        $this->client->click($crawler->filter('#contact-planets-'.$her->getId())->link());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#planets', $her->getPlanets()->first()->getCoordinates());
    }

    public function testAPlayersPlanetsCanBeSpiedFromHisProfile(): void
    {
        $this->register('Ziel');
        $this->client->request('GET', '/logout');
        $this->register('Spaeherin');
        $target = $this->user('Ziel');
        $planet = $target->getPlanets()->first();

        $crawler = $this->client->request('GET', '/player/'.$target->getId());
        self::assertSelectorExists('#spy-planet-'.$planet->getId());
        $this->client->submit($crawler->filter('#spy-planet-'.$planet->getId())->form());

        self::assertResponseRedirects('/player/'.$target->getId().'#planets', null, 'back to the profile, not to the galaxy');
        $this->client->request('GET', '/player/'.$this->user('Spaeherin')->getId());
        self::assertSelectorExists('[id^="spy-planet-"]', 'own planets may be spied on too');
    }

    public function testMessagesAreSortedIntoFolders(): void
    {
        $this->register('Leserin');
        $inbox = static::getContainer()->get(\App\Game\Inbox::class);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->user('Leserin');
        $inbox->send($user, \Symfony\Component\Translation\t('Spionagebericht Testwelt'), \Symfony\Component\Translation\t('Viel Eisen.'), folder: \App\Game\MessageFolder::Spy);
        $inbox->send($user, \Symfony\Component\Translation\t('Kampfbericht Testwelt'), \Symfony\Component\Translation\t('Gewonnen.'), folder: \App\Game\MessageFolder::Battle);
        $em->flush();

        $this->client->request('GET', '/messages?folder=spy');
        self::assertSelectorTextContains('#folder-spy.active', 'Spionage');
        self::assertSelectorTextContains('.message summary', 'Spionagebericht Testwelt');
        self::assertSelectorTextNotContains('main', 'Kampfbericht Testwelt');

        $this->client->request('GET', '/messages');
        self::assertSelectorTextContains('main', 'Kampfbericht Testwelt');
        self::assertSelectorTextContains('main', 'Spionagebericht Testwelt');
    }

    public function testTheSearchFindsPlayersAndAlliancesExactMatchesFirst(): void
    {
        $this->register('Sternwanderer');
        $this->client->request('GET', '/logout');
        $this->register('Stern');
        $this->client->request('GET', '/alliance');
        $this->client->submitForm('alliance-found', ['name' => 'Sternenbund', 'tag' => 'STB']);

        // The field at the top of every page.
        $crawler = $this->client->request('GET', '/overview');
        $this->client->submit($crawler->filter('form.top-search')->form(['q' => 'stern']));
        self::assertResponseIsSuccessful();
        $names = $this->client->getCrawler()->filter('#search-players .player-name a:not(.alliance-tag)')->each(static fn ($a): string => $a->text());
        self::assertSame(['Stern', 'Sternwanderer'], $names, 'the exact name first');
        self::assertSelectorTextContains('#search-tab-alliances .badge', '1');

        $this->client->request('GET', '/suche', ['q' => 'STB', 'tab' => 'alliances']);
        self::assertSelectorTextContains('#search-alliances', 'Sternenbund');

        // Old links to the player search still work.
        $this->client->request('GET', '/spieler', ['q' => 'stern']);
        self::assertResponseRedirects('/suche?q=stern', 301);
    }

    public function testAFailedPayPalBookingIsReportedToTheAdmins(): void
    {
        $this->register('Kaeuferin');
        // With a subscription the page shows the cancel form – its token is the one the PayPal button uses too.
        $user = $this->user('Kaeuferin');
        $user->setSubscription('I-TEST123456', 'ACTIVE');
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $crawler = $this->client->request('GET', '/erweitert');
        $token = (string) $crawler->filter('form[action$="/erweitert/kuendigen"] input[name="_token"]')->attr('value');

        $this->client->request('POST', '/erweitert/problem', ['_token' => $token, 'kind' => 'error', 'message' => 'Window closed'], [], ['HTTP_USER_AGENT' => 'Testbrowser']);
        self::assertResponseStatusCodeSame(204);
        $this->client->request('POST', '/erweitert/problem', ['_token' => $token, 'kind' => 'unknown']);

        $notices = static::getContainer()->get(\App\Repository\AdminNoticeRepository::class)->findBy(['kind' => \App\Entity\AdminNotice::PAYPAL]);
        self::assertCount(1, $notices, 'unknown kinds are ignored');
        self::assertStringContainsString('Window closed', (string) $notices[0]->getDetail());
        self::assertStringContainsString('Testbrowser', (string) $notices[0]->getDetail());
    }

    public function testSpyingFromTheUniverseAnswersInPlaceWithoutReload(): void
    {
        $this->register('Lauscherin');
        $crawler = $this->client->request('GET', '/galaxy');
        $form = $crawler->filter('form[action$="/galaxy/spy"]')->first()->form();

        // Sent by game.js: a short answer instead of a redirect (no probe in the hangar yet).
        $this->client->request('POST', $form->getUri(), $form->getPhpValues(), [], ['HTTP_X-Requested-With' => 'XMLHttpRequest']);
        self::assertResponseStatusCodeSame(422);
        $answer = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertFalse($answer['ok']);
        self::assertStringContainsString('Sonde', $answer['message']);

        // Without JavaScript the form still works with a redirect.
        $this->client->submit($form);
        self::assertResponseRedirects();
    }

    public function testAdminsSeeAndManageExtendedAccounts(): void
    {
        $this->register('Spenderin');
        $this->client->request('GET', '/logout');
        $this->register('Chefin');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->user('Chefin')->setRoles(['ROLE_ADMIN']);
        $em->flush();
        $this->client->loginUser($this->user('Chefin'));

        $crawler = $this->client->request('GET', '/admin/erweitert');
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->filter('#extended-grant')->form(['username' => 'Spenderin', 'days' => '10']));
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Spenderin');
        $player = $this->user('Spenderin');
        self::assertTrue($player->isExtended(new \DateTimeImmutable('+9 days')));

        $crawler = $this->client->request('GET', '/admin/erweitert');
        self::assertSelectorExists('#account-'.$player->getId());
        $this->client->submit($crawler->filter('#end-'.$player->getId())->form());
        self::assertFalse($this->user('Spenderin')->isExtended(new \DateTimeImmutable('+1 minute')));

        // Players don't get in.
        $this->client->loginUser($this->user('Spenderin'));
        $this->client->request('GET', '/admin/erweitert');
        self::assertResponseStatusCodeSame(403);
    }

    public function testThePlayerChoosesWhichSectionsTheOverviewShows(): void
    {
        $this->register('Ordnerin');
        $this->client->request('GET', '/overview');
        self::assertSelectorExists('.quick-contacts');

        $crawler = $this->client->request('GET', '/konto');
        $form = $crawler->filter('#overview-save')->form();
        foreach ($form['sections'] as $box) {
            'hangar' === $box->availableOptionValues()[0] ? $box->tick() : $box->untick();
        }
        $this->client->submit($form);
        self::assertResponseRedirects('/konto#startseite');

        $this->client->request('GET', '/overview');
        self::assertSelectorNotExists('.quick-contacts');
        self::assertSelectorExists('[data-fold="overview-hangar"]');
        self::assertSelectorNotExists('[data-fold="overview-resources"]');
    }

    public function testTheBattleSimulatorReadsASpyReportAndFights(): void
    {
        $this->register('Strategin');
        $crawler = $this->client->request('GET', '/kampfsimulator');
        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->filter('#sim-read-defender')->form([
            'defender[report]' => "Planet Heimat [1:2:3] von Halden\nForschung:\n  Impulslaser: Stufe 3\nStationierte Schiffe und Türme:\n  Pfeil: 40\n  Lasergeschütz: 10",
        ]));
        self::assertResponseIsSuccessful();
        $crawler = $this->client->getCrawler();
        self::assertSame('40', $crawler->filter('input[name="defender[ships][pfeil]"]')->attr('value'));
        self::assertSame('3', $crawler->filter('input[name="defender[research][impulse_laser]"]')->attr('value'));

        $this->client->submit($crawler->filter('#sim-run')->form(['attacker[ships][lanze]' => '50']));
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#sim-result');
        self::assertSelectorExists('#ablauf');

        // Swapped for an interception: the defender's ships attack, its turrets stay behind.
        $this->client->submit($this->client->getCrawler()->filter('#sim-swap')->form());
        $crawler = $this->client->getCrawler();
        self::assertSame('40', $crawler->filter('input[name="attacker[ships][pfeil]"]')->attr('value'));
        self::assertSame('3', $crawler->filter('input[name="attacker[research][impulse_laser]"]')->attr('value'));
        self::assertSame('50', $crawler->filter('input[name="defender[ships][lanze]"]')->attr('value'));
        self::assertSame('Halden', $crawler->filter('input[name="attackerName"]')->attr('value'));
    }

    public function testTheBattleSummaryAddsUpPastedReportLinks(): void
    {
        $this->register('Chronistin');
        $reports = static::getContainer()->get(\App\Game\BattleReports::class);
        $battle = new \App\Game\Battle();
        $battle->add('attacker', $this->user('Chronistin'), ['lanze' => 20], 'attacker');
        $battle->add('defender', new \App\Entity\User('Halden'), ['pfeil' => 10], 'hangar');
        $replay = $battle->fight()->replay() + ['loot' => []];
        $link = $reports->publish(\Symfony\Component\Translation\t('Kampfbericht'), \Symfony\Component\Translation\t('Kampfbericht'), [], $replay);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $this->client->request('GET', '/kampfbilanz');
        $this->client->submitForm('summary-run', ['links' => 'Hier: http://localhost'.$link]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#summary-reports', '1 Kampf');
        self::assertSelectorTextContains('#summary-defender', '10 × Pfeil');
        self::assertSelectorTextContains('#summary-players', 'Chronistin');
    }

    public function testRememberedCoordinatesCanBeReorderedAndStaySelected(): void
    {
        $this->register('Lotse');
        $crawler = $this->client->request('GET', '/galaxy');
        $adds = $crawler->filter('form[action$="/bookmarks/add"]');
        $coords = [];
        foreach ([0, 1, 2] as $i) {
            $form = $adds->eq($i)->form();
            $coords[] = implode(':', [$form['galaxy']->getValue(), $form['system']->getValue(), $form['position']->getValue()]);
            $this->client->submit($form);
        }
        $rows = fn (): array => $this->client->getCrawler()->filter('tr[data-bookmark-id]')->each(static fn ($row): string => trim($row->filter('td.num')->first()->text(), ' []'));

        // Tick the last one and move it to the top: it stays ticked for the next move.
        $crawler = $this->client->request('GET', '/bookmarks');
        self::assertSame($coords, $rows());
        $lastId = $crawler->filter('tr[data-bookmark-id]')->last()->attr('data-bookmark-id');
        $form = $crawler->filter('#bookmark-move-top')->form();
        $form['ids'][2]->tick();
        $this->client->submit($form);
        self::assertResponseRedirects('/bookmarks?selected='.$lastId.'#bookmark-'.$lastId);
        $crawler = $this->client->followRedirect();
        self::assertSame([$coords[2], $coords[0], $coords[1]], $rows());
        self::assertNotNull($crawler->filter('#bookmark-check-'.$lastId)->attr('checked'), 'still ticked');

        $this->client->submit($crawler->filter('#bookmark-move-down')->form());
        $crawler = $this->client->followRedirect();
        self::assertSame([$coords[0], $coords[2], $coords[1]], $rows());

        // Drag & drop sends the whole order (the fleet form lists them the same way).
        $ids = $crawler->filter('tr[data-bookmark-id]')->each(static fn ($row): string => (string) $row->attr('data-bookmark-id'));
        $this->client->request('POST', '/bookmarks/order', ['_token' => $crawler->filter('[data-bookmark-sort]')->attr('data-token'), 'ids' => array_reverse($ids)]);
        self::assertResponseStatusCodeSame(204);
        $crawler = $this->client->request('GET', '/bookmarks');
        self::assertSame([$coords[1], $coords[2], $coords[0]], $rows());

        // "Clear selection" works without JavaScript too: the plain page has nothing ticked.
        $this->client->click($crawler->filter('[data-bookmark-clear]')->link());
        self::assertCount(0, $this->client->getCrawler()->filter('tr[data-bookmark-id] input:checked'));
    }

    public function testTheBuildBotFillsTheBuildingQueue(): void
    {
        $this->register('Bauherrin');
        $crawler = $this->client->request('GET', '/baubot');
        self::assertSelectorTextContains('.warning', 'erweiterten Account', 'the bot belongs to the extended account');
        self::assertSelectorTextContains('.sidebar a[href="/baubot"]', '✨');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->user('Bauherrin');
        $user->extendUntil(new \DateTimeImmutable('+1 month'));
        $em->flush();
        $crawler = $this->client->request('GET', '/baubot');
        self::assertSelectorNotExists('.warning', 'extended accounts may use it');
        self::assertSelectorTextNotContains('.sidebar a[href="/baubot"]', '✨');

        $this->client->submit($crawler->filter('#bot-fill-buildings')->form(['goals[0][building]' => 'iron_mine', 'goals[0][level]' => '3']));
        $this->client->followRedirect();

        self::assertSelectorTextContains('.flash-success', 'Eisenmine Stufe');
        self::assertNotEmpty($this->planet('Bauherrin')->getBuildingQueue());
        self::assertSame('iron_mine', $this->user('Bauherrin')->getBuildBot()->goals[0]['building']->value, 'the settings were saved');
    }

    private function allianceToken(): string
    {
        $crawler = $this->client->request('GET', '/alliance');

        return $crawler->filter('input[name="_token"]')->first()->attr('value');
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

    private function buildToken(): string
    {
        $crawler = $this->client->request('GET', '/buildings');

        return $crawler->filter('form[action$="/build"] input[name="_token"]')->first()->attr('value');
    }

    private function user(string $username): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        return $em->getRepository(User::class)->findOneBy(['username' => $username]);
    }

    private function planet(string $username): Planet
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $user = $em->getRepository(User::class)->findOneBy(['username' => $username]);

        return $user->getPlanets()->first();
    }
}
