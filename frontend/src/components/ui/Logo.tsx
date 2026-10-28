/**
 * The McpMate mark: two linked rounded rectangles (docs/ui.md §6.8), in
 * `currentColor`. Decorative: the name "McpMate" always sits next to
 * it as real text. backend/app/Menu.php draws the same paths for the menu.
 */
export default function Logo({ size = 24 }: { size?: number }) {
  return (
    <svg
      className="el-logo"
      width={size}
      height={size}
      viewBox="0 0 24 24"
      aria-hidden="true"
      focusable="false"
    >
      <g transform="rotate(-45 12 12)" fill="currentColor" fillRule="evenodd">
        <path d="M6.5 6.75h4a5.25 5.25 0 0 1 0 10.5h-4a5.25 5.25 0 0 1 0-10.5zm0 2.5h4a2.75 2.75 0 0 1 0 5.5h-4a2.75 2.75 0 0 1 0-5.5z" />
        <path d="M13.5 6.75h4a5.25 5.25 0 0 1 0 10.5h-4a5.25 5.25 0 0 1 0-10.5zm0 2.5h4a2.75 2.75 0 0 1 0 5.5h-4a2.75 2.75 0 0 1 0-5.5z" />
      </g>
    </svg>
  );
}
