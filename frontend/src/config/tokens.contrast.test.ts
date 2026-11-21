import { describe, expect, it } from "vitest";

import { colors, type ColorToken } from "./tokens";

/** WCAG 2.x relative luminance of `#RRGGBB`. */
function luminance(hex: string): number {
  const channels = [1, 3, 5].map((start) => {
    const value = parseInt(hex.slice(start, start + 2), 16) / 255;

    return value <= 0.04045 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4;
  });

  return 0.2126 * channels[0] + 0.7152 * channels[1] + 0.0722 * channels[2];
}

function contrast(a: string, b: string): number {
  const [light, dark] = [luminance(a), luminance(b)].sort((x, y) => y - x);

  return (light + 0.05) / (dark + 0.05);
}

const WHITE = "#FFFFFF";
const TEXT = 4.5;
const UI = 3;

/**
 * Every foreground and background pair the UI draws (docs/ui.md §6.1). A
 * token change that drops a pair under its minimum fails here, before any
 * page renders it.
 */
const PAIRS: [ColorToken | "white", ColorToken, number][] = [
  // Text on the page and on cards.
  ...(
    [
      "text",
      "text-secondary",
      "text-label",
      "text-disabled",
      "brand",
    ] as ColorToken[]
  ).flatMap((fg): [ColorToken, ColorToken, number][] => [
    [fg, "bg-surface", TEXT],
    [fg, "bg-page", TEXT],
  ]),
  ["text-secondary", "bg-brand-tint", TEXT],
  ["brand", "bg-brand-tint", TEXT],
  ["text", "bg-subtle", TEXT],
  ["text-secondary", "bg-subtle", TEXT],
  // Buttons.
  ["white", "brand", TEXT],
  ["white", "brand-hover", TEXT],
  ["brand-hover", "bg-brand-tint", TEXT],
  ["white", "danger", TEXT],
  ["white", "danger-hover", TEXT],
  ["danger", "bg-surface", TEXT],
  // Status text on its tint and on a card.
  ...(["success", "warning", "danger", "info", "neutral"] as const).flatMap(
    (tone): [ColorToken, ColorToken, number][] => [
      [`${tone}-text`, `${tone}-bg`, TEXT],
      [`${tone}-text`, "bg-surface", TEXT],
      ["text", `${tone}-bg`, TEXT],
    ],
  ),
  ["diff-add-text", "diff-add-bg", TEXT],
  ["diff-del-text", "diff-del-bg", TEXT],
  // Status dots, control borders, the toggle track, the focus ring.
  ...(["success-dot", "warning-dot", "danger-dot", "neutral-dot"] as const).map(
    (dot): [ColorToken, ColorToken, number] => [dot, "bg-surface", UI],
  ),
  ["border-strong", "bg-surface", UI],
  ["border-strong", "bg-page", UI],
  ["border-strong", "bg-subtle", UI],
  ["brand", "bg-surface", UI],
  ["focus", "bg-surface", UI],
  ["focus", "bg-page", UI],
  ["focus", "bg-subtle", UI],
  ["focus", "bg-brand-tint", UI],
];

const hex = (token: ColorToken | "white") =>
  token === "white" ? WHITE : colors[token];

describe("colour tokens (WCAG 2.2 AA)", () => {
  it.each(PAIRS)("%s on %s reaches %s:1", (fg, bg, minimum) => {
    expect(contrast(hex(fg), hex(bg))).toBeGreaterThanOrEqual(minimum);
  });

  it("matches the ratios in docs/ui.md §6.1", () => {
    expect(contrast(colors.text, colors["bg-surface"])).toBeCloseTo(15.87, 1);
    expect(contrast(WHITE, colors.brand)).toBeCloseTo(10.36, 1);
    expect(contrast(colors.focus, colors["bg-brand-tint"])).toBeCloseTo(
      4.24,
      1,
    );
    expect(contrast(colors["info-text"], colors["info-bg"])).toBeCloseTo(
      6.16,
      1,
    );
    expect(contrast(colors["border-strong"], colors["bg-surface"])).toBeCloseTo(
      3.7,
      1,
    );
  });
});
