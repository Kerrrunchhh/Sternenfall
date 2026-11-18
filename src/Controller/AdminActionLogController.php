<?php

namespace App\Controller;

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
 * had to do with each other – in the last 30 days. Game content only, no IP addresses. It can be
 * downloaded as CSV; every look-up and download is logged too.
 */
#[IsGranted('ROLE_ADMIN')]
class AdminActionLogController extends AbstractGameController
{
    #[Route('/admin/protokoll', name: 'app_admin_action_log', methods: ['GET'])]
    public function index(Request $request, ActionLog $log, UserRepository $users, EntityManagerInterface $em): Response
    {
        $planet = $this->currentPlanet();
        [$player, $with, $kind] = $this->filter($request, $users);
        $entries = [];
        if (null !== $player) {
            $entries = $log->forPlayer($player, $with, $kind);
            $log->record($this->player(), ActionLog::ADMIN_VIEW, \sprintf('Protokoll von %s angesehen%s', $player->getUsername(), $this->describe($with, $kind)), $player);
            $em->flush();
        }

        return $this->renderGame('game/admin_action_log.html.twig', $planet, [
            'subject' => $player,
            'with' => $with,
            'kind' => $kind,
            'kinds' => ActionLog::KINDS,
            'entries' => $entries,
            'keep_days' => ActionLog::KEEP_DAYS,
            'query' => ['player' => $request->query->getString('player'), 'with' => $request->query->getString('with')],
        ]);
    }

    /** The same view as CSV (semicolons, UTF-8 with BOM: opens directly in Excel). */
    #[Route('/admin/protokoll.csv', name: 'app_admin_action_log_csv', methods: ['GET'])]
    public function csv(Request $request, ActionLog $log, UserRepository $users, EntityManagerInterface $em): Response
    {
        $this->currentPlanet();
        [$player, $with, $kind] = $this->filter($request, $users);
        if (null === $player) {
            return $this->redirectToRoute('app_admin_action_log');
        }
        $entries = $log->forPlayer($player, $with, $kind, 100000);
        $log->record($this->player(), ActionLog::ADMIN_VIEW, \sprintf('Protokoll von %s heruntergeladen%s', $player->getUsername(), $this->describe($with, $kind)), $player);
        $em->flush();

        $out = fopen('php://temp', 'r+');
        fwrite($out, "\u{FEFF}");
        fputcsv($out, ['Zeit', 'Art', 'Spieler', 'betrifft', 'Was'], ';', '"', '');
        foreach ($entries as $e) {
            fputcsv($out, [
                \App\I18n\DisplayZone::local($e->getCreatedAt())->format('d.m.Y H:i:s'),
                ActionLog::KINDS[$e->getKind()] ?? $e->getKind(),
                $e->getActor()->getUsername(),
                $e->getOther()?->getUsername() ?? '',
                $e->getText(),
            ], ';', '"', '');
        }
        rewind($out);
        $name = \sprintf('protokoll-%s%s-%s.csv', $player->getUsername(), null !== $with ? '-'.$with->getUsername() : '', (new \DateTimeImmutable())->format('Y-m-d'));

        return new Response((string) stream_get_contents($out), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => \Symfony\Component\HttpFoundation\HeaderUtils::makeDisposition('attachment', $name, (string) preg_replace('/[^A-Za-z0-9._-]/', '_', $name)),
        ]);
    }

    /** @return array{?\App\Entity\User, ?\App\Entity\User, ?string} player, other player, kind */
    private function filter(Request $request, UserRepository $users): array
    {
        $query = $request->query;
        $find = static fn (string $name): ?\App\Entity\User => '' === trim($name) ? null : $users->findOneBy(['username' => trim($name)]);
        $player = $find($query->getString('player'));
        if ('' !== $query->getString('player') && null === $player) {
            $this->addFlash('error', t('Einen Spieler „{username}“ gibt es nicht.', ['{username}' => $query->getString('player')]));
        }

        return [$player, $find($query->getString('with')), \array_key_exists($query->getString('kind'), ActionLog::KINDS) ? $query->getString('kind') : null];
    }

    private function describe(?\App\Entity\User $with, ?string $kind): string
    {
        return (null !== $with ? ' (mit '.$with->getUsername().')' : '').(null !== $kind ? ' (nur '.ActionLog::KINDS[$kind].')' : '');
    }
}
