import type {
  CliCommand,
  CliRisk,
  Grant,
  OnboardingState,
  OnboardingStep,
} from "../../types/api";

/** The wizard's decisions, kept apart from the components so they are tested on their own. */

export const ONBOARDING_QUERY_KEY = ["onboarding"];

/** UTC `Y-m-d H:i:s` or ISO 8601 → epoch ms (NaN when empty). */
export function utcMs(value: string | null | undefined): number {
  if (!value) {
    return Number.NaN;
  }

  return Date.parse(
    value.includes("T") ? value : `${value.replace(" ", "T")}Z`,
  );
}

/** Where to open: a finished or unstarted setup starts at Welcome, anything else resumes. */
export function initialStep(onboarding: OnboardingState): OnboardingStep {
  return onboarding.status === "completed" ||
    onboarding.status === "not_started"
    ? "welcome"
    : onboarding.step;
}

export const isLive = (grant: Grant) =>
  grant.status === "pending" || grant.status === "active";

/**
 * AI apps approved since the Add it to your AI step opened (`connect_since`), newest first.
 */
export function newGrants(
  grants: Grant[],
  connectSince: string | null,
): Grant[] {
  const since = utcMs(connectSince);

  if (Number.isNaN(since)) {
    return [];
  }

  return grants
    .filter((grant) => isLive(grant) && utcMs(grant.created_at) >= since)
    .sort((a, b) => utcMs(b.created_at) - utcMs(a.created_at));
}

export type Bundle = "on" | "off" | "custom";

/**
 * Whether every command of `risk` is enabled, none is, or only some
 * (set in Settings: the wizard then never overwrites the selection).
 */
export function bundleState(commands: CliCommand[], risk: CliRisk): Bundle {
  const ofRisk = commands.filter((command) => command.risk === risk);
  const on = ofRisk.filter((command) => command.enabled).length;

  if (ofRisk.length === 0 || on === 0) {
    return "off";
  }

  return on === ofRisk.length ? "on" : "custom";
}

/**
 * The enable list after switching the `risk` bundle on (every command of
 * that risk added) or off (only those removed).
 */
export function applyBundle(
  enabled: string[],
  commands: CliCommand[],
  risk: CliRisk,
  on: boolean,
): string[] {
  const names = commands
    .filter((command) => command.risk === risk)
    .map((command) => command.name);

  return on
    ? Array.from(new Set([...enabled, ...names]))
    : enabled.filter((name) => !names.includes(name));
}
