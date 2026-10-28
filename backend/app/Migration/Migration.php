<?php

namespace McpMate\Migration;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Base class for database migrations.
 */
abstract class Migration
{
    /**
     * Run the migration (create tables, add columns, etc.).
     */
    abstract public function up(): void;

    /**
     * Reverse the migration (drop tables, remove columns, etc.).
     */
    abstract public function down(): void;
}
