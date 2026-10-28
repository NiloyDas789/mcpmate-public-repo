<?php

namespace McpMate\HTTP\Controllers;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\HTTP\Request\Request;
use McpMate\HTTP\Response;
use McpMate\Services\AuditLog\Logger;
use McpMate\Services\AuditLog\Retention;
use McpMate\Services\ChangeHistory\ChangeCapture;
use McpMate\Services\Settings\Preferences;
use McpMate\Services\Settings\PreferencesException;
use McpMate\Services\Stats\Stats;

/**
 * The Settings page (docs/ui.md §5.5, B8). AJAX routes (nonce + admin).
 *
 * `preferences/update {changes}` saves only valid values: one invalid value
 * (or a pair that disagrees, Preferences::crossCheck()) is a 400 that names
 * each invalid key (`data.errors`) and saves nothing.
 * Unknown keys are ignored. Saving writes an audit row with the keys and
 * values that changed.
 */
final class PreferencesController
{
    public function show(Request $request): Response
    {
        return Response::success(self::view());
    }

    public function update(Request $request): Response
    {
        $changes = $request->get('changes', []);

        // FormData sends objects as JSON strings.
        if (is_string($changes)) {
            $changes = json_decode($changes, true);
        }

        if (!is_array($changes)) {
            return Response::error(__('The settings are invalid.', 'mcpmate'), 400, PreferencesException::CODE);
        }

        $before = Preferences::all();

        try {
            $after = Preferences::update($changes, Retention::filtered());
        } catch (PreferencesException $e) {
            return Response::error($e->getMessage(), 400, PreferencesException::CODE)->withDetails(['errors' => $e->errors()]);
        }

        $changed = [];
        foreach ($after as $key => $value) {
            if ($before[$key] !== $value) {
                $changed[$key] = ['from' => $before[$key], 'to' => $value];
            }
        }

        if ($changed !== []) {
            Logger::log([
                'tool'   => 'settings',
                'action' => 'update',
                'target' => 'preferences',
                'args'   => $changed,
                'result' => Logger::RESULT_OK,
            ]);
            Stats::forget();
        }

        return Response::success(['message' => __('Settings saved.', 'mcpmate')] + self::view());
    }

    /**
     * @return array<string, mixed>
     */
    private static function view(): array
    {
        return [
            'preferences'  => Preferences::all(),
            // Keys a site admin cannot change here, and why (locked, or set by a filter).
            'fixed'        => Preferences::fixed(Retention::filtered()),
            'change_types' => ChangeCapture::TYPES,
        ];
    }
}
