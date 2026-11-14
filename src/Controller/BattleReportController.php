<?php

namespace App\Controller;

use App\Entity\BattleReport;
use App\Game\BattleReportRefunds;
use App\Game\BattleReports;
use App\Game\ShipType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Shared combat reports: anyone with the link can read them for 30 days, even without logging in. */
class BattleReportController extends AbstractController
{
    #[Route('/kampfbericht/{hash}', name: 'app_battle_report', requirements: ['hash' => '[0-9a-f]{24}'], methods: ['GET'])]
    public function show(string $hash, BattleReports $reports, BattleReportRefunds $refunds, \Symfony\Contracts\Translation\TranslatorInterface $translator): Response
    {
        $report = $reports->find($hash);
        $viewer = $this->getUser();
        $viewerId = $viewer instanceof \App\Entity\User ? $viewer->getId() : null;

        return $this->render('battle_report.html.twig', [
            'report' => $report,
            'days' => BattleReport::DAYS_AVAILABLE,
            // Admins can annul the report and give ships back.
            'refund_players' => null !== $report && $this->isGranted('ROLE_ADMIN') ? $refunds->participants($report) : null,
            'ship_types' => ShipType::cases(),
            'replay' => null === $report?->getReplay() ? null : self::replayView($report->getReplay(), $viewerId, $translator),
        ], new Response(status: null === $report ? Response::HTTP_NOT_FOUND : Response::HTTP_OK));
    }

    /**
     * Sample battles to show what the replay looks like (linked from the news): always the same,
     * never expiring, no real players. The first attacker shows as the reader's own.
     */
    #[Route('/kampfbericht/beispiel/{example}', name: 'app_battle_example', requirements: ['example' => 'kolonie|verband|angriff|verteidigung'], defaults: ['example' => 'kolonie'], methods: ['GET'])]
    public function example(string $example, \Symfony\Contracts\Translation\TranslatorInterface $translator): Response
    {
        $battle = new \App\Game\Battle();
        $players = [];
        foreach (self::EXAMPLES[$example] as $i => [$side, $name, $ships, $source]) {
            $players[$name] ??= new \App\Entity\User($name);
            // Negative ids: never a real player.
            (new \ReflectionProperty(\App\Entity\User::class, 'id'))->setValue($players[$name], -1 - array_search($name, array_keys($players), true));
            $battle->add($side, $players[$name], $ships, $source);
        }

        return $this->render('battle_example.html.twig', [
            'example' => $example,
            'examples' => array_keys(self::EXAMPLES),
            'replay' => self::replayView($battle->fight()->replay(), -1, $translator),
        ]);
    }

    /** @var array<string, list<array{string, string, array<string, int>, string}>> side, player, ships, source */
    public const array EXAMPLES = [
        // A colony is attacked.
        'kolonie' => [
            ['attacker', 'Kommandantin Vega', ['falke' => 60, 'lanze' => 20, 'mantikor' => 8, 'heavy_bomber' => 6, 'invasion_ship' => 2], 'attacker'],
            ['defender', 'Gouverneur Halden', ['pfeil' => 40, 'laser_turret' => 25, 'korsar' => 10, 'hammerhai' => 4, 'freighter' => 6], 'hangar'],
        ],
        // A joint attack of two players against a planet and the waiting fleet of an alliance mate.
        'verband' => [
            ['attacker', 'Kommandantin Vega', ['falke' => 40, 'mantikor' => 6, 'heavy_bomber' => 4], 'attacker'],
            ['attacker', 'Kapitän Orsk', ['lanze' => 25, 'hammerhai' => 5, 'invasion_ship' => 3], 'joiner:1'],
            ['defender', 'Gouverneur Halden', ['pfeil' => 30, 'laser_turret' => 20, 'korsar' => 8, 'freighter' => 4], 'hangar'],
            ['defender', 'Admiralin Teska', ['inferno' => 3, 'lanze' => 15, 'falke' => 20], 'fleet:1'],
        ],
        // The same attack fleet (952 build hours) against an attack fleet waiting at home …
        'angriff' => [
            ['attacker', 'Kommandantin Vega', self::ATTACK_FLEET, 'attacker'],
            ['defender', 'Freibeuter Krask', self::ATTACK_FLEET, 'hangar'],
        ],
        // … and against a defense of the same build hours.
        'verteidigung' => [
            ['attacker', 'Kommandantin Vega', self::ATTACK_FLEET, 'attacker'],
            ['defender', 'Gouverneur Halden', ['bastion' => 12, 'hammerhai' => 14, 'falke' => 40, 'pfeil' => 40, 'laser_turret' => 30], 'hangar'],
        ],
    ];

    /** @var array<string, int> ships built to attack: Korsaren, heavy bombers, Brecher, with Lanzen */
    public const array ATTACK_FLEET = ['korsar' => 24, 'heavy_bomber' => 10, 'brecher' => 2, 'lanze' => 10];

    /**
     * The replay with names for the page, plus what every player's units did (summed per type).
     *
     * @param array<string, mixed> $replay
     *
     * @return array{groups: list<array<string, mixed>>, steps: list<array<string, mixed>>, summary: list<array<string, mixed>>, experience: list<array<string, mixed>>, viewer: ?int}
     */
    public static function replayView(array $replay, ?int $viewerId, \Symfony\Contracts\Translation\TranslatorInterface $translator): array
    {
        $groups = [];
        $summary = [];
        foreach ($replay['groups'] ?? [] as $group) {
            $type = ShipType::tryFrom((string) $group['type']);
            $group['label'] = null === $type ? (string) $group['type'] : $translator->trans($type->label());
            $group['own'] = null !== $viewerId && $group['ownerId'] === $viewerId;
            $groups[] = $group;
            $key = $group['side'].'|'.$group['owner'].'|'.$group['type'];
            $summary[$key] ??= ['side' => $group['side'], 'owner' => $group['owner'], 'label' => $group['label'], 'own' => $group['own'], 'count' => 0, 'alive' => 0, 'damage' => 0.0, 'destroyed' => 0];
            $summary[$key]['count'] += $group['count'];
            $summary[$key]['alive'] += $group['alive'];
            $summary[$key]['damage'] += $group['damage'];
            $summary[$key]['destroyed'] += $group['destroyed'];
        }

        $experience = array_map(static fn (array $e): array => $e + ['own' => null !== $viewerId && $e['ownerId'] === $viewerId], $replay['experience'] ?? []);

        return ['groups' => $groups, 'steps' => $replay['steps'] ?? [], 'summary' => array_values($summary), 'experience' => $experience, 'viewer' => $viewerId];
    }
}
