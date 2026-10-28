import { createContext, useContext } from "react";

export interface AnnounceOptions {
  /** Use the assertive region (errors that need attention now). */
  assertive?: boolean;
}

export type Announce = (text: string, options?: AnnounceOptions) => void;

export const AnnounceContext = createContext<Announce>(() => undefined);

/**
 * Say something through the app's live regions (components/ui/Announcer):
 * result counts, saves, copies, sort changes, async results. The same text
 * is not repeated within 500 ms.
 */
export function useAnnounce(): Announce {
  return useContext(AnnounceContext);
}
