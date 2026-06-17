<?php

declare(strict_types=1);

namespace App\Domain\Contracts\Enums;

enum AttachmentKind: string
{
    case SignedScan = 'signed_scan'; // Required for Draft → Signed transition (guard in S2.5)
    case Payment = 'payment';
    case Other = 'other';
}
