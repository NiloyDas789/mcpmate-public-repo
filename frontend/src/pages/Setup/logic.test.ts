import { describe, expect, it } from "vitest";

import type {
  CliCommand,
  Grant,
  OnboardingStateResponse,
} from "../../types/api";
import {
  applyBundle,
  bundleState,
  initialStep,
  newGrants,
  utcMs,
} from "./logic";

const grant = (overrides: Partial<Grant>): Grant =>
  ({
    grant_id: "g_AAAAAAAAAAAAAAAAAAAAAA",
    status: "pending",
    client_name: "Claude",
    created_at: "2026-10-26T10:00:05Z",
    ...overrides,
  }) as Grant;

const command = (name: string, risk: CliCommand["risk"], enabled: boolean) =>
  ({ name, risk, enabled }) as CliCommand;

describe("setup logic", () => {
  it("reads both UTC formats", () => {
    expect(utcMs("2026-10-26 10:00:00")).toBe(utcMs("2026-10-26T10:00:00Z"));
    expect(Number.isNaN(utcMs(null))).toBe(true);
  });

  it("resumes at the saved step unless setup is finished or unstarted", () => {
    const base = { step: "add_ai" } as OnboardingStateResponse["onboarding"];
    expect(initialStep({ ...base, status: "in_progress" })).toBe("add_ai");
    expect(initialStep({ ...base, status: "skipped" })).toBe("add_ai");
    expect(initialStep({ ...base, status: "completed" })).toBe("welcome");
    expect(initialStep({ ...base, status: "not_started" })).toBe("welcome");
  });

  it("finds only live apps approved since Add it to your AI opened, newest first", () => {
    const grants = [
      grant({ grant_id: "old", created_at: "2026-10-26T09:59:59Z" }),
      grant({ grant_id: "new1", created_at: "2026-10-26T10:00:00Z" }),
      grant({
        grant_id: "new2",
        created_at: "2026-10-26T10:05:00Z",
        status: "active",
      }),
      grant({
        grant_id: "gone",
        created_at: "2026-10-26T10:06:00Z",
        status: "revoked",
      }),
    ];

    expect(
      newGrants(grants, "2026-10-26 10:00:00").map((g) => g.grant_id),
    ).toEqual(["new2", "new1"]);
    expect(newGrants(grants, null)).toEqual([]);
  });

  it("treats command bundles as on, off or custom, and never touches other commands", () => {
    const commands = [
      command("plugin.list", "read", true),
      command("theme.list", "read", true),
      command("cache.flush", "write", true),
      command("option.update", "write", false),
      command("plugin.delete", "destructive", true),
    ];

    expect(bundleState(commands, "read")).toBe("on");
    expect(bundleState(commands, "write")).toBe("custom");
    expect(bundleState([], "read")).toBe("off");

    const enabled = commands.filter((c) => c.enabled).map((c) => c.name);
    expect(applyBundle(enabled, commands, "read", false)).toEqual([
      "cache.flush",
      "plugin.delete",
    ]);
    expect(applyBundle(enabled, commands, "write", true).sort()).toEqual(
      [
        "cache.flush",
        "option.update",
        "plugin.delete",
        "plugin.list",
        "theme.list",
      ].sort(),
    );
  });
});
