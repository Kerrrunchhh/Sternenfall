<?php

namespace App\EventListener;

use App\Controller\AbstractGameController;
use App\Controller\Attribute\RequiresFleetControl;
use App\Entity\Planet;
use App\Entity\User;
use App\Game\BuildingType;
use App\Game\PlanetService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use function Symfony\Component\Translation\t;

/** Refuses fleet commands (#[RequiresFleetControl]) unless the selected planet has a fleet control center. */
#[AsEventListener]
final readonly class FleetControlListener
{
    public function __construct(
        private Security $security,
        private PlanetService $planets,
        private UrlGeneratorInterface $urls,
    ) {
    }

    public function __invoke(ControllerArgumentsEvent $event): void
    {
        $attribute = $event->getAttributes(RequiresFleetControl::class)[0] ?? null;
        $user = $this->security->getUser();
        if (!$attribute instanceof RequiresFleetControl || !$user instanceof User) {
            return;
        }
        $request = $event->getRequest();
        $selectedId = $request->hasSession() ? $request->getSession()->get(AbstractGameController::SESSION_PLANET) : null;
        $planet = $user->getPlanets()->findFirst(static fn (int $key, Planet $p): bool => $p->getId() === $selectedId) ?? $user->getPlanets()->first();
        if (!$planet instanceof Planet) {
            return;
        }
        // A control center that has just been finished counts.
        $this->planets->synchronize($planet);
        if ($planet->getBuildingLevel(BuildingType::FleetControl) > 0) {
            return;
        }

        $session = $request->getSession();
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('error', t('Von {name} [{coords}] aus kannst du keine Flotten steuern: Dort steht kein Flottenkontrollzentrum. Wähle links einen Planeten mit Flottenkontrollzentrum oder baue eins.', ['{name}' => $planet->getName(), '{coords}' => $planet->getCoordinates()]));
        }
        $url = $this->urls->generate($attribute->redirectTo);
        $event->setController(static fn (): RedirectResponse => new RedirectResponse($url));
    }
}
