import { useCallback, useEffect, useRef, useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { CheckCircleFilled, RightCircleFilled } from "@ant-design/icons";
import { Button, Popconfirm, Spin } from "antd";
import { useNavigate } from "react-router";
import { __ } from "@wordpress/i18n";
import { fetchOnboardingState, updateOnboarding } from "@services/api";
import type { OnboardingStateResponse, OnboardingStep } from "../../types/api";
import { STEPS, stepPosition, stepTitle } from "./labels";
import { initialStep, ONBOARDING_QUERY_KEY } from "./logic";
import { AjaxErrorAlert, type StepProps } from "./shared";
import AddAi from "./steps/AddAi";
import Check from "./steps/Check";
import FirstCall from "./steps/FirstCall";
import Safety from "./steps/Safety";
import Welcome from "./steps/Welcome";

const STEP_COMPONENTS: Record<
  OnboardingStep,
  (props: StepProps) => JSX.Element
> = {
  welcome: Welcome,
  check: Check,
  safety: Safety,
  add_ai: AddAi,
  first_call: FirstCall,
};

function ProgressList({ current }: { current: OnboardingStep }) {
  const index = STEPS.indexOf(current);

  return (
    <ol
      aria-label={__("Setup progress", "mcpmate")}
      className="m-0 mb-6 flex list-none flex-wrap gap-x-4 gap-y-1 p-0"
    >
      {STEPS.map((step, position) => {
        const done = position < index;
        const isCurrent = position === index;

        return (
          <li
            key={step}
            aria-current={isCurrent ? "step" : undefined}
            className={`flex items-center gap-1 text-sm ${isCurrent ? "font-semibold text-[#1d2327]" : "text-[#50575e]"}`}
          >
            <span aria-hidden="true">
              {done ? (
                <CheckCircleFilled className="text-[#00a32a]" />
              ) : isCurrent ? (
                <RightCircleFilled className="text-[#2271b1]" />
              ) : (
                <span className="inline-block h-[14px] w-[14px] rounded-full border border-solid border-[#8c8f94]" />
              )}
            </span>
            <span>
              {position + 1}. {stepTitle(step)}
            </span>
            <span className="sr-only">
              {done
                ? __("(done)", "mcpmate")
                : isCurrent
                  ? __("(current)", "mcpmate")
                  : __("(not started)", "mcpmate")}
            </span>
          </li>
        );
      })}
    </ol>
  );
}

/**
 * The first-run setup wizard (`#/setup`, opened by `?setup=1`): from
 * "plugin activated" to "my AI made its first call". Every step change is
 * saved (admin-ajax `onboarding/update`), so a reload resumes where the
 * admin was, and steps that wait for something resume waiting.
 */
export default function Setup() {
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const [step, setStep] = useState<OnboardingStep | null>(null);
  const [announcement, setAnnouncement] = useState("");
  const [saveError, setSaveError] = useState<{
    error: unknown;
    retry: () => void;
  } | null>(null);
  const skipTrigger = useRef<HTMLButtonElement>(null);

  const { data, error, refetch } = useQuery({
    queryKey: ONBOARDING_QUERY_KEY,
    queryFn: fetchOnboardingState,
    staleTime: 0,
    refetchOnMount: "always",
  });

  useEffect(() => {
    if (data && step === null) {
      setStep(initialStep(data.onboarding));
    }
  }, [data, step]);

  // "Step 2 of 5: Check your site ‹ McpMate setup"
  useEffect(() => {
    const original = document.title;

    return () => {
      document.title = original;
    };
  }, []);
  useEffect(() => {
    if (step) {
      document.title = `${stepPosition(step)} ‹ ${__("McpMate setup", "mcpmate")}`;
    }
  }, [step]);

  const announce = useCallback((text: string) => {
    // Cleared first, so the same text is announced again when it recurs.
    setAnnouncement("");
    setTimeout(() => setAnnouncement(text), 50);
  }, []);

  const refresh = useCallback(async () => (await refetch()).data, [refetch]);

  const save = useCallback(
    (changes: Parameters<typeof updateOnboarding>[0], then?: () => void) => {
      const attempt = () => {
        setSaveError(null);
        updateOnboarding(changes)
          .then((onboarding) => {
            SERVER_VARIABLES.onboarding = {
              status: onboarding.status,
              step: onboarding.step,
            };
            queryClient.setQueryData<OnboardingStateResponse>(
              ONBOARDING_QUERY_KEY,
              (current) => (current ? { ...current, onboarding } : current),
            );
            void refetch();
            then?.();
          })
          .catch((caught: unknown) =>
            setSaveError({ error: caught, retry: attempt }),
          );
      };
      attempt();
    },
    [queryClient, refetch],
  );

  const goTo = useCallback(
    (next: OnboardingStep, extra: { grant_id?: string } = {}) => {
      setStep(next);
      save({ step: next, status: "in_progress", ...extra });
    },
    [save],
  );

  const finish = useCallback(
    (to = "/") => save({ status: "completed" }, () => navigate(to)),
    [save, navigate],
  );

  const skip = () => save({ status: "skipped" }, () => navigate("/"));

  if (error && !data) {
    return (
      <div className="mx-auto max-w-[720px]">
        <AjaxErrorAlert error={error} onRetry={() => void refetch()} />
      </div>
    );
  }

  if (!data || !step) {
    return (
      <div
        className="flex min-h-[300px] items-center justify-center"
        aria-busy="true"
      >
        <Spin />
      </div>
    );
  }

  const StepComponent = STEP_COMPONENTS[step];

  return (
    <div className="mcpmate-setup mx-auto max-w-[720px]">
      <div className="mb-2 flex items-center justify-between gap-3">
        <h1 className="m-0 text-sm font-normal text-[#50575e]">
          {__("McpMate setup", "mcpmate")}
        </h1>
        <Popconfirm
          title={__(
            "Skip setup? You can come back to it from Settings at any time.",
            "mcpmate",
          )}
          okText={__("Skip", "mcpmate")}
          cancelText={__("Keep going", "mcpmate")}
          onConfirm={skip}
          onOpenChange={(open) => {
            if (!open) {
              skipTrigger.current?.focus();
            }
          }}
        >
          <Button ref={skipTrigger} type="link" className="!px-0">
            {__("Skip setup", "mcpmate")}
          </Button>
        </Popconfirm>
      </div>

      <p className="sr-only">{stepPosition(step)}</p>
      <ProgressList current={step} />

      {saveError && (
        <AjaxErrorAlert error={saveError.error} onRetry={saveError.retry} />
      )}

      <div className="rounded-lg bg-white p-6 shadow-sm">
        <StepComponent
          key={step}
          state={data}
          refresh={refresh}
          goTo={goTo}
          announce={announce}
          finish={finish}
        />
      </div>

      <div aria-live="polite" role="status" className="sr-only">
        {announcement}
      </div>
    </div>
  );
}
