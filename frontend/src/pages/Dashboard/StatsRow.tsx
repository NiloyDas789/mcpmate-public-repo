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
 * Tool calls, Success rate and Tools turned on in one strip, the week's
 * changes along its foot (docs/ui.md §5.1), from `GET /stats` (cached 5
 * minutes by the server).
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
    <Card className="el-dash-figures">
      <div className={cx("el-dash-figures-row", columns === 3 && "is-row")}>
        <div className="el-dash-figure">
          <StatTile
            label={__("Tool calls", "mcpmate")}
            value={calls?.total ?? 0}
            loading={isLoading}
            meta={
              calls &&
              sprintf(
                /* translators: %s: "30 reads, 12 changes". */
                __("%s in the last 24 hours", "mcpmate"),
                callsSplit(calls.reads, calls.writes),
              )
            }
          />
        </div>
        <div className="el-dash-figure">
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
                      "%s failed in the last 24 hours",
                      "%s failed in the last 24 hours",
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
          <p className="el-dash-figure-note">
            {__("Counts calls that reached a tool.", "mcpmate")}
          </p>
        </div>
        <div className="el-dash-figure">
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
        </div>
      </div>
      {data && (
        <div className="el-dash-week">
          <WeekLine stats={data} />
        </div>
      )}
    </Card>
  );
}
