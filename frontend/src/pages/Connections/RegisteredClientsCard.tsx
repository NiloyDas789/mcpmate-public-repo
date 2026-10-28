import { useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { __, _n, sprintf } from "@wordpress/i18n";
import Button from "@components/ui/Button";
import Card from "@components/ui/Card";
import ConfirmDialog from "@components/ui/ConfirmDialog";
import EmptyState from "@components/ui/EmptyState";
import Notice from "@components/ui/Notice";
import StatusText from "@components/ui/StatusText";
import Table, { type TableColumn } from "@components/ui/Table";
import { useAnnounce } from "@/hooks/useAnnounce";
import { fetchClients, restoreClient, revokeClient } from "@services/api";
import { CLIENTS_QUERY_KEY, GRANTS_QUERY_KEY } from "@services/queries";
import { formatNumber } from "@utils/format";
import { requestErrorText } from "@utils/errors";
import type { ClientsResponse, RegisteredClient } from "../../types/api";
import { clientStatus, formatDay, sortClients } from "./logic";

function ClientName({ client }: { client: RegisteredClient }) {
  return (
    <span className="grid gap-1">
      <strong>{client.client_name}</strong>
      <span className="font-normal">
        {client.verified_host ? (
          <>
            {__("verified by", "mcpmate")}{" "}
            <bdi dir="ltr" className="el-mono">
              {client.verified_host}
            </bdi>
          </>
        ) : (
          <StatusText tone="warning">
            {__("not verified", "mcpmate")}
          </StatusText>
        )}
      </span>
    </span>
  );
}

/**
 * Registered clients (docs/ui.md §5.2): every OAuth client of this site,
 * the ones that registered themselves and the ones known by a metadata
 * document. Revoke signs out every AI app the client connected and refuses
 * it until a site admin allows it again. The row stays, and its button
 * becomes Allow again (and back) in place, so focus stays on it.
 */
export default function RegisteredClientsCard({
  onConnect,
}: {
  onConnect: () => void;
}) {
  const queryClient = useQueryClient();
  const announce = useAnnounce();
  const clients = useQuery({
    queryKey: CLIENTS_QUERY_KEY,
    queryFn: fetchClients,
    staleTime: 0,
  });
  const [revoking, setRevoking] = useState<RegisteredClient | null>(null);
  const [restoring, setRestoring] = useState<string | null>(null);
  const [restoreError, setRestoreError] = useState<string | null>(null);
  const data = clients.data;
  const rows = sortClients(data?.clients ?? []);

  const replace = (client: RegisteredClient) =>
    queryClient.setQueryData<ClientsResponse>(CLIENTS_QUERY_KEY, (current) =>
      current
        ? {
            ...current,
            clients: current.clients.map((item) =>
              item.client_key === client.client_key ? client : item,
            ),
          }
        : current,
    );

  const revoke = async (client: RegisteredClient) => {
    const result = await revokeClient(client.client_key);
    replace(result.client);
    void queryClient.invalidateQueries({ queryKey: GRANTS_QUERY_KEY });
    announce(
      result.revoked_grants > 0
        ? sprintf(
            /* translators: 1: AI app name, 2: number of AI apps signed out. */
            _n(
              "%1$s is revoked. %2$s AI app lost access.",
              "%1$s is revoked. %2$s AI apps lost access.",
              result.revoked_grants,
              "mcpmate",
            ),
            client.client_name,
            formatNumber(result.revoked_grants),
          )
        : sprintf(
            /* translators: %s: AI app name. */
            __("%s is revoked.", "mcpmate"),
            client.client_name,
          ),
    );
  };

  const restore = async (client: RegisteredClient) => {
    setRestoring(client.client_key);
    setRestoreError(null);
    try {
      replace(await restoreClient(client.client_key));
      announce(
        sprintf(
          /* translators: %s: AI app name. */
          __(
            "%s can connect again. You approve it here when it asks.",
            "mcpmate",
          ),
          client.client_name,
        ),
      );
    } catch (error) {
      const message = requestErrorText(error);
      setRestoreError(message);
      announce(message, { assertive: true });
    } finally {
      setRestoring(null);
    }
  };

  const columns: TableColumn<RegisteredClient>[] = [
    {
      key: "client",
      title: __("Client", "mcpmate"),
      rowHeader: true,
      render: (client) => <ClientName client={client} />,
    },
    {
      key: "client_id",
      title: __("Client ID", "mcpmate"),
      render: (client) => (
        <bdi dir="ltr" className="el-mono break-all">
          {client.client_id}
        </bdi>
      ),
    },
    {
      key: "redirect",
      title: __("Redirect domain", "mcpmate"),
      render: (client) =>
        client.redirect_hosts.length > 0 ? (
          <span className="grid gap-1">
            {client.redirect_hosts.map((host) => (
              <bdi key={host} dir="ltr" className="el-mono break-all">
                {host}
              </bdi>
            ))}
          </span>
        ) : (
          "—"
        ),
    },
    {
      key: "registered",
      title: __("Registered", "mcpmate"),
      render: (client) => (
        <time dateTime={client.created_at}>{formatDay(client.created_at)}</time>
      ),
    },
    {
      key: "status",
      title: __("Status", "mcpmate"),
      render: (client) => {
        const status = clientStatus(client);

        return <StatusText tone={status.tone}>{status.text}</StatusText>;
      },
    },
    {
      key: "actions",
      title: __("Actions", "mcpmate"),
      hiddenTitle: true,
      render: (client) =>
        client.status === "revoked" ? (
          <Button
            size="sm"
            variant="secondary"
            loading={restoring === client.client_key}
            onClick={() => void restore(client)}
            label={sprintf(
              /* translators: %s: AI app name. */
              __("Allow %s again", "mcpmate"),
              client.client_name,
            )}
          >
            {__("Allow again", "mcpmate")}
          </Button>
        ) : (
          <Button
            size="sm"
            variant="danger-secondary"
            onClick={() => setRevoking(client)}
            label={sprintf(
              /* translators: %s: AI app name. */
              __("Revoke %s", "mcpmate"),
              client.client_name,
            )}
          >
            {__("Revoke", "mcpmate")}
          </Button>
        ),
    },
  ];

  return (
    <Card
      title={__("Registered clients", "mcpmate")}
      busy={clients.isLoading}
      actions={
        data && (
          <span className="text-el-text-secondary">
            {sprintf(
              /* translators: 1: self-registered clients, 2: how many this site accepts. */
              __("%1$s of %2$s self-registered", "mcpmate"),
              formatNumber(data.dcr_count),
              formatNumber(data.dcr_limit),
            )}
          </span>
        )
      }
    >
      <div className="el-stack">
        <p className="m-0">
          {__(
            "Each AI app registers with this site once, so it can sign in. Revoking a client signs out every app it connected and stops it from connecting again until you allow it.",
            "mcpmate",
          )}
        </p>

        {restoreError && (
          <Notice
            tone="danger"
            title={__("Couldn't allow it again.", "mcpmate")}
            onDismiss={() => setRestoreError(null)}
          >
            {restoreError}
          </Notice>
        )}

        <Table<RegisteredClient>
          caption={__(
            "OAuth clients registered with this site, newest first",
            "mcpmate",
          )}
          columns={columns}
          rows={rows}
          rowKey={(client) => client.client_key}
          loading={clients.isLoading}
          error={
            !clients.isLoading && clients.error && !data ? (
              <Notice
                tone="danger"
                title={__("Couldn't load the registered clients.", "mcpmate")}
                action={
                  <Button
                    variant="secondary"
                    size="sm"
                    onClick={() => void clients.refetch()}
                  >
                    {__("Try again", "mcpmate")}
                  </Button>
                }
              >
                {requestErrorText(clients.error)}
              </Notice>
            ) : undefined
          }
          empty={
            <EmptyState
              title={__("No AI apps have registered yet.", "mcpmate")}
              body={__(
                "An AI app registers here the first time you add this site's MCP URL to it.",
                "mcpmate",
              )}
              action={{
                label: __("Connect an AI app", "mcpmate"),
                onClick: onConnect,
              }}
            />
          }
        />
      </div>

      <ConfirmDialog
        open={revoking !== null}
        tone="danger"
        title={sprintf(
          /* translators: %s: AI app name. */
          __("Revoke %s?", "mcpmate"),
          revoking?.client_name ?? "",
        )}
        body={
          revoking && (
            <>
              <p className="m-0">
                {revoking.live_grants > 0
                  ? sprintf(
                      /* translators: 1: number of AI apps, 2: AI app name. */
                      _n(
                        "%1$s AI app connected through %2$s loses access to this site right away.",
                        "%1$s AI apps connected through %2$s lose access to this site right away.",
                        revoking.live_grants,
                        "mcpmate",
                      ),
                      formatNumber(revoking.live_grants),
                      revoking.client_name,
                    )
                  : sprintf(
                      /* translators: %s: AI app name. */
                      __(
                        "No AI app is connected through %s right now.",
                        "mcpmate",
                      ),
                      revoking.client_name,
                    )}
              </p>
              <p className="m-0 mt-2">
                {sprintf(
                  /* translators: %s: AI app name. */
                  __(
                    "%s can't connect again until you allow it here. Past activity stays in the audit log.",
                    "mcpmate",
                  ),
                  revoking.client_name,
                )}
              </p>
            </>
          )
        }
        confirmLabel={sprintf(
          /* translators: %s: AI app name. */
          __("Revoke %s", "mcpmate"),
          revoking?.client_name ?? "",
        )}
        busyLabel={__("Revoking…", "mcpmate")}
        cancelLabel={__("Keep it", "mcpmate")}
        onConfirm={() => (revoking ? revoke(revoking) : undefined)}
        onClose={() => setRevoking(null)}
      />
    </Card>
  );
}
