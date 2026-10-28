import {
  useEffect,
  useLayoutEffect,
  useRef,
  useState,
  type RefObject,
} from "react";
import { tabbable } from "@utils/focus";
import { makeAppInert } from "@utils/portal";

/**
 * Where focus goes when a dialog opens: an element of the caller's, the
 * title (long content), or `auto`: the first field, else the first action.
 */
export type InitialFocus = "auto" | "title" | RefObject<HTMLElement | null>;

/** Marks a dialog's title, so `initialFocus: "title"` can find it. */
export const TITLE_ATTRIBUTE = "data-dialog-title";

const CLOSE_BUTTON = ".ant-modal-close, .ant-drawer-close";

function initialTarget(
  panel: HTMLElement,
  initialFocus: InitialFocus,
): HTMLElement | null {
  const title = panel.querySelector<HTMLElement>(`[${TITLE_ATTRIBUTE}]`);

  if (initialFocus === "title") {
    return title;
  }
  if (initialFocus !== "auto") {
    return initialFocus.current;
  }

  const candidates = tabbable(panel).filter(
    (element) => !element.matches(CLOSE_BUTTON),
  );

  return (
    candidates.find((element) => element.matches("input, select, textarea")) ??
    candidates[0] ??
    title
  );
}

/**
 * Focus rules shared by Modal and Drawer (docs/ui.md §7): focus moves into
 * the dialog when it opens, Tab and Shift+Tab wrap inside it, the app behind
 * it is inert, and on close focus returns to the element that opened it.
 *
 * Ant Design's own sentinels trap focus too, but only after an extra stop
 * on an unnamed element, and their timing follows the open animation; these
 * rules are the same in every browser and in tests. Returns the callback
 * ref for the dialog's panel.
 */
export function useDialogFocus(
  open: boolean,
  initialFocus: InitialFocus = "auto",
) {
  const [panel, setPanel] = useState<HTMLElement | null>(null);
  const opener = useRef<HTMLElement | null>(null);
  const initial = useRef(initialFocus);
  initial.current = initialFocus;

  // Before anything moves focus: remember the opener, make the app inert.
  // Closing wakes the app here, in the commit.
  useLayoutEffect(() => {
    if (!open) {
      return;
    }

    const active = document.activeElement;
    if (active instanceof HTMLElement && active !== document.body) {
      opener.current = active;
    }

    return makeAppInert();
  }, [open]);

  // Focus goes back after the commit: React restores the element that was
  // focused before a commit, which would pull focus into the closing dialog.
  useEffect(() => {
    if (!open) {
      return;
    }

    return () => {
      const target = opener.current;
      opener.current = null;
      if (target?.isConnected) {
        target.focus();
      }
    };
  }, [open]);

  // Initial focus, retried for a few frames: the panel can still be hidden
  // while its open animation starts.
  useEffect(() => {
    if (!open || !panel) {
      return;
    }

    let frame = 0;
    let tries = 0;
    const attempt = () => {
      const target = initialTarget(panel, initial.current);
      target?.focus();
      if (target && document.activeElement !== target && tries++ < 10) {
        frame = window.requestAnimationFrame(attempt);
      }
    };
    attempt();

    return () => window.cancelAnimationFrame(frame);
  }, [open, panel]);

  // Tab and Shift+Tab wrap around inside the panel.
  useEffect(() => {
    if (!open || !panel) {
      return;
    }

    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key !== "Tab") {
        return;
      }

      const items = tabbable(panel);
      if (items.length === 0) {
        event.preventDefault();
        return;
      }

      const first = items[0];
      const last = items[items.length - 1];
      const active = document.activeElement;
      const outside = !(active instanceof Node) || !panel.contains(active);

      if (event.shiftKey && (active === first || outside)) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && (active === last || outside)) {
        event.preventDefault();
        first.focus();
      }
    };

    panel.addEventListener("keydown", onKeyDown);

    return () => panel.removeEventListener("keydown", onKeyDown);
  }, [open, panel]);

  return setPanel;
}
