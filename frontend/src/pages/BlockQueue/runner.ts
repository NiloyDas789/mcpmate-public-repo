import { AjaxRequestError } from "@services/api";
import type {
  BlockBatch,
  BlockPayloadPiece,
  BlockQueueClaim,
  BlockQueueJob,
} from "../../types/api";
import {
  BridgeError,
  errorText,
  finalizeInEditor,
  type BlockSpecNode,
  type BridgeJob,
  type BridgeOptions,
  type BridgeResult,
  type EditorWp,
} from "./editorBridge";
import type { OpenEditor } from "./editorFrame";

/** Where a run is, for the progress line. */
export interface RunProgress {
  step: "claiming" | "loading" | "building" | "checking" | "saving";
  /** 0-based change index (claiming and saving: 0). */
  index: number;
  total: number;
  title: string;
}

export interface RunnerDeps {
  claim(id: number): Promise<BlockQueueClaim>;
  payload(
    id: number,
    item: number,
    lease: string,
    offset: number,
  ): Promise<BlockPayloadPiece>;
  prepare(
    id: number,
    item: number,
    lease: string,
    content: string,
    editor: { recovered: number; registered: string[] },
  ): Promise<BlockBatch>;
  fail(
    id: number,
    item: number,
    lease: string,
    reason: string,
    message: string,
  ): Promise<BlockBatch>;
  commit(id: number, lease: string): Promise<BlockBatch>;
  openEditor(url: string): Promise<OpenEditor>;
  closeEditor(): void;
  /** finalizeInEditor, or a stand-in in tests. */
  finalize?: (
    wp: EditorWp,
    job: BridgeJob,
    options: BridgeOptions,
  ) => Promise<BridgeResult>;
  onProgress?(progress: RunProgress): void;
  bridgeOptions?: BridgeOptions;
  /** Set when the page is left: the run stops before the next step, and nothing more is saved. */
  signal?: { aborted: boolean };
}

export type RunOutcome =
  | { ok: true; batch: BlockBatch }
  /** `batch` when the server answered with it; `error` says what stopped the run. */
  | { ok: false; batch: BlockBatch | null; error: unknown };

/** The most payload pieces one change may take (1 MiB at ≥ 16 KiB each). */
const MAX_PIECES = 64;

/**
 * Finalizes batch `id` in this browser: claims it, and for each change
 * reads its payload, applies it in the post's block editor and sends the
 * editor's content to be checked; once every change passed, commits, which
 * checks them all again and saves them as drafts. The first failure stops
 * the run, and the server fails the batch (nothing more is saved).
 */
export async function runFinalization(
  id: number,
  deps: RunnerDeps,
): Promise<RunOutcome> {
  const finalize = deps.finalize ?? finalizeInEditor;
  let claim: BlockQueueClaim;

  deps.onProgress?.({ step: "claiming", index: 0, total: 0, title: "" });
  try {
    claim = await deps.claim(id);
  } catch (error) {
    return { ok: false, batch: null, error };
  }

  const total = claim.items.length;

  for (const [index, job] of claim.items.entries()) {
    const progress = (step: RunProgress["step"]) =>
      deps.onProgress?.({ step, index, total, title: job.post_title });

    let result: BridgeResult;
    try {
      stopIfAborted(deps);
      progress("loading");
      const payload = await readPayload(deps, id, job, claim.lease);
      const editor = await deps.openEditor(job.editor_url);

      progress("building");
      result = await finalize(editor.wp, toBridgeJob(job, payload), {
        ...deps.bridgeOptions,
        clone: editor.clone,
      });
      stopIfAborted(deps);
    } catch (error) {
      return failChange(deps, id, job, claim.lease, error);
    } finally {
      deps.closeEditor();
    }

    progress("checking");
    try {
      await deps.prepare(id, job.id, claim.lease, result.content, {
        recovered: result.recovered,
        registered: result.registered,
      });
    } catch (error) {
      // The server already failed the batch when it refused the change.
      return { ok: false, batch: null, error };
    }
  }

  deps.onProgress?.({ step: "saving", index: 0, total, title: "" });
  try {
    return { ok: true, batch: await deps.commit(id, claim.lease) };
  } catch (error) {
    return { ok: false, batch: null, error };
  }
}

function stopIfAborted(deps: RunnerDeps): void {
  if (deps.signal?.aborted) {
    throw new BridgeError(
      "network",
      "Finalizing stopped: the site admin left the page.",
    );
  }
}

/** A change's payload, put together from its pieces. */
async function readPayload(
  deps: RunnerDeps,
  id: number,
  job: BlockQueueJob,
  lease: string,
): Promise<string> {
  let text = "";
  let offset = 0;

  for (let pieces = 0; pieces < MAX_PIECES; pieces++) {
    const piece = await deps.payload(id, job.id, lease, offset);
    text += piece.piece;

    if (piece.next_offset >= piece.total_bytes) {
      return text;
    }
    if (piece.next_offset <= offset) {
      break;
    }
    offset = piece.next_offset;
  }

  throw new BridgeError(
    "network",
    "The change could not be read from the site in full.",
  );
}

function toBridgeJob(job: BlockQueueJob, payload: string): BridgeJob {
  if (job.mode !== "blocks") {
    return { post_id: job.post_id, mode: job.mode, content: payload };
  }

  let blocks: unknown;
  try {
    blocks = JSON.parse(payload);
  } catch {
    throw new BridgeError(
      "invalid_block_spec",
      "The queued block tree is not valid JSON.",
    );
  }

  if (!Array.isArray(blocks)) {
    throw new BridgeError(
      "invalid_block_spec",
      "The queued block tree is not a list.",
    );
  }

  return {
    post_id: job.post_id,
    mode: "blocks",
    blocks: blocks as BlockSpecNode[],
  };
}

/**
 * Tells the server why a change failed in the browser (it fails the
 * batch). A refusal from the server itself is passed on as it is.
 */
async function failChange(
  deps: RunnerDeps,
  id: number,
  job: BlockQueueJob,
  lease: string,
  error: unknown,
): Promise<RunOutcome> {
  if (error instanceof AjaxRequestError && error.kind === "refused") {
    return { ok: false, batch: null, error };
  }

  const reason =
    error instanceof BridgeError
      ? error.reason
      : error instanceof AjaxRequestError
        ? "network"
        : "serializer_failed";

  try {
    const batch = await deps.fail(id, job.id, lease, reason, errorText(error));

    return { ok: false, batch, error };
  } catch {
    // Unreachable site: the lease runs out and the batch can be finalized again.
    return { ok: false, batch: null, error };
  }
}
