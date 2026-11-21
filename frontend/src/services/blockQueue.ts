import { AjaxRequestError } from "@services/api";
import type {
  BlockBatch,
  BlockBatchStatus,
  BlockPayloadPiece,
  BlockQueueClaim,
  BlockQueuePage,
  RestEnvelope,
} from "../types/api";

/**
 * The block editor queue's REST routes for a site admin in wp-admin
 * (MCPMate Pro, `block-queue/*`, `wpAdmin`: an MCP call never reaches
 * them). Each call unwraps the envelope and throws an AjaxRequestError:
 * `refused` with the route's code and message, `session` for a stale
 * nonce, `network` and `server` as admin-ajax calls do.
 */
async function call<T>(
  endpoint: string,
  method: "GET" | "POST" = "GET",
  body?: Record<string, unknown>,
): Promise<T> {
  let response: Response;
  try {
    response = await fetch(`${SERVER_VARIABLES.apiURL.base}/${endpoint}`, {
      method,
      headers: {
        "X-WP-Nonce": SERVER_VARIABLES.restNonce,
        "Content-Type": "application/json",
      },
      credentials: "same-origin",
      ...(body && { body: JSON.stringify(body) }),
    });
  } catch {
    throw new AjaxRequestError("Request failed: network error", "network", 0);
  }

  let envelope: RestEnvelope<T> | null = null;
  try {
    envelope = (await response.json()) as RestEnvelope<T>;
  } catch {
    envelope = null;
  }

  if (envelope?.ok && envelope.data !== null) {
    return envelope.data;
  }

  const error = envelope?.error;
  if (error?.code === "rest_cookie_invalid_nonce") {
    throw new AjaxRequestError(error.message, "session", response.status);
  }

  if (error) {
    const refused = new AjaxRequestError(
      error.message,
      "refused",
      response.status,
      { code: error.code, message: error.message },
    );
    refused.hint = error.hint ?? "";
    throw refused;
  }

  throw new AjaxRequestError(
    `Request failed: ${response.status}`,
    "server",
    response.status,
  );
}

/** Every user's batches, newest first; an empty status lists them all. */
export function fetchBlockQueue(
  status: BlockBatchStatus | "",
  page: number,
  trashed = false,
): Promise<BlockQueuePage> {
  const query = new URLSearchParams({ page: String(page), per_page: "20" });
  if (status) {
    query.set("status", status);
  }
  if (trashed) {
    query.set("trashed", "1");
  }

  return call<BlockQueuePage>(`block-queue?${query.toString()}`);
}

/** One batch with its changes, who asked and through which app. */
export function fetchBlockBatch(id: number): Promise<BlockBatch> {
  return call<BlockBatch>(`block-queue/${id}`);
}

export function trashBlockBatch(id: number): Promise<BlockBatch> {
  return call<BlockBatch>(`block-queue/${id}/trash`, "POST", {});
}

/** This browser finalizes the batch: a lease and the changes, without payloads. */
export function claimBlockBatch(id: number): Promise<BlockQueueClaim> {
  return call<BlockQueueClaim>(`block-queue/${id}/claim`, "POST", {});
}

export function fetchPayloadPiece(
  id: number,
  item: number,
  lease: string,
  offset: number,
): Promise<BlockPayloadPiece> {
  return call<BlockPayloadPiece>(
    `block-queue/${id}/items/${item}/payload`,
    "POST",
    { lease, offset },
  );
}

/** The editor's content for one change, checked as the save would (nothing saved). */
export function prepareBlockChange(
  id: number,
  item: number,
  lease: string,
  content: string,
  editor: { recovered: number; registered: string[] },
): Promise<BlockBatch> {
  return call<BlockBatch>(`block-queue/${id}/items/${item}/prepare`, "POST", {
    lease,
    content,
    editor,
  });
}

/** What went wrong in the browser; the batch fails. */
export function failBlockChange(
  id: number,
  item: number,
  lease: string,
  reason: string,
  message: string,
): Promise<BlockBatch> {
  return call<BlockBatch>(`block-queue/${id}/items/${item}/fail`, "POST", {
    lease,
    reason,
    message: message.slice(0, 500),
  });
}

/** Checks every prepared change again, then saves them all as drafts. */
export function commitBlockBatch(
  id: number,
  lease: string,
): Promise<BlockBatch> {
  return call<BlockBatch>(`block-queue/${id}/commit`, "POST", { lease });
}
