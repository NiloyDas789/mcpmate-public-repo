/**
 * The MCPMate mark: two rounded strokes forming an M (docs/ui.md §6.8,
 * frontend/src/resource/brand/mcpmate-mark-on-light.svg), the right one in
 * `currentColor` (`--el-brand`) and the left one in `--el-brand-accent`.
 * Decorative: the name "MCPMate" always sits next to it as real text.
 * backend/app/Menu.php draws the same paths for the menu.
 */
export default function Logo({ size = 24 }: { size?: number }) {
  return (
    <svg
      className="el-logo"
      width={size}
      height={size}
      viewBox="80 84 352 352"
      aria-hidden="true"
      focusable="false"
    >
      <path
        fill="currentColor"
        d="M432 388L432 132A44 44 0 0 0 354.06 104L222.06 264A44 44 0 0 0 289.94 320L344 254.47L344 388A44 44 0 0 0 432 388Z"
      />
      <path
        className="el-logo-accent"
        d="M80 388L80 132A44 44 0 0 1 157.94 104L289.94 264A44 44 0 0 1 222.06 320L168 254.47L168 388A44 44 0 0 1 80 388Z"
      />
    </svg>
  );
}
