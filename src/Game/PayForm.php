<?php

namespace App\Game;

use App\I18n\NumberInput;

/** A credit payment as typed on a profile: the amount ("1.000" or "1,000") and a message. */
final readonly class PayForm
{
    public function __construct(
        public string $amount = '',
        public string $message = '',
    ) {
    }

    public function credits(): int
    {
        return (int) floor(NumberInput::amount($this->amount));
    }
}
