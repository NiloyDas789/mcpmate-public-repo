/**
 * AI app logos for the Connections page: `resource/ai-apps/<id>.svg`.
 * Sources: LobeHub Icons (`@lobehub/icons-static-svg` 1.100.0, MIT) and,
 * for Zed and Raycast, Simple Icons 16.36.0 (CC0). The marks are their
 * owners' trademarks, shown only to name the app they belong to.
 *
 * A self-registered client can call itself anything, so a stored app shows
 * a brand's logo only when that brand's own domain verified it (its client
 * ID metadata document); any other app gets its initial instead.
 */
const files = import.meta.glob<string>("../../resource/ai-apps/*.svg", {
  eager: true,
  query: "?url",
  import: "default",
});

const LOGOS: Record<string, string> = Object.fromEntries(
  Object.entries(files).map(([path, url]) => [
    path.replace(/^.*\/([^/]+)\.svg$/, "$1"),
    url,
  ]),
);

/**
 * Each brand's domains, and its products' logos: the first whose name
 * matches wins, and one without a name is the brand's default.
 */
const BRANDS: { domains: string[]; logos: { id: string; name?: RegExp }[] }[] =
  [
    {
      domains: ["claude.ai", "claude.com", "anthropic.com"],
      logos: [{ id: "claude-code", name: /claude\s*code/i }, { id: "claude" }],
    },
    {
      domains: ["chatgpt.com", "openai.com"],
      logos: [{ id: "codex", name: /codex/i }, { id: "chatgpt" }],
    },
    { domains: ["cursor.com", "cursor.sh"], logos: [{ id: "cursor" }] },
    { domains: ["windsurf.com", "codeium.com"], logos: [{ id: "windsurf" }] },
    { domains: ["zed.dev"], logos: [{ id: "zed" }] },
    { domains: ["raycast.com"], logos: [{ id: "raycast" }] },
    { domains: ["github.com"], logos: [{ id: "copilot", name: /copilot/i }] },
    { domains: ["perplexity.ai"], logos: [{ id: "perplexity" }] },
    { domains: ["mistral.ai"], logos: [{ id: "mistral" }] },
    { domains: ["gemini.google.com"], logos: [{ id: "gemini" }] },
  ];

const onDomain = (host: string, domain: string) =>
  host === domain || host.endsWith(`.${domain}`);

/** The logo of an app its own brand verified, by the host that vouched for it. */
export function verifiedLogo(
  host: string | null | undefined,
  name: string,
): string | undefined {
  const domain = host?.toLowerCase();

  if (!domain) {
    return undefined;
  }

  const brand = BRANDS.find((entry) =>
    entry.domains.some((each) => onDomain(domain, each)),
  );
  const logo = brand?.logos.find((each) => !each.name || each.name.test(name));

  return logo ? LOGOS[logo.id] : undefined;
}

/** Connect-dialog app ids whose logo file has another name. */
const CLIENT_LOGOS: Record<string, string> = {
  vscode: "copilot",
  "gemini-cli": "gemini",
};

/** The logo of one of the apps the Connect dialog has steps for. */
export function clientLogo(id: string): string | undefined {
  return LOGOS[CLIENT_LOGOS[id] ?? id];
}
