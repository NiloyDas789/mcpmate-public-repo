import { useEffect, useRef, useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { Link, useParams } from "react-router";
import { ArrowLeftOutlined } from "@ant-design/icons";
import { __, _n, sprintf } from "@wordpress/i18n";
import Button from "@components/ui/Button";
import Card from "@components/ui/Card";
import ConfirmDialog from "@components/ui/ConfirmDialog";
import Notice from "@components/ui/Notice";
import Skeleton from "@components/ui/Skeleton";
import StatusText from "@components/ui/StatusText";
import Table, { type TableColumn } from "@components/ui/Table";
import { useAnnounce } from "@/hooks/useAnnounce";
import { useLeaveGuard } from "@/hooks/useLeaveGuard";
import {
  claimBlockBatch,
  commitBlockBatch,
  failBlockChange,
  fetchBlockBatch,
  fetchPayloadPiece,
  prepareBlockChange,
  trashBlockBatch,
} from "@services/blockQueue";
import { requestErrorText } from "@utils/errors";
import { formatNumber } from "@utils/format";
import type { ApprovalUser, BlockBatch, BlockChange } from "../../types/api";
import { fullDateTime, postEditUrl } from "../Activity/logic";
import { createEditorFrame, type EditorFrame } from "./editorFrame";
import {
  BLOCK_QUEUE_QUERY_KEY,
  batchName,
  batchStatusLabel,
  batchStatusTone,
  canFinalize,
  changeStatusLabel,
  changeStatusTone,
  modeLabel,
  progressText,
} from "./labels";
import { runFinalization, type RunProgress } from "./runner";

function userLabel(user: ApprovalUser | null | undefined): string {
  if (!user) {
    return "—";
  }

  const name = user.display_name ?? `#${user.id}`;

  return user.login ? `${name} (${user.login})` : name;
}

function BackLink() {
  return (
    <p className="m-0">
      <Link to="/activity/block-queue" className="el-back-link">
        <ArrowLeftOutlined aria-hidden className="el-flip" />{" "}
        {__("All batches", "mcpmate")}
      </Link>
    </p>
  );
}

function sizeText(bytes: number): string {
  return bytes < 1024
    ? sprintf(
        /* translators: %s: a number of bytes. */
        __("%s B", "mcpmate"),
        formatNumber(bytes),
      )
    : sprintf(
        /* translators: %s: a number of kilobytes. */
        __("%s KB", "mcpmate"),
        formatNumber(Math.round(bytes / 102.4) / 10),
      );
}

/** Where a saved change went, or why it failed. */
function Outcome({ change }: { change: BlockChange }) {
  if (change.error) {
    return (
      <>
        <span className="block">{change.error.message}</span>
        {change.error.hint && (
          <span className="block text-el-text-secondary">
            {change.error.hint}
          </span>
        )}
      </>
    );
  }

  if (!change.result) {
    return <>—</>;
  }

  const { result } = change;
  const text = result.copied
    ? sprintf(
        /* translators: %d: post ID of the new draft. */
        __(
          "Saved to a new draft copy, post #%d. The live post is unchanged.",
          "mcpmate",
        ),
        result.target_id,
      )
    : result.changed
      ? __("Saved to the draft.", "mcpmate")
      : __("Unchanged: the block editor wrote the same content.", "mcpmate");

  const repaired = change.editor?.recovered ?? 0;

  return (
    <>
      <span className="block">{text}</span>
      {repaired > 0 && (
        <span className="block text-el-text-secondary">
          {sprintf(
            /* translators: %s: number of blocks. */
            _n(
              "The block editor repaired %s invalid block.",
              "The block editor repaired %s invalid blocks.",
              repaired,
              "mcpmate",
            ),
            formatNumber(repaired),
          )}
        </span>
      )}
      {result.edit_url && (
        <a href={result.edit_url} target="_blank" rel="noreferrer">
          {__("Review the draft", "mcpmate")}
          <span className="sr-only">
            {" "}
            {__("(opens in a new tab)", "mcpmate")}
          </span>
        </a>
      )}
    </>
  );
}

/**
 * Activity → Block editor queue → one batch (`#/activity/block-queue/:id`;
 * MCPMate Pro). Finalize runs the block editor of each post in a hidden
 * frame of this page, lets every block write its own markup, has the
 * server check each result as the save would, and then saves them all as
 * drafts. Leaving the page stops it; nothing more is saved.
 */
export default function BlockBatchDetail() {
  const id = Number(useParams().id);
  const queryClient = useQueryClient();
  const announce = useAnnounce();
  const heading = useRef<HTMLHeadingElement>(null);
  const frameHost = useRef<HTMLDivElement>(null);
  const [running, setRunning] = useState(false);
  const [progress, setProgress] = useState<RunProgress | null>(null);
  const [outcome, setOutcome] = useState<{ ok: boolean; text: string } | null>(
    null,
  );
  const [confirmTrash, setConfirmTrash] = useState(false);
  const guard = useLeaveGuard(running);
  // Leaving the page stops a run before its next step.
  const signal = useRef({ aborted: false });
  useEffect(() => {
    const current = signal.current;
    current.aborted = false;

    return () => {
      current.aborted = true;
    };
  }, []);
  const queryKey = [...BLOCK_QUEUE_QUERY_KEY, "batch", id];

  const { data, isPending, isError, error, refetch } = useQuery({
    queryKey,
    queryFn: () => fetchBlockBatch(id),
    enabled: Number.isInteger(id) && id > 0,
    staleTime: 0,
  });

  const loaded = Boolean(data);
  useEffect(() => {
    if (loaded) {
      heading.current?.focus();
    }
  }, [loaded, id]);

  const refresh = async (batch: BlockBatch | null) => {
    if (batch) {
      queryClient.setQueryData<BlockBatch>(queryKey, batch);
    } else {
      await queryClient.invalidateQueries({ queryKey });
    }
    void queryClient.invalidateQueries({
      queryKey: [...BLOCK_QUEUE_QUERY_KEY, "list"],
    });
  };

  const finalize = async () => {
    if (!frameHost.current) {
      return;
    }

    const frame: EditorFrame = createEditorFrame(frameHost.current);
    setRunning(true);
    setOutcome(null);

    const result = await runFinalization(id, {
      claim: claimBlockBatch,
      payload: fetchPayloadPiece,
      prepare: prepareBlockChange,
      fail: failBlockChange,
      commit: commitBlockBatch,
      signal: signal.current,
      openEditor: frame.open,
      closeEditor: frame.close,
      onProgress: (next) => {
        setProgress(next);
        announce(progressText(next));
      },
    });

    frame.close();
    setRunning(false);
    setProgress(null);
    await refresh(result.batch);

    const text = result.ok
      ? __(
          "Finalized. Every post was saved as a draft: review them before you publish.",
          "mcpmate",
        )
      : sprintf(
          /* translators: %s: what went wrong. */
          __("Finalizing stopped, and nothing more was saved: %s", "mcpmate"),
          requestErrorText(result.error),
        );
    setOutcome({ ok: result.ok, text });
    announce(text, { assertive: !result.ok });
  };

  const trash = async () => {
    const batch = await trashBlockBatch(id);
    await refresh(batch);
    setConfirmTrash(false);
    announce(__("The batch is in the trash. No post was changed.", "mcpmate"));
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
          title={__("Couldn't load this batch.", "mcpmate")}
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

  const changes = data.items ?? [];
  const finalizable = canFinalize(data);
  const revoked = data.grant !== null && data.grant.status !== "active";

  const columns: TableColumn<BlockChange>[] = [
    {
      key: "post",
      title: __("Post", "mcpmate"),
      rowHeader: true,
      render: (change) => (
        <>
          <a
            href={postEditUrl(change.post_id)}
            target="_blank"
            rel="noreferrer"
          >
            <bdi>
              {change.post_title ||
                sprintf(
                  /* translators: %d: post ID. */
                  __("Post #%d", "mcpmate"),
                  change.post_id,
                )}
            </bdi>
            <span className="sr-only">
              {" "}
              {__("(opens in a new tab)", "mcpmate")}
            </span>
          </a>
          <span className="block text-el-text-secondary">
            {sprintf(
              /* translators: 1: post ID, 2: post status, e.g. draft. */
              __("#%1$d · %2$s", "mcpmate"),
              change.post_id,
              change.post_status,
            )}
          </span>
        </>
      ),
    },
    {
      key: "change",
      title: __("Change", "mcpmate"),
      render: (change) => (
        <>
          {modeLabel(change.mode)}
          {change.mode !== "resave" && (
            <span className="block text-el-text-secondary">
              {sizeText(change.payload_bytes)}
            </span>
          )}
          {change.loss_accepted && (
            <span className="block text-el-text-secondary">
              {__("Removes content the user confirmed", "mcpmate")}
            </span>
          )}
        </>
      ),
    },
    {
      key: "status",
      title: __("Status", "mcpmate"),
      render: (change) => (
        <StatusText {...changeStatusTone(change.status)}>
          {changeStatusLabel(change.status)}
        </StatusText>
      ),
    },
    {
      key: "outcome",
      title: __("Outcome", "mcpmate"),
      render: (change) => <Outcome change={change} />,
    },
  ];

  return (
    <div className="el-stack el-approval">
      <BackLink />

      <div className="el-approval-head">
        <h2 ref={heading} tabIndex={-1} className="el-approval-title">
          <bdi>{batchName(data)}</bdi>
        </h2>
        <StatusText {...batchStatusTone(data.status)}>
          {data.trashed
            ? __("In the trash", "mcpmate")
            : batchStatusLabel(data.status)}
        </StatusText>
      </div>

      {outcome && (
        <Notice tone={outcome.ok ? "success" : "danger"} title={outcome.text} />
      )}

      {finalizable && !running && !outcome && (
        <Notice
          tone="warning"
          title={__(
            "An AI app asks you to finalize these post changes.",
            "mcpmate",
          )}
        >
          {__(
            "Finalize opens each post in the block editor, hidden in this page, lets every block write its own markup, and saves the post as a draft. A published post is never changed: it gets a new draft copy. Nothing is published. Keep this page open until it is done.",
            "mcpmate",
          )}
        </Notice>
      )}

      {data.status === "open" && !data.trashed && (
        <Notice
          tone="info"
          title={__("The AI app is still adding changes.", "mcpmate")}
        >
          {__(
            "You can finalize the batch once the app asks you to.",
            "mcpmate",
          )}
        </Notice>
      )}

      {data.error && data.status === "failed" && !outcome && (
        <Notice tone="danger" title={data.error.message}>
          {data.error.hint}
        </Notice>
      )}

      <Card title={__("Details", "mcpmate")} headingLevel={3}>
        <dl className="el-details">
          <div>
            <dt>{__("Queued by", "mcpmate")}</dt>
            <dd>{userLabel(data.user)}</dd>
          </div>
          {data.grant && (
            <div>
              <dt>{__("AI app", "mcpmate")}</dt>
              <dd>
                <bdi>{data.grant.client_name}</bdi>
                {revoked && ` · ${__("disconnected", "mcpmate")}`}
              </dd>
            </div>
          )}
          {data.note && (
            <div>
              <dt>{__("The app's note", "mcpmate")}</dt>
              <dd>
                <bdi>{data.note}</bdi>
              </dd>
            </div>
          )}
          {data.requested_at && (
            <div>
              <dt>{__("Asked to finalize", "mcpmate")}</dt>
              <dd>
                <time dateTime={data.requested_at}>
                  {fullDateTime(data.requested_at)}
                </time>
              </dd>
            </div>
          )}
          {data.finalized_at && (
            <div>
              <dt>{__("Finalized by", "mcpmate")}</dt>
              <dd>
                {sprintf(
                  /* translators: 1: admin's name, 2: date and time. */
                  __("%1$s, %2$s", "mcpmate"),
                  userLabel(data.finalized_by),
                  fullDateTime(data.finalized_at),
                )}
              </dd>
            </div>
          )}
        </dl>
      </Card>

      <Table<BlockChange>
        caption={__("Post changes, in the order they are applied.", "mcpmate")}
        columns={columns}
        rows={changes}
        rowKey={(change) => String(change.id)}
        empty={<p className="m-0">{__("No changes yet.", "mcpmate")}</p>}
      />

      <div aria-live="polite" className="el-finalizer-progress">
        {progress && <p className="m-0">{progressText(progress)}</p>}
      </div>

      {/* The hidden block editor lives here while it runs. */}
      <div ref={frameHost} className="el-finalizer-host" />

      {!data.trashed && (
        <div className="el-dialog-actions el-approval-actions">
          {finalizable && (
            <Button
              variant="primary"
              loading={running}
              disabled={revoked}
              onClick={() => void finalize()}
            >
              {running
                ? __("Finalizing…", "mcpmate")
                : data.status === "failed"
                  ? __("Try finalizing again", "mcpmate")
                  : __("Finalize in the block editor", "mcpmate")}
            </Button>
          )}
          {data.status !== "running" && !running && (
            <Button variant="secondary" onClick={() => setConfirmTrash(true)}>
              {__("Move to trash", "mcpmate")}
            </Button>
          )}
        </div>
      )}

      <ConfirmDialog
        open={confirmTrash}
        tone="danger"
        title={__("Move this batch to the trash?", "mcpmate")}
        body={__(
          "Its changes won't be applied. No post is changed, and drafts an earlier finalization saved stay as they are.",
          "mcpmate",
        )}
        confirmLabel={__("Move to trash", "mcpmate")}
        busyLabel={__("Moving…", "mcpmate")}
        cancelLabel={__("Cancel", "mcpmate")}
        onConfirm={trash}
        onClose={() => setConfirmTrash(false)}
      />

      <ConfirmDialog
        open={guard.blocked}
        tone="danger"
        title={__("Stop finalizing?", "mcpmate")}
        body={__(
          "The block editor is still applying changes. If you leave, finalizing stops and nothing more is saved. You can finalize the batch again in ten minutes.",
          "mcpmate",
        )}
        confirmLabel={__("Leave and stop", "mcpmate")}
        cancelLabel={__("Stay on this page", "mcpmate")}
        onConfirm={async () => guard.proceed()}
        onClose={guard.stay}
      />
    </div>
  );
}
