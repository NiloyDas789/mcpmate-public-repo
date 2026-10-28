/**
 * The same-browser shortcut between the approval screen (`#/connect`) and
 * the setup wizard: after Approve or Deny, `#/connect` posts what happened
 * on a BroadcastChannel and the wizard reacts at once. Polling stays the
 * fallback (another browser or device, no BroadcastChannel). The message
 * never holds a token or a secret.
 */
export const SETUP_CHANNEL = "mcpmate";

export interface SetupChannelMessage {
  type: "grant-approved" | "grant-denied";
  grant_id?: string;
  client_name: string;
}

export function postSetupMessage(message: SetupChannelMessage): void {
  if (typeof BroadcastChannel === "undefined") {
    return;
  }

  try {
    const channel = new BroadcastChannel(SETUP_CHANNEL);
    channel.postMessage(message);
    channel.close();
  } catch {
    // Not available (privacy mode): the wizard's polling still sees it.
  }
}

/**
 * Listen for approval-screen messages; returns the unsubscribe function.
 */
export function onSetupMessage(
  listener: (message: SetupChannelMessage) => void,
): () => void {
  if (typeof BroadcastChannel === "undefined") {
    return () => undefined;
  }

  let channel: BroadcastChannel;
  try {
    channel = new BroadcastChannel(SETUP_CHANNEL);
  } catch {
    return () => undefined;
  }

  channel.onmessage = (event: MessageEvent) => {
    const data = event.data as Partial<SetupChannelMessage> | null;

    if (
      data &&
      (data.type === "grant-approved" || data.type === "grant-denied") &&
      typeof data.client_name === "string"
    ) {
      listener({
        type: data.type,
        client_name: data.client_name,
        grant_id: typeof data.grant_id === "string" ? data.grant_id : undefined,
      });
    }
  };

  return () => channel.close();
}
