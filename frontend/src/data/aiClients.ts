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
  id:
    | "claude"
    | "chatgpt"
    | "cursor"
    | "claude-code"
    | "codex"
    | "gemini-cli"
    | "vscode"
    | "windsurf"
    | "antigravity"
    | "zed"
    | "cline"
    | "continue"
    | "amp";
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

/**
 * More AI apps, in the Connect dialog after the four the setup wizard
 * offers. Each was checked against its vendor's own docs only, not run in
 * the app (AGENTS.md, gap 31).
 */
export const MORE_AI_CLIENTS: AiClient[] = [
  {
    id: "codex",
    name: "Codex CLI",
    docsUrl: "https://learn.chatgpt.com/docs/extend/mcp?surface=cli",
    checkedOn: "2026-11-16",
    desktopRedirect: true,
    steps: (mcpUrl) => [
      {
        text: __("In your terminal, run:", "mcpmate"),
        code: `codex mcp add wordpress --url ${mcpUrl}`,
      },
      {
        text: __(
          "Then run **codex mcp login wordpress** and sign in on the page that opens.",
          "mcpmate",
        ),
      },
      {
        text: __("Approve on the page that opens on this site.", "mcpmate"),
      },
    ],
    note: () =>
      __(
        "In the Codex app or IDE extension, open **MCP servers**, select **Add server**, choose **Streamable HTTP**, then **Authenticate**.",
        "mcpmate",
      ),
  },
  {
    id: "gemini-cli",
    name: "Gemini CLI",
    docsUrl: "https://geminicli.com/docs/tools/mcp-server/",
    checkedOn: "2026-11-16",
    desktopRedirect: true,
    steps: (mcpUrl) => [
      {
        text: __("In your terminal, run:", "mcpmate"),
        code: `gemini mcp add --transport http wordpress ${mcpUrl}`,
      },
      {
        text: __(
          "Start Gemini CLI, type **/mcp auth wordpress** and sign in on the page that opens.",
          "mcpmate",
        ),
      },
      {
        text: __("Approve on the page that opens on this site.", "mcpmate"),
      },
    ],
    note: () =>
      __(
        "Google replaced Gemini CLI with Antigravity CLI for some plans: if you use Antigravity, follow its steps instead.",
        "mcpmate",
      ),
  },
  {
    id: "vscode",
    name: "VS Code + Copilot",
    docsUrl:
      "https://code.visualstudio.com/docs/agent-customization/mcp-servers",
    checkedOn: "2026-11-16",
    desktopRedirect: true,
    steps: (mcpUrl) => [
      {
        text: __(
          "Add this to ~/.copilot/mcp-config.json (or .mcp.json in your workspace):",
          "mcpmate",
        ),
        code: `{ "mcpServers": { "wordpress": { "type": "http", "url": "${mcpUrl}" } } }`,
      },
      {
        text: __(
          "Or, in a Copilot chat, run **Chat: Open Customizations**, select **MCP Servers**, **Add Server**, then **HTTP (HTTP or Server-Sent Events)** and paste the MCP URL. Sign in when asked, or select **Authenticate**.",
          "mcpmate",
        ),
      },
      {
        text: __("Approve on the page that opens on this site.", "mcpmate"),
      },
    ],
  },
  {
    id: "windsurf",
    name: "Windsurf",
    docsUrl: "https://docs.devin.ai/desktop/cascade/mcp",
    checkedOn: "2026-11-16",
    desktopRedirect: true,
    steps: (mcpUrl) => [
      {
        text: __(
          "Add this to ~/.config/devin/mcp_config.json (create it if it doesn't exist):",
          "mcpmate",
        ),
        code: `{ "mcpServers": { "wordpress": { "url": "${mcpUrl}", "transport": "http" } } }`,
      },
      {
        text: __(
          "Sign in when asked the first time it is used, or open **Customize**, the **MCPs** tab, and select **Authenticate**.",
          "mcpmate",
        ),
      },
      {
        text: __("Approve on the page that opens on this site.", "mcpmate"),
      },
    ],
    note: () =>
      __(
        "Windsurf's docs now live under Devin Desktop; an older Windsurf may keep its MCP settings elsewhere.",
        "mcpmate",
      ),
  },
  {
    id: "antigravity",
    name: "Antigravity",
    docsUrl: "https://antigravity.google/docs/mcp",
    checkedOn: "2026-11-16",
    desktopRedirect: true,
    steps: (mcpUrl) => [
      {
        text: __(
          "Add this to ~/.gemini/config/mcp_config.json (in the IDE: **…** at the top of the agent panel, **MCP Servers**, **Manage MCP Servers**, **View raw config**):",
          "mcpmate",
        ),
        code: `{ "mcpServers": { "wordpress": { "serverUrl": "${mcpUrl}" } } }`,
      },
      {
        text: __(
          "Under **MCP Servers**, select **Authenticate**, sign in, then paste the code into **Enter authorization code** and select **Submit**.",
          "mcpmate",
        ),
      },
      {
        text: __("Approve on the page that opens on this site.", "mcpmate"),
      },
    ],
  },
  {
    id: "zed",
    name: "Zed",
    docsUrl: "https://zed.dev/docs/ai/mcp",
    checkedOn: "2026-11-16",
    desktopRedirect: true,
    steps: (mcpUrl) => [
      {
        text: __(
          "Open **Settings → AI → MCP Servers**, select **Add Server**, then **Add Remote Server**, and paste the MCP URL. Or add this to your settings file:",
          "mcpmate",
        ),
        code: `{ "context_servers": { "wordpress": { "url": "${mcpUrl}" } } }`,
      },
      {
        text: __("Sign in when Zed asks.", "mcpmate"),
      },
      {
        text: __("Approve on the page that opens on this site.", "mcpmate"),
      },
    ],
  },
  {
    id: "cline",
    name: "Cline",
    docsUrl: "https://docs.cline.bot/mcp/connecting-to-a-remote-server",
    checkedOn: "2026-11-16",
    desktopRedirect: true,
    steps: () => [
      {
        text: __(
          "Select the MCP Servers icon, open the **Remote Servers** tab, enter a **Server Name** and the MCP URL as **Server URL**, choose **Streamable HTTP**, then **Add Server**.",
          "mcpmate",
        ),
      },
      {
        text: __(
          "Sign in when Cline asks. With the Cline CLI, run **cline mcp** and choose **Authorize OAuth**.",
          "mcpmate",
        ),
      },
      {
        text: __("Approve on the page that opens on this site.", "mcpmate"),
      },
    ],
  },
  {
    id: "continue",
    name: "Continue",
    docsUrl: "https://docs.continue.dev/customize/deep-dives/mcp",
    checkedOn: "2026-11-16",
    desktopRedirect: true,
    steps: (mcpUrl) => [
      {
        text: __("Add this to your config.yaml:", "mcpmate"),
        code: `mcpServers:\n  - name: wordpress\n    type: streamable-http\n    url: ${mcpUrl}`,
      },
      {
        text: __(
          "Use it in **agent** mode. If Continue opens a sign-in page, sign in and approve on the page that opens on this site.",
          "mcpmate",
        ),
      },
    ],
    note: () =>
      __(
        "Continue's docs don't describe signing in to a remote server. If no sign-in page opens, Continue can't connect to this site yet.",
        "mcpmate",
      ),
  },
  {
    id: "amp",
    name: "Amp",
    docsUrl: "https://ampcode.com/docs/customize/mcp",
    checkedOn: "2026-11-16",
    desktopRedirect: true,
    steps: (mcpUrl) => [
      {
        text: __("In your terminal, run:", "mcpmate"),
        code: `amp mcp add wordpress ${mcpUrl}`,
      },
      {
        text: __(
          "Start Amp: it opens the sign-in page in your browser.",
          "mcpmate",
        ),
      },
      {
        text: __("Approve on the page that opens on this site.", "mcpmate"),
      },
    ],
  },
];

/** Every AI app the Connect dialog has steps for. */
export function availableClients(): AiClient[] {
  return [...AI_CLIENTS, ...MORE_AI_CLIENTS];
}

export function findClient(id: string): AiClient {
  return availableClients().find((client) => client.id === id) ?? AI_CLIENTS[0];
}
