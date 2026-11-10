<?php

namespace App\Game;

use App\Entity\Fleet;
use App\Entity\Planet;
use App\Entity\TransportRequest;
use App\Entity\User;
use App\Repository\TransportRequestRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Transport requests for beginners: resources to one of their planets, at most one small
 * freighter load, paid with credits held in escrow. Traders serve many at once with small
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
    ) {
    }

    public static function maxLoad(): int
    {
        return ShipType::Freighter->cargo();
    }

    /** What one should offer: a tenth of the amount in credits. */
    public static function suggestedOffer(float $amount): int
    {
        return (int) ceil($amount / 10);
    }

    public function create(User $requester, Planet $planet, Resources $goods, float $offer): TransportRequest
    {
        if (!$planet->getOwner()->isSameAs($requester)) {
            throw new GameException('Transportgesuche gibt es nur für deine eigenen Planeten.');
        }
        foreach (ResourceType::cases() as $type) {
            if ($goods->get($type) < 0) {
                throw new GameException('Mengen dürfen nicht negativ sein.');
            }
        }
        if ($goods->total() < 1) {
            throw new GameException('Gib an, welche Rohstoffe du brauchst.');
        }
        if ($goods->total() > self::maxLoad()) {
            throw new GameException(\sprintf('Ein Gesuch umfasst höchstens eine Frachterladung (%s Rohstoffe).', number_format(self::maxLoad(), 0, ',', '.')));
        }
        $offer = floor($offer);
        if ($offer < 1) {
            throw new GameException('Biete mindestens 1 Credit.');
        }
        $taken = $this->em->getConnection()->executeStatement('UPDATE `user` SET credits = credits - ? WHERE id = ? AND credits >= ?', [$offer, $requester->getId(), $offer]);
        $this->em->refresh($requester);
        if (0 === $taken) {
            throw new GameException(\sprintf('Du hast nur %s Credits.', number_format($requester->getCredits(), 0, ',', '.')));
        }
        $request = new TransportRequest($requester, $planet, $goods, $offer, $this->now());
        $this->em->persist($request);
        $this->em->flush();

        return $request;
    }

    public function cancel(TransportRequest $request, User $requester): void
    {
        if (!$request->getRequester()->isSameAs($requester) || TransportRequest::OPEN !== $request->getStatus()) {
            throw new GameException('Zurückziehen geht nur, solange niemand liefert.');
        }
        $request->close(TransportRequest::CANCELLED);
        $this->credit($requester, $request->getOffer());
        $this->em->flush();
    }

    /** A fleet unloaded at a planet: pays the trader if it delivered a request. */
    public function delivered(Fleet $fleet, Planet $planet): void
    {
        foreach ($this->requests->findBy(['fleet' => $fleet, 'status' => TransportRequest::ASSIGNED]) as $request) {
            if ($planet->getGalaxy() !== $request->getGalaxy() || $planet->getSystem() !== $request->getSystem() || !$planet->covers($request->getPosition())) {
                continue;
            }
            $trader = $request->getTrader();
            $request->close(TransportRequest::DONE);
            if (null !== $trader) {
                $this->credit($trader, $request->getOffer());
                $this->inbox->send($trader, 'Transportgesuch erfüllt', \sprintf('Geliefert an %s [%s] von %s: %s Credits.', $request->getPlanetName(), $request->getCoordinates(), $request->getRequester()->getUsername(), number_format($request->getOffer(), 0, ',', '.')));
            }
            $this->inbox->send($request->getRequester(), 'Lieferung angekommen', \sprintf('%s hat dein Transportgesuch für %s [%s] erfüllt.', $trader?->getUsername() ?? 'Ein Händler', $request->getPlanetName(), $request->getCoordinates()));
        }
    }

    /** Requests whose fleet is gone (destroyed, dissolved) are open again. */
    public function reopenOrphans(): void
    {
        foreach ($this->requests->findBy(['status' => TransportRequest::ASSIGNED, 'fleet' => null]) as $request) {
            $request->reopen();
        }
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
