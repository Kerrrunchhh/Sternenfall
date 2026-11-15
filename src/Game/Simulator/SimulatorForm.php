<?php

namespace App\Game\Simulator;

/** The posted simulator form: both sides and which button was pressed. */
final readonly class SimulatorForm
{
    public const string READ_ATTACKER = 'read_attacker';
    public const string READ_DEFENDER = 'read_defender';
    public const string SIMULATE = 'simulate';
    /** Swap the sides, e.g. to simulate intercepting the attacking fleet in space. */
    public const string SWAP = 'swap';

    public function __construct(
        public SimulatorSide $attacker = new SimulatorSide(),
        public SimulatorSide $defender = new SimulatorSide(),
        public string $do = self::SIMULATE,
        /** Names read from the reports (kept in hidden fields), shown in the result. */
        public string $attackerName = '',
        public string $defenderName = '',
    ) {
    }
}
