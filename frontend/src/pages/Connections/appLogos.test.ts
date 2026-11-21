import { describe, expect, it } from "vitest";

import { AI_CLIENTS } from "@/data/aiClients";
import { clientLogo, verifiedLogo } from "./appLogos";

/** Small files are inlined as data URIs, so compare with each logo's own URL. */
const logo = (id: string) => {
  const url = clientLogo(id);
  expect(url, id).toBeDefined();
  return url;
};

describe("AI app logos", () => {
  it("shows a brand's logo for an app its own domain verified", () => {
    expect(verifiedLogo("claude.ai", "Claude")).toBe(logo("claude"));
    expect(verifiedLogo("chatgpt.com", "ChatGPT")).toBe(logo("chatgpt"));
    expect(verifiedLogo("chat.mistral.ai", "Le Chat")).toBe(logo("mistral"));
    expect(verifiedLogo("Zed.dev", "Zed")).toBe(logo("zed"));
  });

  it("tells a brand's products apart by name", () => {
    expect(verifiedLogo("claude.ai", "Claude Code")).toBe(logo("claude-code"));
    expect(verifiedLogo("github.com", "Copilot")).toBe(logo("copilot"));
    // github.com vouches for more than Copilot: no logo for anything else.
    expect(verifiedLogo("github.com", "Some tool")).toBeUndefined();
  });

  it("never lends a brand's logo to an app the brand did not verify", () => {
    expect(verifiedLogo(null, "Claude")).toBeUndefined();
    expect(verifiedLogo("example.com", "ChatGPT")).toBeUndefined();
    expect(verifiedLogo("claude.ai.example.com", "Claude")).toBeUndefined();
    expect(verifiedLogo("notclaude.ai", "Claude")).toBeUndefined();
  });

  it("has a logo for every app the Connect dialog has steps for", () => {
    for (const client of AI_CLIENTS) {
      expect(clientLogo(client.id), client.id).toBeDefined();
    }
  });
});
