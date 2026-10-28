<?php

namespace McpMate\HTTP\Controllers\Api;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\HTTP\ErrorPresenter;
use McpMate\HTTP\Request\Request;
use McpMate\HTTP\Response;
use McpMate\HTTP\RestEnvelope;
use McpMate\Services\Approvals\ApprovalException;
use McpMate\Services\Approvals\Approvals;
use McpMate\Services\ThemeDraft\DraftFiles;
use McpMate\Services\ThemeDraft\DraftPath;
use McpMate\Services\ThemeDraft\Drafts;
use McpMate\Services\ThemeDraft\PreviewToken;
use McpMate\Services\ThemeDraft\ThemeBackups;
use McpMate\Services\ThemeDraft\ThemeDraftException;
use McpMate\Services\ThemeDraft\ThemeDraftSettings;
use McpMate\Services\ThemeDraft\ThemePreview;
use McpMate\Services\ThemeDraft\ThemePublisher;

/**
 * Theme drafts: the AI edits a copy of the active theme, never the live one.
 *
 *   GET    /theme-draft                          drafts, backups, live theme
 *   POST   /theme-draft                          copy the active theme → 201
 *   GET    /theme-draft/{id}/files?path=&recursive=
 *   GET    /theme-draft/{id}/file?path=&line=
 *   PUT    /theme-draft/{id}/file                { path, content }
 *   POST   /theme-draft/{id}/file/replace        { path, find, replace }
 *   DELETE /theme-draft/{id}/file?path=
 *   POST   /theme-draft/{id}/preview             { path, expires_in } → signed link
 *   POST   /theme-draft/publish                  { draft_id, dry_run } → 202 pending_approval
 *   POST   /theme-draft/rollback                 { backup_id, dry_run } → 202 pending_approval
 *
 * Order of checks:
 *
 * 1. The route needs edit_themes, which WordPress already withholds when
 *    DISALLOW_FILE_EDIT or DISALLOW_FILE_MODS is set, and from everyone but
 *    super admins on multisite. Publish and rollback also need switch_themes.
 * 2. A site admin must have switched theme drafts on (ThemeDraftSettings) → 403.
 * 3. The draft must exist and not be the live theme → 404 / 409.
 * 4. File paths: DraftPath (relative, allowlisted extension, realpath inside
 *    the draft, no symbolic links) → 400.
 * 5. Writes: CodeLint (PHP syntax and lint, JSON, style.css header) → 400
 *    with the line; nothing is saved.
 * 6. Publish and rollback never run here. Without dry_run they are planned
 *    (every check runs) and queued for a site admin (Approvals, tool
 *    `theme_draft`); ApprovedThemeChange runs them once on approval.
 *
 * Writes are audited (POST/PUT/DELETE) with target `theme_draft:<id>`;
 * the audit row keeps the lengths of content, find and replace, not the code.
 */
final class ThemeDraftController extends RestController
{
    private const SWITCH = 'edit_themes,switch_themes';

    /**
     * edit_themes everywhere; publish and rollback also switch_themes (the
     * `can:` middleware checks every capability in the list).
     */
    protected const CAPABILITIES = [
        'index'    => 'edit_themes',
        'create'   => 'edit_themes',
        'files'    => 'edit_themes',
        'read'     => 'edit_themes',
        'write'    => 'edit_themes',
        'replace'  => 'edit_themes',
        'delete'   => 'edit_themes',
        'preview'  => 'edit_themes',
        'publish'  => self::SWITCH,
        'rollback' => self::SWITCH,
    ];

    protected const TOOL = 'theme_draft';

    /** Backups listed by index(). */
    private const LISTED_BACKUPS = 10;

    // ──────────────────────────────────────────────
    //  Drafts
    // ──────────────────────────────────────────────

    public function index(Request $request): Response
    {
        $disabled = $this->disabled();
        if ($disabled) {
            return $disabled;
        }

        $live = wp_get_theme(get_option('stylesheet'));

        return $this->ok([
            'live_theme' => [
                'stylesheet' => $live->get_stylesheet(),
                'template'   => $live->get_template(),
                'name'       => (string) $live->get('Name'),
                'version'    => (string) $live->get('Version'),
            ],
            'drafts'     => array_values(array_map([Drafts::class, 'describe'], Drafts::all())),
            'backups'    => array_map([ThemeBackups::class, 'describe'], array_slice(ThemeBackups::all(), 0, self::LISTED_BACKUPS)),
            'limits'     => [
                'max_drafts'      => Drafts::MAX_DRAFTS,
                'extensions'      => DraftPath::EXTENSIONS,
                'max_write_bytes' => DraftFiles::MAX_WRITE_BYTES,
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        $disabled = $this->disabled();
        if ($disabled) {
            return $disabled;
        }

        try {
            $record = Drafts::create(get_current_user_id());
        } catch (ThemeDraftException $e) {
            return $this->fromException($e);
        }

        $this->auditTarget('theme_draft', (string) $record['id']);

        return $this->ok(Drafts::describe($record) + ['copied' => $record['copied']], 201);
    }

    // ──────────────────────────────────────────────
    //  Files
    // ──────────────────────────────────────────────

    public function files(Request $request, string $id): Response
    {
        return $this->withFiles($id, static fn (DraftFiles $files) => $files->list(
            (string) $request->get('path', ''),
            (bool) $request->get('recursive', true)
        ));
    }

    public function read(Request $request, string $id): Response
    {
        return $this->withFiles($id, static fn (DraftFiles $files) => $files->read(
            (string) $request->get('path', ''),
            (int) $request->get('line', 1)
        ));
    }

    public function write(Request $request, string $id): Response
    {
        $created = false;

        $response = $this->withFiles($id, static function (DraftFiles $files) use ($request, &$created): array {
            $result  = $files->write((string) $request->get('path', ''), (string) $request->get('content', ''));
            $created = $result['created'];

            return $result;
        });

        return $created ? $response->httpStatus(201) : $response;
    }

    public function replace(Request $request, string $id): Response
    {
        return $this->withFiles($id, static fn (DraftFiles $files) => $files->replace(
            (string) $request->get('path', ''),
            (string) $request->get('find', ''),
            (string) $request->get('replace', '')
        ));
    }

    public function delete(Request $request, string $id): Response
    {
        return $this->withFiles($id, static fn (DraftFiles $files) => $files->delete((string) $request->get('path', '')));
    }

    // ──────────────────────────────────────────────
    //  Preview
    // ──────────────────────────────────────────────

    public function preview(Request $request, string $id): Response
    {
        $disabled = $this->disabled();
        if ($disabled) {
            return $disabled;
        }

        $this->auditTarget('theme_draft', $id);
        $record = Drafts::find($id);

        try {
            Drafts::workingDirectory($record);
            /** @var array<string, mixed> $record */
            Drafts::validTheme($record);
            $path = ThemePreview::pagePath((string) $request->get('path', '/'));
        } catch (ThemeDraftException $e) {
            return $this->fromException($e);
        }

        return $this->ok(
            ThemePreview::url($record, $path, (int) $request->get('expires_in', PreviewToken::DEFAULT_TTL))
            + ['draft' => Drafts::describe($record)]
        );
    }

    // ──────────────────────────────────────────────
    //  Publish and rollback (approval required)
    // ──────────────────────────────────────────────

    public function publish(Request $request): Response
    {
        $disabled = $this->disabled();
        if ($disabled) {
            return $disabled;
        }

        $id = (string) $request->get('draft_id', '');
        $this->auditTarget('theme_draft', $id);

        try {
            $plan = ThemePublisher::planPublish(Drafts::find($id));
        } catch (ThemeDraftException $e) {
            return $this->fromException($e);
        }

        if ((bool) $request->get('dry_run', false)) {
            return $this->ok(['status' => 'dry_run', 'dry_run' => true] + $plan);
        }

        return $this->requestApproval(
            $request,
            'publish',
            'theme_draft:' . $id,
            ['operation' => 'publish', 'draft_id' => $id],
            $plan,
            (string) $plan['draft']['slug']
        );
    }

    public function rollback(Request $request): Response
    {
        $disabled = $this->disabled();
        if ($disabled) {
            return $disabled;
        }

        $backup = null;

        if ($request->has('backup_id')) {
            $backup = ThemeBackups::find((string) $request->get('backup_id'));

            if ($backup === null) {
                return $this->error(
                    __('Theme backup not found.', 'mcpmate'),
                    404,
                    ThemeDraftException::BACKUP_NOT_FOUND,
                    __('GET /theme-draft lists the backups; leave out backup_id to use the newest one.', 'mcpmate')
                );
            }
        }

        try {
            $plan = ThemePublisher::planRollback($backup);
        } catch (ThemeDraftException $e) {
            return $this->fromException($e);
        }

        $backupId = (string) $plan['backup']['id'];
        $this->auditTarget('theme_backup', $backupId);

        if ((bool) $request->get('dry_run', false)) {
            return $this->ok(['status' => 'dry_run', 'dry_run' => true] + $plan);
        }

        return $this->requestApproval(
            $request,
            'rollback',
            'theme_backup:' . $backupId,
            ['operation' => 'rollback', 'backup_id' => $backupId],
            $plan,
            (string) $plan['restore']
        );
    }

    // ──────────────────────────────────────────────
    //  Args
    // ──────────────────────────────────────────────

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function idArgs(): array
    {
        return [
            'id' => [
                'type'        => 'string',
                'pattern'     => Drafts::ID_PATTERN,
                'required'    => true,
                'description' => 'The draft ID from POST /theme-draft.',
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function filesArgs(): array
    {
        return self::idArgs() + [
            'path'      => self::pathArg(false, 'Folder to list, relative to the draft; empty for the whole draft.'),
            'recursive' => [
                'type'        => 'boolean',
                'default'     => true,
                'description' => 'List every file below the folder (true), or only its direct files and folders.',
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function readArgs(): array
    {
        return self::idArgs() + [
            'path' => self::pathArg(true, 'File to read, relative to the draft.'),
            'line' => [
                'type'        => 'integer',
                'minimum'     => 1,
                'default'     => 1,
                'description' => 'First line to return; files over 64 KB come in pages (next_line).',
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function writeArgs(): array
    {
        return self::idArgs() + [
            'path'    => self::pathArg(true, 'File to create or replace, relative to the draft.'),
            'content' => [
                'type'        => 'string',
                'required'    => true,
                'maxLength'   => DraftFiles::MAX_WRITE_BYTES,
                'description' => 'The whole new file. PHP is syntax-checked and linted, JSON validated; a failing file is not saved.',
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function replaceArgs(): array
    {
        return self::idArgs() + [
            'path'    => self::pathArg(true, 'File to edit, relative to the draft.'),
            'find'    => [
                'type'        => 'string',
                'required'    => true,
                'minLength'   => 1,
                'maxLength'   => DraftFiles::MAX_WRITE_BYTES,
                'description' => 'Exact text to replace; it must occur exactly once in the file.',
            ],
            'replace' => [
                'type'        => 'string',
                'required'    => true,
                'maxLength'   => DraftFiles::MAX_WRITE_BYTES,
                'description' => 'Text to put in its place (may be empty).',
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function deleteArgs(): array
    {
        return self::idArgs() + [
            'path' => self::pathArg(true, 'File to delete from the draft (not style.css).'),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function previewArgs(): array
    {
        return self::idArgs() + [
            'path'       => [
                'type'        => 'string',
                'default'     => '/',
                'maxLength'   => 2000,
                'description' => 'Page to preview, as a path on the site (e.g. "/" or "/about/").',
            ],
            'expires_in' => [
                'type'        => 'integer',
                'minimum'     => PreviewToken::MIN_TTL,
                'maximum'     => PreviewToken::MAX_TTL,
                'default'     => PreviewToken::DEFAULT_TTL,
                'description' => 'Seconds the link works for (5 minutes to 24 hours).',
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function publishArgs(): array
    {
        return [
            'draft_id' => [
                'type'        => 'string',
                'pattern'     => Drafts::ID_PATTERN,
                'required'    => true,
                'description' => 'The draft to make the live theme.',
            ],
            'dry_run'  => [
                'type'        => 'boolean',
                'default'     => false,
                'description' => 'Only show what would happen. Otherwise the publish waits for a site admin\'s approval.',
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function rollbackArgs(): array
    {
        return [
            'backup_id' => [
                'type'        => 'string',
                'pattern'     => ThemeBackups::ID_PATTERN,
                'description' => 'The backup to restore; defaults to the newest one not yet restored.',
            ],
            'dry_run'   => [
                'type'        => 'boolean',
                'default'     => false,
                'description' => 'Only show what would happen. Otherwise the rollback waits for a site admin\'s approval.',
            ],
        ];
    }

    /**
     * Code is replaced by its length: the audit log records that a file
     * changed, not its contents.
     *
     * @param array<array-key, mixed> $args
     * @return array<array-key, mixed>
     */
    protected static function auditArgs(array $args): array
    {
        foreach (['content', 'find', 'replace'] as $key) {
            if (isset($args[$key]) && is_string($args[$key])) {
                $args[$key] = sprintf('[%d bytes]', strlen($args[$key]));
            }
        }

        return $args;
    }

    // ──────────────────────────────────────────────
    //  Internals
    // ──────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private static function pathArg(bool $required, string $description): array
    {
        return [
            'type'        => 'string',
            'required'    => $required,
            'maxLength'   => DraftPath::MAX_LENGTH,
            'description' => $description,
        ];
    }

    private function disabled(): ?Response
    {
        if (ThemeDraftSettings::isEnabled()) {
            return null;
        }

        return $this->error(
            __('Theme drafts are not enabled on this site.', 'mcpmate'),
            403,
            'mcpmate_theme_drafts_disabled',
            __('A site admin can enable them under McpMate → Tools → Core (Theme drafts).', 'mcpmate')
        );
    }

    /**
     * Run a file tool against draft $id, if the draft may be changed.
     *
     * @param callable(DraftFiles): array<string, mixed> $run
     */
    private function withFiles(string $id, callable $run): Response
    {
        $disabled = $this->disabled();
        if ($disabled) {
            return $disabled;
        }

        $this->auditTarget('theme_draft', $id);

        try {
            return $this->ok($run(new DraftFiles(Drafts::workingDirectory(Drafts::find($id)))));
        } catch (ThemeDraftException $e) {
            return $this->fromException($e);
        }
    }

    /**
     * Queue a planned publish or rollback for a site admin (202). The client
     * polls POST /approvals/{id}/status with the token for the result.
     *
     * @param array<string, mixed> $args    What ApprovedThemeChange will run.
     * @param array<string, mixed> $preview The plan shown to the admin.
     * @param string               $theme   The theme folder that would go live.
     */
    private function requestApproval(Request $request, string $action, string $target, array $args, array $preview, string $theme): Response
    {
        $restRequest = $request->restRequest();

        try {
            $approval = Approvals::request(
                self::tool(),
                $action,
                $target,
                $args,
                $preview,
                $restRequest ? RestEnvelope::requestId($restRequest) : ''
            );
        } catch (ApprovalException $e) {
            return $this->error(ErrorPresenter::plain($e), $e->status(), $e->errorCode(), ErrorPresenter::text($e->hint()));
        }

        $message = $action === 'publish'
            /* translators: 1: draft theme folder name, 2: approval ID. */
            ? __('Publishing theme draft "%1$s" is waiting for a site admin to approve it (approval #%2$d).', 'mcpmate')
            /* translators: 1: theme folder name, 2: approval ID. */
            : __('Restoring theme "%1$s" is waiting for a site admin to approve it (approval #%2$d).', 'mcpmate');

        return $this->pendingApproval(
            sprintf($message, $theme, $approval['id']),
            [
                'status'           => 'pending_approval',
                'pending_approval' => true,
                'operation'        => $action,
                'approval_id'      => $approval['id'],
                'approval_token'   => $approval['token'],
                'approval_url'     => $approval['approval_url'],
                'expires_at'       => $approval['expires_at'],
                'preview'          => $preview,
            ]
        );
    }

    private function fromException(ThemeDraftException $e): Response
    {
        return $this->error(ErrorPresenter::plain($e), $e->status(), $e->errorCode(), ErrorPresenter::text($e->hint()));
    }
}
