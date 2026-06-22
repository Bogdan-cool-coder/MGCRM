<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Shared SQL LIKE-pattern escaper. The single source of truth for building
 * injection-safe, wildcard-safe LIKE patterns across the codebase.
 *
 * A LIKE pattern treats `%` (any run of chars) and `_` (any single char) as
 * wildcards. When a user-supplied value is interpolated into a LIKE pattern,
 * those two characters MUST be escaped or the value silently behaves as a
 * wildcard (e.g. searching the tag `vip_lead` would also match `vipXlead`).
 *
 * Escaping uses the backslash as the escape character, so the backslash itself
 * must be escaped FIRST. addcslashes('\%_') escapes all three in a single pass
 * with the correct ordering (it walks the input once, emitting `\` before each
 * listed char), so `\` never double-escapes a subsequently-emitted `\%`/`\_`.
 *
 * Because Eloquent's `where(col, 'like', $pattern)` does NOT emit an
 * `ESCAPE` clause, every LIKE built from these helpers must carry an explicit
 * `ESCAPE '\'` — use the `whereLike` / `orWhereLike` query-builder macros
 * (registered in AppServiceProvider) which append it for you. Both PostgreSQL
 * and SQLite honour `LIKE ... ESCAPE '\'`.
 */
final class LikeEscape
{
    /** The escape character carried in the `ESCAPE` clause of every LIKE. */
    public const ESCAPE_CHAR = '\\';

    /**
     * Escape the LIKE wildcards (`%`, `_`) and the escape char (`\`) in a raw
     * value WITHOUT adding surrounding wildcards. Use for an exact-substring
     * match where the caller supplies its own `%` wrappers, or for an anchored
     * match.
     */
    public static function escape(string $value): string
    {
        return addcslashes($value, '\\%_');
    }

    /**
     * Escape a raw value and wrap it in `%...%` for a "contains" LIKE search.
     * The wrapping `%` are literal wildcards (not escaped); only the value's
     * own wildcard characters are neutralised.
     */
    public static function wrap(string $value): string
    {
        return '%'.self::escape($value).'%';
    }
}
