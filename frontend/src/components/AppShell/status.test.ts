import { describe, expect, it } from "vitest";

import { pillState, shellNotices, type ShellData } from "./status";

const base: ShellData = {
  grantsLive: 3,
  paused: false,
  readOnly: false,
  pendingApprovals: 0,
  onboarding: { status: "completed", step: "first_call" },
};

describe("pillState", () => {
  it("follows the first condition that holds", () => {
    expect(pillState({ ...base, paused: true, grantsLive: 0 })).toMatchObject({
      tone: "warning",
      glyph: "pause",
      text: "AI access paused",
    });
    expect(pillState({ ...base, paused: true, readOnly: true })).toMatchObject({
      text: "AI access paused",
    });
    expect(pillState({ ...base, readOnly: true, grantsLive: 0 })).toMatchObject(
      { tone: "warning", text: "Read only" },
    );
    expect(pillState({ ...base, grantsLive: 0 })).toMatchObject({
      text: "No AI apps connected",
    });
    expect(pillState({ ...base, grantsLive: 1 })).toMatchObject({
      tone: "success",
      text: "1 AI app connected",
    });
    expect(pillState(base)).toMatchObject({ text: "3 AI apps connected" });
  });
});

describe("shellNotices", () => {
  it("keeps the fixed order and at most two", () => {
    const notices = shellNotices(
      {
        ...base,
        paused: true,
        pendingApprovals: 2,
        onboarding: { status: "in_progress", step: "safety" },
      },
      "/tools",
    );

    expect(notices.map((notice) => notice.key)).toEqual([
      "paused",
      "approvals",
    ]);
  });

  it("says read mode is on, and leads to its switch from other pages", () => {
    const readOnly = { ...base, readOnly: true, pendingApprovals: 1 };

    expect(shellNotices(readOnly, "/tools").map((n) => n.key)).toEqual([
      "read-mode",
      "approvals",
    ]);
    expect(shellNotices(readOnly, "/tools")[0].action?.href).toBe("#/");
    expect(shellNotices(readOnly, "/")[0].action).toBeUndefined();
  });

  it("links waiting approvals and unfinished setup", () => {
    const notices = shellNotices(
      {
        ...base,
        pendingApprovals: 2,
        onboarding: { status: "in_progress", step: "safety" },
      },
      "/tools",
    );

    expect(notices[0]).toMatchObject({
      title: "2 requests are waiting for your approval.",
      action: { href: "#/activity/approvals" },
    });
    expect(notices[1]).toMatchObject({
      title: "Finish setting up. You're on step 2 of 4.",
      action: { href: "#/setup" },
    });
  });

  it("leaves the setup notice to the Dashboard's own card, and skipped setup alone", () => {
    const open = {
      ...base,
      onboarding: { status: "in_progress" as const, step: "safety" as const },
    };

    expect(shellNotices(open, "/")).toEqual([]);
    expect(
      shellNotices(
        { ...base, onboarding: { status: "skipped", step: "safety" } },
        "/tools",
      ),
    ).toEqual([]);
  });
});
