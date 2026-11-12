<?php

namespace App\Controller;

use App\I18n\NumberInput;
use App\Entity\Bounty;
use App\Entity\Contract;
use App\Game\BountyService;
use App\Game\ContractService;
use App\Game\ShipType;
use App\Repository\BountyRepository;
use App\Repository\ContractRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;

use function Symfony\Component\Translation\t;

/** Contracts: planet protection and invasions for a cash reward, and bounties on players. */
class ContractController extends AbstractGameController
{
    public function __construct(
        private readonly ContractService $service,
        private readonly ContractRepository $contracts,
        private readonly BountyService $bounties,
        private readonly BountyRepository $bountyRepository,
    ) {
    }

    #[Route('/auftraege', name: 'app_contracts', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $planet = $this->currentPlanet();

        return $this->renderGame('game/contracts.html.twig', $planet, [
            'open' => array_values(array_filter($this->contracts->findOpen(), fn (Contract $c): bool => !$c->getClient()->isSameAs($this->player()))),
            'mine' => $this->contracts->findOf($this->player()),
            'min_days' => Contract::MIN_DAYS,
            'max_days' => Contract::MAX_DAYS,
            'bounties' => $this->bountyRepository->findOpen(),
            'my_bounties' => $this->bountyRepository->findOf($this->player()),
            'bounty_target' => $request->query->getString('bounty'),
            'bounty_min_days' => Bounty::MIN_DAYS,
            'bounty_max_days' => Bounty::MAX_DAYS,
            'bounty_example' => [
                'ships' => array_map(static fn (string $type, int $count): array => ['type' => ShipType::from($type), 'count' => $count], array_keys(BountyService::EXAMPLE_FLEET), BountyService::EXAMPLE_FLEET),
                'shield' => BountyService::exampleShield(),
            ],
            'prefill' => ['galaxy' => $request->query->getInt('galaxy', $planet->getGalaxy()), 'system' => $request->query->getInt('system', $planet->getSystem()), 'position' => $request->query->getInt('position', $planet->getPosition()), 'kind' => $request->query->getString('kind', Contract::PROTECTION)],
        ]);
    }

    #[Route('/auftraege/neu', name: 'app_contract_create', methods: ['POST'])]
    #[IsCsrfTokenValid('contracts', tokenKey: '_token')]
    public function create(Request $request): Response
    {
        $this->currentPlanet();
        $f = $request->request;
        $reward = NumberInput::amount($f->getString('reward'));
        $this->attempt(fn () => $this->service->create($this->player(), $f->getString('kind'), $f->getInt('galaxy'), $f->getInt('system'), $f->getInt('position'), $reward, $f->getInt('days'), $f->getString('note')), t('Auftrag angelegt – die Belohnung ist bis zum Ende verwahrt.'));

        return $this->redirectToRoute('app_contracts');
    }

    #[Route('/auftraege/{id}/bewerben', name: 'app_contract_apply', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid('contracts', tokenKey: '_token')]
    public function apply(Contract $contract, Request $request): Response
    {
        $this->currentPlanet();
        $this->attempt(fn () => $this->service->apply($contract, $this->player(), $request->request->getString('message')), t('Bewerbung gesendet.'));

        return $this->redirectToRoute('app_contracts');
    }

    #[Route('/auftraege/{id}/vergeben', name: 'app_contract_accept', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid('contracts', tokenKey: '_token')]
    public function accept(Contract $contract, Request $request): Response
    {
        $this->currentPlanet();
        $this->attempt(fn () => $this->service->accept($contract, $this->player(), $request->request->getInt('application')), t('Auftrag vergeben.'));

        return $this->redirectToRoute('app_contracts');
    }

    #[Route('/auftraege/{id}/zurueckziehen', name: 'app_contract_cancel', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid('contracts', tokenKey: '_token')]
    public function cancel(Contract $contract): Response
    {
        $this->currentPlanet();
        $this->attempt(fn () => $this->service->cancel($contract, $this->player()), t('Auftrag zurückgezogen, die Belohnung ist wieder bei dir.'));

        return $this->redirectToRoute('app_contracts');
    }

    #[Route('/kopfgeld/neu', name: 'app_bounty_create', methods: ['POST'])]
    #[IsCsrfTokenValid('contracts', tokenKey: '_token')]
    public function createBounty(Request $request): Response
    {
        $this->currentPlanet();
        $f = $request->request;
        $this->attempt(
            function () use ($f): void {
                $this->bounties->create($this->player(), $f->getString('target'), NumberInput::amount($f->getString('reward')), NumberInput::amount($f->getString('shield')), $f->getInt('days'), $f->getString('note'));
            },
            t('Kopfgeld ausgesetzt – die Credits sind verwahrt, bis es jemand verdient oder die Zeit abläuft.'),
        );

        return $this->redirect($this->generateUrl('app_contracts').'#kopfgeld');
    }

    #[Route('/kopfgeld/{id}/zurueckziehen', name: 'app_bounty_cancel', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid('contracts', tokenKey: '_token')]
    public function cancelBounty(Bounty $bounty): Response
    {
        $this->currentPlanet();
        $this->attempt(fn () => $this->bounties->cancel($bounty, $this->player()), t('Kopfgeld zurückgezogen, die Credits sind wieder bei dir.'));

        return $this->redirect($this->generateUrl('app_contracts').'#kopfgeld');
    }
}
