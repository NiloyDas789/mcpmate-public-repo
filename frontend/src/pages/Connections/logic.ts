import { __, _n, sprintf } from "@wordpress/i18n";
import type { StatusTone } from "@components/ui/StatusDot";
import type { TableSort } from "@components/ui/Table";
import { AI_CLIENTS } from "@/data/aiClients";
import { isLiveGrant } from "@services/queries";
import { formatDate, formatNumber } from "@utils/format";
import type { PickableGroup } from "@pages/Tools/logic";
import type { Grant, RegisteredClient } from "../../types/api";

/** The Connections page's decisions (docs/ui.md §5.2), kept pure for tests. */

/** `?connect-app=<id>` opens the Connect an AI app modal on that app's tab. */
export const CONNECT_APP_PARAM = "connect-app";

const CLIENT_STORAGE_KEY = "mcpmate-connect-app";

/** `?section=sign-in` moves to "Token lifetimes and client registration". */
export const SECTION_PARAM = "section";

export const SIGN_IN_SECTION = "sign-in";

export const SIGN_IN_HREF = `#/connections?${SECTION_PARAM}=${SIGN_IN_SECTION}`;

/** The app tab picked last in this browser session (WCAG 3.3.7). */
export function rememberedClient(): string {
  try {
    return window.sessionStorage.getItem(CLIENT_STORAGE_KEY) ?? "claude";
  } catch {
    return "claude";
  }
}

export function rememberClient(id: string): void {
  try {
    window.sessionStorage.setItem(CLIENT_STORAGE_KEY, id);
  } catch {
    // Storage blocked: the default tab is fine.
  }
}

/** A known client id from the hash, else the remembered one. */
export function clientFromParam(value: string | null): string {
  return AI_CLIENTS.some((client) => client.id === value)
    ? (value as string)
    : rememberedClient();
}

export function connectAppHref(): string {
  return `#/connections?${CONNECT_APP_PARAM}=${rememberedClient()}`;
}

/** The host that vouched for the app (`cimd:<host>`), or null when it is not verified. */
export function verifiedHost(grant: Grant): string | null {
  return grant.vouched_by.startsWith("cimd:")
    ? grant.vouched_by.slice(5)
    : null;
}

/** "acts as admin", or "acts as deleted user #12". */
export function actsAs(grant: Grant): string {
  return grant.user.login
    ? sprintf(
        /* translators: %s: WordPress user login. */
        __("acts as %s", "mcpmate"),
        grant.user.login,
      )
    : sprintf(
        /* translators: %d: WordPress user ID. */
        __("acts as deleted user #%d", "mcpmate"),
        grant.user.id,
      );
}

/** "Full access", "Read-only", "Custom, 6 tools". */
export function accessLabel(grant: Grant): string {
  if (grant.access === "custom") {
    const count = grant.tools?.length ?? 0;

    return sprintf(
      /* translators: %d: number of tools the AI app may use. */
      _n("Custom, %d tool", "Custom, %d tools", count, "mcpmate"),
      count,
    );
  }

  return grant.access === "read_only"
    ? __("Read-only", "mcpmate")
    : __("Full access", "mcpmate");
}

/** "Revoked by a site admin", "Its WordPress user was deleted"… */
export function revokeReasonLabel(grant: Grant): string {
  switch (grant.revoke_reason) {
    case "user_deleted":
      return __("Its WordPress user was deleted", "mcpmate");
    case "client_revoked":
      return __("Revoked with its registered client", "mcpmate");
    case "refresh_reuse":
    case "code_reuse":
      return __("Revoked automatically: a used token came back", "mcpmate");
    default:
      return __("Revoked by a site admin", "mcpmate");
  }
}

/** "12 Oct 2026" in the admin's language and the site's timezone. */
export function formatDay(iso: string | null): string {
  return iso
    ? formatDate(iso, { day: "numeric", month: "short", year: "numeric" })
    : "";
}

const time = (iso: string | null) => (iso ? Date.parse(iso) : Number.NaN);

/** Live apps sorted by Approved or Last used; never-used apps sort as oldest. */
export function sortGrants(grants: Grant[], sort: TableSort): Grant[] {
  const field = sort.key === "approved" ? "created_at" : "last_used_at";
  const sign = sort.order === "ascending" ? 1 : -1;

  return [...grants].sort((a, b) => {
    const left = time(a[field]);
    const right = time(b[field]);
    const l = Number.isNaN(left) ? -Infinity : left;
    const r = Number.isNaN(right) ? -Infinity : right;

    if (l === r) {
      return time(b.created_at) - time(a.created_at);
    }

    return l < r ? -sign : sign;
  });
}

export const DEFAULT_SORT: TableSort = {
  key: "last_used",
  order: "descending",
};

/** Live apps first, then the ones revoked (the server keeps those 30 days). */
export function splitGrants(grants: Grant[]): {
  live: Grant[];
  revoked: Grant[];
} {
  return {
    live: grants.filter(isLiveGrant),
    revoked: grants
      .filter((grant) => !isLiveGrant(grant))
      .sort((a, b) => time(b.revoked_at) - time(a.revoked_at)),
  };
}

/**
 * Apps approved since the Connect modal opened: live grants whose IDs were
 * not there before, newest first. IDs, not times, so the two clocks never
 * need to agree.
 */
export function newGrants(grants: Grant[], before: Set<string>): Grant[] {
  return grants
    .filter((grant) => isLiveGrant(grant) && !before.has(grant.grant_id))
    .sort((a, b) => time(b.created_at) - time(a.created_at));
}

/**
 * What Custom starts with: the app's own list, or what its current level
 * reaches among the tools that are on (every one for Full, the read tools
 * for Read-only).
 */
export function initialSelection(
  grant: Grant,
  groups: PickableGroup[],
): Set<string> {
  if (grant.access === "custom") {
    return new Set(grant.tools ?? []);
  }

  return new Set(
    groups.flatMap(({ tools }) =>
      tools
        .filter((tool) => grant.access === "full" || tool.kind === "read")
        .map((tool) => tool.name),
    ),
  );
}

/** A registered client's status as a dot and words (docs/ui.md §5.2). */
export function clientStatus(client: RegisteredClient): {
  tone: StatusTone;
  text: string;
} {
  switch (client.status) {
    case "connected":
      return {
        tone: "success",
        text: sprintf(
          /* translators: %s: number of AI apps connected through this client. */
          _n(
            "Connected (%s app)",
            "Connected (%s apps)",
            client.live_grants,
            "mcpmate",
          ),
          formatNumber(client.live_grants),
        ),
      };
    case "revoked":
      return {
        tone: "danger",
        text: sprintf(
          /* translators: %s: date. */
          __("Revoked %s", "mcpmate"),
          formatDay(client.revoked_at),
        ),
      };
    case "unused":
      return {
        tone: "warning",
        text: __("Never approved", "mcpmate"),
      };
    default:
      return { tone: "neutral", text: __("Not connected", "mcpmate") };
  }
}

/** Live clients first (the server's order), newest registered first. */
export function sortClients(clients: RegisteredClient[]): RegisteredClient[] {
  return [...clients].sort((a, b) => {
    const revoked =
      Number(a.status === "revoked") - Number(b.status === "revoked");

    return revoked !== 0 ? revoked : time(b.created_at) - time(a.created_at);
  });
}
