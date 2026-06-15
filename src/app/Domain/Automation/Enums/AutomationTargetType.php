<?php

declare(strict_types=1);

namespace App\Domain\Automation\Enums;

/**
 * Target entity of an automation run (AutomationRun.target_type).
 *
 * MVP supports only `deal` (sales pipelines). The enum is forward-compatible
 * with `subscription`/`contract`/`approval` once cross-pipeline automation lands
 * (a later phase — see backend plan §9).
 */
enum AutomationTargetType: string
{
    case Deal = 'deal';
}
