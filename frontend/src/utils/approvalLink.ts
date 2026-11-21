/**
 * The approval_url handed to AI clients is
 * `admin.php?page=mcpmate&approval=<id>`: a query argument, because a
 * `#/` route would be lost in the wp-login.php redirect. Returns the SPA
 * route to open for it, or null when the page already has a route.
 */
export function approvalRoute(search: string, hash: string): string | null {
  const id = new URLSearchParams(search).get("approval");

  if (!id || !/^[1-9]\d*$/.test(id)) {
    return null;
  }

  if (hash !== "" && hash !== "#" && hash !== "#/") {
    return null;
  }

  return `#/activity/approvals/${id}`;
}
