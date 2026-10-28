import { __, sprintf } from "@wordpress/i18n";
import { formatNumber } from "@utils/format";
import Skeleton from "./Skeleton";

interface StatTileProps {
  label: string;
  /** Null when there is no data ("—"). A ratio (0–1) for `percent`. */
  value: number | null;
  format?: "count" | "percent" | "fraction";
  /** The "of 31" in a fraction. */
  total?: number;
  meta?: string;
  link?: { label: string; href: string };
  loading?: boolean;
  error?: string;
}

function formatValue(
  value: number,
  format: StatTileProps["format"],
  total?: number,
): string {
  if (format === "percent") {
    return formatNumber(value, { style: "percent", maximumFractionDigits: 0 });
  }

  if (format === "fraction") {
    return sprintf(
      /* translators: 1: a count, 2: the total, e.g. "18 of 31". */
      __("%1$s of %2$s", "mcpmate"),
      formatNumber(value),
      formatNumber(total ?? 0),
    );
  }

  return formatNumber(value);
}

/**
 * A label, a big number and a line of context, as a `<dl>` (docs/ui.md §7).
 * The value is not live: the page announces refreshes.
 */
export default function StatTile({
  label,
  value,
  format = "count",
  total,
  meta,
  link,
  loading = false,
  error,
}: StatTileProps) {
  return (
    <dl className="el-stat" aria-busy={loading || undefined}>
      <dt className="el-stat-label">{label}</dt>
      <dd className="el-stat-value">
        {loading ? (
          <Skeleton shape="number" />
        ) : value === null ? (
          <>
            <span aria-hidden="true">—</span>
            <span className="sr-only">{__("No data", "mcpmate")}</span>
          </>
        ) : (
          formatValue(value, format, total)
        )}
      </dd>
      {error ? (
        <dd className="el-stat-error">{error}</dd>
      ) : (
        meta && <dd className="el-stat-meta">{meta}</dd>
      )}
      {link && (
        <dd className="el-stat-link">
          <a href={link.href}>{link.label}</a>
        </dd>
      )}
    </dl>
  );
}
