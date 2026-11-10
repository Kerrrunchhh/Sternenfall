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

/**
 * Ends vacations that are over. On vacation a player may still talk and look around –
 * black board, tickers, mail, galaxy, diplomacy, alliance – but not play (no fleets,
 * no radar, no building, research or trade); those pages lead to the vacation page.
 */
#[AsEventListener(event: KernelEvents::CONTROLLER, priority: 10)]
final readonly class VacationListener
{
    /** Route names (or prefixes ending with "*") a player on vacation may use. */
    public const array ALLOWED = [
        'app_vacation*', 'app_galaxy', 'app_planet_select', 'app_alliance*', 'app_diplomacy*', 'app_tickers', 'app_ticker*',
        'app_mail*', 'app_contact_*', 'app_messages', 'app_message_*', 'app_messages_*', 'app_player', 'app_notes*', 'app_account',
        'app_account_email', 'app_support*', 'app_highscores', 'app_newspaper', 'app_game_status', 'app_ship_info', 'app_media', 'app_player_search', 'app_extended*', 'app_paypal_webhook',
    ];

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
        $route = (string) $event->getRequest()->attributes->get('_route');
        if ($object instanceof AbstractGameController && $user->isOnVacation(\DateTimeImmutable::createFromInterface($this->clock->now())) && !self::allowed($route)) {
            $url = $this->urls->generate('app_vacation');
            $event->setController(static fn (): RedirectResponse => new RedirectResponse($url));
        }
    }

    public static function allowed(string $route): bool
    {
        foreach (self::ALLOWED as $pattern) {
            if ($pattern === $route || (str_ends_with($pattern, '*') && str_starts_with($route, substr($pattern, 0, -1)))) {
                return true;
            }
        }

        return false;
    }
}
