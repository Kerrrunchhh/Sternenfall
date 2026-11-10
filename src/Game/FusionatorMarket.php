<?php

namespace App\Game;

use App\Entity\Fleet;
use App\Entity\FusionatorOffer;
use App\Entity\Planet;
use App\Entity\User;
use App\Repository\AllianceRepository;
use App\Repository\PlanetRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Lock\LockFactory;
use App\I18n\Num;
use Symfony\Contracts\Translation\TranslatableInterface;

use function Symfony\Component\Translation\t;

/**
 * The fusionator market: traders offer fusionators from a planet hangar at a price
 * per piece, open to everybody or only to chosen life forms and alliances. Buyers take
 * any number; the bought fusionators fly as a new fleet to the buyer's main planet,
 * free of charge but with the normal flight time.
 *
 * Runs under the fleet event lock, then the lock of the player whose planet changes,
 * the same order the fleet processing uses.
 */
final readonly class FusionatorMarket
{
    public function __construct(
        private EntityManagerInterface $em,
        private PlanetService $planets,
        private PlanetRepository $planetRepository,
        private AllianceRepository $alliances,
        private Navigation $navigation,
        private Economy $economy,
        private Inbox $inbox,
        private LockFactory $lockFactory,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Takes fusionators out of the hangar of $planet and puts them on offer.
     *
     * @param list<LifeForm> $lifeForms
     * @param list<int>      $allianceIds
     */
    public function offer(Planet $planet, int $quantity, float $price, array $lifeForms, array $allianceIds): void
    {
        if ($quantity < 1) {
            throw new GameException(t('Stelle mindestens einen Fusionator ein.'));
        }
        if ($price < 0 || !is_finite($price)) {
            throw new GameException(t('Der Preis darf nicht negativ sein.'));
        }
        $alliances = [] === $allianceIds ? [] : $this->alliances->findBy(['id' => $allianceIds]);

        $this->withEventLock(fn () => $this->planets->transaction($planet, function (Planet $planet, \DateTimeImmutable $now) use ($quantity, $price, $lifeForms, $alliances): void {
            $seller = $planet->getOwner();
            if (LifeForm::Trader !== $seller->getLifeForm()) {
                throw new GameException(t('Nur Händler können Fusionatoren verkaufen.'));
            }
            $inHangar = $planet->getShipCount(ShipType::Fusionator);
            if ($quantity > $inHangar) {
                throw new GameException(t('Im Hangar von {name} stehen nur {inHangar} Fusionator(en).', ['{name}' => $planet->getName(), '{inHangar}' => $inHangar]));
            }
            $planet->addShips(ShipType::Fusionator, -$quantity);
            $this->em->persist(new FusionatorOffer($seller, $planet, $quantity, round($price, 2), $lifeForms, $alliances, $now));
        }));
    }

    /** The seller takes the rest of an offer back into the hangar (of the planet it came from, if still his). */
    public function withdraw(FusionatorOffer $offer, User $actor): void
    {
        $this->withEventLock(function () use ($offer, $actor): void {
            $this->em->refresh($offer);
            if (!$offer->getSeller()->isSameAs($actor)) {
                throw new GameException(t('Das ist nicht dein Angebot.'));
            }
            $origin = $this->planetRepository->findAt($offer->getGalaxy(), $offer->getSystem(), $offer->getPosition());
            $hangar = null !== $origin && $origin->getOwner()->isSameAs($actor) ? $origin : $this->mainPlanet($actor);
            if (null === $hangar) {
                throw new GameException(t('Du besitzt keinen Planeten, auf den die Fusionatoren zurück könnten.'));
            }
            $count = $offer->getQuantity();
            $this->planets->transaction($hangar, function (Planet $hangar) use ($offer, $count): void {
                $hangar->addShips(ShipType::Fusionator, $count);
                $this->em->remove($offer);
            });
        });
    }

    /**
     * Buys $quantity fusionators of an offer. The credits go to the seller; the fusionators
     * leave at once as a new fleet towards the buyer's main planet.
     */
    public function buy(FusionatorOffer $offer, User $buyer, int $quantity): TranslatableInterface
    {
        $message = '';
        $this->withEventLock(function () use ($offer, $buyer, $quantity, &$message): void {
            $this->em->refresh($offer);
            $seller = $offer->getSeller();
            if ($seller->isSameAs($buyer)) {
                throw new GameException(t('Eigene Fusionatoren ziehst du mit „Zurückziehen“ zurück.'));
            }
            if (!$offer->isAvailableTo($buyer)) {
                throw new GameException(t('Dieses Angebot ist nicht für dich bestimmt.'));
            }
            if ($quantity < 1 || $quantity > $offer->getQuantity()) {
                throw new GameException(t('Es sind noch {quantity} Fusionator(en) im Angebot.', ['{quantity}' => $offer->getQuantity()]));
            }
            $home = $this->mainPlanet($buyer);
            if (null === $home) {
                throw new GameException(t('Du brauchst einen Planeten, zu dem die Fusionatoren fliegen können.'));
            }
            $price = $offer->getPrice() * $quantity;

            // Pay first (under the buyer's lock): without enough credits nothing happens.
            $this->planets->transaction($home, function () use ($buyer, $price): void {
                if ($buyer->getCredits() < $price) {
                    throw new GameException(t('Du hast nicht genug Credits ({price} benötigt).', ['{price}' => new Num($price)]));
                }
                $buyer->addCredits(-$price);
            });
            $sellerPlanet = $this->mainPlanet($seller);
            if (null !== $sellerPlanet) {
                $this->planets->transaction($sellerPlanet, static fn () => $seller->addCredits($price));
            } else {
                $seller->addCredits($price);
            }

            $offer->take($quantity);
            if (0 === $offer->getQuantity()) {
                $this->em->remove($offer);
            }

            $now = \DateTimeImmutable::createFromInterface($this->clock->now());
            $name = mb_substr('Fusionatoren von '.$seller->getUsername(), 0, 40);
            $fleet = new Fleet($buyer, $name, $offer->getGalaxy(), $offer->getSystem(), $offer->getPosition(), [ShipType::Fusionator->value => $quantity]);
            $distance = $this->navigation->distance($offer->getGalaxy(), $offer->getSystem(), $offer->getPosition(), $home->getGalaxy(), $home->getSystem(), $home->getPosition());
            $seconds = $this->navigation->flightSeconds($distance, $this->economy->shipSpeed(ShipType::Fusionator, $buyer));
            // A named fleet "stationed" at its own planet waits there for orders.
            $fleet->depart(FleetMission::Station, $home->getGalaxy(), $home->getSystem(), $home->getPosition(), $now, $seconds);
            $this->em->persist($fleet);
            $this->em->flush();

            $arrival = $fleet->getArriveAt()->setTimezone(new \DateTimeZone('Europe/Berlin'))->format('d.m.Y H:i:s');
            $this->inbox->send($seller, t('Fusionatoren an {player} verkauft', ['{player}' => $buyer->getUsername()]), t("{player} hat {quantity} Fusionator(en) aus deinem Angebot bei [{coords}] gekauft.\nEinnahme: {price} Credits\nIm Angebot sind noch {quantity2}.", ['{player}' => $buyer->getUsername(), '{quantity}' => $quantity, '{coords}' => $offer->getCoordinates(), '{price}' => new Num($price), '{quantity2}' => $offer->getQuantity()]));
            $this->inbox->send($buyer, t('{quantity} Fusionator(en) gekauft', ['{quantity}' => $quantity]), t("Du hast {quantity} Fusionator(en) von {player} für {price} Credits gekauft.\nSie fliegen als Flotte „{name}“ von [{coords}] zu {name2} [{coords2}], Ankunft {arrival}.", ['{quantity}' => $quantity, '{player}' => $seller->getUsername(), '{price}' => new Num($price), '{name}' => $name, '{coords}' => $offer->getCoordinates(), '{name2}' => $home->getName(), '{coords2}' => $home->getCoordinates(), '{arrival}' => $arrival]));
            $message = t('{quantity} Fusionator(en) gekauft. Sie fliegen als Flotte „{name}“ zu {name2} [{coords}], Ankunft {arrival}.', ['{quantity}' => $quantity, '{name}' => $name, '{name2}' => $home->getName(), '{coords}' => $home->getCoordinates(), '{arrival}' => $arrival]);
        });

        return $message;
    }

    /** Flight time of bought fusionators from an offer to the buyer's main planet (null without planet). */
    public function deliverySeconds(FusionatorOffer $offer, User $buyer): ?int
    {
        $home = $this->mainPlanet($buyer);
        if (null === $home) {
            return null;
        }
        $distance = $this->navigation->distance($offer->getGalaxy(), $offer->getSystem(), $offer->getPosition(), $home->getGalaxy(), $home->getSystem(), $home->getPosition());

        return $this->navigation->flightSeconds($distance, $this->economy->shipSpeed(ShipType::Fusionator, $buyer));
    }

    /** The home planet, or the first planet if the home planet is gone. */
    public function mainPlanet(User $player): ?Planet
    {
        $planets = $player->getPlanets();
        $home = $planets->findFirst(static fn (int $key, Planet $p): bool => $p->isHomeworld()) ?? $planets->first();

        return $home instanceof Planet ? $home : null;
    }

    /** @param callable(): void $operation */
    private function withEventLock(callable $operation): void
    {
        $lock = $this->lockFactory->createLock('fleet_events');
        $lock->acquire(true);
        try {
            $operation();
        } finally {
            $lock->release();
        }
    }
}
