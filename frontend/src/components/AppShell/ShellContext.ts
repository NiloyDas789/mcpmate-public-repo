import { createContext, useMemo, useRef } from "react";
import { useLocation, useNavigationType } from "react-router";

export interface ShellContextValue {
  /**
   * True once the user has moved to another route (a link, Back, Forward),
   * not for the first route or a redirect. A page's `<h1>` takes focus
   * when it mounts after that (docs/ui.md §9: "after a route change, focus
   * moves to the h1"). Tabs within a page keep the same `<h1>`, so focus
   * stays on the tab.
   */
  navigated: () => boolean;
}

export const ShellContext = createContext<ShellContextValue>({
  navigated: () => false,
});

export function useShellContextValue(): ShellContextValue {
  const location = useLocation();
  const navigationType = useNavigationType();
  const lastKey = useRef(location.key);
  const navigated = useRef(false);

  if (location.key !== lastKey.current) {
    lastKey.current = location.key;
    if (navigationType !== "REPLACE") {
      navigated.current = true;
    }
  }

  return useMemo(() => ({ navigated: () => navigated.current }), []);
}
