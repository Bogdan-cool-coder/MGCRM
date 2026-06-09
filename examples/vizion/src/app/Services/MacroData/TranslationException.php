<?php

declare(strict_types=1);

namespace App\Services\MacroData;

/**
 * Thrown by ExpressionSqlTranslator when an expression cannot be converted to SQL.
 *
 * Callers should catch this exception and fall back to PHP-path evaluation
 * (ExpressionLanguage::evaluate) rather than aborting the request.
 */
class TranslationException extends \RuntimeException
{
}
