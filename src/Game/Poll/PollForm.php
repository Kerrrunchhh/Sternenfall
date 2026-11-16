<?php

namespace App\Game\Poll;

/** A new poll as an admin typed it: one answer per line, the run time in days and hours. */
final readonly class PollForm
{
    public function __construct(
        public string $question = '',
        public string $options = '',
        public string $questionEn = '',
        public string $optionsEn = '',
        public bool $multiple = false,
        /** Also announce it by e-mail (to players who subscribed to news). */
        public bool $mail = false,
        public int $days = 3,
        public int $hours = 0,
    ) {
    }

    /** @return list<string> */
    public function optionList(): array
    {
        return preg_split('/\R/u', $this->options) ?: [];
    }

    /** @return list<string> */
    public function optionListEn(): array
    {
        return preg_split('/\R/u', $this->optionsEn) ?: [];
    }

    public function totalHours(): int
    {
        return max(0, $this->days) * 24 + max(0, $this->hours);
    }
}
