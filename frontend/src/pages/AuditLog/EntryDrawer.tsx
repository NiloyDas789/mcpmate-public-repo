import { useQuery } from "@tanstack/react-query";
import { Link } from "react-router";
import { __, sprintf } from "@wordpress/i18n";
import Button from "@components/ui/Button";
import CopyField from "@components/ui/CopyField";
import Drawer from "@components/ui/Drawer";
import JsonBlock from "@components/ui/JsonBlock";
import Notice from "@components/ui/Notice";
import Skeleton from "@components/ui/Skeleton";
import StatusText from "@components/ui/StatusText";
import { fetchAuditEntry, fetchChanges } from "@services/api";
import { AUDIT_LOG_QUERY_KEY, CHANGES_QUERY_KEY } from "@services/queries";
import { requestErrorText } from "@utils/errors";
import type { AuditLogEntry } from "../../types/api";
import { formatDuration, fullDateTime, postEditUrl } from "../Activity/logic";
import { objectText } from "../Changes/logic";
import {
  entryApp,
  entryTitle,
  entryUser,
  resultLabel,
  resultTone,
  targetPostId,
} from "./filters";

function Details({
  entry,
  onShowApp,
}: {
  entry: AuditLogEntry;
  onShowApp: (grantId: string) => void;
}) {
  const postId = targetPostId(entry.target);
  const changes = useQuery({
    queryKey: [...CHANGES_QUERY_KEY, "audit", entry.id],
    queryFn: () => fetchChanges(1, 10, { audit_id: String(entry.id) }),
    enabled: entry.kind !== "read",
    staleTime: 0,
  });

  return (
    <>
      <p className="el-drawer-meta">
        <time dateTime={entry.created_at}>
          {fullDateTime(entry.created_at)}
        </time>
      </p>
      <dl className="el-details">
        <div>
          <dt>{__("Status", "mcpmate")}</dt>
          <dd>
            <StatusText tone={resultTone(entry.result)}>
              {resultLabel(entry.result)}
            </StatusText>
          </dd>
        </div>
        {entry.message && (
          <div>
            <dt>{__("Message", "mcpmate")}</dt>
            <dd>{entry.message}</dd>
          </div>
        )}
        <div>
          <dt>{__("AI app", "mcpmate")}</dt>
          <dd>
            {entryApp(entry)}
            {entry.grant_id && (
              <>
                {" "}
                <Button
                  variant="link"
                  size="sm"
                  onClick={() => onShowApp(entry.grant_id)}
                >
                  {__("Show only this app", "mcpmate")}
                </Button>
              </>
            )}
          </dd>
        </div>
        <div>
          <dt>{__("User", "mcpmate")}</dt>
          <dd>{entryUser(entry)}</dd>
        </div>
        <div>
          <dt>{__("Action", "mcpmate")}</dt>
          <dd>
            <bdi dir="ltr" className="el-mono">
              {entry.action}
            </bdi>
          </dd>
        </div>
        {entry.target && (
          <div>
            <dt>{__("Object", "mcpmate")}</dt>
            <dd>
              <bdi dir="ltr" className="el-mono">
                {entry.target}
              </bdi>
              {postId && (
                <>
                  {" "}
                  <a href={postEditUrl(postId)}>
                    {__("Open in editor", "mcpmate")}
                  </a>
                </>
              )}
            </dd>
          </div>
        )}
        <div>
          <dt>{__("Duration", "mcpmate")}</dt>
          <dd>{formatDuration(entry.duration_ms)}</dd>
        </div>
      </dl>
      {entry.request_id && (
        <CopyField
          label={__("Request ID", "mcpmate")}
          value={entry.request_id}
        />
      )}

      <h3 className="el-drawer-heading">
        {__("Arguments (secrets hidden)", "mcpmate")}
      </h3>
      <JsonBlock value={entry.args ?? {}} label={__("Arguments", "mcpmate")} />

      {entry.result === "pending_approval" && (
        <p className="el-drawer-line">
          {__("Waiting for approval:", "mcpmate")}{" "}
          <Link to="/activity/approvals">
            {__("Review request", "mcpmate")}
          </Link>
        </p>
      )}

      {changes.data && changes.data.total > 0 && (
        <>
          <h3 className="el-drawer-heading">
            {__("Before and after", "mcpmate")}
          </h3>
          <ul className="el-drawer-links">
            {changes.data.items.map((change) => (
              <li key={change.id}>
                <Link to={`/activity/changes?change=${change.id}`}>
                  {sprintf(
                    /* translators: %s: what changed, e.g. Page "Home" (#41). */
                    __("View before and after: %s", "mcpmate"),
                    objectText(change),
                  )}
                </Link>
              </li>
            ))}
          </ul>
        </>
      )}
    </>
  );
}

/**
 * One audit log entry (`?entry=<id>`; docs/ui.md §5.4.1): status, who,
 * what, the request ID, the redacted arguments, and links to the changes
 * it made. An entry not on the current page (a link from Change history)
 * is fetched by its ID.
 */
export default function EntryDrawer({
  entryId,
  rows,
  onClose,
  onShowApp,
}: {
  entryId: number | null;
  /** The current page, so a row opened from it needs no request. */
  rows: AuditLogEntry[];
  onClose: () => void;
  onShowApp: (grantId: string) => void;
}) {
  const onPage = rows.find((row) => row.id === entryId);
  const entry = useQuery({
    queryKey: [...AUDIT_LOG_QUERY_KEY, "entry", entryId],
    queryFn: () => fetchAuditEntry(entryId as number),
    enabled: entryId !== null && !onPage,
    staleTime: 0,
  });
  const shown = onPage ?? entry.data ?? null;

  const body = () => {
    if (shown) {
      return <Details entry={shown} onShowApp={onShowApp} />;
    }
    if (entry.isPending) {
      return (
        <div aria-busy="true">
          <Skeleton count={6} />
        </div>
      );
    }
    if (entry.isError) {
      return (
        <Notice
          tone="danger"
          title={__("Couldn't load this entry.", "mcpmate")}
          action={
            <Button
              variant="secondary"
              size="sm"
              onClick={() => void entry.refetch()}
            >
              {__("Try again", "mcpmate")}
            </Button>
          }
        >
          {requestErrorText(entry.error)}
        </Notice>
      );
    }

    return (
      <Notice
        tone="info"
        title={__("This entry isn't in the audit log any more.", "mcpmate")}
      >
        {__(
          "Entries are removed when they are older than the time Settings keeps them for.",
          "mcpmate",
        )}
      </Notice>
    );
  };

  return (
    <Drawer
      open={entryId !== null}
      onClose={onClose}
      title={shown ? entryTitle(shown) : __("Audit log entry", "mcpmate")}
    >
      {body()}
    </Drawer>
  );
}
