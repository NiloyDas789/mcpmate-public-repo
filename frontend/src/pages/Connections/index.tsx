import { useEffect, useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { useSearchParams } from "react-router";
import { __ } from "@wordpress/i18n";
import PageHeader from "@components/AppShell/PageHeader";
import { fetchConnection } from "@services/api";
import { CONNECTION_QUERY_KEY, useGrants } from "@services/queries";
import AiAppsCard from "./AiAppsCard";
import ConnectAppModal from "./ConnectAppModal";
import {
  clientFromParam,
  CONNECT_APP_PARAM,
  rememberClient,
  SECTION_PARAM,
  SIGN_IN_SECTION,
} from "./logic";
import McpUrlCard from "./McpUrlCard";
import RegisteredClientsCard from "./RegisteredClientsCard";
import SignInSettingsCard from "./SignInSettingsCard";

/**
 * Connections (`#/connections`, docs/ui.md §5.2): this site's MCP URL, the
 * AI apps approved on it, the OAuth clients registered with it, and token
 * lifetimes and client registration. `?connect-app=<id>` opens Connect an
 * AI app on that app's tab, so Back, Reload and the Dashboard's link all
 * reach it; `?section=sign-in` moves to the settings card (Settings links
 * there).
 */
export default function Connections() {
  const queryClient = useQueryClient();
  const [params, setParams] = useSearchParams();
  const status = useQuery({
    queryKey: CONNECTION_QUERY_KEY,
    queryFn: fetchConnection,
    staleTime: 0,
  });
  const grants = useGrants();
  const connectOpen = params.has(CONNECT_APP_PARAM);
  const clientId = clientFromParam(params.get(CONNECT_APP_PARAM));
  const [known, setKnown] = useState<Set<string> | null>(null);
  const toSignIn = params.get(SECTION_PARAM) === SIGN_IN_SECTION;

  useEffect(() => {
    if (connectOpen) {
      rememberClient(clientId);
    }
  }, [connectOpen, clientId]);

  // The apps there before the modal opened: anything new was approved since.
  useEffect(() => {
    if (!connectOpen) {
      setKnown(null);
    }
  }, [connectOpen]);

  const openConnect = () => {
    setKnown(
      grants.data
        ? new Set(grants.data.grants.map((grant) => grant.grant_id))
        : null,
    );
    setParams((current) => {
      const next = new URLSearchParams(current);
      next.set(CONNECT_APP_PARAM, clientId);
      return next;
    });
  };

  const closeConnect = () =>
    setParams(
      (current) => {
        const next = new URLSearchParams(current);
        next.delete(CONNECT_APP_PARAM);
        return next;
      },
      { replace: true },
    );

  return (
    <>
      <PageHeader
        title={__("Connections", "mcpmate")}
        intro={__(
          "Your site's MCP URL, the AI apps you've approved, and how they sign in. Each app acts as the WordPress user who approved it.",
          "mcpmate",
        )}
      />
      <div className="el-stack">
        <McpUrlCard
          connection={status.data?.connection}
          loading={status.isLoading}
          error={status.error}
          onRetry={() =>
            void queryClient.invalidateQueries({
              queryKey: CONNECTION_QUERY_KEY,
            })
          }
        />
        <AiAppsCard
          data={grants.data}
          loading={grants.isLoading}
          error={grants.error}
          onRetry={() => void grants.refetch()}
          onConnect={openConnect}
        />
        <RegisteredClientsCard onConnect={openConnect} />
        <SignInSettingsCard focusOnLoad={toSignIn} />
      </div>

      <ConnectAppModal
        open={connectOpen}
        clientId={clientId}
        onClose={closeConnect}
        mcpUrl={
          (status.data?.connection ?? SERVER_VARIABLES.connection).mcp_url
        }
        knownGrants={known}
      />
    </>
  );
}
