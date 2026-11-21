import { useCallback, useEffect, useRef, useState } from "react";
import { useQueryClient } from "@tanstack/react-query";
import { __, sprintf } from "@wordpress/i18n";
import { useAnnounce } from "@/hooks/useAnnounce";
import { saveTools } from "@services/api";
import { STATS_QUERY_KEY, TOOLS_QUERY_KEY } from "@services/queries";
import type { ToolGroup, ToolsResponse, ToolsUpdate } from "../../types/api";
import {
  applyChange,
  isEmptyChange,
  mergeChanges,
  withoutChange,
} from "./logic";

export type SaveState =
  | { status: "idle" }
  | { status: "saving" }
  | { status: "saved" }
  | { status: "failed"; error: unknown };

/** How long "Saved." stays on screen (docs/ui.md §4, rule 8). */
const SAVED_MS = 5000;

/**
 * Saves each change on the Tools page as soon as it is made: one
 * POST /tools per change, one at a time and in order, so a late answer
 * never undoes a newer choice. Until its answer arrives a change is shown
 * as made; a change that fails is taken back and kept for Retry.
 */
export function useToolSaves(groups: ToolGroup[] | undefined) {
  const queryClient = useQueryClient();
  const announce = useAnnounce();
  const [pending, setPending] = useState<ToolsUpdate[]>([]);
  const [state, setState] = useState<SaveState>({ status: "idle" });
  const failed = useRef<ToolsUpdate | null>(null);
  const lastError = useRef<unknown>(null);
  const queue = useRef<Promise<void>>(Promise.resolve());
  const waiting = useRef(0);

  useEffect(() => {
    if (state.status !== "saved") {
      return undefined;
    }
    const timer = window.setTimeout(
      () => setState({ status: "idle" }),
      SAVED_MS,
    );

    return () => window.clearTimeout(timer);
  }, [state]);

  const save = useCallback(
    (change: ToolsUpdate, message: string) => {
      if (isEmptyChange(change)) {
        return;
      }

      // A newer choice replaces what a failed save would have sent.
      if (failed.current) {
        const left = withoutChange(failed.current, change);
        failed.current = isEmptyChange(left) ? null : left;
      }

      waiting.current += 1;
      setPending((list) => [...list, change]);
      setState({ status: "saving" });

      queue.current = queue.current.then(async () => {
        try {
          const saved = await saveTools(change);
          queryClient.setQueryData<ToolsResponse>(TOOLS_QUERY_KEY, saved);
          void queryClient.invalidateQueries({ queryKey: STATS_QUERY_KEY });
          waiting.current -= 1;
          setPending((list) => list.filter((item) => item !== change));

          if (waiting.current === 0) {
            // A change that failed earlier keeps its error and Retry.
            setState(
              failed.current
                ? { status: "failed", error: lastError.current }
                : { status: "saved" },
            );
          }
          announce(
            sprintf(
              /* translators: %s: what changed, e.g. "Upload an image or PDF turned on." */
              __("%s Saved.", "mcpmate"),
              message,
            ),
          );
        } catch (error) {
          waiting.current -= 1;
          failed.current = failed.current
            ? mergeChanges(failed.current, change)
            : change;
          lastError.current = error;
          setPending((list) => list.filter((item) => item !== change));
          setState({ status: "failed", error });
        }
      });
    },
    [announce, queryClient],
  );

  /** Send everything that failed again, as one change. */
  const retry = useCallback(() => {
    const change = failed.current;
    failed.current = null;
    if (change) {
      save(change, __("Your changes were sent again.", "mcpmate"));
    }
  }, [save]);

  const view = groups ? pending.reduce(applyChange, groups) : undefined;

  return { view, state, save, retry };
}
