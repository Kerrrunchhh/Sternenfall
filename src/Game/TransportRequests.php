<?php

namespace App\Game;

use App\Entity\Fleet;
use App\Entity\Planet;
use App\Entity\TransportRequest;
use App\Entity\User;
use App\Repository\TransportRequestRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use App\I18n\Num;

use function Symfony\Component\Translation\t;

/**
 * Transport requests for beginners: resources to one of their planets, at most one small
 * freighter load, for a fixed price in credits held in escrow (no haggling, so beginners
 * neither overpay nor wait for nobody). Traders serve many at once with small
 * trade fleets stationed at one planet; the trader is paid when the goods arrive.
 * (Sending the fleets is done by TransportRequestDispatcher; this class keeps the books.)
 */
final readonly class TransportRequests
{
    public function __construct(
        private EntityManagerInterface $em,
        private TransportRequestRepository $requests,
        private Inbox $inbox,
        private ClockInterface $clock,
        private ?CreditLedger $ledger = null,
    ) {
    }

    public static function maxLoad(): int
    {
        return ShipType::Freighter->cargo();
    }

    /** Resources delivered per credit. */
    public const int RESOURCES_PER_CREDIT = 10;

    /** The fixed price of a request: one credit per ten resources, rounded up. */
    public static function price(float $amount): int
    {
        return (int) ceil($amount / self::RESOURCES_PER_CREDIT);
    }

    /** New players only: requests can be posted this many days after the e-mail address was confirmed (the start of the game). */
    public const int NEWCOMER_DAYS = 14;

    /** Until when the player may post requests (his start of the game + NEWCOMER_DAYS). */
    public static function openUntil(User $player): \DateTimeImmutable
    {
        return $player->getCreatedAt()->modify('+'.self::NEWCOMER_DAYS.' days');
    }

    /** Requests a player may have running at the same time (open or on their way). */
    public const int MAX_ACTIVE = 3;
    /** Delivered or cancelled requests stay in the lists this long, then they are deleted. */
    public const string CLOSED_KEPT = '3 days';

    public function create(User $requester, Planet $planet, Resources $goods): TransportRequest
    {
        if (!$planet->getOwner()->isSameAs($requester)) {
            throw new GameException(t('Transportgesuche gibt es nur für deine eigenen Planeten.'));
        }
        if ($this->now() >= self::openUntil($requester)) {
            throw new GameException(t('Transportgesuche kannst du nur in den ersten {days} Tagen nach der Bestätigung deiner E-Mail-Adresse stellen – deine Zeit ist am {date} abgelaufen.', ['{days}' => self::NEWCOMER_DAYS, '{date}' => new \App\I18n\When(self::openUntil($requester), 'd.m.Y H:i')]));
        }
        if ($this->requests->countActive($requester) >= self::MAX_ACTIVE) {
            throw new GameException(t('Du hast schon {max} Transportgesuche laufen. Ein neues geht erst, wenn eines geliefert oder zurückgezogen ist.', ['{max}' => self::MAX_ACTIVE]));
        }
        foreach (ResourceType::cases() as $type) {
            if ($goods->get($type) < 0) {
                throw new GameException(t('Mengen dürfen nicht negativ sein.'));
            }
        }
        if ($goods->total() < 1) {
            throw new GameException(t('Gib an, welche Rohstoffe du brauchst.'));
        }
        if ($goods->total() > self::maxLoad()) {
            throw new GameException(t('Ein Gesuch umfasst höchstens eine Frachterladung ({value} Rohstoffe).', ['{value}' => new Num(self::maxLoad())]));
        }
        $offer = self::price($goods->total());
        $taken = $this->em->getConnection()->executeStatement('UPDATE `user` SET credits = credits - ? WHERE id = ? AND credits >= ?', [$offer, $requester->getId(), $offer]);
        $this->em->refresh($requester);
        if (0 === $taken) {
            throw new GameException(t('Du hast nur {credits} Credits.', ['{credits}' => new Num($requester->getCredits())]));
        }
        $request = new TransportRequest($requester, $planet, $goods, $offer, $this->now());
        $this->em->persist($request);
        $this->ledger?->book($requester, -$offer, CreditLedger::TRANSPORT, null, \sprintf('Transportgesuch für %s [%s]: %s', $planet->getName(), $planet->getCoordinates(), ActionLog::resources($goods)), counterpartyName: 'Transportgesuch');
        $this->em->flush();

        return $request;
    }

    public function cancel(TransportRequest $request, User $requester): void
    {
        if (!$request->getRequester()->isSameAs($requester) || TransportRequest::OPEN !== $request->getStatus()) {
            throw new GameException(t('Zurückziehen geht nur, solange niemand liefert.'));
        }
        $request->close(TransportRequest::CANCELLED, $this->now());
        $this->credit($requester, $request->getOffer());
        $this->ledger?->book($requester, $request->getOffer(), CreditLedger::TRANSPORT, null, \sprintf('Transportgesuch für %s [%s] zurückgezogen', $request->getPlanetName(), $request->getCoordinates()), counterpartyName: 'Transportgesuch');
        $this->em->flush();
    }

    /** Whether the fleet is on its way to deliver a paid transport request. */
    public function isPaidDelivery(Fleet $fleet): bool
    {
        return null !== $fleet->getId() && null !== $this->requests->findOneBy(['fleet' => $fleet, 'status' => TransportRequest::ASSIGNED]);
    }

    /** A fleet unloaded at a planet: pays the trader if it delivered a request. */
    public function delivered(Fleet $fleet, Planet $planet): void
    {
        foreach ($this->requests->findBy(['fleet' => $fleet, 'status' => TransportRequest::ASSIGNED]) as $request) {
            if ($planet->getGalaxy() !== $request->getGalaxy() || $planet->getSystem() !== $request->getSystem() || !$planet->covers($request->getPosition())) {
                continue;
            }
            $trader = $request->getTrader();
            $request->close(TransportRequest::DONE, $this->now());
            if (null !== $trader) {
                $this->credit($trader, $request->getOffer());
                $this->ledger?->book($trader, $request->getOffer(), CreditLedger::TRANSPORT, $request->getRequester(), \sprintf('Transportgesuch geliefert an %s [%s]', $request->getPlanetName(), $request->getCoordinates()));
                $this->inbox->send($trader, t('Transportgesuch erfüllt'), t('Geliefert an {planetName} [{coords}] von {player}: {offer} Credits.', ['{planetName}' => $request->getPlanetName(), '{coords}' => $request->getCoordinates(), '{player}' => $request->getRequester()->getUsername(), '{offer}' => new Num($request->getOffer())]), folder: MessageFolder::Trade);
            }
            $this->inbox->send($request->getRequester(), t('Lieferung angekommen'), t('{value} hat dein Transportgesuch für {planetName} [{coords}] erfüllt.', ['{value}' => $trader?->getUsername() ?? t('Ein Händler'), '{planetName}' => $request->getPlanetName(), '{coords}' => $request->getCoordinates()]), folder: MessageFolder::Trade);
        }
    }

    /**
     * Requests whose fleet is gone (destroyed, dissolved) or no longer on its way there
     * (recalled, sent elsewhere) are open again – the requester waits for nobody.
     */
    public function reopenOrphans(): void
    {
        // Deliveries of the trade guild have no fleet on purpose.
        foreach ($this->requests->findBy(['status' => TransportRequest::ASSIGNED, 'fleet' => null, 'guildDeliveryAt' => null]) as $request) {
            $request->reopen();
        }
        foreach ($this->requests->findAssignedToFleets() as $request) {
            if (!self::isOnItsWay($request)) {
                $request->reopen();
            }
        }
    }

    /** Whether the request's fleet is still flying the goods to the requested planet. */
    public static function isOnItsWay(TransportRequest $request): bool
    {
        $fleet = $request->getFleet();

        return null !== $fleet && $fleet->isMoving() && !$fleet->isReturning()
            && FleetMission::Transport === $fleet->getMission()
            && [$fleet->getTargetGalaxy(), $fleet->getTargetSystem(), $fleet->getTargetPosition()] === [$request->getGalaxy(), $request->getSystem(), $request->getPosition()];
    }

    /** Deletes requests delivered or cancelled more than CLOSED_KEPT ago. @return int how many */
    public function purgeClosed(): int
    {
        return $this->requests->deleteClosedBefore($this->now()->modify('-'.self::CLOSED_KEPT));
    }

    private function credit(User $user, float $amount): void
    {
        $this->em->getConnection()->executeStatement('UPDATE `user` SET credits = credits + ? WHERE id = ?', [$amount, $user->getId()]);
    }

    private function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now());
    }
}
