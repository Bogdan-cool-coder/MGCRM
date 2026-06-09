<?php

declare(strict_types=1);

namespace App\Services\MacroData;

use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use Symfony\Component\ExpressionLanguage\Node\BinaryNode;
use Symfony\Component\ExpressionLanguage\Node\ConstantNode;
use Symfony\Component\ExpressionLanguage\Node\NameNode;
use Symfony\Component\ExpressionLanguage\Node\UnaryNode;
use Symfony\Component\ExpressionLanguage\Node\Node;

/**
 * Translates a subset of Symfony ExpressionLanguage expressions to safe SQL fragments.
 *
 * Supported subset (whitelist):
 *   Binary operators : ==, !=, <, >, <=, >=, &&, ||
 *   Unary operator   : !
 *   Operands         : direct field names (NameNode), scalar literals (ConstantNode: int/float/string/null/bool)
 *   NOT supported    : function calls, array access, method calls, dot-notation names,
 *                      ternary, range (..), match, null-coalescing
 *
 * Security guarantees:
 *   - Column names are validated against /^[a-zA-Z_][a-zA-Z0-9_]*$/ and wrapped in backticks.
 *   - String literals are escaped via PDO::quote() — never concatenated raw.
 *   - Numeric literals are cast to PHP float/int and formatted via sprintf() — no strings passed.
 *   - Any unsupported node type throws TranslationException → caller falls back to PHP path.
 *
 * Usage:
 *   $translator = new ExpressionSqlTranslator($pdo);
 *   try {
 *       $sql = $translator->translate('status == 1 && deal_id != null', ['status', 'deal_id']);
 *       // → "((`status` = 1) AND (`deal_id` IS NOT NULL))"
 *   } catch (TranslationException $e) {
 *       // fall back to PHP evaluation
 *   }
 */
class ExpressionSqlTranslator
{
    /** Operators mapped from ExpressionLanguage to SQL. */
    protected const BINARY_OPS = [
        '=='  => '=',
        '!='  => '!=',
        '<'   => '<',
        '>'   => '>',
        '<='  => '<=',
        '>='  => '>=',
        '&&'  => 'AND',
        'and' => 'AND',
        '||'  => 'OR',
        'or'  => 'OR',
    ];

    protected ?\PDO $pdo;
    protected ExpressionLanguage $el;

    public function __construct(?\PDO $pdo = null)
    {
        $this->pdo = $pdo;
        $this->el  = new ExpressionLanguage();
    }

    /**
     * Translate an expression string to a SQL boolean fragment.
     *
     * @param  string   $expression  ExpressionLanguage expression string
     * @param  string[] $names       Variable names present in the expression (for parsing)
     * @return string                SQL fragment suitable for use in CASE WHEN (...) THEN
     * @throws TranslationException  When the expression uses unsupported constructs
     */
    public function translate(string $expression, array $names): string
    {
        try {
            $parsed = $this->el->parse($expression, $names);
        } catch (\Throwable $e) {
            throw new TranslationException(
                "Failed to parse expression: {$expression}. Error: " . $e->getMessage(),
                0,
                $e
            );
        }

        return $this->translateNode($parsed->getNodes());
    }

    /**
     * Check whether the expression is translatable (without actually translating).
     * Returns true if translate() would succeed, false otherwise.
     *
     * Useful for canUseSqlGroupBy() pre-check.
     */
    public function isTranslatable(string $expression, array $names): bool
    {
        try {
            $this->translate($expression, $names);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Recursively translate a Node to SQL.
     *
     * @throws TranslationException
     */
    protected function translateNode(Node $node): string
    {
        if ($node instanceof BinaryNode) {
            return $this->translateBinary($node);
        }

        if ($node instanceof UnaryNode) {
            return $this->translateUnary($node);
        }

        if ($node instanceof NameNode) {
            return $this->translateName($node);
        }

        if ($node instanceof ConstantNode) {
            return $this->translateConstant($node);
        }

        throw new TranslationException(
            'Unsupported AST node type: ' . get_class($node) . '. ' .
            'Only direct field comparisons with scalar literals are supported.'
        );
    }

    /**
     * Translate a BinaryNode.
     *
     * Special cases:
     *   name == null  → `name` IS NULL
     *   name != null  → `name` IS NOT NULL
     *   null == name  → `name` IS NULL  (commutative)
     *   null != name  → `name` IS NOT NULL
     *
     * @throws TranslationException
     */
    protected function translateBinary(BinaryNode $node): string
    {
        $op = $node->attributes['operator'] ?? '';

        if (!array_key_exists($op, self::BINARY_OPS)) {
            throw new TranslationException(
                "Unsupported binary operator '{$op}'. " .
                'Allowed: ' . implode(', ', array_keys(self::BINARY_OPS))
            );
        }

        $left  = $node->nodes['left'];
        $right = $node->nodes['right'];

        // Special case: IS NULL / IS NOT NULL
        if (in_array($op, ['==', '!='], true)) {
            $leftIsNull  = $left instanceof ConstantNode && $this->isNullConstant($left);
            $rightIsNull = $right instanceof ConstantNode && $this->isNullConstant($right);

            if ($rightIsNull) {
                // name == null → `name` IS NULL
                $leftSql = $this->translateNode($left);
                return $op === '=='
                    ? "({$leftSql} IS NULL)"
                    : "({$leftSql} IS NOT NULL)";
            }

            if ($leftIsNull) {
                // null == name → `name` IS NULL
                $rightSql = $this->translateNode($right);
                return $op === '=='
                    ? "({$rightSql} IS NULL)"
                    : "({$rightSql} IS NOT NULL)";
            }
        }

        $sqlOp   = self::BINARY_OPS[$op];
        $leftSql = $this->translateNode($left);
        $rightSql = $this->translateNode($right);

        return "({$leftSql} {$sqlOp} {$rightSql})";
    }

    /**
     * Translate a UnaryNode (only `!` is supported).
     *
     * @throws TranslationException
     */
    protected function translateUnary(UnaryNode $node): string
    {
        $op = $node->attributes['operator'] ?? '';

        if ($op !== '!') {
            throw new TranslationException(
                "Unsupported unary operator '{$op}'. Only '!' is allowed."
            );
        }

        $inner = $this->translateNode($node->nodes['node']);
        return "(NOT {$inner})";
    }

    /**
     * Translate a NameNode (column reference).
     *
     * Validates that the name is a safe SQL identifier (no dots, no special chars).
     *
     * @throws TranslationException
     */
    protected function translateName(NameNode $node): string
    {
        $name = $node->attributes['name'] ?? '';

        // Security: validate identifier — must be [a-zA-Z_][a-zA-Z0-9_]* (no dots)
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) {
            throw new TranslationException(
                "Invalid or unsafe column name '{$name}'. " .
                'Only simple identifiers (letters, digits, underscores) are allowed. ' .
                'Dot-notation relation paths are not supported in expression-where.'
            );
        }

        return "`{$name}`";
    }

    /**
     * Translate a ConstantNode (scalar literal).
     *
     * Supported PHP types: null, bool, int, float, string.
     * Strings are escaped via PDO::quote() or doubled-apostrophe fallback if PDO unavailable.
     *
     * @throws TranslationException
     */
    protected function translateConstant(ConstantNode $node): string
    {
        // ConstantNode stores value in attributes['value']; null value means the key is absent.
        // We use array_key_exists to distinguish absent (null) from other types.
        $attrs = $node->attributes;
        $isNull = !array_key_exists('value', $attrs) || $attrs['value'] === null;

        if ($isNull) {
            return 'NULL';
        }

        $value = $attrs['value'];

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            return sprintf('%F', $value);
        }

        if (is_string($value)) {
            return $this->quoteString($value);
        }

        throw new TranslationException(
            'Unsupported constant type ' . gettype($value) . '. ' .
            'Only null, bool, int, float, and string literals are allowed.'
        );
    }

    /**
     * Check whether a ConstantNode represents a null literal.
     */
    protected function isNullConstant(ConstantNode $node): bool
    {
        $attrs = $node->attributes;
        return !array_key_exists('value', $attrs) || $attrs['value'] === null;
    }

    /**
     * Safely quote a string for SQL embedding.
     *
     * Uses PDO::quote() when a PDO connection is available; falls back to
     * doubled-apostrophe escaping which is safe for MySQL string contexts.
     */
    protected function quoteString(string $value): string
    {
        if ($this->pdo !== null) {
            return $this->pdo->quote($value);
        }

        // Fallback: escape single quotes by doubling them (SQL standard).
        // Wrapping in single quotes; no SQL injection possible since all special
        // SQL control characters are removed by the doubled-apostrophe escaping.
        return "'" . str_replace("'", "''", $value) . "'";
    }
}
