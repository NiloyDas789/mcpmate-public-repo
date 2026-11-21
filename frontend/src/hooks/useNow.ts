import { useEffect, useState } from "react";

/** The current time, refreshed every `intervalMs` (relative times). */
export function useNow(intervalMs = 60_000): Date {
  const [now, setNow] = useState(() => new Date());

  useEffect(() => {
    const timer = window.setInterval(() => setNow(new Date()), intervalMs);

    return () => window.clearInterval(timer);
  }, [intervalMs]);

  return now;
}
