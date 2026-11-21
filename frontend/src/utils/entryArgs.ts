/**
 * `setup=1`, `approval=<id>` and `block_batch=<id>` only say which route to open when the
 * page loads. Left in the address they would open it again on every
 * reload of the Dashboard (`#/`), e.g. after the wizard finishes. Returns
 * the query without them, or null when there is nothing to remove.
 * `connect=` stays: PHP reads it again when the approval screen reloads.
 */
export function withoutEntryArgs(search: string): string | null {
  const params = new URLSearchParams(search);

  if (
    !params.has("setup") &&
    !params.has("approval") &&
    !params.has("block_batch")
  ) {
    return null;
  }

  params.delete("setup");
  params.delete("approval");
  params.delete("block_batch");
  const query = params.toString();

  return query ? `?${query}` : "";
}
