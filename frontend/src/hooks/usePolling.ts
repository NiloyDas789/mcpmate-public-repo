import { useCallback, useEffect, useRef, useState } from "react";

interface PollingOptions {
  enabled: boolean;
  intervalMs?: number;
  /** Stop and report `timedOut` after this long; Keep waiting starts again. */
  limitMs?: number;
}

/**
 * Call `poll` every `intervalMs` until it returns true (done), fails, or
 * `limitMs` passed. Polling pauses while the tab is hidden and runs once
 * more as soon as it is visible again. Never two calls at once.
 */
export function usePolling(
  poll: () => Promise<boolean>,
  { enabled, intervalMs = 3000, limitMs = 15 * 60 * 1000 }: PollingOptions,
) {
  const [timedOut, setTimedOut] = useState(false);
  const [error, setError] = useState<unknown>(null);
  const [round, setRound] = useState(0);
  const pollRef = useRef(poll);
  pollRef.current = poll;
  const nowRef = useRef<() => void>(() => undefined);

  useEffect(() => {
    if (!enabled || timedOut || error) {
      return undefined;
    }

    let cancelled = false;
    let running = false;
    let timer: ReturnType<typeof setTimeout> | undefined;
    const started = Date.now();

    const tick = async () => {
      if (cancelled || running) {
        return;
      }

      if (document.hidden) {
        // visibilitychange calls tick again.
        return;
      }

      clearTimeout(timer);
      running = true;

      try {
        const done = await pollRef.current();
        running = false;

        if (cancelled || done) {
          return;
        }
      } catch (caught) {
        running = false;

        if (!cancelled) {
          setError(caught);
        }

        return;
      }

      if (Date.now() - started >= limitMs) {
        setTimedOut(true);
        return;
      }

      timer = setTimeout(tick, intervalMs);
    };

    const onVisibility = () => {
      if (!document.hidden) {
        void tick();
      }
    };

    nowRef.current = () => void tick();
    document.addEventListener("visibilitychange", onVisibility);
    void tick();

    return () => {
      cancelled = true;
      clearTimeout(timer);
      nowRef.current = () => undefined;
      document.removeEventListener("visibilitychange", onVisibility);
    };
  }, [enabled, timedOut, error, round, intervalMs, limitMs]);

  return {
    timedOut,
    error,
    /** After the cap: start a new waiting period. */
    keepWaiting: useCallback(() => {
      setTimedOut(false);
      setRound((value) => value + 1);
    }, []),
    /** After an error: clear it and poll again. */
    retry: useCallback(() => {
      setError(null);
      setRound((value) => value + 1);
    }, []),
    /** Poll now (a BroadcastChannel message arrived). */
    pollNow: useCallback(() => nowRef.current(), []),
  };
}
