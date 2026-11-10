<?php

namespace App\Payment;

use App\Game\GameException;

/** Something went wrong with a payment; the message is meant for the player. */
final class PaymentException extends GameException
{
}
