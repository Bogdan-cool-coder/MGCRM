<?php

declare(strict_types=1);

namespace App\Domain\Sales\Enums;

/**
 * Pipeline kind. In S1.3 only `sales` is implemented; `lifecycle`/`renewal`
 * belong to the CustomerSuccess context (cs-specialist).
 */
enum PipelineKind: string
{
    case Sales = 'sales';
    case Lifecycle = 'lifecycle';
    case Renewal = 'renewal';
}
