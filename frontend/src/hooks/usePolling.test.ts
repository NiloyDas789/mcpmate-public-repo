import { act, renderHook } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { usePolling } from "./usePolling";

function setHidden(hidden: boolean) {
  Object.defineProperty(document, "hidden", {
    value: hidden,
    configurable: true,
  });
  document.dispatchEvent(new Event("visibilitychange"));
}

beforeEach(() => {
  vi.useFakeTimers();
  setHidden(false);
});

afterEach(() => {
  vi.useRealTimers();
  setHidden(false);
});

describe("usePolling", () => {
  it("polls on an interval until done", async () => {
    let calls = 0;
    const poll = vi.fn(async () => ++calls >= 3);

    renderHook(() => usePolling(poll, { enabled: true, intervalMs: 3000 }));

    await act(async () => {
      await vi.advanceTimersByTimeAsync(10_000);
    });

    expect(poll).toHaveBeenCalledTimes(3);
  });

  it("pauses while the tab is hidden and polls once more when it is visible", async () => {
    const poll = vi.fn(async () => false);

    renderHook(() => usePolling(poll, { enabled: true, intervalMs: 3000 }));
    await act(async () => {
      await vi.advanceTimersByTimeAsync(0);
    });
    expect(poll).toHaveBeenCalledTimes(1);

    act(() => setHidden(true));
    await act(async () => {
      await vi.advanceTimersByTimeAsync(30_000);
    });
    // The timer that was already set fires once and finds the tab hidden.
    expect(poll).toHaveBeenCalledTimes(1);

    await act(async () => {
      setHidden(false);
      await vi.advanceTimersByTimeAsync(0);
    });
    expect(poll).toHaveBeenCalledTimes(2);
  });

  it("stops after the limit and Keep waiting starts again", async () => {
    const poll = vi.fn(async () => false);

    const { result } = renderHook(() =>
      usePolling(poll, { enabled: true, intervalMs: 1000, limitMs: 5000 }),
    );
    await act(async () => {
      await vi.advanceTimersByTimeAsync(20_000);
    });

    expect(result.current.timedOut).toBe(true);
    const calls = poll.mock.calls.length;

    await act(async () => {
      result.current.keepWaiting();
      await vi.advanceTimersByTimeAsync(0);
    });
    expect(result.current.timedOut).toBe(false);
    expect(poll.mock.calls.length).toBe(calls + 1);
  });

  it("reports an error and polls again on retry", async () => {
    const poll = vi
      .fn()
      .mockRejectedValueOnce(new Error("down"))
      .mockResolvedValue(true);

    const { result } = renderHook(() => usePolling(poll, { enabled: true }));
    await act(async () => {
      await vi.advanceTimersByTimeAsync(0);
    });
    expect(result.current.error).toBeInstanceOf(Error);

    await act(async () => {
      result.current.retry();
      await vi.advanceTimersByTimeAsync(0);
    });
    expect(result.current.error).toBeNull();
    expect(poll).toHaveBeenCalledTimes(2);
  });
});
