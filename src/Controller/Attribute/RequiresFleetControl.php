<?php

namespace App\Controller\Attribute;

/**
 * Fleet commands: only allowed while the selected planet has a fleet control center
 * (checked by App\EventListener\FleetControlListener before the action runs).
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final readonly class RequiresFleetControl
{
    public function __construct(
        /** Route to go back to when the command is refused. */
        public string $redirectTo = 'app_fleet',
    ) {
    }
}
