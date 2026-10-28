<?php

namespace McpMate\Services\SearchReplace;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Replaces a string inside a database value without breaking PHP
 * serialization.
 *
 * A serialized value is decoded, the replacement is applied to every string
 * inside it (array values, stdClass properties, nested serialized strings),
 * and it is serialized again, so every `s:N:` length is correct. Array keys
 * are left alone, as in WP-CLI.
 *
 * Values that cannot be changed safely are left untouched and counted in
 * `skipped`: objects of classes other than stdClass (decoded as
 * `__PHP_Incomplete_Class`, whose properties PHP will not let us change),
 * corrupt serialized strings (a plain str_replace would corrupt them
 * further), and anything nested deeper than Serialized::MAX_DEPTH.
 *
 * A value with no match is returned byte-for-byte unchanged.
 */
final class SerializedReplacer
{
    private string $search;

    private string $replace;

    private int $replacements = 0;

    private int $skipped = 0;

    private function __construct(string $search, string $replace)
    {
        $this->search  = $search;
        $this->replace = $replace;
    }

    /**
     * @return array{value: string, replacements: int, skipped: int}
     */
    public static function replace(string $value, string $search, string $replace): array
    {
        if ($search === '' || strpos($value, $search) === false) {
            return ['value' => $value, 'replacements' => 0, 'skipped' => 0];
        }

        $replacer = new self($search, $replace);
        $result   = $replacer->string($value, 0);

        return ['value' => $result, 'replacements' => $replacer->replacements, 'skipped' => $replacer->skipped];
    }

    private function string(string $value, int $depth): string
    {
        if (strpos($value, $this->search) === false) {
            return $value;
        }

        if (!Serialized::isSerialized($value)) {
            $result = str_replace($this->search, $this->replace, $value, $count);
            $this->replacements += $count;

            return $result;
        }

        $decoded = $depth < Serialized::MAX_DEPTH ? Serialized::decode($value) : ['ok' => false, 'value' => null];
        if (!$decoded['ok']) {
            $this->skipped++;

            return $value;
        }

        $before = $this->replacements;
        $data   = $this->value($decoded['value'], $depth + 1);

        return $this->replacements === $before ? $value : Serialized::encode($data);
    }

    /**
     * @param mixed $data
     * @return mixed
     */
    private function value($data, int $depth)
    {
        if (is_string($data)) {
            return $this->string($data, $depth);
        }

        if ($depth >= Serialized::MAX_DEPTH && (is_array($data) || is_object($data))) {
            $this->skipped++;

            return $data;
        }

        if (is_array($data)) {
            foreach ($data as $key => $item) {
                $data[$key] = $this->value($item, $depth + 1);
            }

            return $data;
        }

        if ($data instanceof \__PHP_Incomplete_Class) {
            if (strpos(Serialized::encode($data), $this->search) !== false) {
                $this->skipped++;
            }

            return $data;
        }

        if ($data instanceof \stdClass) {
            foreach (get_object_vars($data) as $key => $item) {
                $data->{$key} = $this->value($item, $depth + 1);
            }
        }

        return $data;
    }
}
