import { useCallback, useEffect, useRef, useState } from "react";
import { Alert, Button, Tabs, Typography } from "antd";
import { Link } from "react-router";
import { __, sprintf } from "@wordpress/i18n";
import CopyCode from "@components/CopyCode";
import McpUrl from "@components/McpUrl";
import RichText from "@components/RichText";
import { AI_CLIENTS, findClient } from "@/data/aiClients";
import { usePolling } from "@/hooks/usePolling";
import { fetchGrants } from "@services/api";
import { onSetupMessage } from "@utils/setupChannel";
import type { Grant, GrantsResponse } from "../../../types/api";
import {
  AjaxErrorAlert,
  StatusWord,
  StepFooter,
  StepHeading,
  type StepProps,
} from "../shared";
import { isLive, newGrants } from "../logic";

const { Paragraph, Text } = Typography;

const TAB_STORAGE_KEY = "mcpmate-setup-client";

/** Wait this long after "{client} is connected." before moving to step 5. */
export const ADVANCE_DELAY_MS = 1500;

function rememberedTab(): string {
  try {
    return window.localStorage.getItem(TAB_STORAGE_KEY) ?? "claude";
  } catch {
    return "claude";
  }
}

function rememberTab(id: string): void {
  try {
    window.localStorage.setItem(TAB_STORAGE_KEY, id);
  } catch {
    // Storage blocked: the default tab is fine.
  }
}

/**
 * Step 4: add the connector in the AI app and approve it here. Waits for
 * the approval from the moment it opens: grants/list every 3 s, or at once
 * when the approval screen in this browser says so (BroadcastChannel).
 */
export default function AddAi({ state, goTo, announce }: StepProps) {
  const [tab, setTab] = useState(rememberedTab);
  const [grants, setGrants] = useState<GrantsResponse | null>(null);
  const [found, setFound] = useState<Grant | null>(null);
  const [denied, setDenied] = useState("");
  const advance = useRef<ReturnType<typeof setTimeout>>();
  const connectSince = state.onboarding.connect_since;

  const accept = useCallback(
    (grant: Grant) => {
      setFound(grant);
      announce(
        sprintf(
          /* translators: %s: AI app name. */
          __("%s is connected. Moving to the next step.", "mcpmate"),
          grant.client_name,
        ),
      );
      advance.current = setTimeout(
        () => goTo("first_call", { grant_id: grant.grant_id }),
        ADVANCE_DELAY_MS,
      );
    },
    [announce, goTo],
  );

  useEffect(() => () => clearTimeout(advance.current), []);

  const poll = useCallback(async () => {
    const response = await fetchGrants();
    setGrants(response);

    const fresh = newGrants(response.grants, connectSince);
    if (fresh.length > 0) {
      accept(fresh[0]);
      return true;
    }

    return false;
  }, [accept, connectSince]);

  const { timedOut, error, keepWaiting, retry, pollNow } = usePolling(poll, {
    enabled: !found && connectSince !== null,
  });

  useEffect(
    () =>
      onSetupMessage((message) => {
        if (message.type === "grant-approved") {
          pollNow();
        } else {
          setDenied(message.client_name);
          announce(
            sprintf(
              /* translators: %s: AI app name. */
              __("You denied %s.", "mcpmate"),
              message.client_name,
            ),
          );
        }
      }),
    [pollNow, announce],
  );

  const mcpUrl = state.connection.mcp_url;
  const client = findClient(tab);
  const live = (grants?.grants ?? []).filter(isLive);
  const fresh = found ? newGrants(grants?.grants ?? [], connectSince) : [];
  const existing = live.filter((grant) => !fresh.includes(grant));
  const atLimit = grants !== null && live.length >= grants.limit;

  const continueWith = (grant: Grant) => {
    clearTimeout(advance.current);
    goTo("first_call", { grant_id: grant.grant_id });
  };

  return (
    <>
      <StepHeading>{__("Add this site to your AI app", "mcpmate")}</StepHeading>
      <Paragraph>
        {__(
          "A connector is how your AI app links to an outside service, like this site. Pick your app and follow the steps. When your app asks, a page on this site opens for you to approve it.",
          "mcpmate",
        )}
      </Paragraph>

      {mcpUrl && <McpUrl url={mcpUrl} size="large" onCopied={announce} />}

      <Tabs
        className="mt-4"
        activeKey={client.id}
        onChange={(key) => {
          setTab(key);
          rememberTab(key);
        }}
        items={AI_CLIENTS.map((item) => ({
          key: item.id,
          label: item.name,
          children: (
            <div>
              <ol className="m-0 pl-5">
                {item.steps(mcpUrl).map((step) => (
                  <li key={step.text} className="mb-2">
                    <RichText text={step.text} />
                    {step.code && (
                      <CopyCode code={step.code} onCopied={announce} />
                    )}
                  </li>
                ))}
              </ol>
              {item.note && (
                <Paragraph type="secondary" className="!mb-2">
                  <RichText text={item.note()} />
                </Paragraph>
              )}
              <a href={item.docsUrl} target="_blank" rel="noreferrer">
                {sprintf(
                  /* translators: %s: AI app name, e.g. Claude. */
                  __(
                    "Steps look different? See %s's help (opens in a new tab).",
                    "mcpmate",
                  ),
                  item.name,
                )}
              </a>
            </div>
          ),
        }))}
      />

      <div
        className="mt-4 rounded border border-solid border-[#dcdcde] p-4"
        aria-busy={!found && !timedOut}
      >
        {error ? (
          <AjaxErrorAlert error={error} onRetry={retry} />
        ) : found ? (
          <>
            <div className="flex items-center gap-2">
              <StatusWord status="pass" />
              <Text strong>
                {sprintf(
                  /* translators: %s: AI app name. */
                  __("%s is connected.", "mcpmate"),
                  found.client_name,
                )}
              </Text>
            </div>
            {!found.scopes.includes("site:write") && (
              <div>
                {__("It can read your site but not make changes.", "mcpmate")}
              </div>
            )}
            {fresh.length > 1 && (
              <div>
                {sprintf(
                  /* translators: %s: comma-separated AI app names. */
                  __("Also connected: %s.", "mcpmate"),
                  fresh
                    .slice(1)
                    .map((grant) => grant.client_name)
                    .join(", "),
                )}
              </div>
            )}
          </>
        ) : timedOut ? (
          <>
            <Text strong>{__("Still waiting? Common fixes:", "mcpmate")}</Text>
            <ul className="m-0 mt-2 pl-5">
              <li>
                {__(
                  "No approval page opened? Your browser may have blocked a pop-up. Look for it in your AI app or your browser's address bar.",
                  "mcpmate",
                )}
              </li>
              <li>
                {__(
                  "The page said the link expired? Approval links last 10 minutes. Start the connection again in your AI app.",
                  "mcpmate",
                )}
              </li>
              <li>
                {__(
                  "Asked to log in? Log in to this site in that browser; you'll come back to the approval page.",
                  "mcpmate",
                )}
              </li>
              <li>
                {__("Your AI app says it can't connect?", "mcpmate")}{" "}
                <Button
                  type="link"
                  className="!p-0"
                  onClick={() => goTo("check")}
                >
                  {__("Run the site checks again.", "mcpmate")}
                </Button>
              </li>
              <li>
                {__(
                  "On a phone or another computer? Approve there; this page will update by itself.",
                  "mcpmate",
                )}
              </li>
            </ul>
            <Button className="mt-3" onClick={keepWaiting}>
              {__("Keep waiting", "mcpmate")}
            </Button>
          </>
        ) : (
          <>
            <div className="flex items-center gap-2">
              <StatusWord status="waiting" />
              <Text strong>{__("Waiting for your approval…", "mcpmate")}</Text>
            </div>
            <div>
              {__(
                "We'll move on as soon as you approve your app on this site.",
                "mcpmate",
              )}
            </div>
          </>
        )}

        {denied && !found && (
          <Alert
            type="warning"
            showIcon
            className="mt-3"
            message={sprintf(
              /* translators: %s: AI app name. */
              __(
                "You denied %s. To try again, start the connection again in your AI app.",
                "mcpmate",
              ),
              denied,
            )}
          />
        )}

        {!found && existing.length > 0 && (
          <Paragraph className="!mb-0 !mt-3">
            {sprintf(
              /* translators: %s: comma-separated AI app names. */
              __(
                "Already connected: %s. Connect another app, or continue.",
                "mcpmate",
              ),
              existing.map((grant) => grant.client_name).join(", "),
            )}
          </Paragraph>
        )}

        {!found && atLimit && grants && (
          <Paragraph className="!mb-0 !mt-3">
            {sprintf(
              /* translators: 1: connected apps, 2: limit. */
              __(
                "%1$d of %2$d apps connected. Revoke one under Connected AI apps first.",
                "mcpmate",
              ),
              live.length,
              grants.limit,
            )}{" "}
            <Link to="/connections">{__("Connected AI apps", "mcpmate")}</Link>
          </Paragraph>
        )}
      </div>

      <StepFooter onBack={() => goTo("safety")}>
        {found ? (
          <Button type="primary" onClick={() => continueWith(found)}>
            {__("Continue", "mcpmate")}
          </Button>
        ) : (
          existing.length > 0 && (
            <Button type="primary" onClick={() => continueWith(existing[0])}>
              {__("Continue", "mcpmate")}
            </Button>
          )
        )}
      </StepFooter>
    </>
  );
}
