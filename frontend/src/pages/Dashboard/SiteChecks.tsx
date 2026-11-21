import { useEffect, useId, useRef, useState } from "react";
import { DownOutlined } from "@ant-design/icons";
import { useQuery } from "@tanstack/react-query";
import { __, _n, sprintf } from "@wordpress/i18n";
import Button from "@components/ui/Button";
import Card from "@components/ui/Card";
import CopyField from "@components/ui/CopyField";
import EmptyState from "@components/ui/EmptyState";
import Notice from "@components/ui/Notice";
import RelativeTime from "@components/ui/RelativeTime";
import StatusText from "@components/ui/StatusText";
import { useAnnounce } from "@/hooks/useAnnounce";
import { runChecks } from "@services/api";
import { pendingChecks } from "@pages/Setup/labels";
import { requestErrorText } from "@utils/errors";
import type { EnvironmentCheck } from "../../types/api";
import {
  checkStatus,
  checksSummaryText,
  checksTally,
  DASHBOARD_CHECKS_QUERY_KEY,
} from "./logic";

function Details({ check }: { check: EnvironmentCheck }) {
  return check.details.map((detail) => (
    <div key={detail.text + (detail.code ?? "")}>
      {detail.text && <p className="m-0">{detail.text}</p>}
      {detail.code && (
        <CopyField
          value={detail.code}
          label={
            detail.code.includes("\n")
              ? __("Lines", "mcpmate")
              : __("Line", "mcpmate")
          }
          layout={detail.code.includes("\n") ? "narrow" : "auto"}
        />
      )}
    </div>
  ));
}

/**
 * One check, as in setup: title, status word, message, and How to fix
 * (the fix, then the lines it names). A row that passed can still carry
 * notes, such as the addresses to keep out of a cache.
 */
function CheckRow({ check }: { check: EnvironmentCheck }) {
  const status = checkStatus(check.status);
  const needsAction = check.status === "warn" || check.status === "fail";

  return (
    <li className="el-table-card">
      <div className="flex flex-wrap items-baseline justify-between gap-2">
        <strong>{check.title}</strong>
        <StatusText tone={status.tone}>{status.text}</StatusText>
      </div>
      <p className="m-0 mt-1">{check.message}</p>
      {needsAction && (check.fix || check.details.length > 0) && (
        <div className="mt-2 grid gap-2">
          <strong>{__("How to fix", "mcpmate")}</strong>
          {check.fix && <p className="m-0">{check.fix}</p>}
          <Details check={check} />
        </div>
      )}
      {!needsAction && check.details.length > 0 && (
        <div className="mt-2 grid gap-2">
          <Details check={check} />
        </div>
      )}
    </li>
  );
}

/**
 * Site checks (docs/ui.md §5.1): setup's environment checks, run on demand
 * (≤ 10 s) and kept for this visit, with the rows behind a disclosure.
 */
export default function SiteChecks() {
  const announce = useAnnounce();
  const listId = useId();
  const [open, setOpen] = useState(false);
  const { data, error, isFetching, refetch, dataUpdatedAt } = useQuery({
    queryKey: DASHBOARD_CHECKS_QUERY_KEY,
    queryFn: runChecks,
    enabled: false,
    staleTime: Infinity,
    gcTime: Infinity,
    retry: false,
  });
  const ran = useRef(false);
  const title = __("Site checks", "mcpmate");
  const rows = data ? data.checks : [];

  useEffect(() => {
    if (ran.current && !isFetching && data) {
      ran.current = false;
      announce(checksSummaryText(data.checks));
    }
  }, [isFetching, data, announce]);

  const run = () => {
    ran.current = true;
    announce(__("Checking your site…", "mcpmate"));
    void refetch();
  };

  if (!data && !isFetching) {
    return (
      <Card title={title}>
        {error ? (
          <Notice
            tone="danger"
            title={__("We couldn't run the checks.", "mcpmate")}
            action={
              <Button variant="secondary" size="sm" onClick={run}>
                {__("Try again", "mcpmate")}
              </Button>
            }
            live
          >
            {requestErrorText(error)}
          </Notice>
        ) : (
          <EmptyState
            title={__("Not checked yet.", "mcpmate")}
            action={{ label: __("Run checks", "mcpmate"), onClick: run }}
          />
        )}
      </Card>
    );
  }

  const tally = checksTally(rows);
  const count = isFetching ? pendingChecks().length : rows.length;

  return (
    <Card title={title}>
      <div className="el-stack">
        {data && !isFetching && (
          <div className="grid gap-1">
            <p className="m-0 text-el-md font-semibold">
              {checksSummaryText(rows)}
            </p>
            <p className="m-0">
              {tally.attention > 0 ? (
                <StatusText tone="warning">
                  {sprintf(
                    /* translators: %d: number of checks that need attention. */
                    _n(
                      "%d needs attention",
                      "%d need attention",
                      tally.attention,
                      "mcpmate",
                    ),
                    tally.attention,
                  )}
                </StatusText>
              ) : (
                <StatusText tone="success">
                  {__("Everything looks good", "mcpmate")}
                </StatusText>
              )}
            </p>
            <p className="m-0 text-el-text-secondary">
              {__("Last checked", "mcpmate")}{" "}
              <RelativeTime date={new Date(dataUpdatedAt)} />
            </p>
          </div>
        )}

        <div>
          <Button variant="secondary" loading={isFetching} onClick={run}>
            {isFetching
              ? __("Checking…", "mcpmate")
              : __("Run checks again", "mcpmate")}
          </Button>
        </div>

        <div>
          <button
            type="button"
            className="el-disclosure"
            aria-expanded={open}
            aria-controls={listId}
            onClick={() => setOpen(!open)}
          >
            {sprintf(
              /* translators: %d: number of checks. */
              open
                ? __("Hide all checks (%d)", "mcpmate")
                : __("Show all checks (%d)", "mcpmate"),
              count,
            )}
            <DownOutlined aria-hidden className="el-disclosure-icon" />
          </button>
          <div id={listId} hidden={!open}>
            <ul
              aria-busy={isFetching || undefined}
              className="el-table-cards mt-2"
            >
              {isFetching
                ? pendingChecks().map((check) => (
                    <li key={check.id} className="el-table-card">
                      <div className="flex flex-wrap items-baseline justify-between gap-2">
                        <strong>{check.title}</strong>
                        <StatusText tone="neutral">
                          {__("Checking…", "mcpmate")}
                        </StatusText>
                      </div>
                    </li>
                  ))
                : rows.map((check) => (
                    <CheckRow key={check.id} check={check} />
                  ))}
            </ul>
          </div>
        </div>
      </div>
    </Card>
  );
}
