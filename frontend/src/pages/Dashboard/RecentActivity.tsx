import { useQuery } from "@tanstack/react-query";
import { __, sprintf } from "@wordpress/i18n";
import Button from "@components/ui/Button";
import Card from "@components/ui/Card";
import EmptyState from "@components/ui/EmptyState";
import Notice from "@components/ui/Notice";
import RelativeTime from "@components/ui/RelativeTime";
import Skeleton from "@components/ui/Skeleton";
import StatusText from "@components/ui/StatusText";
import { useAnnounce } from "@/hooks/useAnnounce";
import { fetchAuditLog } from "@services/api";
import { isLiveGrant, useGrants } from "@services/queries";
import { connectAppHref } from "@pages/Connections/logic";
import { copyText } from "@utils/clipboard";
import { requestErrorText } from "@utils/errors";
import {
  FIRST_PROMPT,
  RECENT_COUNT,
  RECENT_QUERY_KEY,
  RECENT_SCAN,
  recentCalls,
  resultStatus,
} from "./logic";

/**
 * Recent activity (docs/ui.md §5.1): the last calls AI apps made, each a
 * link to its audit log entry.
 */
export default function RecentActivity() {
  const announce = useAnnounce();
  const grants = useGrants();
  const { data, isLoading, error, refetch } = useQuery({
    queryKey: RECENT_QUERY_KEY,
    queryFn: () => fetchAuditLog(1, RECENT_SCAN),
    staleTime: 30_000,
  });
  const title = __("Recent activity", "mcpmate");

  if (isLoading) {
    return (
      <Card title={title} busy>
        <Skeleton count={RECENT_COUNT} />
      </Card>
    );
  }

  if (!data) {
    return (
      <Card title={title}>
        <Notice
          tone="danger"
          title={__("Couldn't load recent activity.", "mcpmate")}
          action={
            <Button
              variant="secondary"
              size="sm"
              onClick={() => void refetch()}
            >
              {__("Try again", "mcpmate")}
            </Button>
          }
        >
          {requestErrorText(error)}
        </Notice>
      </Card>
    );
  }

  const calls = recentCalls(data.items);
  const apps = new Map(
    (grants.data?.grants ?? []).map((grant) => [grant.grant_id, grant]),
  );
  const hasApps = (grants.data?.grants ?? []).some(isLiveGrant);

  if (calls.length === 0) {
    return (
      <Card title={title}>
        {hasApps ? (
          <EmptyState
            title={__("No activity yet.", "mcpmate")}
            body={sprintf(
              /* translators: %s: a prompt to send to the AI app. */
              __("Try asking your AI app: “%s”", "mcpmate"),
              FIRST_PROMPT(),
            )}
            action={{
              label: __("Copy prompt", "mcpmate"),
              onClick: () =>
                void copyText(FIRST_PROMPT()).then((copied) =>
                  announce(
                    copied
                      ? __("Prompt copied.", "mcpmate")
                      : __(
                          "Couldn't copy. Select the prompt and copy it.",
                          "mcpmate",
                        ),
                  ),
                ),
            }}
          />
        ) : (
          <EmptyState
            title={__("No activity yet.", "mcpmate")}
            body={__(
              "When an AI app uses this site, its calls show up here.",
              "mcpmate",
            )}
            action={{
              label: __("Connect an AI app", "mcpmate"),
              href: connectAppHref(),
            }}
          />
        )}
      </Card>
    );
  }

  return (
    <Card title={title}>
      <ul className="m-0 grid list-none gap-1 p-0">
        {calls.map((call) => {
          const status = resultStatus(call.result);
          const app = apps.get(call.grant_id)?.client_name;

          return (
            <li key={call.id}>
              <a
                href={`#/activity?entry=${call.id}`}
                className="flex flex-wrap items-center gap-x-3 gap-y-1 rounded py-2 text-el-text no-underline"
              >
                <StatusText tone={status.tone}>{status.text}</StatusText>
                <bdi dir="ltr" className="el-mono">
                  {call.mcp_tool}
                </bdi>
                <span className="text-el-text-secondary">
                  {app && call.user_login
                    ? sprintf(
                        /* translators: 1: AI app name, 2: WordPress user login. */
                        __("%1$s · %2$s", "mcpmate"),
                        app,
                        call.user_login,
                      )
                    : (app ?? call.user_login ?? "")}
                </span>
                <span className="ms-auto text-el-text-secondary">
                  <RelativeTime date={call.created_at} />
                </span>
              </a>
            </li>
          );
        })}
      </ul>
      <p className="m-0 mt-3">
        <a href="#/activity">{__("View all activity", "mcpmate")}</a>
      </p>
    </Card>
  );
}
