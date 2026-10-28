import { useQuery } from "@tanstack/react-query";
import { ajaxOrThrow, fetchConnection, fetchGrants } from "@services/api";
import type { AccessState, ConnectionSummary, Grant } from "../types/api";

/**
 * Queries that more than one part of the app reads: the shell's status pill
 * and notices follow what the Dashboard and Connections pages change,
 * without a reload.
 */

export const GRANTS_QUERY_KEY = ["grants"];

export const ACCESS_QUERY_KEY = ["access"];

export const CONNECTION_QUERY_KEY = ["connection"];

export const STATS_QUERY_KEY = ["stats"];

export const TOOLS_QUERY_KEY = ["tools"];

export const PREFERENCES_QUERY_KEY = ["preferences"];

/** Connections → Registered clients: refetched after a revoke on either table. */
export const CLIENTS_QUERY_KEY = ["clients"];

/** Every page of the audit log (and single entries): refetched after a purge. */
export const AUDIT_LOG_QUERY_KEY = ["audit-log"];

export const CHANGES_QUERY_KEY = ["changes"];

/** Every list and request on Activity → Approvals: refetched after a decision. */
export const APPROVALS_QUERY_KEY = ["approvals"];

/** How many requests wait for a site admin: the Approvals tab and the shell's notice. */
export const PENDING_APPROVALS_QUERY_KEY = ["pending-approvals"];

const NOT_PAUSED: AccessState = { paused: false, at: null, by: null };

export const isLiveGrant = (grant: Grant) =>
  grant.status === "pending" || grant.status === "active";

/** admin-ajax `grants/list`, fetched by the page that shows it. */
export function useGrants() {
  return useQuery({
    queryKey: GRANTS_QUERY_KEY,
    queryFn: fetchGrants,
    staleTime: 0,
  });
}

/**
 * Whether AI access is paused: `SERVER_VARIABLES.access` until a pause or
 * resume in this tab replaces it.
 */
export function useAccessState(): AccessState {
  return useQuery({
    queryKey: ACCESS_QUERY_KEY,
    queryFn: async () =>
      (await ajaxOrThrow<{ access: AccessState }>("access")).access,
    initialData: SERVER_VARIABLES.access ?? NOT_PAUSED,
    staleTime: Infinity,
  }).data;
}

/**
 * The live grant count: from `grants/list` once a page loaded it, else
 * `SERVER_VARIABLES.grants_live`. Never fetches on its own.
 */
export function useLiveGrantCount(): number {
  const { data } = useQuery({
    queryKey: GRANTS_QUERY_KEY,
    queryFn: fetchGrants,
    enabled: false,
  });

  return data
    ? data.grants.filter(isLiveGrant).length
    : (SERVER_VARIABLES.grants_live ?? 0);
}

/**
 * This site's connection: from `connection/status` once a page loaded it,
 * else `SERVER_VARIABLES.connection`. Never fetches on its own.
 */
export function useConnectionSummary(): ConnectionSummary {
  const { data } = useQuery({
    queryKey: CONNECTION_QUERY_KEY,
    queryFn: fetchConnection,
    enabled: false,
  });

  return data?.connection ?? SERVER_VARIABLES.connection;
}

/**
 * Requests waiting for a site admin: `SERVER_VARIABLES.pending_approvals`
 * until Activity → Approvals counts them or a decision takes one away.
 * Never fetches on its own.
 */
export function usePendingApprovalCount(): number {
  const { data } = useQuery({
    queryKey: PENDING_APPROVALS_QUERY_KEY,
    queryFn: () => SERVER_VARIABLES.pending_approvals ?? 0,
    initialData: SERVER_VARIABLES.pending_approvals ?? 0,
    enabled: false,
  });

  return data;
}
