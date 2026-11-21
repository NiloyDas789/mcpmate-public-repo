import { act, renderHook } from "@testing-library/react";
import { afterEach, describe, expect, it } from "vitest";

import { isNarrow, useContainerWidth } from "./useContainerWidth";

/** A container the hook can measure (happy-dom has no layout). */
function box(width: number): HTMLDivElement {
  const node = document.createElement("div");
  node.getBoundingClientRect = () => ({ width }) as DOMRect;

  return node;
}

afterEach(() => {
  document.documentElement.style.fontSize = "";
});

describe("useContainerWidth", () => {
  it("measures the container", () => {
    const { result } = renderHook(() => useContainerWidth());

    act(() => result.current[0](box(1000)));

    expect(result.current[1]).toBe(1000);
  });

  it("counts a larger text size as a narrower container (200 % text)", () => {
    document.documentElement.style.fontSize = "32px";
    const { result } = renderHook(() => useContainerWidth());

    act(() => result.current[0](box(1000)));

    // A 720 px breakpoint (table → cards) now applies at 1000 px.
    expect(result.current[1]).toBe(500);
    expect(isNarrow("auto", result.current[1], 720)).toBe(true);
  });
});
