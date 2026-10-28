import { useNow } from "@/hooks/useNow";
import { formatRelative } from "@utils/format";

/**
 * "5 min ago" in a `<time datetime>` (docs/ui.md §4, rule 6). The text
 * follows the clock each minute without announcing anything.
 */
export default function RelativeTime({ date }: { date: string | Date }) {
  const now = useNow();
  const value = typeof date === "string" ? new Date(date) : date;

  if (Number.isNaN(value.getTime())) {
    return null;
  }

  return (
    <time dateTime={value.toISOString()}>{formatRelative(value, now)}</time>
  );
}
