/**
 * `setup=1` and `approval=<id>` only say which route to open when the
 * page loads. Left in the address they would open it again on every
 * reload of the Dashboard (`#/`), e.g. after the wizard finishes. Returns
 * the query without them, or null when there is nothing to remove.
 * `connect=` stays: PHP reads it again when the approval screen reloads.
 */
export function withoutEntryArgs(search: string): string | null {
  const params = new URLSearchParams(search);

  if (!params.has("setup") && !params.has("approval")) {
    return null;
  }

  params.delete("setup");
  params.delete("approval");
  const query = params.toString();

  return query ? `?${query}` : "";
}
