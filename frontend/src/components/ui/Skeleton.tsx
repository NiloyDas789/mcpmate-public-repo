import { __ } from "@wordpress/i18n";

interface SkeletonProps {
  /** `text`: lines; `number`: a stat value; `row`: one table row of cells. */
  shape?: "text" | "number" | "row";
  /** Lines for `text`, cells for `row`. */
  count?: number;
  /** Visually hidden "Loading…"; leave it off when the region already says so. */
  announce?: boolean;
}

/**
 * Grey blocks shaped like the content that is coming (docs/ui.md §7).
 * Hidden from assistive tech; the region around it sets aria-busy, and
 * the shimmer stops under reduced motion.
 */
export default function Skeleton({
  shape = "text",
  count = 1,
  announce = true,
}: SkeletonProps) {
  const blocks =
    shape === "number" ? (
      <span className="el-skeleton-block el-skeleton-number" />
    ) : (
      Array.from({ length: count }, (_, index) => (
        <span
          key={index}
          className={`el-skeleton-block el-skeleton-${shape}`}
        />
      ))
    );

  return (
    <span className={`el-skeleton el-skeleton-shape-${shape}`}>
      {announce && <span className="sr-only">{__("Loading…", "mcpmate")}</span>}
      <span aria-hidden="true" className="el-skeleton-blocks">
        {blocks}
      </span>
    </span>
  );
}
