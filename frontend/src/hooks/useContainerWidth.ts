import { useCallback, useRef, useState } from "react";

/**
 * How many times the browser's default text size (16 px) the root font
 * is: 2 when the visitor set text to 200 %.
 */
function textScale(): number {
  const size =
    typeof window === "undefined"
      ? NaN
      : parseFloat(window.getComputedStyle(document.documentElement).fontSize);

  return size > 0 ? size / 16 : 1;
}

/**
 * The width of an element, kept up to date with a ResizeObserver
 * (docs/ui.md §6.5): components that change shape follow their container,
 * so they behave the same at 200 % zoom and in narrow columns. The width
 * is in px at the default text size, so a larger text size counts as a
 * narrower container (WCAG 1.4.4): at 200 % text a 1000 px column is 500,
 * and a table becomes cards instead of overflowing. Undefined until
 * measured (and where layout is not computed, as in tests).
 */
export function useContainerWidth<T extends HTMLElement = HTMLDivElement>() {
  const [width, setWidth] = useState<number>();
  const observer = useRef<ResizeObserver | null>(null);

  const ref = useCallback((node: T | null) => {
    observer.current?.disconnect();
    observer.current = null;

    if (!node) {
      return;
    }

    const measure = (value: number) =>
      setWidth(value > 0 ? Math.round(value / textScale()) : undefined);
    measure(node.getBoundingClientRect().width);

    if (typeof ResizeObserver !== "undefined") {
      observer.current = new ResizeObserver(([entry]) =>
        measure(entry.contentRect.width),
      );
      observer.current.observe(node);
    }
  }, []);

  return [ref, width] as const;
}

export type Layout = "auto" | "wide" | "narrow";

/**
 * Narrow when forced, or when the measured width is below `breakpoint`.
 * An unmeasured container counts as wide.
 */
export function isNarrow(
  layout: Layout,
  width: number | undefined,
  breakpoint: number,
): boolean {
  if (layout !== "auto") {
    return layout === "narrow";
  }

  return width !== undefined && width < breakpoint;
}
