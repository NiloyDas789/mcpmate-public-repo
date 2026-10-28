import { __ } from "@wordpress/i18n";

/**
 * How to add this site's MCP URL to each AI app, shared by the Connection
 * page and the setup wizard. Vendors change their menus: re-check each
 * entry against its `docsUrl` and update `checkedOn` when they do.
 *
 * Strings are built inside functions so `__()` runs at render with literal
 * strings. Menu labels stay in English in bold, because they are the
 * vendor's own UI words.
 */
export interface ClientStep {
  /** Plain text; `**…**` marks a vendor UI label. */
  text: string;
  /** Copyable command or snippet. */
  code?: string;
}

export interface AiClient {
  id: "claude" | "chatgpt" | "cursor" | "claude-code";
  /** Product name, not translated. */
  name: string;
  /** Vendor help page, shown as "Steps look different?". */
  docsUrl: string;
  /** Date the steps were checked against docsUrl (YYYY-MM-DD). */
  checkedOn: string;
  steps: (mcpUrl: string) => ClientStep[];
  note?: () => string;
  /** The approval screen will show the "this computer" (localhost) warning. */
  desktopRedirect: boolean;
}

const desktopNote = () =>
  __(
    'The approval page will warn about "this computer". That\'s expected for desktop apps.',
    "mcpmate",
  );

export const AI_CLIENTS: AiClient[] = [
  {
    id: "claude",
    name: "Claude",
    docsUrl:
      "https://support.claude.com/en/articles/11175166-getting-started-with-custom-connectors-using-remote-mcp",
    checkedOn: "2026-10-26",
    desktopRedirect: false,
    steps: () => [
      {
        text: __(
          "In Claude, open **Customize → Connectors**, select **+ Add**, then **Add custom connector**.",
          "mcpmate",
        ),
      },
      {
        text: __(
          'Name it (for example "My WordPress site"), paste the MCP URL and select **Continue**. Keep the sign-in settings Claude detects, choose **Sign in now**, then **Add**.',
          "mcpmate",
        ),
      },
      {
        text: __(
          "Approve the request on the page that opens on this site. To use it in a chat, select **+ → Connectors** and turn it on.",
          "mcpmate",
        ),
      },
    ],
    note: () =>
      __(
        "On Team and Enterprise plans, an owner adds the connector under **Organization settings → Connectors**; you then select **Connect**. The free plan allows one custom connector.",
        "mcpmate",
      ),
  },
  {
    id: "chatgpt",
    name: "ChatGPT",
    docsUrl: "https://developers.openai.com/apps-sdk/deploy/connect-chatgpt",
    checkedOn: "2026-10-26",
    desktopRedirect: false,
    steps: () => [
      {
        text: __(
          "Open **chatgpt.com/plugins**, select **+**, then **Add custom MCP server**.",
          "mcpmate",
        ),
      },
      {
        text: __(
          "Enter a name, paste the MCP URL under **Connection**, choose OAuth sign-in, confirm **I understand and want to continue**, then select **Create as a plugin**.",
          "mcpmate",
        ),
      },
      {
        text: __(
          "Sign in when asked, and approve on the page that opens on this site. In a chat, type **@** and pick it.",
          "mcpmate",
        ),
      },
    ],
    note: () =>
      __(
        "Your ChatGPT plan or workspace admin decides whether custom MCP servers are allowed.",
        "mcpmate",
      ),
  },
  {
    id: "cursor",
    name: "Cursor",
    docsUrl: "https://cursor.com/docs/context/mcp",
    checkedOn: "2026-10-26",
    desktopRedirect: true,
    steps: (mcpUrl) => [
      {
        text: __(
          "Open ~/.cursor/mcp.json (create it if it doesn't exist) and add:",
          "mcpmate",
        ),
        code: `{ "mcpServers": { "wordpress": { "url": "${mcpUrl}" } } }`,
      },
      {
        text: __(
          'In Cursor, open the **Customize** page, find "wordpress" in your MCP servers and start its sign-in.',
          "mcpmate",
        ),
      },
      {
        text: __("Approve on the page that opens in your browser.", "mcpmate"),
      },
    ],
    note: desktopNote,
  },
  {
    id: "claude-code",
    name: "Claude Code",
    docsUrl: "https://code.claude.com/docs/en/mcp",
    checkedOn: "2026-10-26",
    desktopRedirect: true,
    steps: (mcpUrl) => [
      {
        text: __(
          "In your terminal, run this (add --scope user to use it in every project):",
          "mcpmate",
        ),
        code: `claude mcp add --transport http wordpress ${mcpUrl}`,
      },
      {
        text: __(
          "Start Claude Code, type **/mcp**, pick **wordpress** and sign in. Or run **claude mcp login wordpress**.",
          "mcpmate",
        ),
      },
      {
        text: __("Approve on the page that opens in your browser.", "mcpmate"),
      },
    ],
    note: desktopNote,
  },
];

export function findClient(id: string): AiClient {
  return AI_CLIENTS.find((client) => client.id === id) ?? AI_CLIENTS[0];
}
