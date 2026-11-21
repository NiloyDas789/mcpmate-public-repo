/**
 * Pretty-printed JSON in a focusable scroll region (docs/ui.md §7), so a
 * keyboard can scroll it and a screen reader knows what it holds.
 * `[redacted]` values stay literal; the heading above says secrets are hidden.
 */
export default function JsonBlock({
  value,
  label,
}: {
  value: unknown;
  /** Names the region: "Arguments". */
  label: string;
}) {
  return (
    <pre
      className="el-json"
      tabIndex={0}
      role="region"
      aria-label={label}
      dir="ltr"
    >
      {JSON.stringify(value, null, 2)}
    </pre>
  );
}
