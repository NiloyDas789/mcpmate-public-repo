import { useCallback, useMemo, useRef, useState, type ReactNode } from "react";
import { createPortal } from "react-dom";
import { AnnounceContext, type Announce } from "@/hooks/useAnnounce";
import { PORTAL_ID } from "@utils/portal";

interface Message {
  text: string;
  /** A new key replaces the node, so the same words are read again. */
  id: number;
}

const REPEAT_MS = 500;

/**
 * One polite (`role="status"`) and one assertive (`role="alert"`) live
 * region for the whole app, exposed as `useAnnounce()` (docs/ui.md §7).
 * They render into the portal container, outside the app, so they still
 * speak while a dialog makes the app inert.
 */
export default function Announcer({ children }: { children: ReactNode }) {
  const [polite, setPolite] = useState<Message>({ text: "", id: 0 });
  const [assertive, setAssertive] = useState<Message>({ text: "", id: 0 });
  const last = useRef({ text: "", at: 0 });

  const announce = useCallback<Announce>((text, options = {}) => {
    const now = Date.now();
    if (
      !text ||
      (text === last.current.text && now - last.current.at < REPEAT_MS)
    ) {
      return;
    }
    last.current = { text, at: now };

    const set = options.assertive ? setAssertive : setPolite;
    set((previous) => ({ text, id: previous.id + 1 }));
  }, []);

  const regions = (
    <>
      <div role="status" aria-live="polite" className="sr-only">
        <span key={polite.id}>{polite.text}</span>
      </div>
      <div role="alert" aria-live="assertive" className="sr-only">
        <span key={assertive.id}>{assertive.text}</span>
      </div>
    </>
  );

  const portal = useMemo(
    () =>
      typeof document !== "undefined"
        ? document.getElementById(PORTAL_ID)
        : null,
    [],
  );

  return (
    <AnnounceContext.Provider value={announce}>
      {children}
      {portal ? createPortal(regions, portal) : regions}
    </AnnounceContext.Provider>
  );
}
