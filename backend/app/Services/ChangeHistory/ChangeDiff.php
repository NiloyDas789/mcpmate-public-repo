<?php

namespace McpMate\Services\ChangeHistory;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The difference between two snapshots, per field, for the "Before and
 * after" drawer (docs/ui.md §5.4.2). Computed on the server so the admin
 * app needs no diff library.
 *
 * Each field is `unchanged` (with its value), `added`, `removed` or
 * `changed`. A changed field carries line operations, `equal`, `add` or
 * `remove`, each with its lines: text is split into lines, and arrays are
 * compared as pretty-printed JSON. Lines are compared with WordPress's
 * bundled Text_Diff.
 */
final class ChangeDiff
{
    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     * @return array{fields: array<int, array<string, mixed>>, added: int, removed: int}
     */
    public static function diff(?array $before, ?array $after): array
    {
        $before = $before ?? [];
        $after  = $after ?? [];
        $fields = [];
        $added  = 0;
        $removed = 0;

        foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $field) {
            $has    = [array_key_exists($field, $before), array_key_exists($field, $after)];
            $old    = $before[$field] ?? null;
            $new    = $after[$field] ?? null;
            $status = 'changed';

            if ($has[0] && $has[1] && $old === $new) {
                $fields[] = ['field' => (string) $field, 'status' => 'unchanged', 'value' => $new];
                continue;
            }

            if (!$has[0]) {
                $status = 'added';
            } elseif (!$has[1]) {
                $status = 'removed';
            }

            $ops = self::lines($has[0] ? self::lineList($old) : [], $has[1] ? self::lineList($new) : []);

            foreach ($ops as $op) {
                if ($op['op'] === 'add') {
                    $added += count($op['lines']);
                } elseif ($op['op'] === 'remove') {
                    $removed += count($op['lines']);
                }
            }

            $fields[] = ['field' => (string) $field, 'status' => $status, 'ops' => $ops];
        }

        return ['fields' => $fields, 'added' => $added, 'removed' => $removed];
    }

    /**
     * Line operations turning $from into $to.
     *
     * @param string[] $from
     * @param string[] $to
     * @return array<int, array{op: string, lines: string[]}>
     */
    public static function lines(array $from, array $to): array
    {
        if (!class_exists('Text_Diff', false)) {
            require_once ABSPATH . 'wp-includes/Text/Diff.php';
        }

        $ops = [];

        foreach ((new \Text_Diff('auto', [$from, $to]))->getDiff() as $edit) {
            if ($edit instanceof \Text_Diff_Op_copy) {
                $ops[] = ['op' => 'equal', 'lines' => (array) $edit->orig];
            } elseif ($edit instanceof \Text_Diff_Op_delete) {
                $ops[] = ['op' => 'remove', 'lines' => (array) $edit->orig];
            } elseif ($edit instanceof \Text_Diff_Op_add) {
                $ops[] = ['op' => 'add', 'lines' => (array) $edit->final];
            } elseif ($edit instanceof \Text_Diff_Op_change) {
                $ops[] = ['op' => 'remove', 'lines' => (array) $edit->orig];
                $ops[] = ['op' => 'add', 'lines' => (array) $edit->final];
            }
        }

        return $ops;
    }

    /**
     * @param mixed $value
     * @return string[]
     */
    private static function lineList($value): array
    {
        if (is_array($value)) {
            $value = (string) wp_json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } elseif (is_bool($value)) {
            $value = $value ? 'true' : 'false';
        } elseif ($value === null) {
            $value = 'null';
        }

        return explode("\n", str_replace(["\r\n", "\r"], "\n", (string) $value));
    }
}
