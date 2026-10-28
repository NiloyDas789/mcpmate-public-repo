import { __, _n, sprintf } from "@wordpress/i18n";
import { STEPS } from "@pages/Setup/labels";
import type { NoticeTone } from "@components/ui/Notice";
import type { StatusGlyph, StatusTone } from "@components/ui/StatusDot";
import type { OnboardingStatus, OnboardingStep } from "../../types/api";

/** What the top bar shows: pure, from `SERVER_VARIABLES` (docs/ui.md §3.3). */
export interface ShellData {
  grantsLive: number;
  paused: boolean;
  pendingApprovals: number;
  onboarding: { status: OnboardingStatus; step: OnboardingStep };
}

/**
 * From `SERVER_VARIABLES`, with what this tab learned since the page loaded
 * (a pause, a revoke, a new AI app) in `live`.
 */
export function shellData(live: Partial<ShellData> = {}): ShellData {
  return {
    grantsLive: SERVER_VARIABLES.grants_live ?? 0,
    paused: Boolean(SERVER_VARIABLES.access?.paused),
    pendingApprovals: SERVER_VARIABLES.pending_approvals ?? 0,
    onboarding: SERVER_VARIABLES.onboarding,
    ...live,
  };
}

export interface PillState {
  tone: StatusTone;
  text: string;
  glyph?: StatusGlyph;
}

/** The status pill: the first condition that holds (docs/ui.md §3.3). */
export function pillState(data: ShellData): PillState {
  if (data.paused) {
    return {
      tone: "warning",
      glyph: "pause",
      text: __("AI access paused", "mcpmate"),
    };
  }

  if (data.grantsLive === 0) {
    return {
      tone: "neutral",
      glyph: "hollow",
      text: __("No AI apps connected", "mcpmate"),
    };
  }

  return {
    tone: "success",
    text: sprintf(
      /* translators: %d: number of connected AI apps. */
      _n(
        "%d AI app connected",
        "%d AI apps connected",
        data.grantsLive,
        "mcpmate",
      ),
      data.grantsLive,
    ),
  };
}

export interface ShellNotice {
  key: string;
  tone: NoticeTone;
  title: string;
  action?: { label: string; href: string };
}

/**
 * The notices under the top bar, at most two, in a fixed order: paused,
 * approvals waiting, setup not finished. The
 * Dashboard has its own setup card, so the setup notice stays off it.
 */
export function shellNotices(data: ShellData, pathname: string): ShellNotice[] {
  const notices: ShellNotice[] = [];

  if (data.paused) {
    notices.push({
      key: "paused",
      tone: "warning",
      title: __(
        "AI access is paused. AI apps get an error until you resume it.",
        "mcpmate",
      ),
    });
  }

  if (data.pendingApprovals > 0) {
    notices.push({
      key: "approvals",
      tone: "info",
      title: sprintf(
        /* translators: %d: number of approval requests. */
        _n(
          "%d request is waiting for your approval.",
          "%d requests are waiting for your approval.",
          data.pendingApprovals,
          "mcpmate",
        ),
        data.pendingApprovals,
      ),
      action: {
        label: __("Review requests", "mcpmate"),
        href: "#/activity/approvals",
      },
    });
  }

  const setupOpen =
    data.onboarding.status === "not_started" ||
    data.onboarding.status === "in_progress";
  if (setupOpen && pathname !== "/") {
    notices.push({
      key: "setup",
      tone: "info",
      title: sprintf(
        /* translators: 1: current step number, 2: number of steps. */
        __("Finish setting up. You're on step %1$d of %2$d.", "mcpmate"),
        Math.max(STEPS.indexOf(data.onboarding.step), 0) + 1,
        STEPS.length,
      ),
      action: { label: __("Resume setup", "mcpmate"), href: "#/setup" },
    });
  }

  return notices.slice(0, 2);
}
