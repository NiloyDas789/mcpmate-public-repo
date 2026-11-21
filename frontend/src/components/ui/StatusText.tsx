import type { ReactNode } from "react";
import { StatusDot, type StatusGlyph, type StatusTone } from "./StatusDot";

/**
 * Dot plus text without the pill, for tables and lists: Succeeded, Failed,
 * Waiting for approval, Applied, Active, Paused, Revoked, Passed…
 */
export default function StatusText({
  tone,
  glyph,
  children,
}: {
  tone: StatusTone;
  glyph?: StatusGlyph;
  children: ReactNode;
}) {
  return (
    <span className="el-status-text">
      <StatusDot tone={tone} glyph={glyph} />
      <span>{children}</span>
    </span>
  );
}
