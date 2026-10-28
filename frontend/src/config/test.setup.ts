import "@testing-library/jest-dom";

/**
 * Mock WordPress-injected global config.
 *
 * In the browser, Vite rewrites `SERVER_VARIABLES` to `window.MCPMATE_`
 * (see vite.config.ts). Tests skip that define, so the global is set here.
 */
const serverVariables: Partial<typeof SERVER_VARIABLES> = {
  nonce: "test-nonce",
  restNonce: "test-rest-nonce",
  rootURL: "http://localhost",
  siteURL: "http://localhost",
  ajaxURL: "http://localhost/wp-admin/admin-ajax.php",
  apiURL: { base: "http://localhost/wp-json/mcpmate/v1", separator: "?" },
  routePrefix: "MCPMATE_",
  pluginSlug: "mcpmate",
  pluginName: "McpMate",
  version: "1.0.0",
  connection: {
    mcp_url: "http://localhost/wp-json/mcpmate/mcp",
    issuer: "http://localhost/mcpmate",
    home_url: "http://localhost",
    public_url: "",
    open_registration: true,
  },
  connectRequest: null,
  onboarding: { status: "completed", step: "first_call" },
  lang: "en_US",
  grants_live: 0,
  pending_approvals: 0,
  rtl: false,
  links: { docs: "", support: "", review: "", feedback: "" },
  debug: false,
};

(globalThis as Record<string, unknown>).SERVER_VARIABLES = serverVariables;
(globalThis as Record<string, unknown>).MCPMATE_ = serverVariables;
