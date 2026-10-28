import { useState } from "react";
import { useQueryClient } from "@tanstack/react-query";
import { __, sprintf } from "@wordpress/i18n";
import Button from "@components/ui/Button";
import Card from "@components/ui/Card";
import ConfirmDialog from "@components/ui/ConfirmDialog";
import EmptyState from "@components/ui/EmptyState";
import Notice from "@components/ui/Notice";
import RelativeTime from "@components/ui/RelativeTime";
import Skeleton from "@components/ui/Skeleton";
import StatTile from "@components/ui/StatTile";
import StatusText from "@components/ui/StatusText";
import { useAnnounce } from "@/hooks/useAnnounce";
import { setAccessPaused } from "@services/api";
import {
  ACCESS_QUERY_KEY,
  isLiveGrant,
  useAccessState,
  useGrants,
} from "@services/queries";
import { connectAppHref } from "@pages/Connections/logic";
import { interpolate } from "@utils/i18n";
import { requestErrorText } from "@utils/errors";
import type { AccessState } from "../../types/api";
import { formatMoment, lastUsed } from "./logic";

function AccessLine({ access }: { access: AccessState }) {
  if (!access.paused) {
    return <StatusText tone="success">{__("Active", "mcpmate")}</StatusText>;
  }

  return (
    <StatusText tone="warning" glyph="pause">
      {access.at && access.by?.name
        ? sprintf(
            /* translators: 1: time, e.g. "10:42", 2: name of the admin who paused. */
            __("Paused since %1$s by %2$s", "mcpmate"),
            formatMoment(access.at),
            access.by.name,
          )
        : access.at
          ? sprintf(
              /* translators: %s: time, e.g. "10:42". */
              __("Paused since %s", "mcpmate"),
              formatMoment(access.at),
            )
          : __("Paused", "mcpmate")}
    </StatusText>
  );
}

/**
 * AI access (docs/ui.md §5.1): how many AI apps are connected, which one was
 * used last, and Pause or Resume AI access for all of them at once.
 */
export default function AccessCard() {
  const queryClient = useQueryClient();
  const announce = useAnnounce();
  const grants = useGrants();
  const access = useAccessState();
  const [confirmPause, setConfirmPause] = useState(false);
  const [resuming, setResuming] = useState(false);
  const [resumeError, setResumeError] = useState("");
  const title = __("AI access", "mcpmate");

  const store = (next: AccessState) =>
    queryClient.setQueryData<AccessState>(ACCESS_QUERY_KEY, next);

  const pause = async () => {
    store(await setAccessPaused(true));
    announce(__("AI access paused.", "mcpmate"));
  };

  const resume = async () => {
    setResuming(true);
    setResumeError("");
    try {
      store(await setAccessPaused(false));
      announce(__("AI access resumed.", "mcpmate"));
    } catch (caught) {
      setResumeError(requestErrorText(caught));
    } finally {
      setResuming(false);
    }
  };

  if (grants.isLoading) {
    return (
      <Card title={title} busy>
        <Skeleton shape="number" />
        <Skeleton count={2} announce={false} />
      </Card>
    );
  }

  if (!grants.data) {
    return (
      <Card title={title}>
        <Notice
          tone="danger"
          title={__("Couldn't load your AI apps.", "mcpmate")}
          action={
            <Button
              variant="secondary"
              size="sm"
              onClick={() => void grants.refetch()}
            >
              {__("Try again", "mcpmate")}
            </Button>
          }
        >
          {requestErrorText(grants.error)}
        </Notice>
      </Card>
    );
  }

  const live = grants.data.grants.filter(isLiveGrant).length;
  const latest = lastUsed(grants.data.grants);

  return (
    <Card title={title}>
      <div className="el-stack">
        {live === 0 ? (
          <EmptyState
            title={__("No AI apps connected yet.", "mcpmate")}
            action={{
              label: __("Connect an AI app", "mcpmate"),
              href: connectAppHref(),
            }}
          />
        ) : (
          <div className="grid gap-1">
            <StatTile label={__("AI apps connected", "mcpmate")} value={live} />
            <p className="m-0 text-el-text-secondary">
              {latest
                ? interpolate(
                    /* translators: 1: when, e.g. "5 min ago", 2: AI app name. */
                    __("Last used %1$s by %2$s", "mcpmate"),
                    [
                      <RelativeTime key="at" date={latest.at} />,
                      latest.grant.client_name,
                    ],
                  )
                : __("Not used yet", "mcpmate")}
            </p>
          </div>
        )}

        {(live > 0 || access.paused) && (
          <>
            <p className="m-0">
              <AccessLine access={access} />
            </p>

            {resumeError && (
              <Notice
                tone="danger"
                title={__("Couldn't resume AI access.", "mcpmate")}
                live
              >
                {resumeError}
              </Notice>
            )}

            <div className="flex flex-wrap gap-3">
              {live > 0 && (
                <Button variant="secondary" href="#/connections">
                  {__("Manage connections", "mcpmate")}
                </Button>
              )}
              {access.paused ? (
                <Button
                  variant="primary"
                  loading={resuming}
                  onClick={() => void resume()}
                >
                  {resuming
                    ? __("Resuming…", "mcpmate")
                    : __("Resume AI access", "mcpmate")}
                </Button>
              ) : (
                <Button
                  variant="danger-secondary"
                  onClick={() => setConfirmPause(true)}
                >
                  {__("Pause AI access", "mcpmate")}
                </Button>
              )}
            </div>
          </>
        )}
      </div>

      <ConfirmDialog
        open={confirmPause}
        tone="danger"
        title={__("Pause AI access?", "mcpmate")}
        body={__(
          "Every connected AI app gets an error until you resume. Nothing is revoked, and you can resume at any time. Requests already waiting for your approval stay in the list.",
          "mcpmate",
        )}
        confirmLabel={__("Pause AI access", "mcpmate")}
        busyLabel={__("Pausing…", "mcpmate")}
        cancelLabel={__("Cancel", "mcpmate")}
        onConfirm={pause}
        onClose={() => setConfirmPause(false)}
      />
    </Card>
  );
}
