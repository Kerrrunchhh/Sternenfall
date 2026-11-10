<?php

namespace App\EventListener;

use App\Controller\AbstractGameController;
use App\Entity\User;
use App\Game\VacationService;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** Ends vacations that are over; a player still on vacation only sees the vacation page. */
#[AsEventListener(event: KernelEvents::CONTROLLER, priority: 10)]
final readonly class VacationListener
{
    public function __construct(
        private Security $security,
        private VacationService $vacations,
        private UrlGeneratorInterface $urls,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $this->vacations->finishAllOver();
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return;
        }
        $controller = $event->getController();
        $object = \is_array($controller) ? $controller[0] : $controller;
        if ($object instanceof AbstractGameController && $user->isOnVacation(\DateTimeImmutable::createFromInterface($this->clock->now()))) {
            $url = $this->urls->generate('app_vacation');
            $event->setController(static fn (): RedirectResponse => new RedirectResponse($url));
        }
    }
}
