<?php

namespace App\Game\BuildBot;

/** The posted build bot form: the settings (as typed) and which button was pressed. */
final readonly class BuildBotForm
{
    public const string SAVE = 'save';
    public const string FILL_BUILDINGS = 'fill_buildings';
    public const string FILL_SHIPYARD = 'fill_shipyard';
    public const string FILL_DEFENSE = 'fill_defense';
    /** Every planet, with the same settings. */
    public const string FILL_ALL = 'fill_all';

    /**
     * @param list<array{building?: string, level?: string}>              $goals
     * @param array<string, array{ship?: string, percent?: string, clear?: string}> $queues
     */
    public function __construct(
        public array $goals = [],
        public string $default = '',
        public array $queues = [],
        public string $do = self::SAVE,
    ) {
    }

    public function settings(): BuildBotSettings
    {
        return BuildBotSettings::fromArray(['goals' => array_values($this->goals), 'default' => $this->default, 'queues' => $this->queues]);
    }
}
