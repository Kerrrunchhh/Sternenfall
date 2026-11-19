<?php

namespace App\Game;

use App\I18n\When;
use App\Entity\Fleet;
use App\Entity\Planet;
use App\Entity\TransportRequest;
use App\Entity\User;
use App\Repository\FleetRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Translation\TranslatableInterface;

use function Symfony\Component\Translation\t;

/** Traders serve transport requests: one small trade fleet from the chosen planet per request. */
final readonly class TransportRequestDispatcher
{
    /** A trader's delivery may take at most this long – otherwise the trade guild is faster (2 h wait + at most 4 h). */
    public const int MAX_DELIVERY_HOURS = 12;

    public function __construct(
        private EntityManagerInterface $em,
        private FleetRepository $fleets,
        private FleetService $fleetService,
        private Navigation $navigation,
    ) {
    }

    /** Flight time of the fleet from where it waits to the requested planet, in seconds. */
    public function flightSeconds(Fleet $fleet, TransportRequest $request): int
    {
        return $this->navigation->flightSeconds(
            $this->navigation->distance($fleet->getGalaxy(), $fleet->getSystem(), $fleet->getPosition(), $request->getGalaxy(), $request->getSystem(), $request->getPosition()),
            $this->navigation->slowestSpeed($fleet->getShips(), $fleet->getOwner()),
        );
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
     * @return list<TranslatableInterface> one line per request
     */
    public function serve(User $trader, Planet $source, array $requests): array
    {
        if (LifeForm::Trader !== $trader->getLifeForm()) {
            throw new GameException(t('Transportgesuche bedienen nur Händler.'));
        }
        if (!$source->getOwner()->isSameAs($trader)) {
            throw new GameException(t('Liefern geht nur von einem deiner Planeten.'));
        }
        $ready = $this->readyFleets($trader, $source);
        $lines = [];
        foreach ($requests as $request) {
            $where = $request->getPlanetName().' ['.$request->getCoordinates().']';
            if (TransportRequest::OPEN !== $request->getStatus()) {
                $lines[] = t('{where}: wird schon beliefert.', ['{where}' => $where]);
                continue;
            }
            $fleet = null;
            $tooFar = null;
            foreach ($ready as $i => $candidate) {
                if ($this->capacity($candidate) < $request->getGoods()->total()) {
                    continue;
                }
                $seconds = $this->flightSeconds($candidate, $request);
                if ($seconds > self::MAX_DELIVERY_HOURS * 3600) {
                    $tooFar = min($tooFar ?? $seconds, $seconds);
                    continue;
                }
                $fleet = $candidate;
                unset($ready[$i]);
                break;
            }
            if (null === $fleet && null !== $tooFar) {
                $lines[] = t('{where}: zu weit – deine Flotte bräuchte {duration}, Lieferungen dürfen höchstens {hours} Stunden dauern.', ['{where}' => $where, '{duration}' => new \App\I18n\Raw(\App\Twig\GameExtension::formatDuration($tooFar)), '{hours}' => self::MAX_DELIVERY_HOURS]);
                continue;
            }
            if (null === $fleet) {
                $lines[] = t('{where}: keine freie Handelsflotte mit genug Frachtraum mehr bei {planet}.', ['{where}' => $where, '{planet}' => $source->getName()]);
                continue;
            }
            try {
                // Assigned first, so the delivery counts as paid (no pushing limit); undone if it can't start.
                $request->assign($trader, $fleet);
                $this->em->flush();
                $this->fleetService->sendFleet($fleet, $trader, $request->getGalaxy(), $request->getSystem(), $request->getPosition(), FleetMission::Transport, $request->getGoods());
                $this->em->flush();
                $lines[] = t('{where}: {label} ist unterwegs (Ankunft {value}).', ['{where}' => $where, '{label}' => $fleet->getLabel(), '{value}' => new When($fleet->getArriveAt(), 'd.m. H:i')]);
            } catch (GameException $e) {
                $request->reopen();
                $this->em->flush();
                $lines[] = t('{where}: {error}', ['{where}' => $where, '{error}' => $e->translatable()]);
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
