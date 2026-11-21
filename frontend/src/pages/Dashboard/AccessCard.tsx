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
import { setAccessPaused, setReadMode } from "@services/api";
import {
  ACCESS_QUERY_KEY,
  isLiveGrant,
  READ_MODE_QUERY_KEY,
  useAccessState,
  useGrants,
  useReadModeState,
} from "@services/queries";
import { connectAppHref } from "@pages/Connections/logic";
import { cx } from "@utils/classNames";
import { interpolate } from "@utils/i18n";
import { requestErrorText } from "@utils/errors";
import type { AccessState, ReadModeState } from "../../types/api";
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

function ReadModeLine({ readMode }: { readMode: ReadModeState }) {
  return (
    <StatusText tone="warning">
      {readMode.at && readMode.by?.name
        ? sprintf(
            /* translators: 1: time, e.g. "10:42", 2: name of the admin who turned it on. */
            __("Read only since %1$s by %2$s", "mcpmate"),
            formatMoment(readMode.at),
            readMode.by.name,
          )
        : readMode.at
          ? sprintf(
              /* translators: %s: time, e.g. "10:42". */
              __("Read only since %s", "mcpmate"),
              formatMoment(readMode.at),
            )
          : __("Read only", "mcpmate")}
    </StatusText>
  );
}

/**
 * AI access (docs/ui.md §5.1): how many AI apps are connected, which one was
 * used last, Pause or Resume AI access for all of them at once, and read
 * mode (P21): every AI app may read only, whatever its access level.
 */
export default function AccessCard() {
  const queryClient = useQueryClient();
  const announce = useAnnounce();
  const grants = useGrants();
  const access = useAccessState();
  const [confirmPause, setConfirmPause] = useState(false);
  const [resuming, setResuming] = useState(false);
  const [resumeError, setResumeError] = useState("");
  const readMode = useReadModeState();
  const [confirmReadMode, setConfirmReadMode] = useState(false);
  const [leavingReadMode, setLeavingReadMode] = useState(false);
  const [readModeError, setReadModeError] = useState("");
  const title = __("AI access", "mcpmate");

  const store = (next: AccessState) =>
    queryClient.setQueryData<AccessState>(ACCESS_QUERY_KEY, next);

  const storeReadMode = (next: ReadModeState) =>
    queryClient.setQueryData<ReadModeState>(READ_MODE_QUERY_KEY, next);

  const readModeOn = async () => {
    storeReadMode(await setReadMode(true));
    announce(__("Read mode is on.", "mcpmate"));
  };

  const readModeOff = async () => {
    setLeavingReadMode(true);
    setReadModeError("");
    try {
      storeReadMode(await setReadMode(false));
      announce(__("Read mode is off.", "mcpmate"));
    } catch (caught) {
      setReadModeError(requestErrorText(caught));
    } finally {
      setLeavingReadMode(false);
    }
  };

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

  const controls = live > 0 || access.paused || readMode.on;

  return (
    <Card
      title={title}
      className={cx(
        "el-dash-access",
        (access.paused || readMode.on) && "is-paused",
      )}
      actions={
        controls && (
          <p className="el-dash-access-state">
            {(access.paused || !readMode.on) && <AccessLine access={access} />}
            {readMode.on && <ReadModeLine readMode={readMode} />}
          </p>
        )
      }
    >
      <div className="el-dash-access-body">
        {live === 0 ? (
          <EmptyState
            title={__("No AI apps connected yet.", "mcpmate")}
            action={{
              label: __("Connect an AI app", "mcpmate"),
              href: connectAppHref(),
            }}
          />
        ) : (
          <div className="el-dash-access-apps">
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

        {controls && (
          <div className="el-dash-access-actions">
            {live > 0 && (
              <Button variant="secondary" href="#/connections">
                {__("Manage connections", "mcpmate")}
              </Button>
            )}
            {readMode.on ? (
              <Button
                variant="secondary"
                loading={leavingReadMode}
                onClick={() => void readModeOff()}
              >
                {leavingReadMode
                  ? __("Turning off…", "mcpmate")
                  : __("Turn off read mode", "mcpmate")}
              </Button>
            ) : (
              <Button
                variant="secondary"
                onClick={() => setConfirmReadMode(true)}
              >
                {__("Turn on read mode", "mcpmate")}
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
        )}
      </div>

      {readModeError && (
        <div className="el-dash-access-error">
          <Notice
            tone="danger"
            title={__("Couldn't turn off read mode.", "mcpmate")}
            live
          >
            {readModeError}
          </Notice>
        </div>
      )}

      {resumeError && (
        <div className="el-dash-access-error">
          <Notice
            tone="danger"
            title={__("Couldn't resume AI access.", "mcpmate")}
            live
          >
            {resumeError}
          </Notice>
        </div>
      )}

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

      <ConfirmDialog
        open={confirmReadMode}
        title={__("Turn on read mode?", "mcpmate")}
        body={__(
          "Every connected AI app can still read the site, but nothing that would change it: write tools disappear from their lists, and requests waiting for your approval can't be approved until you turn read mode off. Nothing is revoked.",
          "mcpmate",
        )}
        confirmLabel={__("Turn on read mode", "mcpmate")}
        busyLabel={__("Turning on…", "mcpmate")}
        cancelLabel={__("Cancel", "mcpmate")}
        onConfirm={readModeOn}
        onClose={() => setConfirmReadMode(false)}
      />
    </Card>
  );
}
