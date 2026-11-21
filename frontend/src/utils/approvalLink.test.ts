import { describe, expect, it } from "vitest";

import { approvalRoute } from "./approvalLink";

describe("approvalRoute", () => {
  it("opens the approval from the query argument", () => {
    expect(approvalRoute("?page=mcpmate&approval=12", "")).toBe(
      "#/activity/approvals/12",
    );
    expect(approvalRoute("?page=mcpmate&approval=12", "#/")).toBe(
      "#/activity/approvals/12",
    );
  });

  it("keeps a route the page already has", () => {
    expect(
      approvalRoute("?page=mcpmate&approval=12", "#/audit-log"),
    ).toBeNull();
  });

  it("ignores missing or malformed IDs", () => {
    expect(approvalRoute("?page=mcpmate", "")).toBeNull();
    expect(approvalRoute("?page=mcpmate&approval=0", "")).toBeNull();
    expect(
      approvalRoute("?page=mcpmate&approval=12/../settings", ""),
    ).toBeNull();
  });
});
