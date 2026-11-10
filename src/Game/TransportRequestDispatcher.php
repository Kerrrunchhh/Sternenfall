<?php

namespace App\Game;

use App\Entity\Fleet;
use App\Entity\Planet;
use App\Entity\TransportRequest;
use App\Entity\User;
use App\Repository\FleetRepository;
use Doctrine\ORM\EntityManagerInterface;

/** Traders serve transport requests: one small trade fleet from the chosen planet per request. */
final readonly class TransportRequestDispatcher
{
    public function __construct(
        private EntityManagerInterface $em,
        private FleetRepository $fleets,
        private FleetService $fleetService,
    ) {
    }

    /** @return list<Fleet> idle trade fleets (freighters only, empty) waiting at the planet */
    public function readyFleets(User $trader, Planet $planet): array
    {
        return array_values(array_filter($this->fleets->findByOwner($trader), static function (Fleet $f) use ($planet): bool {
            if (!$f->isIdle() || !$f->hasShips() || !$f->getCargo()->isEmpty() || $f->getGalaxy() !== $planet->getGalaxy() || $f->getSystem() !== $planet->getSystem() || !$planet->covers($f->getPosition())) {
                return false;
            }
            foreach ($f->getShips() as $type => $count) {
                if (!ShipType::from($type)->carriesCargo()) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * @param list<TransportRequest> $requests
     *
     * @return list<string> one line per request
     */
    public function serve(User $trader, Planet $source, array $requests): array
    {
        if (LifeForm::Trader !== $trader->getLifeForm()) {
            throw new GameException('Transportgesuche bedienen nur Händler.');
        }
        if (!$source->getOwner()->isSameAs($trader)) {
            throw new GameException('Liefern geht nur von einem deiner Planeten.');
        }
        $ready = $this->readyFleets($trader, $source);
        $lines = [];
        foreach ($requests as $request) {
            $where = $request->getPlanetName().' ['.$request->getCoordinates().']';
            if (TransportRequest::OPEN !== $request->getStatus()) {
                $lines[] = $where.': wird schon beliefert.';
                continue;
            }
            $fleet = null;
            foreach ($ready as $i => $candidate) {
                if ($this->capacity($candidate) >= $request->getGoods()->total()) {
                    $fleet = $candidate;
                    unset($ready[$i]);
                    break;
                }
            }
            if (null === $fleet) {
                $lines[] = $where.': keine freie Handelsflotte mit genug Frachtraum mehr bei '.$source->getName().'.';
                continue;
            }
            try {
                $this->fleetService->sendFleet($fleet, $trader, $request->getGalaxy(), $request->getSystem(), $request->getPosition(), FleetMission::Transport, $request->getGoods());
                $request->assign($trader, $fleet);
                $this->em->flush();
                $lines[] = \sprintf('%s: %s ist unterwegs (Ankunft %s).', $where, $fleet->getLabel(), $fleet->getArriveAt()?->setTimezone(new \DateTimeZone('Europe/Berlin'))->format('d.m. H:i'));
            } catch (GameException $e) {
                $lines[] = $where.': '.$e->getMessage();
            }
        }

        return $lines;
    }

    private function capacity(Fleet $fleet): int
    {
        $capacity = 0;
        foreach ($fleet->getShips() as $type => $count) {
            $capacity += ShipType::from($type)->cargo() * $count;
        }

        return $capacity;
    }
}
