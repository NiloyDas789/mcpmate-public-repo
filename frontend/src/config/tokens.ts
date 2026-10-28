/**
 * Design tokens (docs/ui.md §6). The one source for the `--el-*` CSS custom
 * properties on #mcpmate-root and #mcpmate-portal (written by the
 * Tailwind plugin in tailwind.config.mjs), the Tailwind theme, and the Ant
 * Design theme (config/theme.ts).
 *
 * Plain values only: tailwind.config.mjs imports this file outside Vite.
 */

// ── Colour (§6.1; contrast ratios are in the spec) ────────────

export const colors = {
  "bg-page": "#F4F6FB",
  "bg-surface": "#FFFFFF",
  "bg-subtle": "#F1F3F8",
  "bg-brand-tint": "#E9ECF6",
  border: "#DCE0EA",
  "border-strong": "#7C8599",
  text: "#1B2233",
  "text-secondary": "#4A5468",
  "text-label": "#5A6478",
  "text-disabled": "#636C80",
  brand: "#324376",
  "brand-hover": "#26335C",
  focus: "#2B59C3",
  "success-text": "#1D6B36",
  "success-bg": "#E7F4EC",
  "success-dot": "#2E8540",
  "warning-text": "#7A4500",
  "warning-bg": "#FFF3DC",
  "warning-dot": "#B25E00",
  "danger-text": "#A3222A",
  "danger-bg": "#FCEBEC",
  "danger-dot": "#C4321F",
  danger: "#B42318",
  "danger-hover": "#912018",
  "info-text": "#1D4F91",
  "info-bg": "#E8F0FB",
  "neutral-text": "#3D4659",
  "neutral-bg": "#EDEFF4",
  "neutral-dot": "#6B7489",
  "diff-add-text": "#14532D",
  "diff-add-bg": "#E6F4EA",
  "diff-del-text": "#8A1C1C",
  "diff-del-bg": "#FCE8E8",
  skeleton: "#E3E7EF",
} as const;

export type ColorToken = keyof typeof colors;

// ── Spacing (§6.2, 4 px base) ─────────────────────────────────

export const space = {
  1: "0.25rem",
  2: "0.5rem",
  3: "0.75rem",
  4: "1rem",
  5: "1.25rem",
  6: "1.5rem",
  8: "2rem",
  10: "2.5rem",
  12: "3rem",
} as const;

// ── Radius (§6.3) ─────────────────────────────────────────────

export const radius = {
  sm: "6px",
  DEFAULT: "8px",
  lg: "12px",
  full: "999px",
} as const;

// ── Type (§6.4) ───────────────────────────────────────────────

export const fontFamily = {
  sans: '"Inter Variable", Inter, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif',
  mono: 'ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace',
} as const;

/** Font size and line height, in rem so the browser's text size applies. */
export const fontSize = {
  xs: ["0.75rem", "1rem"],
  sm: ["0.8125rem", "1.25rem"],
  base: ["0.875rem", "1.375rem"],
  md: ["1rem", "1.5rem"],
  lg: ["1.25rem", "1.75rem"],
  xl: ["1.5rem", "2rem"],
  "2xl": ["2rem", "2.5rem"],
  mono: ["0.8125rem", "1.25rem"],
} as const;

// ── Layout (§6.5) ─────────────────────────────────────────────

/** Width breakpoints in CSS px. Components that change shape use container width. */
export const breakpoints = {
  sm: 600,
  md: 782,
  lg: 960,
  xl: 1280,
} as const;

export const maxContentWidth = 1440;

// ── Elevation, motion, layers (§6.6) ──────────────────────────

export const shadow = {
  overlay: "0 8px 24px rgb(27 34 51 / 0.16)",
} as const;

export const duration = {
  fast: "120ms",
  DEFAULT: "200ms",
} as const;

export const zIndex = {
  topbar: 20,
  savebar: 20,
} as const;

/** `--el-<name>`; the DEFAULT key drops its suffix (`--el-radius`). */
function varName(group: string, key: string): string {
  return key === "DEFAULT" ? `--el-${group}` : `--el-${group}-${key}`;
}

/**
 * Every token as a CSS custom property, e.g. `--el-bg-page: #F4F6FB`.
 * Font sizes give two properties: `--el-text-sm` and `--el-text-sm-leading`.
 */
export function cssVariables(): Record<string, string> {
  const vars: Record<string, string> = {};

  for (const [key, value] of Object.entries(colors)) {
    vars[`--el-${key}`] = value;
  }
  for (const [key, value] of Object.entries(space)) {
    vars[varName("space", key)] = value;
  }
  for (const [key, value] of Object.entries(radius)) {
    vars[varName("radius", key)] = value;
  }
  for (const [key, [size, leading]] of Object.entries(fontSize)) {
    vars[`--el-text-${key}`] = size;
    vars[`--el-text-${key}-leading`] = leading;
  }
  vars["--el-font-sans"] = fontFamily.sans;
  vars["--el-font-mono"] = fontFamily.mono;
  vars["--el-shadow-overlay"] = shadow.overlay;
  for (const [key, value] of Object.entries(duration)) {
    vars[varName("duration", key)] = value;
  }
  vars["--el-max-width"] = `${maxContentWidth}px`;

  return vars;
}
