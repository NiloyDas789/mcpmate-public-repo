import { useRef } from "react";
import { useLocation, useNavigate } from "react-router";
import { withParams } from "./logic";

/**
 * The list's state in the hash query, for pages that write it on every
 * filter, sort, page and drawer (Audit log, Change history).
 *
 * The router renders a new URL in a transition, which a quick next key
 * press interrupts: until it commits, the rendered `search` is the old one.
 * `current()` is the newest URL this page wrote, so a handler never builds
 * on a URL that is already gone (and drops the sort or page set a moment
 * before). A URL that comes from elsewhere (Back, a link) replaces it.
 */
export function useHashQuery() {
  const { pathname, search, state } = useLocation();
  const navigate = useNavigate();
  const latest = useRef(search);
  // URLs written here and not rendered yet, oldest first.
  const written = useRef<string[]>([]);
  const rendered = useRef(search);

  if (search !== rendered.current) {
    rendered.current = search;
    const index = written.current.indexOf(search);
    if (index === -1) {
      written.current = [];
      latest.current = search;
    } else {
      written.current = written.current.slice(index + 1);
    }
  }

  const remember = (next: string) => {
    latest.current = next;
    written.current.push(next);
  };

  return {
    /** The rendered query: what the page shows. */
    search,
    /** The newest query: what the next change builds on. */
    current: () => latest.current,
    /** Replace the query; `push` adds a history entry (an open drawer, so Back closes it). */
    go: (next: string, options: { push?: boolean } = {}) => {
      remember(next);
      navigate(
        { pathname, search: next },
        options.push ? { state: { drawer: true } } : { replace: true },
      );
    },
    /** Close a drawer kept in `param`: Back when this page opened it, else remove the key. */
    closeDrawer: (param: string) => {
      const next = withParams(latest.current, { [param]: null });
      remember(next);
      if ((state as { drawer?: boolean } | null)?.drawer) {
        navigate(-1);
      } else {
        navigate({ pathname, search: next }, { replace: true });
      }
    },
  };
}
