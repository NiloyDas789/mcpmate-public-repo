<?php

namespace McpMate\HTTP\Controllers;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\HTTP\Request\Request;
use McpMate\HTTP\Response;
use McpMate\Services\AuditLog\Logger;
use McpMate\Services\Connection\GrantRevoker;
use McpMate\Services\OAuth\Clients;

/**
 * Connections → Registered clients (docs/ui.md §5.2): the OAuth clients
 * that registered themselves or were fetched from a metadata document,
 * with Revoke and Allow again.
 *
 * AJAX routes (nonce + admin), never REST (rule 9). Revoke refuses the
 * client at authorize, token and approve, and revokes every grant of it
 * with its tokens. Both write an audit row.
 */
final class OAuthClientsController
{
    public function index(Request $request): Response
    {
        return Response::success([
            'clients'   => Clients::all(),
            'dcr_count' => Clients::countDcr(),
            'dcr_limit' => Clients::MAX_DCR,
        ]);
    }

    public function revoke(Request $request): Response
    {
        $client = self::client($request);

        if ($client === null) {
            return self::unknown();
        }

        if (!Clients::revoke((string) $client['client_key'])) {
            return Response::error(__('That AI app was already revoked.', 'mcpmate'), 409, 'mcpmate_client_revoked');
        }

        $grants = GrantRevoker::revokeClient((string) $client['client_key'], get_current_user_id());

        self::audit('revoke_client', $client, ['revoked_grants' => count($grants)]);

        return Response::success([
            'client'         => self::reload($client),
            'revoked_grants' => count($grants),
        ]);
    }

    public function restore(Request $request): Response
    {
        $client = self::client($request);

        if ($client === null) {
            return self::unknown();
        }

        if (!Clients::restore((string) $client['client_key'])) {
            return Response::error(__('That AI app isn\'t revoked.', 'mcpmate'), 409, 'mcpmate_client_not_revoked');
        }

        self::audit('restore_client', $client);

        return Response::success(['client' => self::reload($client)]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function client(Request $request): ?array
    {
        $key = (string) $request->get('client_key', '');

        return Clients::isClientKey($key) ? Clients::findByKey($key) : null;
    }

    /**
     * The client as the table lists it.
     *
     * @param array<string, mixed> $client
     * @return array<string, mixed>
     */
    private static function reload(array $client): array
    {
        foreach (Clients::all() as $row) {
            if ($row['client_key'] === $client['client_key']) {
                return $row;
            }
        }

        return Clients::format((array) Clients::findByKey((string) $client['client_key']));
    }

    private static function unknown(): Response
    {
        return Response::error(__('That AI app was not found.', 'mcpmate'), 404, 'mcpmate_client_unknown');
    }

    /**
     * @param array<string, mixed> $client
     * @param array<string, mixed> $extra
     */
    private static function audit(string $action, array $client, array $extra = []): void
    {
        Logger::log([
            'tool'   => 'connection',
            'action' => $action,
            'target' => 'client:' . substr((string) $client['client_key'], 0, 16),
            'args'   => [
                'client_id'   => (string) $client['client_id'],
                'client_name' => (string) $client['client_name'],
                'kind'        => (string) $client['kind'],
            ] + $extra,
            'result' => Logger::RESULT_OK,
        ]);
    }
}
