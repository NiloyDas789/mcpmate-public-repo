import { __, _n, sprintf } from "@wordpress/i18n";
import type { StatusGlyph, StatusTone } from "@components/ui/StatusDot";
import { formatNumber } from "@utils/format";
import type {
  BlockBatch,
  BlockBatchStatus,
  BlockChangeMode,
  BlockChangeStatus,
} from "../../types/api";
import type { RunProgress } from "./runner";

/** React Query keys of the block editor queue's pages. */
export const BLOCK_QUEUE_QUERY_KEY = ["block-queue"] as const;

export function batchStatusLabel(status: BlockBatchStatus): string {
  switch (status) {
    case "open":
      return __("Being queued", "mcpmate");
    case "ready":
      return __("Waiting for you", "mcpmate");
    case "running":
      return __("Finalizing", "mcpmate");
    case "finalized":
      return __("Finalized", "mcpmate");
    case "failed":
      return __("Failed", "mcpmate");
  }
}

/** The dot next to a status: never the only sign of it. */
export function batchStatusTone(status: BlockBatchStatus): {
  tone: StatusTone;
  glyph?: StatusGlyph;
} {
  switch (status) {
    case "open":
      return { tone: "neutral", glyph: "hollow" };
    case "ready":
    case "running":
      return { tone: "warning" };
    case "finalized":
      return { tone: "success" };
    case "failed":
      return { tone: "danger" };
  }
}

export function changeStatusLabel(status: BlockChangeStatus): string {
  switch (status) {
    case "queued":
      return __("Queued", "mcpmate");
    case "prepared":
      return __("Built, not saved yet", "mcpmate");
    case "saved":
      return __("Saved", "mcpmate");
    case "failed":
      return __("Failed", "mcpmate");
  }
}

export function changeStatusTone(status: BlockChangeStatus): {
  tone: StatusTone;
  glyph?: StatusGlyph;
} {
  switch (status) {
    case "queued":
    case "prepared":
      return { tone: "neutral", glyph: "hollow" };
    case "saved":
      return { tone: "success" };
    case "failed":
      return { tone: "danger" };
  }
}

/** What the AI app sent for a post. */
export function modeLabel(mode: BlockChangeMode): string {
  switch (mode) {
    case "blocks":
      return __("New blocks", "mcpmate");
    case "content":
      return __("New block markup", "mcpmate");
    case "resave":
      return __("Re-save its own blocks", "mcpmate");
  }
}

/** A batch's name: its label, or its number. */
export function batchName(batch: Pick<BlockBatch, "id" | "label">): string {
  return batch.label !== ""
    ? batch.label
    : sprintf(
        /* translators: %d: batch number. */
        __("Batch #%d", "mcpmate"),
        batch.id,
      );
}

export function changesText(count: number): string {
  return sprintf(
    /* translators: %s: number of post changes. */
    _n("%s change", "%s changes", count, "mcpmate"),
    formatNumber(count),
  );
}

/** Whether a site admin can finalize the batch now (a running one only when its tab went away). */
export function canFinalize(
  batch: Pick<BlockBatch, "status" | "trashed">,
): boolean {
  return (
    !batch.trashed && (batch.status === "ready" || batch.status === "failed")
  );
}

/** The progress line while this browser finalizes. */
export function progressText(progress: RunProgress): string {
  const n = formatNumber(progress.index + 1);
  const total = formatNumber(progress.total);

  switch (progress.step) {
    case "claiming":
      return __("Starting…", "mcpmate");
    case "loading":
      return sprintf(
        /* translators: 1: change number, 2: number of changes, 3: post title. */
        __("Opening post %1$s of %2$s in the block editor: %3$s…", "mcpmate"),
        n,
        total,
        progress.title,
      );
    case "building":
      return sprintf(
        /* translators: 1: change number, 2: number of changes, 3: post title. */
        __(
          "Letting the block editor write post %1$s of %2$s: %3$s…",
          "mcpmate",
        ),
        n,
        total,
        progress.title,
      );
    case "checking":
      return sprintf(
        /* translators: 1: change number, 2: number of changes, 3: post title. */
        __("Checking post %1$s of %2$s: %3$s…", "mcpmate"),
        n,
        total,
        progress.title,
      );
    case "saving":
      return __("Saving every post as a draft…", "mcpmate");
  }
}
