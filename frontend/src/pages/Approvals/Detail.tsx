import { useEffect, useRef, useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { Link, useParams } from "react-router";
import { ArrowLeftOutlined } from "@ant-design/icons";
import { __, sprintf } from "@wordpress/i18n";
import Button from "@components/ui/Button";
import Card from "@components/ui/Card";
import ConfirmDialog from "@components/ui/ConfirmDialog";
import JsonBlock from "@components/ui/JsonBlock";
import Notice from "@components/ui/Notice";
import RelativeTime from "@components/ui/RelativeTime";
import Skeleton from "@components/ui/Skeleton";
import StatusText from "@components/ui/StatusText";
import { useAnnounce } from "@/hooks/useAnnounce";
import { decideApproval, fetchApproval } from "@services/api";
import {
  APPROVALS_QUERY_KEY,
  PENDING_APPROVALS_QUERY_KEY,
} from "@services/queries";
import { requestErrorText } from "@utils/errors";
import { interpolate } from "@utils/i18n";
import type { Approval, ApprovalUser } from "../../types/api";
import { fullDateTime } from "../Activity/logic";
import {
  describeRequest,
  requestName,
  statusLabel,
  statusTone,
} from "./labels";

function userLabel(user: ApprovalUser | null): string {
  if (!user) {
    return "—";
  }

  const name = user.display_name ?? `#${user.id}`;

  return user.login ? `${name} (${user.login})` : name;
}

function BackLink() {
  return (
    <p className="m-0">
      <Link to="/activity/approvals" className="el-back-link">
        <ArrowLeftOutlined aria-hidden className="el-flip" />{" "}
        {__("All approval requests", "mcpmate")}
      </Link>
    </p>
  );
}

const mono = (text: string) => (
  <bdi dir="ltr" className="el-mono break-all">
    {text}
  </bdi>
);

/** What the decision did, for the live region and the notice on the page. */
function outcomeText(approval: Approval): string {
  if (approval.status === "rejected") {
    return __("Request rejected.", "mcpmate");
  }

  return approval.result?.ok
    ? __("Approved and run.", "mcpmate")
    : __("Approved, but it failed when it ran.", "mcpmate");
}

/**
 * One approval request (`#/activity/approvals/:id`, opened from the
 * approval_url the AI app received; docs/ui.md §5.4.3). Shows exactly what
 * will run and its preview. Approve runs it once, in this admin's session;
 * Reject asks first. Both are nonce-checked admin-ajax calls. The outcome
 * stays on the page and is announced; focus moves to the request's heading.
 */
export default function ApprovalDetail() {
  const id = Number(useParams().id);
  const queryClient = useQueryClient();
  const announce = useAnnounce();
  const heading = useRef<HTMLHeadingElement>(null);
  const queryKey = [...APPROVALS_QUERY_KEY, "show", id];
  const [deciding, setDeciding] = useState<"approve" | "reject" | null>(null);
  const [confirmReject, setConfirmReject] = useState(false);
  const [approveError, setApproveError] = useState("");
  const [outcome, setOutcome] = useState("");

  const { data, isPending, isError, error, refetch } = useQuery({
    queryKey,
    queryFn: () => fetchApproval(id),
    enabled: Number.isInteger(id) && id > 0,
    staleTime: 0,
  });

  // Opening a request, and its outcome, put focus on its heading: the
  // buttons that had it are gone. After the commit, so a closing dialog
  // has given focus back first.
  const loaded = Boolean(data);
  useEffect(() => {
    if (loaded) {
      heading.current?.focus();
    }
  }, [loaded, id, outcome]);

  const decide = async (decision: "approve" | "reject") => {
    setDeciding(decision);
    setApproveError("");
    try {
      const approval = await decideApproval(id, decision);
      queryClient.setQueryData<Approval>(queryKey, approval);
      queryClient.setQueryData<number>(PENDING_APPROVALS_QUERY_KEY, (count) =>
        Math.max(0, (count ?? 1) - 1),
      );
      void queryClient.invalidateQueries({
        queryKey: [...APPROVALS_QUERY_KEY, "list"],
      });
      const text = outcomeText(approval);
      setOutcome(text);
      announce(text, { assertive: approval.result?.ok === false });
      setConfirmReject(false);
    } catch (caught) {
      // Someone else may have decided it, or it expired: show what it is now.
      void queryClient.invalidateQueries({ queryKey });
      if (decision === "reject") {
        throw caught;
      }
      setApproveError(requestErrorText(caught));
    } finally {
      setDeciding(null);
    }
  };

  if (isPending) {
    return (
      <div className="el-stack" aria-busy="true">
        <BackLink />
        <Skeleton count={6} />
      </div>
    );
  }

  if (isError || !data) {
    return (
      <div className="el-stack">
        <BackLink />
        <Notice
          tone="danger"
          title={__("Couldn't load this approval request.", "mcpmate")}
          action={
            <Button
              variant="secondary"
              size="sm"
              onClick={() => void refetch()}
            >
              {__("Try again", "mcpmate")}
            </Button>
          }
        >
          {requestErrorText(error)}
        </Notice>
      </div>
    );
  }

  const pending = data.status === "pending";
  const command = describeRequest(data);
  const name = requestName(data);

  return (
    <div className="el-stack el-approval">
      <BackLink />

      <div className="el-approval-head">
        <h2 ref={heading} tabIndex={-1} className="el-approval-title">
          {sprintf(
            /* translators: %d: approval request number. */
            __("Approval request #%d", "mcpmate"),
            data.id,
          )}
        </h2>
        <StatusText {...statusTone(data.status)}>
          {statusLabel(data.status)}
        </StatusText>
      </div>

      {outcome && (
        <Notice
          tone={data.result?.ok === false ? "danger" : "success"}
          title={outcome}
        />
      )}

      {pending && (
        <Notice
          tone="warning"
          title={__(
            "An AI app is asking to do something that's hard to undo.",
            "mcpmate",
          )}
        >
          {__(
            "Check what will run and the preview below. Approve runs it once, with your permissions. Reject means it won't run.",
            "mcpmate",
          )}
          <span className="block">
            {interpolate(
              /* translators: 1: when it expires, e.g. "in 9 min", 2: the date and time. */
              __("Expires %1$s · %2$s", "mcpmate"),
              [
                <RelativeTime key="in" date={data.expires_at} />,
                fullDateTime(data.expires_at),
              ],
            )}
          </span>
        </Notice>
      )}

      <Card title={__("What will run", "mcpmate")} headingLevel={3}>
        <p className="m-0 mb-4">{mono(command)}</p>
        <dl className="el-details">
          <div>
            <dt>{__("Requested by", "mcpmate")}</dt>
            <dd>{userLabel(data.created_by)}</dd>
          </div>
          <div>
            <dt>{__("Requested", "mcpmate")}</dt>
            <dd>
              <time dateTime={data.created_at}>
                {fullDateTime(data.created_at)}
              </time>
            </dd>
          </div>
          {data.target && (
            <div>
              <dt>{__("Target", "mcpmate")}</dt>
              <dd>{mono(data.target)}</dd>
            </div>
          )}
          {data.decided_at && (
            <div>
              <dt>{__("Decided by", "mcpmate")}</dt>
              <dd>
                {sprintf(
                  /* translators: 1: admin's name, 2: date and time. */
                  __("%1$s, %2$s", "mcpmate"),
                  userLabel(data.decided_by),
                  fullDateTime(data.decided_at),
                )}
              </dd>
            </div>
          )}
          {data.request_id && (
            <div>
              <dt>{__("Request ID", "mcpmate")}</dt>
              <dd>{mono(data.request_id)}</dd>
            </div>
          )}
        </dl>
      </Card>

      <Card title={__("Preview", "mcpmate")} headingLevel={3}>
        <p className="m-0 mb-3 text-el-text-secondary">
          {pending
            ? __(
                "Nothing has run yet. This is what McpMate found when it tried the request without changing anything.",
                "mcpmate",
              )
            : __(
                "What McpMate found when it tried the request without changing anything, before anyone decided.",
                "mcpmate",
              )}
        </p>
        <JsonBlock
          value={data.preview}
          label={__("Preview details", "mcpmate")}
        />
      </Card>

      {data.result && (
        <Card
          title={
            data.executed_at
              ? sprintf(
                  /* translators: %s: date and time. */
                  __("Result (ran %s)", "mcpmate"),
                  fullDateTime(data.executed_at),
                )
              : __("Result", "mcpmate")
          }
          headingLevel={3}
        >
          {data.result.ok ? (
            <JsonBlock
              value={data.result.data}
              label={__("Result details", "mcpmate")}
            />
          ) : (
            <Notice
              tone="danger"
              title={
                data.result.error?.message ??
                __("It failed when it ran.", "mcpmate")
              }
            >
              {data.result.error?.code && mono(data.result.error.code)}
              {data.result.error?.hint ? (
                <span className="block">{data.result.error.hint}</span>
              ) : null}
            </Notice>
          )}
        </Card>
      )}

      {approveError && (
        <Notice
          tone="danger"
          live
          title={__("Couldn't approve this request.", "mcpmate")}
        >
          {approveError}
        </Notice>
      )}

      {pending && (
        <div className="el-dialog-actions el-approval-actions">
          <Button
            variant="danger"
            loading={deciding === "approve"}
            disabled={deciding === "reject"}
            onClick={() => void decide("approve")}
          >
            {deciding === "approve"
              ? __("Running…", "mcpmate")
              : sprintf(
                  /* translators: %s: what runs, e.g. search-replace. */
                  __("Approve and run %s", "mcpmate"),
                  name,
                )}
          </Button>
          <Button
            variant="secondary"
            disabled={deciding !== null}
            onClick={() => setConfirmReject(true)}
          >
            {__("Reject request", "mcpmate")}
          </Button>
        </div>
      )}

      <ConfirmDialog
        open={confirmReject}
        tone="danger"
        title={__("Reject this request?", "mcpmate")}
        body={interpolate(
          /* translators: %s: what would have run, e.g. search-replace. */
          __(
            "The request to run %s won't run. The AI app that asked is told it was rejected.",
            "mcpmate",
          ),
          [mono(name)],
        )}
        confirmLabel={__("Reject request", "mcpmate")}
        busyLabel={__("Rejecting…", "mcpmate")}
        cancelLabel={__("Cancel", "mcpmate")}
        onConfirm={() => decide("reject")}
        onClose={() => setConfirmReject(false)}
      />
    </div>
  );
}
