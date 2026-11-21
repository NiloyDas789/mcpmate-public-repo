import { describe, expect, it } from "vitest";

import { withoutEntryArgs } from "./entryArgs";

describe("withoutEntryArgs", () => {
  it("drops setup and approval", () => {
    expect(withoutEntryArgs("?page=mcpmate&setup=1")).toBe("?page=mcpmate");
    expect(withoutEntryArgs("?page=mcpmate&approval=12")).toBe("?page=mcpmate");
    expect(withoutEntryArgs("?setup=1")).toBe("");
  });

  it("keeps everything else", () => {
    expect(withoutEntryArgs("?page=mcpmate&connect=abc&setup=1")).toBe(
      "?page=mcpmate&connect=abc",
    );
  });

  it("leaves a clean address alone", () => {
    expect(withoutEntryArgs("?page=mcpmate")).toBeNull();
    expect(withoutEntryArgs("?page=mcpmate&connect=abc")).toBeNull();
  });
});
