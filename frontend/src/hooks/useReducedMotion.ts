import { useEffect, useState } from "react";

const QUERY = "(prefers-reduced-motion: reduce)";

function matches(): boolean {
  return typeof window !== "undefined" &&
    typeof window.matchMedia === "function"
    ? window.matchMedia(QUERY).matches
    : false;
}

/** True while the visitor asks for reduced motion (docs/ui.md §6.6). */
export function useReducedMotion(): boolean {
  const [reduced, setReduced] = useState(matches);

  useEffect(() => {
    if (typeof window.matchMedia !== "function") {
      return;
    }

    const query = window.matchMedia(QUERY);
    const onChange = () => setReduced(query.matches);
    query.addEventListener?.("change", onChange);

    return () => query.removeEventListener?.("change", onChange);
  }, []);

  return reduced;
}
