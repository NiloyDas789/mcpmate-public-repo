import { act, render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";

import { useAnnounce, type Announce } from "@/hooks/useAnnounce";
import Announcer from "./Announcer";

function Grab({ onReady }: { onReady: (announce: Announce) => void }) {
  onReady(useAnnounce());
  return null;
}

function setup() {
  let announce: Announce = () => undefined;
  render(
    <Announcer>
      <Grab onReady={(fn) => (announce = fn)} />
    </Announcer>,
  );
  return (text: string, assertive = false) =>
    act(() => announce(text, { assertive }));
}

describe("Announcer", () => {
  it("has one polite and one assertive region", () => {
    setup();

    expect(screen.getByRole("status")).toHaveAttribute("aria-live", "polite");
    expect(screen.getByRole("alert")).toHaveAttribute("aria-live", "assertive");
  });

  it("speaks politely or assertively", () => {
    const say = setup();

    say("24 entries match.");
    expect(screen.getByRole("status")).toHaveTextContent("24 entries match.");

    say("Couldn't save.", true);
    expect(screen.getByRole("alert")).toHaveTextContent("Couldn't save.");
  });

  it("does not repeat the same words within 500 ms, then does", () => {
    vi.useFakeTimers();
    const say = setup();

    say("Saved.");
    const first = screen.getByText("Saved.");
    say("Saved.");
    expect(screen.getByText("Saved.")).toBe(first);

    vi.advanceTimersByTime(600);
    say("Saved.");
    expect(screen.getByText("Saved.")).not.toBe(first);
    vi.useRealTimers();
  });
});
