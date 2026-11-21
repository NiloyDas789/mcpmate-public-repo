import { describe, expect, it } from "vitest";

import type { Preferences } from "../../types/api";
import {
  changedKeys,
  changesFor,
  firstInvalid,
  SECTION_KEYS,
  validate,
  CONNECTION_KEYS,
} from "./logic";

const TYPES = ["post", "post_meta", "option"];

const saved: Preferences = {
  log_reads: true,
  audit_retention_days: 90,
  read_retention_days: 30,
  change_history: true,
  change_history_types: TYPES,
  change_retention_days: 30,
  approve_capability: "manage_options",
  access_token_minutes: 15,
  refresh_token_days: 30,
  open_registration: true,
  rate_limit: 120,
  delete_data_on_uninstall: true,
};

/**
 * The same cases as tests/Unit/Services/Settings/PreferencesTest.php, so
 * the page refuses exactly what the server refuses, in the same words.
 */
describe("validate", () => {
  const check = (draft: Partial<Preferences>, fixed = {}) => {
    const next = { ...saved, ...draft };

    return validate(
      next,
      changedKeys(saved, next, SECTION_KEYS.logging),
      fixed,
      TYPES,
    );
  };

  it("accepts the defaults and equal windows", () => {
    expect(check({})).toEqual({});
    expect(
      check({ audit_retention_days: 30, read_retention_days: 30 }),
    ).toEqual({});
  });

  it("keeps read calls no longer than the audit log, named on the key that changed", () => {
    expect(
      check({ audit_retention_days: 60, read_retention_days: 90 }),
    ).toEqual({
      read_retention_days:
        "Read calls can't be kept longer than the audit log (60 days).",
    });
    expect(
      validate(
        { ...saved, read_retention_days: 90, audit_retention_days: 60 },
        ["audit_retention_days"],
        {},
        TYPES,
      ),
    ).toEqual({
      audit_retention_days:
        "Keep the audit log at least as long as read calls (90 days).",
    });
  });

  it("never blocks another setting over a pair saved before", () => {
    expect(
      validate(
        {
          ...saved,
          audit_retention_days: 30,
          read_retention_days: 90,
          log_reads: false,
        },
        ["log_reads"],
        {},
        TYPES,
      ),
    ).toEqual({});
  });

  it("compares with a window set in code", () => {
    const longer = {
      audit_retention_days: { reason: "filtered" as const, value: 365 },
    };
    const keepAll = {
      audit_retention_days: { reason: "filtered" as const, value: 0 },
    };
    const shorter = {
      audit_retention_days: { reason: "filtered" as const, value: 14 },
    };

    expect(check({ read_retention_days: 90 }, longer)).toEqual({});
    expect(check({ read_retention_days: 90 }, keepAll)).toEqual({});
    expect(check({ read_retention_days: 90 }, shorter)).toEqual({
      read_retention_days:
        "Read calls can't be kept longer than the audit log (14 days).",
    });
  });

  it("needs a kind of change while change history is on", () => {
    const message =
      "Choose at least one kind of change to record, or turn change history off.";

    expect(check({ change_history_types: [] })).toEqual({
      change_history_types: message,
    });
    expect(check({ change_history: false, change_history_types: [] })).toEqual(
      {},
    );
    expect(
      validate(
        { ...saved, change_history_types: [] },
        ["change_history"],
        {},
        TYPES,
      ),
    ).toEqual({ change_history: message });
  });

  it("refuses values outside the server's lists", () => {
    expect(check({ audit_retention_days: 91 })).toEqual({
      audit_retention_days: "Must be one of: 30, 60, 90, 180, 365.",
    });
    expect(check({ read_retention_days: 365 })).toEqual({
      read_retention_days: "Must be one of: 7, 14, 30, 90.",
    });
    expect(check({ change_history_types: ["post", "attachment"] })).toEqual({
      change_history_types:
        "Each item must be one of: post, post_meta, option.",
    });
    expect(check({ log_reads: "yes" as unknown as boolean })).toEqual({
      log_reads: "Must be true or false.",
    });
  });
});

describe("what a section sends", () => {
  it("is only what changed in that section; a list in another order is no change", () => {
    const draft = {
      ...saved,
      log_reads: false,
      change_history_types: [...TYPES].reverse(),
      delete_data_on_uninstall: false,
    };

    expect(changedKeys(saved, draft, SECTION_KEYS.logging)).toEqual([
      "log_reads",
    ]);
    expect(changesFor(saved, draft, SECTION_KEYS.advanced)).toEqual({
      delete_data_on_uninstall: false,
    });
    // Token lifetimes and client registration are saved on Connections.
    expect(SECTION_KEYS.access).toEqual(["rate_limit"]);
    expect(CONNECTION_KEYS).toEqual([
      "open_registration",
      "access_token_minutes",
      "refresh_token_days",
    ]);
  });

  it("takes only the server's token lifetimes", () => {
    const draft = {
      ...saved,
      access_token_minutes: 120,
      refresh_token_days: 30,
    };

    expect(
      validate(
        draft,
        ["access_token_minutes", "refresh_token_days"],
        {},
        TYPES,
      ),
    ).toEqual({ access_token_minutes: "Must be one of: 5, 15, 30, 60." });
  });

  it("finds the first invalid setting in page order", () => {
    expect(
      firstInvalid({ change_retention_days: "x", audit_retention_days: "y" }),
    ).toBe("audit_retention_days");
    expect(firstInvalid({})).toBeNull();
  });
});
