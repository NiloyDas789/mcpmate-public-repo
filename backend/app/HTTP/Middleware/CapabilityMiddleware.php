<?php

namespace McpMate\HTTP\Middleware;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\HTTP\Request\Request;
use McpMate\HTTP\Response;
use McpMate\Utils\Capabilities;

/**
 * Requires the current user to have every listed capability.
 *
 * Usage in route files (alias `can`, params after the colon):
 *   Route::get('posts', [...])->middleware('can:edit_posts');
 *   Route::post('media', [...])->middleware('can:upload_files,edit_posts');
 *
 * Object-level checks (`current_user_can( 'edit_post', $id )`) belong in the
 * controller, after the object ID has been validated.
 */
final class CapabilityMiddleware
{
    /**
     * @return Response|bool
     */
    public function handle(Request $request, string ...$capabilities)
    {
        // A `can` spec without capabilities is a misconfigured route: deny.
        if ($capabilities === []) {
            return self::deny();
        }

        foreach ($capabilities as $capability) {
            if ($capability === '') {
                return self::deny();
            }

            if (!Capabilities::check($capability)) {
                return self::deny()->withHint(sprintf(
                    /* translators: %s: WordPress capability name, e.g. manage_options. */
                    __('This request needs the "%s" capability. Use a WordPress account that has it.', 'mcpmate'),
                    $capability
                ));
            }
        }

        return true;
    }

    private static function deny(): Response
    {
        return Response::error(
            __('Sorry, you are not allowed to do that.', 'mcpmate'),
            403,
            'mcpmate_forbidden'
        );
    }
}
