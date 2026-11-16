<?php

namespace App\Controller;

use App\Entity\Planet;
use App\Game\BuildBot\BuildBotForm;
use App\Game\BuildBot\BuildBotSettings;
use App\Game\BuildingType;
use App\Game\Economy;
use App\Game\GameException;
use App\Game\PlanetService;
use App\Game\QueueKind;
use App\Game\ShipType;
use App\I18n\Lines;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Contracts\Translation\TranslatableInterface;

use function Symfony\Component\Translation\t;

/**
 * Build bot (for extended accounts once game.build_bot_extended_only is switched on): one set of settings per player – up to three buildings with a
 * target level and a default building, plus a unit type and build rate for the shipyard and the
 * defense. Its buttons fill the queues of the current planet or of all planets – only when pressed.
 */
class BuildBotController extends AbstractGameController
{
    public function __construct(
        /** Switch in config/services.yaml: false = every player may use the bot. */
        #[Autowire(param: 'game.build_bot_extended_only')]
        private readonly bool $extendedOnly,
    ) {
    }

    private function mayUse(\DateTimeInterface $now): bool
    {
        return !$this->extendedOnly || $this->player()->isExtended($now);
    }

    #[Route('/baubot', name: 'app_build_bot', methods: ['GET'])]
    public function index(ClockInterface $clock): Response
    {
        $planet = $this->currentPlanet();

        return $this->renderGame('game/build_bot.html.twig', $planet, [
            'settings' => $this->player()->getBuildBot(),
            'goal_slots' => range(0, BuildBotSettings::GOALS - 1),
            'buildings' => BuildingType::cases(),
            'shipyard_types' => array_values(array_filter(ShipType::cases(), static fn (ShipType $t): bool => !$t->isStationary())),
            'defense_types' => array_values(array_filter(ShipType::cases(), static fn (ShipType $t): bool => $t->isStationary())),
            'percents' => Economy::BUILD_PERCENTS,
            'shipyard' => QueueKind::Shipyard,
            'defense' => QueueKind::Defense,
            'may_use' => $this->mayUse($clock->now()),
        ]);
    }

    #[Route('/baubot', name: 'app_build_bot_run', methods: ['POST'])]
    #[IsCsrfTokenValid('build_bot', tokenKey: '_token')]
    public function run(#[MapRequestPayload] BuildBotForm $form, PlanetService $planets, EntityManagerInterface $em, ClockInterface $clock): Response
    {
        $planet = $this->currentPlanet();
        if (!$this->mayUse($clock->now())) {
            $this->addFlash('error', t('Der Baubot gehört zum erweiterten Account.'));

            return $this->redirectToRoute('app_build_bot');
        }

        // Every button saves the settings first; the bot works with what is saved.
        $this->player()->setBuildBot($form->settings());
        $em->flush();

        match ($form->do) {
            BuildBotForm::FILL_BUILDINGS => $this->flash(fn () => $this->fillBuildings($planets, $planet)),
            BuildBotForm::FILL_SHIPYARD => $this->flash(fn () => $this->fillShips($planets, $planet, QueueKind::Shipyard)),
            BuildBotForm::FILL_DEFENSE => $this->flash(fn () => $this->fillShips($planets, $planet, QueueKind::Defense)),
            BuildBotForm::FILL_ALL => $this->fillAll($planets),
            default => $this->addFlash('success', t('Baubot gespeichert.')),
        };

        return $this->redirectToRoute('app_build_bot');
    }

    /** @param callable(): TranslatableInterface $fill */
    private function flash(callable $fill): void
    {
        try {
            $this->addFlash('success', $fill());
        } catch (GameException $e) {
            $this->addFlash('error', $e->translatable());
        }
    }

    private function fillBuildings(PlanetService $planets, Planet $planet): TranslatableInterface
    {
        $placed = $planets->fillBuildingQueue($planet);
        if ([] === $placed) {
            return t('{planet}: In der Bauschleife ist kein Platz, oder der Baubot hat nichts mehr zu bauen.', ['{planet}' => $planet->getName()]);
        }

        return t('{planet}: {buildings} in die Bauschleife gestellt.', ['{planet}' => $planet->getName(), '{buildings}' => new Lines(array_map(
            static fn (array $p): TranslatableInterface => t('{building} Stufe {level}', ['{building}' => t($p['building']->label()), '{level}' => $p['level']]),
            $placed,
        ), ', ')]);
    }

    private function fillShips(PlanetService $planets, Planet $planet, QueueKind $kind): TranslatableInterface
    {
        [$ship, $count] = $planets->fillShipQueue($planet, $kind);

        return $count > 0
            ? t('{planet}: {count} × {ship} in Auftrag gegeben.', ['{planet}' => $planet->getName(), '{count}' => $count, '{ship}' => t($ship->label())])
            : t('{planet}: Für keine einzige Einheit {ship} reichen Rohstoffe, Credits oder Besatzung.', ['{planet}' => $planet->getName(), '{ship}' => t($ship->label())]);
    }

    /** Every planet with the same settings: buildings, shipyard, defense. */
    private function fillAll(PlanetService $planets): void
    {
        $settings = $this->player()->getBuildBot();
        $messages = [];
        foreach ($this->player()->getPlanets() as $planet) {
            $jobs = [];
            if ([] !== $settings->goals || null !== $settings->default) {
                $jobs[] = fn () => $this->fillBuildings($planets, $planet);
            }
            foreach ([QueueKind::Shipyard, QueueKind::Defense] as $kind) {
                if ([] !== $settings->queue($kind)['ships']) {
                    $jobs[] = fn () => $this->fillShips($planets, $planet, $kind);
                }
            }
            foreach ($jobs as $job) {
                try {
                    $messages[] = $job();
                } catch (GameException $e) {
                    $messages[] = t('{planet}: {reason}', ['{planet}' => $planet->getName(), '{reason}' => $e->translatable()]);
                }
            }
        }
        $this->addFlash('success', [] === $messages ? t('Im Baubot ist noch nichts eingestellt.') : new Lines($messages));
    }
}
