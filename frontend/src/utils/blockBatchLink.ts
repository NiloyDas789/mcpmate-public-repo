/**
 * The finalization_url handed to AI apps is
 * `admin.php?page=mcpmate&block_batch=<id>`: a query argument, because a
 * `#/` route would be lost in the wp-login.php redirect (as approvalLink).
 * Returns the SPA route to open for it, or null when the page already has
 * a route.
 */
export function blockBatchRoute(search: string, hash: string): string | null {
  const id = new URLSearchParams(search).get("block_batch");

  if (!id || !/^[1-9]\d*$/.test(id)) {
    return null;
  }

  if (hash !== "" && hash !== "#" && hash !== "#/") {
    return null;
  }

  return `#/activity/block-queue/${id}`;
}
