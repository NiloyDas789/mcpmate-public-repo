<?php

namespace McpMate\HTTP\Controllers\Api;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\HTTP\ErrorPresenter;
use McpMate\HTTP\Request\Request;
use McpMate\HTTP\Response;
use McpMate\HTTP\RestEnvelope;
use McpMate\Services\Abilities\Abilities;
use McpMate\Services\Abilities\AbilityException;
use McpMate\Services\Abilities\AbilityRisk;
use McpMate\Services\Abilities\AbilityRunner;
use McpMate\Services\Approvals\ApprovalException;
use McpMate\Services\Approvals\Approvals;

/**
 * Other plugins' abilities (WordPress Abilities API, 6.9+).
 *
 * GET  /abilities?search=&category=&page=&per_page= — the abilities exposed
 *      over REST, with their schemas, risk and whether they are enabled.
 * POST /abilities/run { name, input } — run one (AbilityRunner has the
 *      order of checks). Destructive abilities (AbilityRisk) are stored for
 *      a site admin's approval (202 pending_approval) and change nothing until
 *      then; everything else runs at once.
 *
 * Both need edit_posts, the floor below each ability's own permission
 * callback. On WordPress < 6.9 both answer 501
 * mcpmate_abilities_unsupported. Every run is audited (POST) with
 * action = ability name, `/` as `:`.
 */
final class AbilitiesController extends RestController
{
    protected const CAPABILITIES = [
        'index' => 'edit_posts',
        'run'   => 'edit_posts',
    ];

    public const MAX_PER_PAGE = 100;

    public function index(Request $request): Response
    {
        if (!Abilities::isSupported()) {
            return $this->fromException(AbilityRunner::unsupported());
        }

        $search   = (string) $request->get('search', '');
        $category = (string) $request->get('category', '');
        $page     = max(1, (int) $request->get('page', 1));
        $perPage  = min(self::MAX_PER_PAGE, max(1, (int) $request->get('per_page', 50)));

        $matched = [];
        foreach (Abilities::all() as $ability) {
            $described = Abilities::describe($ability);
            if (Abilities::matches($described, $search, $category)) {
                $matched[] = $described;
            }
        }

        $total = count($matched);

        return $this->ok([
            'abilities'   => array_slice($matched, ($page - 1) * $perPage, $perPage),
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => (int) ceil($total / $perPage),
        ]);
    }

    public function run(Request $request): Response
    {
        $name  = (string) $request->get('name', '');
        $input = $request->get('input');

        $this->auditAction(str_replace('/', ':', $name));
        $this->auditTarget('ability', $name);

        try {
            $checked = AbilityRunner::check($name, $input);

            if ($checked['risk'] === AbilityRisk::DESTRUCTIVE) {
                return $this->requestApproval($request, $checked['ability'], $checked['input']);
            }

            $result = AbilityRunner::run($checked['ability'], $checked['input']);
        } catch (AbilityException $e) {
            return $this->fromException($e);
        }

        return $this->ok([
            'name'   => $name,
            'risk'   => $checked['risk'],
            'result' => $result,
        ]);
    }

    /**
     * REST `args` schema for index().
     *
     * @return array<string, array<string, mixed>>
     */
    public static function indexArgs(): array
    {
        return [
            'search'   => [
                'type'        => 'string',
                'description' => 'Only abilities whose name, label or description contains this text (case-insensitive).',
                'maxLength'   => 100,
            ],
            'category' => [
                'type'        => 'string',
                'description' => 'Only abilities in this category (its slug).',
                'pattern'     => '^[a-z0-9-]+$',
                'maxLength'   => 100,
            ],
            'page'     => [
                'type'    => 'integer',
                'minimum' => 1,
                'default' => 1,
            ],
            'per_page' => [
                'type'    => 'integer',
                'minimum' => 1,
                'maximum' => self::MAX_PER_PAGE,
                'default' => 50,
            ],
        ];
    }

    /**
     * REST `args` schema for run().
     *
     * @return array<string, array<string, mixed>>
     */
    public static function runArgs(): array
    {
        return [
            'name'  => [
                'type'        => 'string',
                'description' => 'The ability\'s name, e.g. "my-plugin/get-report".',
                'required'    => true,
                // WP_Abilities_Registry's name rule: namespace/name, lowercase.
                'pattern'     => '^[a-z0-9-]+(\/[a-z0-9-]+)+$',
                'maxLength'   => 200,
            ],
            'input' => [
                'description'       => 'The ability\'s input, matching its input_schema. Omit for abilities without input.',
                'default'           => null,
                // Any JSON value. The ability's own schema validates and coerces it
                // (AbilityRunner); a route-level type would coerce it first ("5" → 5).
                'sanitize_callback' => static fn ($value) => $value,
            ],
        ];
    }

    /**
     * Store the ability and its input for a site admin (202). The client
     * polls POST /approvals/{id}/status with the token to get the result.
     *
     * @param mixed $input
     */
    private function requestApproval(Request $request, \WP_Ability $ability, $input): Response
    {
        $restRequest = $request->restRequest();
        $described   = Abilities::describe($ability, false);
        $preview     = [
            'ability' => array_intersect_key($described, array_flip(['name', 'label', 'description', 'category', 'risk', 'annotations'])),
            'input'   => $input,
        ];

        try {
            $approval = Approvals::request(
                self::tool(),
                str_replace('/', ':', $ability->get_name()),
                'ability:' . $ability->get_name(),
                ['name' => $ability->get_name(), 'input' => $input],
                $preview,
                $restRequest ? RestEnvelope::requestId($restRequest) : ''
            );
        } catch (ApprovalException $e) {
            return $this->error(ErrorPresenter::plain($e), $e->status(), $e->errorCode(), ErrorPresenter::text($e->hint()));
        }

        return $this->pendingApproval(
            sprintf(
                /* translators: 1: ability name, 2: approval ID. */
                __('"%1$s" is waiting for a site admin to approve it (approval #%2$d).', 'mcpmate'),
                $ability->get_name(),
                $approval['id']
            ),
            [
                'status'           => 'pending_approval',
                'pending_approval' => true,
                'approval_id'      => $approval['id'],
                'approval_token'   => $approval['token'],
                'approval_url'     => $approval['approval_url'],
                'expires_at'       => $approval['expires_at'],
                'preview'          => $preview,
                'name'             => $ability->get_name(),
                'risk'             => AbilityRisk::DESTRUCTIVE,
            ]
        );
    }

    private function fromException(AbilityException $e): Response
    {
        return $this->error(ErrorPresenter::plain($e), $e->status(), $e->errorCode(), ErrorPresenter::text($e->hint()));
    }
}
