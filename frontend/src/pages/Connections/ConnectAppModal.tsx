import { useCallback, useEffect, useRef, useState } from "react";
import { ExportOutlined } from "@ant-design/icons";
import { useQueryClient } from "@tanstack/react-query";
import { __, sprintf } from "@wordpress/i18n";
import RichText from "@components/RichText";
import Button from "@components/ui/Button";
import CopyField from "@components/ui/CopyField";
import Modal from "@components/ui/Modal";
import Notice from "@components/ui/Notice";
import StatusText from "@components/ui/StatusText";
import Tabs from "@components/ui/Tabs";
import { AI_CLIENTS, findClient } from "@/data/aiClients";
import { useAnnounce } from "@/hooks/useAnnounce";
import { usePolling } from "@/hooks/usePolling";
import { fetchGrants } from "@services/api";
import { GRANTS_QUERY_KEY } from "@services/queries";
import { requestErrorText } from "@utils/errors";
import { onSetupMessage } from "@utils/setupChannel";
import type { Grant, GrantsResponse } from "../../types/api";
import { CONNECT_APP_PARAM, newGrants } from "./logic";

/** grants/list every 5 s while waiting (paused while the tab is hidden). */
export const CONNECT_POLL_MS = 5000;

function ClientSteps({
  clientId,
  mcpUrl,
}: {
  clientId: string;
  mcpUrl: string;
}) {
  const client = findClient(clientId);

  return (
    <>
      <ol className="m-0 ps-5">
        {client.steps(mcpUrl).map((step) => (
          <li key={step.text} className="mb-2">
            <RichText text={step.text} />
            {step.code && (
              <div className="mt-2">
                <CopyField value={step.code} label={__("Command", "mcpmate")} />
              </div>
            )}
          </li>
        ))}
      </ol>
      {client.note && (
        <p className="m-0 mb-2 text-el-text-secondary">
          <RichText text={client.note()} />
        </p>
      )}
      <a href={client.docsUrl} target="_blank" rel="noopener noreferrer">
        {sprintf(
          /* translators: %s: AI app name, e.g. Claude. */
          __("Steps look different? See %s's help", "mcpmate"),
          client.name,
        )}
        <ExportOutlined aria-hidden className="el-external-icon" />
        <span className="sr-only">{__("(opens in a new tab)", "mcpmate")}</span>
      </a>
    </>
  );
}

/**
 * Step 3: wait for the approval. Polls grants/list (and listens to the
 * approval screen in this browser) for a live app whose ID was not there
 * when the modal opened. Announces it; never moves focus.
 */
function WaitForApproval({ known }: { known: Set<string> | null }) {
  const queryClient = useQueryClient();
  const announce = useAnnounce();
  const baseline = useRef<Set<string> | null>(known);
  const [found, setFound] = useState<Grant | null>(null);
  const [denied, setDenied] = useState("");

  const poll = useCallback(async () => {
    const response = await fetchGrants();
    queryClient.setQueryData<GrantsResponse>(GRANTS_QUERY_KEY, response);

    if (baseline.current === null) {
      baseline.current = new Set(
        response.grants.map((grant) => grant.grant_id),
      );
      return false;
    }

    const fresh = newGrants(response.grants, baseline.current);
    if (fresh.length > 0) {
      setFound(fresh[0]);
      announce(
        sprintf(
          /* translators: %s: AI app name. */
          __("%s is connected.", "mcpmate"),
          fresh[0].client_name,
        ),
      );
      return true;
    }

    return false;
  }, [announce, queryClient]);

  const { error, retry, timedOut, keepWaiting, pollNow } = usePolling(poll, {
    enabled: !found,
    intervalMs: CONNECT_POLL_MS,
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

  if (error) {
    return (
      <Notice
        tone="danger"
        title={__("Couldn't check for your approval.", "mcpmate")}
        action={
          <Button variant="secondary" size="sm" onClick={retry}>
            {__("Try again", "mcpmate")}
          </Button>
        }
        live
      >
        {requestErrorText(error)}
      </Notice>
    );
  }

  if (found) {
    return (
      <p className="m-0">
        <StatusText tone="success">
          {sprintf(
            /* translators: 1: AI app name, 2: WordPress user login. */
            __("%1$s is connected. It acts as %2$s.", "mcpmate"),
            found.client_name,
            found.user.login ?? `#${found.user.id}`,
          )}
        </StatusText>
      </p>
    );
  }

  return (
    <div className="el-stack">
      <p className="m-0">
        <StatusText tone="warning">
          {timedOut
            ? __("Stopped waiting for an approval.", "mcpmate")
            : __("Waiting for an approval…", "mcpmate")}
        </StatusText>
      </p>
      {timedOut && (
        <div>
          <Button variant="secondary" onClick={keepWaiting}>
            {__("Keep waiting", "mcpmate")}
          </Button>
        </div>
      )}
      {denied && (
        <Notice
          tone="warning"
          title={sprintf(
            /* translators: %s: AI app name. */
            __(
              "You denied %s. To try again, start the connection again in your AI app.",
              "mcpmate",
            ),
            denied,
          )}
          live
        />
      )}
    </div>
  );
}

/**
 * Connect an AI app (docs/ui.md §5.2): copy the MCP URL, follow the app's
 * steps (the tab is kept in `?connect-app=`), and wait for the approval on
 * this site. Closing returns focus to the button that opened it.
 */
export default function ConnectAppModal({
  open,
  clientId,
  onClose,
  mcpUrl,
  knownGrants,
}: {
  open: boolean;
  clientId: string;
  onClose: () => void;
  mcpUrl: string;
  /** The app IDs known when the modal opened (null: not loaded yet). */
  knownGrants: Set<string> | null;
}) {
  return (
    <Modal
      open={open}
      title={__("Connect an AI app", "mcpmate")}
      onClose={onClose}
      size="lg"
      footer={
        <div className="el-dialog-actions">
          <Button variant="primary" onClick={onClose}>
            {__("Done", "mcpmate")}
          </Button>
        </div>
      }
    >
      <ol className="el-stack m-0 ps-5">
        <li>
          <h3 className="m-0 mb-2 text-el-md">
            {__("Copy your MCP URL", "mcpmate")}
          </h3>
          <CopyField value={mcpUrl} label={__("MCP URL", "mcpmate")} />
        </li>
        <li>
          <h3 className="m-0 mb-2 text-el-md">
            {__("Add it to your AI app", "mcpmate")}
          </h3>
          <Tabs
            label={__("AI apps", "mcpmate")}
            activeKey={clientId}
            items={AI_CLIENTS.map((client) => ({
              key: client.id,
              label: client.name,
              to: `?${CONNECT_APP_PARAM}=${client.id}`,
            }))}
          >
            <ClientSteps clientId={clientId} mcpUrl={mcpUrl} />
          </Tabs>
        </li>
        <li>
          <h3 className="m-0 mb-2 text-el-md">
            {__("Approve it on this site", "mcpmate")}
          </h3>
          {open && <WaitForApproval known={knownGrants} />}
        </li>
      </ol>
    </Modal>
  );
}
