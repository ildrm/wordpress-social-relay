<?php
namespace SocialRelay;

final class Rules
{
    public static function validate($node, int $depth = 0, int &$count = 0): bool
    {
        if (!is_array($node) || $depth > 8 || ++$count > 100) {
            return false;
        }
        if (isset($node['all']) || isset($node['any'])) {
            $key = isset($node['all']) ? 'all' : 'any';
            if (count($node) !== 1 || !is_array($node[$key]) || count($node[$key]) < 1 || count($node[$key]) > 20) {
                return false;
            }
            foreach ($node[$key] as $child) {
                if (!self::validate($child, $depth + 1, $count)) {
                    return false;
                }
            }
            return true;
        }
        if (isset($node['not'])) {
            return count($node) === 1 && self::validate($node['not'], $depth + 1, $count);
        }
        $field = $node['field'] ?? null;
        $operator = $node['operator'] ?? null;
        $allowed = ['eq', 'neq', 'contains', 'in', 'exists', 'gt', 'gte', 'lt', 'lte'];
        if (!is_string($field) || !is_string($operator) || !in_array($operator, $allowed, true) || !array_key_exists('value', $node)) {
            return false;
        }
        if (!in_array($field, ['post_type', 'profile', 'title', 'excerpt', 'featured_image'], true)
            && !preg_match('/^(taxonomy|field):[a-z][a-z0-9_\-]{0,99}$/', $field)) {
            return false;
        }
        if (count($node) !== 3) {
            return false;
        }
        $value = $node['value'];
        if ($operator !== 'in' && !is_scalar($value)) {
            return false;
        }
        return is_scalar($value) || (is_array($value) && count($value) <= 30 && count(array_filter($value, 'is_scalar')) === count($value));
    }

    public static function evaluate(array $node, array $content): array
    {
        if (isset($node['all'])) {
            $children = array_map(static fn($child) => self::evaluate($child, $content), $node['all']);
            return ['type' => 'all', 'pass' => !in_array(false, array_column($children, 'pass'), true), 'children' => $children];
        }
        if (isset($node['any'])) {
            $children = array_map(static fn($child) => self::evaluate($child, $content), $node['any']);
            return ['type' => 'any', 'pass' => in_array(true, array_column($children, 'pass'), true), 'children' => $children];
        }
        if (isset($node['not'])) {
            $child = self::evaluate($node['not'], $content);
            return ['type' => 'not', 'pass' => !$child['pass'], 'children' => [$child]];
        }
        $field = $node['field'];
        $operator = $node['operator'];
        $expected = $node['value'];
        $actual = self::value($field, $content);
        $pass = self::compare($actual, $operator, $expected);
        return ['type' => 'condition', 'pass' => $pass, 'field' => $field, 'operator' => $operator, 'expected' => $expected, 'actual' => $actual];
    }

    private static function value(string $field, array $content)
    {
        if (str_starts_with($field, 'taxonomy:')) {
            return $content['taxonomies'][substr($field, 9)] ?? [];
        }
        if (str_starts_with($field, 'field:')) {
            return $content['fields'][substr($field, 6)] ?? null;
        }
        return $content[$field] ?? null;
    }

    private static function compare($actual, string $operator, $expected): bool
    {
        if ($operator === 'exists') {
            return $actual !== null && $actual !== '' && $actual !== [];
        }
        if ($actual === null) {
            return false;
        }
        if ($operator === 'contains') {
            return is_array($actual) ? in_array((string) $expected, array_map('strval', $actual), true) : (is_scalar($actual) && str_contains((string) $actual, (string) $expected));
        }
        if ($operator === 'in') {
            $choices = is_array($expected) ? array_map('strval', $expected) : [(string) $expected];
            return is_array($actual) ? count(array_intersect(array_map('strval', $actual), $choices)) > 0 : in_array((string) $actual, $choices, true);
        }
        if (in_array($operator, ['gt', 'gte', 'lt', 'lte'], true)) {
            if (!is_numeric($actual) || !is_numeric($expected)) {
                return false;
            }
            return match ($operator) {
                'gt' => (float) $actual > (float) $expected,
                'gte' => (float) $actual >= (float) $expected,
                'lt' => (float) $actual < (float) $expected,
                'lte' => (float) $actual <= (float) $expected,
            };
        }
        $equal = is_array($actual) ? in_array((string) $expected, array_map('strval', $actual), true) : (string) $actual === (string) $expected;
        return $operator === 'eq' ? $equal : !$equal;
    }
}
