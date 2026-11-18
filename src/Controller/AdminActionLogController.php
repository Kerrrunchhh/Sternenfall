<?php

namespace App\Controller;

use App\Entity\LoginRecord;
use App\Game\ActionLog;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use function Symfony\Component\Translation\t;

/**
 * Player activity log for admins (suspected cheating): what a player did – or what two players
 * had to do with each other – in the last 30 days, with his logins. Every look-up is logged too.
 */
#[IsGranted('ROLE_ADMIN')]
class AdminActionLogController extends AbstractGameController
{
    #[Route('/admin/protokoll', name: 'app_admin_action_log', methods: ['GET'])]
    public function index(Request $request, ActionLog $log, UserRepository $users, EntityManagerInterface $em): Response
    {
        $planet = $this->currentPlanet();
        $query = $request->query;
        $find = static fn (string $name): ?\App\Entity\User => '' === trim($name) ? null : $users->findOneBy(['username' => trim($name)]);
        $player = $find($query->getString('player'));
        $with = $find($query->getString('with'));
        $kind = \array_key_exists($query->getString('kind'), ActionLog::KINDS) ? $query->getString('kind') : null;
        if ('' !== $query->getString('player') && null === $player) {
            $this->addFlash('error', t('Einen Spieler „{username}“ gibt es nicht.', ['{username}' => $query->getString('player')]));
        }

        $entries = [];
        $logins = [];
        if (null !== $player) {
            $entries = $log->forPlayer($player, $with, $kind);
            if (null === $with && (null === $kind || ActionLog::ADMIN_VIEW !== $kind)) {
                $logins = $em->createQuery('SELECT r.ip, r.userAgent, r.fingerprint, r.createdAt FROM '.LoginRecord::class.' r WHERE r.user = :p ORDER BY r.createdAt DESC')
                    ->setParameter('p', $player)->setMaxResults(200)->getArrayResult();
            }
            // Who looked at whose log, and when.
            $log->record($this->player(), ActionLog::ADMIN_VIEW, \sprintf('Protokoll von %s angesehen%s%s', $player->getUsername(), null !== $with ? ' (mit '.$with->getUsername().')' : '', null !== $kind ? ' (nur '.ActionLog::KINDS[$kind].')' : ''), $player);
            $em->flush();
        }

        return $this->renderGame('game/admin_action_log.html.twig', $planet, [
            'subject' => $player,
            'with' => $with,
            'kind' => $kind,
            'kinds' => ActionLog::KINDS,
            'entries' => $entries,
            'logins' => $logins,
            'keep_days' => ActionLog::KEEP_DAYS,
            'query' => ['player' => $query->getString('player'), 'with' => $query->getString('with')],
        ]);
    }
}
