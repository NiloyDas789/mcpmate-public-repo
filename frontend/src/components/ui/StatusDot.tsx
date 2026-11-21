import { cx } from "@utils/classNames";

export type StatusTone = "success" | "warning" | "danger" | "neutral";
export type StatusGlyph = "pause" | "hollow";

/** The coloured dot. Decorative: the text next to it carries the meaning. */
export function StatusDot({
  tone,
  glyph,
}: {
  tone: StatusTone;
  glyph?: StatusGlyph;
}) {
  if (glyph === "pause") {
    return (
      <svg
        className={`el-dot-pause el-tone-${tone}`}
        viewBox="0 0 8 8"
        width="8"
        height="8"
        aria-hidden="true"
        focusable="false"
      >
        <rect x="1" y="0" width="2" height="8" fill="currentColor" />
        <rect x="5" y="0" width="2" height="8" fill="currentColor" />
      </svg>
    );
  }

  return (
    <span
      aria-hidden="true"
      className={cx(
        "el-dot",
        `el-tone-${tone}`,
        glyph === "hollow" && "el-dot-hollow",
      )}
    />
  );
}
