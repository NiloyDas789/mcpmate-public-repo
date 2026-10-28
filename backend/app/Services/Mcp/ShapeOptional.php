<?php

namespace McpMate\Services\Mcp;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * A Shape schema for a key that may be missing from its object.
 */
final class ShapeOptional
{
    /** @var callable */
    private $schema;

    public function __construct(callable $schema)
    {
        $this->schema = $schema;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    public function __invoke($value)
    {
        return ($this->schema)($value);
    }
}
