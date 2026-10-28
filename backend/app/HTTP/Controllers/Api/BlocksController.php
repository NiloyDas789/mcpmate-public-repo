<?php

namespace McpMate\HTTP\Controllers\Api;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\HTTP\Request\Request;
use McpMate\HTTP\Response;
use McpMate\Services\Blocks\BlockTypes;
use McpMate\Services\Blocks\BlockValidator;

/**
 * Gutenberg block markup, so content the AI writes is valid before it is saved.
 *
 * GET  /blocks/types?namespace=&search=&page=&per_page= — the block types
 *      registered on the server, with their attribute schemas.
 * POST /blocks/validate { content } — parse the markup and check block names,
 *      attributes and the serialize round trip (BlockValidator). Answers 200
 *      with `valid: false` and the errors when the markup has problems.
 *
 * Both only read and need edit_posts. validate is a POST because the content
 * can be large; it is not audited (READ_HANDLERS).
 */
final class BlocksController extends RestController
{
    protected const CAPABILITIES = [
        'types'    => 'edit_posts',
        'validate' => 'edit_posts',
    ];

    protected const READ_HANDLERS = ['validate'];

    public const MAX_PER_PAGE = 100;

    public function types(Request $request): Response
    {
        $namespace = (string) $request->get('namespace', '');
        $search    = (string) $request->get('search', '');
        $page      = max(1, (int) $request->get('page', 1));
        $perPage   = min(self::MAX_PER_PAGE, max(1, (int) $request->get('per_page', 50)));

        $types = BlockTypes::all($namespace, $search);
        $total = count($types);

        return $this->ok([
            'block_types' => array_slice($types, ($page - 1) * $perPage, $perPage),
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => (int) ceil($total / $perPage),
            'namespaces'  => (object) BlockTypes::namespaces(),
        ]);
    }

    public function validate(Request $request): Response
    {
        $content = (string) $request->get('content', '');

        if (strlen($content) > BlockValidator::MAX_CONTENT_BYTES) {
            return $this->error(
                __('The content is too large to validate.', 'mcpmate'),
                400,
                'mcpmate_blocks_content_too_large',
                /* translators: %d: size in KiB. */
                sprintf(__('Validate at most %d KiB at a time.', 'mcpmate'), BlockValidator::MAX_CONTENT_BYTES / 1024)
            );
        }

        return $this->ok(BlockValidator::validate($content));
    }

    /**
     * REST `args` schema for types().
     *
     * @return array<string, array<string, mixed>>
     */
    public static function typesArgs(): array
    {
        return [
            'namespace' => [
                'type'        => 'string',
                'description' => 'Only block types in this namespace, e.g. "core" or "woocommerce".',
                'pattern'     => '^[a-z][a-z0-9_-]*$',
                'maxLength'   => 100,
            ],
            'search'    => [
                'type'        => 'string',
                'description' => 'Only block types whose name or title contains this text (case-insensitive).',
                'maxLength'   => 100,
            ],
            'page'      => [
                'type'    => 'integer',
                'minimum' => 1,
                'default' => 1,
            ],
            'per_page'  => [
                'type'    => 'integer',
                'minimum' => 1,
                'maximum' => self::MAX_PER_PAGE,
                'default' => 50,
            ],
        ];
    }

    /**
     * REST `args` schema for validate().
     *
     * @return array<string, array<string, mixed>>
     */
    public static function validateArgs(): array
    {
        return [
            'content' => [
                'type'              => 'string',
                'description'       => 'Block markup, as it would be saved in post_content.',
                'required'          => true,
                // Kept byte for byte: the round trip compares it exactly.
                'sanitize_callback' => static fn ($value) => $value,
            ],
        ];
    }
}
