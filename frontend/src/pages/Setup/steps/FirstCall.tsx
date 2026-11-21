import { useCallback, useEffect, useRef } from "react";
import { Alert, Button, Card, Typography } from "antd";
import { Link } from "react-router";
import { __, sprintf } from "@wordpress/i18n";
import CopyCode from "@components/CopyCode";
import { useFeatureSwitch } from "@/hooks/useFeatureSwitch";
import { usePolling } from "@/hooks/usePolling";
import { formatUtc } from "@utils/time";
import type { CoreProxyResponse } from "../../../types/api";
import { actionLabel, disabledFeature } from "../labels";
import {
  AjaxErrorAlert,
  StatusWord,
  StepFooter,
  StepHeading,
  type StepProps,
} from "../shared";

const { Paragraph, Text } = Typography;

/**
 * Step 5: one harmless prompt proves the whole chain. Waits for the
 * grant's first MCP call (the once-per-grant audit row).
 */
export default function FirstCall({
  state,
  refresh,
  goTo,
  announce,
  finish,
}: StepProps) {
  const coreProxy = useFeatureSwitch<CoreProxyResponse>("core-proxy");
  const announced = useRef(false);

  const { grant, first_call: firstCall } = state;

  const poll = useCallback(async () => {
    const next = await refresh();

    return Boolean(next?.first_call);
  }, [refresh]);

  const { timedOut, error, keepWaiting, retry } = usePolling(poll, {
    enabled: grant !== null && !firstCall,
  });

  useEffect(() => {
    if (firstCall && !announced.current) {
      announced.current = true;
      announce(__("First call received.", "mcpmate"));
    }
  }, [firstCall, announce]);

  if (!grant) {
    return (
      <>
        <StepHeading>{__("Try your first request", "mcpmate")}</StepHeading>
        <Paragraph>
          {__("No AI app is connected yet. Add one first.", "mcpmate")}
        </Paragraph>
        <StepFooter onBack={() => goTo("add_ai")} />
      </>
    );
  }

  const client = grant.client_name;
  const prompts = [
    __("Give me a health summary of my WordPress site.", "mcpmate"),
    __(
      "Which theme and plugins does my site use, and are any out of date?",
      "mcpmate",
    ),
    coreProxy.data?.enabled
      ? __("List my five most recent posts and drafts.", "mcpmate")
      : __(
          "Read my homepage and suggest three ways to make it clearer.",
          "mcpmate",
        ),
  ];
  const auditLink = `/audit-log?tool=connection&grant_id=${encodeURIComponent(grant.grant_id)}`;

  return (
    <>
      <StepHeading>{__("Try your first request", "mcpmate")}</StepHeading>
      <Paragraph>
        {sprintf(
          /* translators: %s: AI app name. */
          __(
            "Go back to %s and ask it something. These are read-only, so nothing on your site changes:",
            "mcpmate",
          ),
          client,
        )}
      </Paragraph>

      <ol className="m-0 list-none p-0">
        {prompts.map((prompt, index) => (
          <li key={prompt} className="mb-2">
            <Card size="small">
              <CopyCode
                code={prompt}
                onCopied={announce}
                label={sprintf(
                  /* translators: %d: prompt number. */
                  __("Copy prompt %d", "mcpmate"),
                  index + 1,
                )}
              />
            </Card>
          </li>
        ))}
      </ol>

      {grant.user.login && (
        <Paragraph type="secondary">
          {sprintf(
            /* translators: %s: WordPress user login. */
            __("It acts as %s.", "mcpmate"),
            grant.user.login,
          )}
        </Paragraph>
      )}

      <div
        className="mt-4 rounded border border-solid border-[#dcdcde] p-4"
        aria-busy={!firstCall && !timedOut}
      >
        {error ? (
          <AjaxErrorAlert error={error} onRetry={retry} />
        ) : firstCall && firstCall.result === "error" ? (
          <Alert
            type="warning"
            showIcon
            message={sprintf(
              /* translators: 1: AI app name, 2: error message. */
              __(
                "%1$s's request reached your site, but it didn't work: %2$s.",
                "mcpmate",
              ),
              client,
              firstCall.message,
            )}
            description={(() => {
              const feature = disabledFeature(firstCall.message);
              return feature ? (
                <>
                  {sprintf(
                    /* translators: %s: feature name. */
                    __("Turn on %s on the Tools page.", "mcpmate"),
                    feature,
                  )}{" "}
                  <Link to="/tools">{__("Open Tools", "mcpmate")}</Link>
                </>
              ) : undefined;
            })()}
          />
        ) : firstCall ? (
          <>
            <Paragraph strong className="!mb-1">
              <StatusWord status="pass" />{" "}
              {sprintf(
                /* translators: 1: AI app name, 2: what it did, e.g. "read your site's details", 3: time. */
                __("It works. %1$s %2$s at %3$s.", "mcpmate"),
                client,
                actionLabel(firstCall.route),
                formatUtc(firstCall.at),
              )}
            </Paragraph>
            <Paragraph className="!mb-1">
              <Text code>{`${firstCall.method} ${firstCall.route}`}</Text> ·{" "}
              {__("Passed", "mcpmate")}
            </Paragraph>
            <Paragraph className="!mb-0">
              {__(
                "Every request the AI makes on your site is listed in the audit log.",
                "mcpmate",
              )}
            </Paragraph>
          </>
        ) : timedOut ? (
          <>
            <Paragraph>
              {__(
                "Nothing yet. Make sure the connector is turned on in this chat (Claude: + → Connectors; ChatGPT: type @). Some apps only connect when you ask something that needs your site.",
                "mcpmate",
              )}
            </Paragraph>
            <Button onClick={keepWaiting}>
              {__("Keep waiting", "mcpmate")}
            </Button>
          </>
        ) : (
          <div className="flex items-center gap-2">
            <StatusWord status="waiting" />
            <Text strong>
              {sprintf(
                /* translators: %s: AI app name. */
                __("Waiting for %s's first request…", "mcpmate"),
                client,
              )}
            </Text>
          </div>
        )}
      </div>

      <StepFooter onBack={() => goTo("add_ai")}>
        {firstCall ? (
          <>
            <Button onClick={() => finish(auditLink)}>
              {__("See it in the audit log", "mcpmate")}
            </Button>
            <Button type="primary" onClick={() => finish()}>
              {__("Done", "mcpmate")}
            </Button>
          </>
        ) : (
          <Button type="link" onClick={() => finish()}>
            {__("I'll try later", "mcpmate")}
          </Button>
        )}
      </StepFooter>
    </>
  );
}
