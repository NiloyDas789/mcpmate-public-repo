/**
 * Type declarations for the WordPress-injected global config.
 */

declare const SERVER_VARIABLES: {
  nonce: string;
  restNonce: string;
  rootURL: string;
  siteURL: string;
  siteBaseURL: string;
  assetsURL: string;
  pluginAdminURL: string;
  ajaxURL: string;
  apiURL: {
    base: string;
    separator: string;
  };
  routePrefix: string;
  dateFormat: string;
  timeFormat: string;
  timeZone: string;
  pluginSlug: string;
  pluginName: string;
  version: string;
  /** The MCP URL and OAuth issuer AI apps connect to. */
  connection: import("./api").ConnectionSummary;
  /**
   * The AI-app authorization request in `?connect=<id>`, checked by PHP.
   * Null when the page was opened without one.
   */
  connectRequest: import("./api").ConnectRequest | null;
  /** First-run setup progress (Dashboard card, Settings link). */
  onboarding: {
    status: import("./api").OnboardingStatus;
    step: import("./api").OnboardingStep;
  };
  lang: string;
  /** Live (pending or active) grants: the status pill's count. */
  grants_live: number;
  /** Approval requests waiting for a site admin (not expired). */
  pending_approvals: number;
  /** The admin is in a right-to-left locale (`is_rtl()`). */
  rtl: boolean;
  /** Help and footer links; '' hides a link (docs/ui.md B14). */
  links: { docs: string; support: string; review: string; feedback: string };
  /** WP_DEBUG is on: the UI kit page (`#/ui-kit`) exists. */
  debug: boolean;
  /**
   * Pause AI access (docs/ui.md B3): `at` (ISO 8601 UTC) and `by` are set
   * while paused. Optional so the UI kit harness can leave it out.
   */
  access?: import("./api").AccessState;
  /**
   * Jed-formatted locale data for `@wordpress/i18n`, injected by PHP for
   * non-English locales. Absent when the catalog is missing or the site runs
   * in en_US.
   */
  translations?: Record<
    string,
    string[] | { domain?: string; lang?: string; plural_forms?: string }
  >;
};

declare module "*.module.css" {
  const classes: { readonly [key: string]: string };
  export default classes;
}

declare module "*.svg" {
  const content: string;
  export default content;
}
