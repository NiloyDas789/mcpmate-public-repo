import { describe, expect, it } from "vitest";
import {
  cssLength,
  designBytes,
  isColor,
  MAX_DESIGN_BYTES,
  nameFromFile,
  readDesignFile,
  swatches,
} from "./designLogic";

describe("design logic", () => {
  it("shows only colours check_design can read", () => {
    expect(isColor("#0b5d7a")).toBe(true);
    expect(isColor("rgb(232, 240, 243)")).toBe(true);
    expect(isColor("hsl(14deg 80% 56% / 50%)")).toBe(true);
    expect(isColor("rebeccapurple")).toBe(false);
    expect(isColor("url(javascript:alert(1))")).toBe(false);
    expect(swatches({ primary: "#0b5d7a", odd: "var(--x)" })).toEqual([
      ["primary", "#0b5d7a"],
    ]);
    expect(swatches(undefined)).toEqual([]);
  });

  it("draws only lengths in px, rem or em", () => {
    expect(cssLength(" 16px ")).toBe("16px");
    expect(cssLength("1.25rem")).toBe("1.25rem");
    expect(cssLength("0")).toBe("0");
    expect(cssLength("clamp(1rem, 2vw, 2rem)")).toBeNull();
    expect(cssLength("50%")).toBeNull();
  });

  it("counts bytes as the server does", () => {
    expect(designBytes("é")).toBe(2);
  });

  it("reads an uploaded Markdown file, and refuses other files and large ones", async () => {
    const file = new File(["---\nname: X\n---\n"], "spring-2027.md", {
      type: "text/markdown",
    });
    expect(await readDesignFile(file)).toEqual({
      content: "---\nname: X\n---\n",
    });
    expect(nameFromFile(file.name)).toBe("spring 2027");

    expect(await readDesignFile(new File(["x"], "logo.png"))).toEqual({
      error: "Choose a Markdown file (.md).",
    });
    expect(
      await readDesignFile(
        new File(["x".repeat(MAX_DESIGN_BYTES + 1)], "big.md"),
      ),
    ).toEqual({
      error: "The file is larger than 65,536 bytes.",
    });
  });
});
