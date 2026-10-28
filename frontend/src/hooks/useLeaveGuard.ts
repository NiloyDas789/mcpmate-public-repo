import { useEffect, useRef } from "react";
import { useBlocker, type Blocker } from "react-router";

/**
 * Hold a move to another page of the app while `dirty` (docs/ui.md §4,
 * rule 8), and let the browser ask before the tab is closed or reloaded.
 * A change of the hash query alone (`?section=`) is not leaving. Render
 * `components/LeaveDialog` with the result.
 */
export function useLeaveGuard(dirty: boolean): {
  blocked: boolean;
  /** Leave without saving. */
  proceed: () => void;
  /** Stay on this page. */
  stay: () => void;
} {
  const blocker = useBlocker(
    ({ currentLocation, nextLocation }) =>
      dirty && currentLocation.pathname !== nextLocation.pathname,
  );
  // The dialog closes right after proceed(), before React re-renders:
  // that close must not take the navigation back.
  const latest = useRef<Blocker>(blocker);
  latest.current = blocker;
  const leaving = useRef(false);
  if (blocker.state === "unblocked") {
    leaving.current = false;
  }

  useEffect(() => {
    if (!dirty) {
      return;
    }
    const warn = (event: BeforeUnloadEvent) => {
      event.preventDefault();
      // Older browsers need a value; the text itself is never shown.
      event.returnValue = "";
    };
    window.addEventListener("beforeunload", warn);

    return () => window.removeEventListener("beforeunload", warn);
  }, [dirty]);

  return {
    blocked: blocker.state === "blocked",
    proceed: () => {
      if (latest.current.state === "blocked") {
        leaving.current = true;
        latest.current.proceed();
      }
    },
    stay: () => {
      if (!leaving.current && latest.current.state === "blocked") {
        latest.current.reset();
      }
    },
  };
}
