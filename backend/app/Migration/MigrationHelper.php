<?php

namespace McpMate\Migration;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Loads and runs database migration files.
 */
final class MigrationHelper
{
    /**
     * Run all migrations in the given config (calls up() on each).
     *
     * @param array{path: string, migrations: string[]} $config
     */
    public static function migrate(array $config): void
    {
        $path = $config['path'];

        foreach ($config['migrations'] as $migration) {
            $file = $path . $migration . '.php';

            if (is_readable($file)) {
                require_once $file;

                if (class_exists($migration)) {
                    (new $migration())->up();
                }
            }
        }
    }

    /**
     * Reverse all migrations in the given config (calls down() on each).
     *
     * @param array{path: string, migrations: string[]} $config
     */
    public static function drop(array $config): void
    {
        $path = $config['path'];

        foreach ($config['migrations'] as $migration) {
            $file = $path . $migration . '.php';

            if (is_readable($file)) {
                require_once $file;

                if (class_exists($migration)) {
                    (new $migration())->down();
                }
            }
        }
    }
}
