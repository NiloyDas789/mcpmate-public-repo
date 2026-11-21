/**
 * Join class names, dropping falsy parts: `cx("el-tab", active && "is-active")`.
 * Prefer it to a template literal with a conditional part: the Tailwind
 * Prettier plugin trims the space at the start of such a part.
 */
export function cx(...parts: (string | false | null | undefined)[]): string {
  return parts.filter(Boolean).join(" ");
}
