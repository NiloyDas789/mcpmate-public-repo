import { useQuery } from "@tanstack/react-query";
import { __, _n, sprintf } from "@wordpress/i18n";
import Button from "@components/ui/Button";
import Card from "@components/ui/Card";
import Notice from "@components/ui/Notice";
import StatTile from "@components/ui/StatTile";
import { fetchStats } from "@services/api";
import { STATS_QUERY_KEY } from "@services/queries";
import { formatNumber } from "@utils/format";
import { requestErrorText } from "@utils/errors";
import { cx } from "@utils/classNames";
import type { StatsSummary } from "../../types/api";
import { callsSplit } from "./logic";

function WeekLine({ stats }: { stats: StatsSummary }) {
  if (!stats.change_history) {
    return (
      <p className="m-0">
        {__(
          "Change history is turned off, so changes by AI apps aren't counted.",
          "mcpmate",
        )}{" "}
        <a href="#/settings" className="underline">
          {__("Open Settings", "mcpmate")}
        </a>
      </p>
    );
  }

  const total = stats.changes_week.total;

  return (
    <p className="m-0">
      {total === 0
        ? __("No changes by AI apps in the last 7 days.", "mcpmate")
        : sprintf(
            /* translators: %s: number of changes. */
            _n(
              "In the last 7 days, AI apps made %s change.",
              "In the last 7 days, AI apps made %s changes.",
              total,
              "mcpmate",
            ),
            formatNumber(total),
          )}{" "}
      <a href="#/activity/changes" className="underline">
        {__("View change history", "mcpmate")}
      </a>
    </p>
  );
}

/**
 * Tool calls, Success rate and Tools turned on, then the week's changes
 * (docs/ui.md §5.1), from `GET /stats` (cached 5 minutes by the server).
 */
export default function StatsRow({ columns }: { columns: 1 | 3 }) {
  const { data, isLoading, error, refetch } = useQuery({
    queryKey: STATS_QUERY_KEY,
    queryFn: fetchStats,
    staleTime: 60_000,
  });

  if (!isLoading && !data) {
    return (
      <Notice
        tone="danger"
        title={__("Couldn't load the numbers.", "mcpmate")}
        action={
          <Button variant="secondary" size="sm" onClick={() => void refetch()}>
            {__("Try again", "mcpmate")}
          </Button>
        }
      >
        {requestErrorText(error)}
      </Notice>
    );
  }

  const calls = data?.calls_24h;
  const rate = data?.success_rate_24h ?? null;

  return (
    <>
      <div className={cx("grid gap-4", columns === 3 && "grid-cols-3")}>
        <Card>
          <StatTile
            label={__("Tool calls", "mcpmate")}
            value={calls?.total ?? 0}
            loading={isLoading}
            meta={
              calls &&
              sprintf(
                /* translators: %s: "30 reads, 12 changes". */
                __("Last 24 hours · %s", "mcpmate"),
                callsSplit(calls.reads, calls.writes),
              )
            }
          />
        </Card>
        <Card>
          <StatTile
            label={__("Success rate", "mcpmate")}
            value={rate}
            format="percent"
            loading={isLoading}
            meta={
              calls &&
              (rate === null
                ? __("No calls in the last 24 hours", "mcpmate")
                : sprintf(
                    /* translators: %s: number of failed calls. */
                    _n(
                      "Last 24 hours · %s failed",
                      "Last 24 hours · %s failed",
                      calls.error,
                      "mcpmate",
                    ),
                    formatNumber(calls.error),
                  ))
            }
            link={
              calls && calls.error > 0
                ? {
                    label: __("View failed calls", "mcpmate"),
                    href: "#/activity?result=error",
                  }
                : undefined
            }
          />
          <p className="m-0 mt-2 text-el-sm text-el-text-secondary">
            {__("Counts calls that reached a tool.", "mcpmate")}
          </p>
        </Card>
        <Card>
          <StatTile
            label={__("Tools turned on", "mcpmate")}
            value={data?.tools.enabled ?? 0}
            total={data?.tools.total}
            format="fraction"
            loading={isLoading}
            link={{
              label: __("Manage tools", "mcpmate"),
              href: "#/tools",
            }}
          />
        </Card>
      </div>
      {data && (
        <Card>
          <WeekLine stats={data} />
        </Card>
      )}
    </>
  );
}
