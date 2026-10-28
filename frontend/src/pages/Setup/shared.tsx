import type { ReactNode } from "react";
import { useEffect, useRef } from "react";
import {
  CheckCircleFilled,
  ClockCircleOutlined,
  CloseCircleFilled,
  ExclamationCircleFilled,
  InfoCircleFilled,
  LoadingOutlined,
} from "@ant-design/icons";
import { Alert, Button } from "antd";
import { __, sprintf } from "@wordpress/i18n";
import { AjaxRequestError } from "@services/api";
import type { OnboardingStateResponse, OnboardingStep } from "../../types/api";

/** What every step gets from the wizard frame. */
export interface StepProps {
  state: OnboardingStateResponse;
  /** Re-read onboarding/state. */
  refresh: () => Promise<OnboardingStateResponse | undefined>;
  /**
   * Show another step (saved with onboarding/update, with `grant_id` in
   * the same request when given; Back never undoes anything).
   */
  goTo: (step: OnboardingStep, extra?: { grant_id?: string }) => void;
  /** Put a message in the page's live region (once per result). */
  announce: (text: string) => void;
  /** Mark setup completed and open the Dashboard (or `to`). */
  finish: (to?: string) => void;
}

export type StatusKind =
  | "pass"
  | "warn"
  | "fail"
  | "info"
  | "later"
  | "waiting"
  | "checking";

/**
 * An icon and a word for a status: never colour alone. The icon is hidden
 * from screen readers next to the visible word.
 */
export function StatusWord({ status }: { status: StatusKind }) {
  const [icon, word, colour] = ((): [ReactNode, string, string] => {
    switch (status) {
      case "pass":
        return [
          <CheckCircleFilled key="i" />,
          __("Passed", "mcpmate"),
          "text-[#00a32a]",
        ];
      case "warn":
        return [
          <ExclamationCircleFilled key="i" />,
          __("Warning", "mcpmate"),
          "text-[#996800]",
        ];
      case "fail":
        return [
          <CloseCircleFilled key="i" />,
          __("Failed", "mcpmate"),
          "text-[#d63638]",
        ];
      case "info":
        return [
          <InfoCircleFilled key="i" />,
          __("Info", "mcpmate"),
          "text-[#2271b1]",
        ];
      case "later":
        return [
          <ClockCircleOutlined key="i" />,
          __("Later", "mcpmate"),
          "text-[#50575e]",
        ];
      case "waiting":
        return [
          <LoadingOutlined key="i" />,
          __("Waiting", "mcpmate"),
          "text-[#50575e]",
        ];
      case "checking":
        return [
          <LoadingOutlined key="i" />,
          __("Checking…", "mcpmate"),
          "text-[#50575e]",
        ];
    }
  })();

  return (
    <span
      className={`inline-flex items-center gap-1 whitespace-nowrap ${colour}`}
    >
      <span aria-hidden="true">{icon}</span>
      <span>{word}</span>
    </span>
  );
}

/**
 * The step's `<h2>`. Focus moves to it when the step opens, so keyboard and
 * screen-reader users start at the top of the new step.
 */
export function StepHeading({ children }: { children: ReactNode }) {
  const ref = useRef<HTMLHeadingElement>(null);

  useEffect(() => {
    ref.current?.focus();
  }, []);

  return (
    <h2
      ref={ref}
      tabIndex={-1}
      className="!mb-3 !mt-0 text-xl font-semibold outline-none"
    >
      {children}
    </h2>
  );
}

/**
 * The §3 errors common to every step: an expired session, an unreachable
 * site, a server error, or the route's own refusal.
 */
export function AjaxErrorAlert({
  error,
  onRetry,
}: {
  error: unknown;
  onRetry: () => void;
}) {
  const kind = error instanceof AjaxRequestError ? error.kind : "server";

  if (kind === "session") {
    return (
      <Alert
        type="error"
        showIcon
        className="mb-4"
        message={__(
          "Your WordPress session has expired. Reload the page to continue. Your progress is saved.",
          "mcpmate",
        )}
        action={
          <Button size="small" onClick={() => window.location.reload()}>
            {__("Reload page", "mcpmate")}
          </Button>
        }
      />
    );
  }

  let text: string;
  if (kind === "network") {
    text = __(
      "Couldn't reach your site. Check your connection and try again.",
      "mcpmate",
    );
  } else if (kind === "refused" && error instanceof Error) {
    text = error.message;
  } else {
    const code =
      error instanceof AjaxRequestError && error.status
        ? String(error.status)
        : error instanceof AjaxRequestError && error.code
          ? error.code
          : "?";
    text = sprintf(
      /* translators: %s: HTTP status or error code. */
      __(
        "Something went wrong on your site (error %s). Try again. If it keeps happening, the audit log or your PHP error log may say more.",
        "mcpmate",
      ),
      code,
    );
  }

  return (
    <Alert
      type="error"
      showIcon
      className="mb-4"
      message={text}
      action={
        <Button size="small" onClick={onRetry}>
          {__("Try again", "mcpmate")}
        </Button>
      }
    />
  );
}

/** Bottom bar: Back on the left (from step 2 on), the primary action on the right. */
export function StepFooter({
  onBack,
  children,
}: {
  onBack?: () => void;
  children?: ReactNode;
}) {
  return (
    <div className="mt-6 flex flex-wrap items-center justify-between gap-3 border-0 border-t border-solid border-[#dcdcde] pt-4">
      <div>
        {onBack && <Button onClick={onBack}>{__("Back", "mcpmate")}</Button>}
      </div>
      <div className="flex flex-wrap items-center gap-3">{children}</div>
    </div>
  );
}
