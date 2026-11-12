<?php

namespace App\Controller;

use App\Entity\BattleReport;
use App\Game\AttackBans;
use App\Game\BattleReportRefunds;
use App\Game\GameException;
use App\Repository\AttackBanRepository;
use App\Repository\BattleReportRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use function Symfony\Component\Translation\t;

/** Game control for admins: a general attack ban, and annulling combat reports with refunds. */
#[IsGranted('ROLE_ADMIN')]
class AdminGameController extends AbstractGameController
{
    public function __construct(
        private readonly AttackBans $bans,
        private readonly AttackBanRepository $banRepository,
        private readonly BattleReportRepository $reports,
        private readonly BattleReportRefunds $refunds,
    ) {
    }

    #[Route('/admin/spiel', name: 'app_admin_game', methods: ['GET'])]
    public function index(): Response
    {
        return $this->renderGame('game/admin_game.html.twig', $this->currentPlanet(), [
            'current_ban' => $this->bans->current(),
            'bans' => $this->banRepository->findLatest(),
            'reports' => $this->reports->findBy([], ['createdAt' => 'DESC'], 50),
            'max_hours' => AttackBans::MAX_HOURS,
        ]);
    }

    #[Route('/admin/spiel/angriffssperre', name: 'app_admin_attack_ban', methods: ['POST'])]
    #[IsCsrfTokenValid('admin', tokenKey: '_token')]
    public function impose(Request $request): Response
    {
        $this->currentPlanet();
        $hours = trim($request->request->getString('hours'));
        $this->attempt(function () use ($request, $hours): void {
            $this->bans->impose($request->request->getString('reason'), '' === $hours ? null : (int) $hours);
        }, t('Angriffssperre verhängt.'));

        return $this->redirectToRoute('app_admin_game');
    }

    #[Route('/admin/spiel/angriffssperre/aufheben', name: 'app_admin_attack_ban_lift', methods: ['POST'])]
    #[IsCsrfTokenValid('admin', tokenKey: '_token')]
    public function lift(): Response
    {
        $this->currentPlanet();
        $this->bans->lift();
        $this->addFlash('success', t('Angriffssperre aufgehoben.'));

        return $this->redirectToRoute('app_admin_game');
    }

    #[Route('/admin/kampfbericht/{hash}/annullieren', name: 'app_admin_report_annul', requirements: ['hash' => '[0-9a-f]{24}'], methods: ['POST'])]
    #[IsCsrfTokenValid('admin', tokenKey: '_token')]
    public function annul(string $hash, Request $request): Response
    {
        $this->currentPlanet();
        $report = $this->reports->findOneBy(['hash' => $hash]) ?? throw $this->createNotFoundException();
        $form = $request->request;
        try {
            $count = $this->refunds->annul($report, $form->getString('reason'), $form->all('ships'), $form->all('planet'));
            $this->addFlash('success', 0 === $count ? t('Kampfbericht annulliert.') : t('Kampfbericht annulliert, Schiffe an {count} Spieler erstattet.', ['{count}' => $count]));
        } catch (GameException $e) {
            $this->addFlash('error', $e->translatable());
        }

        return $this->redirectToRoute('app_battle_report', ['hash' => $hash]);
    }
}
