import { describe, expect, it } from "vitest";

import {
  contentBytes,
  memoryErrors,
  memoryViewFromQuery,
  parseTags,
} from "./memoryLogic";

const LIMITS = {
  title_chars: 200,
  content_bytes: 16384,
  tags: 10,
  tag_chars: 40,
};

describe("Tools → Memory logic", () => {
  it("reads its state from the hash query and drops invalid values", () => {
    expect(
      memoryViewFromQuery(
        "?status=trash&user=3&tag=shop&q=tone&page=2&memory=12",
      ),
    ).toEqual({
      status: "trash",
      user: 3,
      tag: "shop",
      q: "tone",
      page: 2,
      memory: 12,
    });
    expect(memoryViewFromQuery("?status=gone&user=-1&page=x&memory=0")).toEqual(
      {
        status: "live",
        user: 0,
        tag: "",
        q: "",
        page: 1,
        memory: null,
      },
    );
  });

  it("splits tags on commas", () => {
    expect(parseTags(" brand, Shop page ,, tone ")).toEqual([
      "brand",
      "Shop page",
      "tone",
    ]);
    expect(parseTags("")).toEqual([]);
  });

  it("counts bytes as the server stores them", () => {
    expect(contentBytes("  a\r\nb  ")).toBe(3);
    expect(contentBytes("é")).toBe(2);
  });

  it("refuses what the server refuses, in its words", () => {
    expect(
      memoryErrors({ title: "Ok", content: "Text", tags: "a, b" }, LIMITS),
    ).toEqual({});
    expect(
      memoryErrors(
        { title: " ", content: "é".repeat(8193), tags: "x".repeat(41) },
        LIMITS,
      ),
    ).toEqual({
      title: "Give a title of one line, up to 200 characters.",
      content: "Write the memory as plain text, up to 16,384 bytes (16 KB).",
      tags: 'Use up to 10 tags of up to 40 characters: lowercase letters, digits, "-" and "_".',
    });
  });
});
