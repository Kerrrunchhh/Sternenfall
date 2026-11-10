<?php

namespace App\Controller;

use App\Entity\User;
use App\Game\AccountDeletion;
use App\Game\GameException;
use App\Game\Inbox;
use App\Game\VacationService;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Admin area for players: roles, vacation, messages, deleting. */
#[IsGranted('ROLE_ADMIN')]
class AdminUserController extends AbstractGameController
{
    /** @var array<string, string> role => label (players have none of these) */
    public const array ROLES = ['player' => 'Spieler', 'ROLE_MODERATOR' => 'Moderator', 'ROLE_ADMIN' => 'Admin'];

    #[Route('/admin/benutzer', name: 'app_admin_users', methods: ['GET'])]
    public function index(Request $request, UserRepository $users): Response
    {
        $query = trim($request->query->getString('q'));
        $role = $request->query->getString('role');
        $qb = $users->createQueryBuilder('u')->where('u.system = false')->orderBy('u.username', 'ASC')->setMaxResults(200);
        if ('' !== $query) {
            $qb->andWhere('u.username LIKE :q OR u.email LIKE :q')->setParameter('q', '%'.addcslashes($query, '%_').'%');
        }
        $list = $qb->getQuery()->getResult();
        if (isset(self::ROLES[$role])) {
            $list = array_values(array_filter($list, static fn (User $u): bool => self::roleOf($u) === $role));
        }

        return $this->renderGame('game/admin_users.html.twig', $this->currentPlanet(), [
            'users' => $list,
            'query' => $query,
            'role' => $role,
            'roles' => self::ROLES,
        ]);
    }

    #[Route('/admin/benutzer/{id}', name: 'app_admin_user', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid('admin_users', tokenKey: '_token')]
    public function act(User $user, Request $request, EntityManagerInterface $em, VacationService $vacations, Inbox $inbox, AccountDeletion $deletion): Response
    {
        $this->currentPlanet();
        $form = $request->request;
        $admin = $this->player();
        try {
            switch ($form->getString('do')) {
                case 'role':
                    $role = $form->getString('role');
                    if (!isset(self::ROLES[$role])) {
                        throw new GameException('Unbekannte Rolle.');
                    }
                    if ($user->isSameAs($admin) && 'ROLE_ADMIN' !== $role) {
                        throw new GameException('Deine eigene Adminrolle kannst du dir nicht nehmen.');
                    }
                    $keep = array_values(array_diff($user->getRoles(), ['ROLE_USER', 'ROLE_ADMIN', 'ROLE_MODERATOR']));
                    $user->setRoles('player' === $role ? $keep : [...$keep, $role]);
                    $em->flush();
                    $this->addFlash('success', \sprintf('%s ist jetzt %s.', $user->getUsername(), self::ROLES[$role]));
                    break;
                case 'vacation':
                    $reason = trim($form->getString('reason'));
                    if ('' === $reason) {
                        throw new GameException('Gib einen Grund an – der Spieler bekommt ihn mitgeteilt.');
                    }
                    $until = $vacations->impose($user, VacationService::parseDays($form->getString('days')), $reason);
                    $this->addFlash('success', \sprintf('%s ist bis %s im Urlaub.', $user->getUsername(), $until->setTimezone(new \DateTimeZone('Europe/Berlin'))->format('d.m.Y H:i')));
                    break;
                case 'message':
                    $body = trim($form->getString('body'));
                    if ('' === $body) {
                        throw new GameException('Die Nachricht ist leer.');
                    }
                    $inbox->send($user, 'Nachricht der Spielleitung', $body."\n\n– ".$admin->getUsername().' (Spielleitung)');
                    $em->flush();
                    $this->addFlash('success', 'Nachricht an '.$user->getUsername().' gesendet.');
                    break;
                case 'delete':
                    if ($user->isSameAs($admin)) {
                        throw new GameException('Dein eigenes Konto löschst du unter Konto.');
                    }
                    if ($user->getUsername() !== trim($form->getString('confirm'))) {
                        throw new GameException('Zur Bestätigung den Spielernamen genau eintippen.');
                    }
                    $name = $user->getUsername();
                    $user->requestDeletion(new \DateTimeImmutable(), 'destroy' === $form->getString('planets'));
                    $deletion->delete($user);
                    $this->addFlash('success', \sprintf('%s wurde gelöscht; seine Planeten sind herrenlos.', $name));
                    break;
                default:
                    throw new GameException('Unbekannte Aktion.');
            }
        } catch (GameException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_admin_users', ['q' => $form->getString('q')]);
    }

    public static function roleOf(User $user): string
    {
        return \in_array('ROLE_ADMIN', $user->getRoles(), true) ? 'ROLE_ADMIN' : (\in_array('ROLE_MODERATOR', $user->getRoles(), true) ? 'ROLE_MODERATOR' : 'player');
    }
}
