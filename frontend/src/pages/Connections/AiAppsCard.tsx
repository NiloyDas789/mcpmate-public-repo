import { useEffect, useId, useRef, useState } from "react";
import { DownOutlined } from "@ant-design/icons";
import { useQueryClient } from "@tanstack/react-query";
import { __, _n, sprintf } from "@wordpress/i18n";
import Button from "@components/ui/Button";
import Card from "@components/ui/Card";
import ConfirmDialog from "@components/ui/ConfirmDialog";
import EmptyState from "@components/ui/EmptyState";
import Notice from "@components/ui/Notice";
import RelativeTime from "@components/ui/RelativeTime";
import StatusText from "@components/ui/StatusText";
import Table, { type TableColumn, type TableSort } from "@components/ui/Table";
import { useAnnounce } from "@/hooks/useAnnounce";
import { revokeAllGrants, revokeGrant } from "@services/api";
import {
  CLIENTS_QUERY_KEY,
  GRANTS_QUERY_KEY,
  useAccessState,
} from "@services/queries";
import { formatNumber } from "@utils/format";
import { requestErrorText } from "@utils/errors";
import type { Grant, GrantsResponse } from "../../types/api";
import EditAccessModal from "./EditAccessModal";
import {
  accessLabel,
  actsAs,
  DEFAULT_SORT,
  formatDay,
  revokeReasonLabel,
  sortGrants,
  splitGrants,
  verifiedHost,
} from "./logic";

const DATE_ORDERS = () => ({
  ascending: __("oldest first", "mcpmate"),
  descending: __("newest first", "mcpmate"),
});

function AppName({ grant }: { grant: Grant }) {
  const host = verifiedHost(grant);

  return (
    <span className="grid gap-1">
      <strong>{grant.client_name}</strong>
      <span className="font-normal">
        {host ? (
          <bdi dir="ltr" className="el-mono">
            {host}
          </bdi>
        ) : (
          <StatusText tone="warning">
            {__("not verified", "mcpmate")}
          </StatusText>
        )}
      </span>
      <span className="font-normal text-el-text-secondary">
        {actsAs(grant)}
      </span>
    </span>
  );
}

function RevokedList({ revoked }: { revoked: Grant[] }) {
  const [open, setOpen] = useState(false);
  const id = useId();

  if (revoked.length === 0) {
    return null;
  }

  return (
    <div>
      <h3 className="m-0">
        <button
          type="button"
          className="el-disclosure"
          aria-expanded={open}
          aria-controls={id}
          onClick={() => setOpen(!open)}
        >
          {sprintf(
            /* translators: %s: number of revoked AI apps. */
            __("Revoked in the last 30 days (%s)", "mcpmate"),
            formatNumber(revoked.length),
          )}
          <DownOutlined aria-hidden className="el-disclosure-icon" />
        </button>
      </h3>
      <div id={id} hidden={!open}>
        <ul className="el-table-cards mt-2">
          {revoked.map((grant) => (
            <li key={grant.grant_id} className="el-table-card">
              <p className="m-0 font-semibold">{grant.client_name}</p>
              <p className="m-0 text-el-text-secondary">{actsAs(grant)}</p>
              <p className="m-0">
                <StatusText tone="neutral">
                  {sprintf(
                    /* translators: 1: date, 2: why, e.g. "Revoked by a site admin". */
                    __("Revoked %1$s · %2$s", "mcpmate"),
                    formatDay(grant.revoked_at),
                    revokeReasonLabel(grant),
                  )}
                </StatusText>
              </p>
            </li>
          ))}
        </ul>
      </div>
    </div>
  );
}

/**
 * AI apps (docs/ui.md §5.2): every app approved on this site, sortable by
 * Approved and Last used, with Edit access and Revoke; the apps revoked in
 * the last 30 days; and Revoke all.
 */
export default function AiAppsCard({
  data,
  loading,
  error,
  onRetry,
  onConnect,
}: {
  data: GrantsResponse | undefined;
  loading: boolean;
  error: unknown;
  onRetry: () => void;
  onConnect: () => void;
}) {
  const queryClient = useQueryClient();
  const announce = useAnnounce();
  const [sort, setSort] = useState<TableSort>(DEFAULT_SORT);
  const [revoking, setRevoking] = useState<Grant | null>(null);
  const [revokingAll, setRevokingAll] = useState(false);
  const [editing, setEditing] = useState<Grant | null>(null);
  // A revoke removes the button that opened its dialog: focus goes to the
  // table's caption instead, once the dialog has closed.
  const [focusCaption, setFocusCaption] = useState(false);
  const caption = useRef<HTMLElement>(null);
  const { live, revoked } = splitGrants(data?.grants ?? []);
  const { paused } = useAccessState();
  const rows = sortGrants(live, sort);

  useEffect(() => {
    if (focusCaption && !revoking && !revokingAll) {
      setFocusCaption(false);
      caption.current?.focus();
    }
  }, [focusCaption, revoking, revokingAll]);

  const refresh = () =>
    queryClient.invalidateQueries({ queryKey: GRANTS_QUERY_KEY });

  const revokeOne = async (grant: Grant) => {
    const updated = await revokeGrant(grant.grant_id);
    queryClient.setQueryData<GrantsResponse>(GRANTS_QUERY_KEY, (current) =>
      current
        ? {
            ...current,
            grants: current.grants.map((item) =>
              item.grant_id === updated.grant_id ? updated : item,
            ),
          }
        : current,
    );
    void queryClient.invalidateQueries({ queryKey: CLIENTS_QUERY_KEY });
    setFocusCaption(true);
    announce(
      sprintf(
        /* translators: %s: AI app name. */
        __("%s can no longer use this site.", "mcpmate"),
        grant.client_name,
      ),
    );
  };

  const revokeAll = async () => {
    const count = await revokeAllGrants();
    await refresh();
    void queryClient.invalidateQueries({ queryKey: CLIENTS_QUERY_KEY });
    setFocusCaption(true);
    announce(
      sprintf(
        /* translators: %d: number of AI apps revoked. */
        _n(
          "%d AI app can no longer use this site.",
          "%d AI apps can no longer use this site.",
          count,
          "mcpmate",
        ),
        count,
      ),
    );
  };

  const columns: TableColumn<Grant>[] = [
    {
      key: "app",
      title: __("AI app", "mcpmate"),
      rowHeader: true,
      render: (grant) => <AppName grant={grant} />,
    },
    {
      key: "access",
      title: __("Access", "mcpmate"),
      render: (grant) => accessLabel(grant),
    },
    {
      key: "approved",
      title: __("Approved", "mcpmate"),
      sortable: true,
      firstOrder: "descending",
      orderLabels: DATE_ORDERS(),
      render: (grant) => (
        <time dateTime={grant.created_at}>{formatDay(grant.created_at)}</time>
      ),
    },
    {
      key: "last_used",
      title: __("Last used", "mcpmate"),
      sortable: true,
      firstOrder: "descending",
      orderLabels: DATE_ORDERS(),
      render: (grant) =>
        grant.status === "pending" ? (
          <StatusText tone="warning">
            {__("Waiting for first use", "mcpmate")}
          </StatusText>
        ) : grant.last_used_at ? (
          <RelativeTime date={grant.last_used_at} />
        ) : (
          __("Not used yet", "mcpmate")
        ),
    },
    {
      key: "actions",
      title: __("Actions", "mcpmate"),
      hiddenTitle: true,
      render: (grant) => (
        <span className="flex flex-wrap gap-2 whitespace-nowrap">
          <Button
            size="sm"
            variant="secondary"
            onClick={() => setEditing(grant)}
            label={sprintf(
              /* translators: %s: AI app name. */
              __("Edit access for %s", "mcpmate"),
              grant.client_name,
            )}
          >
            {__("Edit access", "mcpmate")}
          </Button>
          <Button
            size="sm"
            variant="danger-secondary"
            onClick={() => setRevoking(grant)}
            label={sprintf(
              /* translators: %s: AI app name. */
              __("Revoke access for %s", "mcpmate"),
              grant.client_name,
            )}
          >
            {__("Revoke", "mcpmate")}
          </Button>
        </span>
      ),
    },
  ];

  const count =
    data &&
    sprintf(
      /* translators: 1: connected AI apps, 2: how many this site allows. */
      _n("%1$s of %2$s app", "%1$s of %2$s apps", data.limit, "mcpmate"),
      formatNumber(live.length),
      formatNumber(data.limit),
    );

  return (
    <Card
      title={__("AI apps", "mcpmate")}
      busy={loading}
      actions={
        <span className="flex flex-wrap items-center gap-3">
          {count && <span className="text-el-text-secondary">{count}</span>}
          <Button variant="primary" onClick={onConnect}>
            {__("Connect an AI app", "mcpmate")}
          </Button>
        </span>
      }
    >
      <div className="el-stack">
        <Table<Grant>
          caption={sprintf(
            /* translators: 1: column name, 2: order, e.g. "newest first". */
            __(
              "AI apps that can use this site, sorted by %1$s, %2$s",
              "mcpmate",
            ),
            sort.key === "approved"
              ? __("Approved", "mcpmate")
              : __("Last used", "mcpmate"),
            DATE_ORDERS()[sort.order],
          )}
          captionRef={caption}
          columns={columns}
          rows={rows}
          rowKey={(grant) => grant.grant_id}
          sort={sort}
          onSort={setSort}
          loading={loading}
          error={
            !loading && error && !data ? (
              <Notice
                tone="danger"
                title={__("Couldn't load your AI apps.", "mcpmate")}
                action={
                  <Button variant="secondary" size="sm" onClick={onRetry}>
                    {__("Try again", "mcpmate")}
                  </Button>
                }
              >
                {requestErrorText(error)}
              </Notice>
            ) : undefined
          }
          empty={
            <EmptyState
              title={__("No AI apps connected yet.", "mcpmate")}
              body={__(
                "Connect Claude, ChatGPT, Cursor or another AI app. You approve each one here before it can do anything.",
                "mcpmate",
              )}
              action={{
                label: __("Connect an AI app", "mcpmate"),
                onClick: onConnect,
              }}
            />
          }
        />

        <RevokedList revoked={revoked} />

        {data && (
          <div className="flex flex-wrap items-end justify-between gap-3 border-0 border-t border-solid border-el-border pt-4">
            <div>
              <h3 className="m-0 text-el-md">
                {__("Revoke all AI apps", "mcpmate")}
              </h3>
              <p className="m-0 text-el-text-secondary">
                {live.length > 0
                  ? __(
                      "Use this if you think an AI app or account was misused.",
                      "mcpmate",
                    )
                  : __("No AI apps to revoke.", "mcpmate")}
              </p>
              {live.length > 0 && !paused && (
                <p className="m-0 mt-1 text-el-text-secondary">
                  {__(
                    "To stop every app for a while without revoking anything, pause AI access instead.",
                    "mcpmate",
                  )}{" "}
                  <a href="#/" className="underline">
                    {__("Pause AI access on the Dashboard", "mcpmate")}
                  </a>
                </p>
              )}
            </div>
            <Button
              variant="danger"
              disabled={live.length === 0}
              onClick={() => setRevokingAll(true)}
            >
              {live.length === 0
                ? __("Revoke all apps", "mcpmate")
                : sprintf(
                    /* translators: %s: number of connected AI apps. */
                    _n(
                      "Revoke %s app",
                      "Revoke all %s apps",
                      live.length,
                      "mcpmate",
                    ),
                    formatNumber(live.length),
                  )}
            </Button>
          </div>
        )}
      </div>

      <ConfirmDialog
        open={revoking !== null}
        tone="danger"
        title={sprintf(
          /* translators: %s: AI app name. */
          __("Revoke access for %s?", "mcpmate"),
          revoking?.client_name ?? "",
        )}
        body={
          revoking &&
          sprintf(
            /* translators: 1: AI app name, 2: WordPress user login. */
            __(
              "%1$s (acting as %2$s) loses access to this site right away. To use it again, you'll need to connect it and approve it here. Its past activity stays in the audit log.",
              "mcpmate",
            ),
            revoking.client_name,
            revoking.user.login ?? `#${revoking.user.id}`,
          )
        }
        confirmLabel={sprintf(
          /* translators: %s: AI app name. */
          __("Revoke access for %s", "mcpmate"),
          revoking?.client_name ?? "",
        )}
        busyLabel={__("Revoking…", "mcpmate")}
        cancelLabel={__("Keep access", "mcpmate")}
        onConfirm={() => (revoking ? revokeOne(revoking) : undefined)}
        onClose={() => setRevoking(null)}
      />

      <ConfirmDialog
        open={revokingAll}
        tone="danger"
        title={__("Revoke access for all AI apps?", "mcpmate")}
        body={
          <>
            <p className="m-0">
              {sprintf(
                /* translators: %s: number of connected AI apps. */
                _n(
                  "Your %s AI app loses access to this site right away. It needs to be connected and approved again. Its past activity stays in the audit log.",
                  "All %s AI apps lose access to this site right away. Each one needs to be connected and approved again. Their past activity stays in the audit log.",
                  live.length,
                  "mcpmate",
                ),
                formatNumber(live.length),
              )}
            </p>
            {!paused && (
              <p className="m-0 mt-2">
                {__(
                  "Only want to stop them for a while? Keep access, then pause AI access on the Dashboard. Nothing is revoked, and you can resume at any time.",
                  "mcpmate",
                )}
              </p>
            )}
          </>
        }
        confirmLabel={sprintf(
          /* translators: %s: number of connected AI apps. */
          _n("Revoke %s app", "Revoke all %s apps", live.length, "mcpmate"),
          formatNumber(live.length),
        )}
        busyLabel={__("Revoking…", "mcpmate")}
        cancelLabel={__("Keep access", "mcpmate")}
        onConfirm={revokeAll}
        onClose={() => setRevokingAll(false)}
      />

      <EditAccessModal grant={editing} onClose={() => setEditing(null)} />
    </Card>
  );
}
