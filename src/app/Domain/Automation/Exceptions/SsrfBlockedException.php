<?php

declare(strict_types=1);

namespace App\Domain\Automation\Exceptions;

use RuntimeException;

/**
 * Raised when SsrfGuard rejects an outbound webhook URL. The message is safe to
 * surface to the admin / store in AutomationRun.result — it never leaks the
 * resolved internal IP.
 */
final class SsrfBlockedException extends RuntimeException {}
