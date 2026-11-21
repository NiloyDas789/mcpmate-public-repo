import { describe, expect, it } from "vitest";

import { blockBatchRoute } from "./blockBatchLink";
import { withoutEntryArgs } from "./entryArgs";

describe("blockBatchRoute", () => {
  it("opens the batch from the finalization_url's query argument", () => {
    expect(blockBatchRoute("?page=mcpmate&block_batch=7", "")).toBe(
      "#/activity/block-queue/7",
    );
    expect(blockBatchRoute("?page=mcpmate&block_batch=7", "#/")).toBe(
      "#/activity/block-queue/7",
    );
  });

  it("keeps a route the page already has, and ignores malformed IDs", () => {
    expect(
      blockBatchRoute("?page=mcpmate&block_batch=7", "#/settings"),
    ).toBeNull();
    expect(blockBatchRoute("?page=mcpmate", "")).toBeNull();
    expect(blockBatchRoute("?page=mcpmate&block_batch=0", "")).toBeNull();
    expect(blockBatchRoute("?page=mcpmate&block_batch=7/../x", "")).toBeNull();
  });

  it("leaves the address once it opened the batch", () => {
    expect(withoutEntryArgs("?page=mcpmate&block_batch=7")).toBe(
      "?page=mcpmate",
    );
  });
});
