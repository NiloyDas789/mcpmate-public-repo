import { useEffect, useRef } from "react";
import { useQuery } from "@tanstack/react-query";
import { Button, Typography } from "antd";
import { __ } from "@wordpress/i18n";
import CopyCode from "@components/CopyCode";
import { runChecks } from "@services/api";
import type { ChecksResponse, EnvironmentCheck } from "../../../types/api";
import { pendingChecks } from "../labels";
import { CHECKS_QUERY_KEY, checksSummary } from "../logic";
import {
  AjaxErrorAlert,
  StatusWord,
  StepFooter,
  StepHeading,
  type StepProps,
} from "../shared";

const { Paragraph, Text } = Typography;

function CheckRow({
  check,
  announce,
}: {
  check: EnvironmentCheck;
  announce: (text: string) => void;
}) {
  const needsAction = check.status === "warn" || check.status === "fail";

  return (
    <li className="border-0 border-b border-solid border-[#f0f0f1] py-3">
      <div className="flex flex-wrap items-baseline justify-between gap-2">
        <Text strong>{check.title}</Text>
        <StatusWord status={check.status} />
      </div>
      <div>{check.message}</div>
      {check.details.length > 0 && !needsAction && (
        <div className="mt-1">
          {check.details.map((detail) => (
            <div key={detail.text + (detail.code ?? "")} className="mb-2">
              {detail.text && <Text type="secondary">{detail.text}</Text>}
              {detail.code && (
                <CopyCode
                  code={detail.code}
                  label={__("Copy line", "mcpmate")}
                  onCopied={announce}
                />
              )}
            </div>
          ))}
        </div>
      )}
      {needsAction && (check.fix || check.details.length > 0) && (
        <div className="mt-2 rounded bg-[#f6f7f7] p-3">
          {check.status === "fail" && (
            <Text strong className="block">
              {__("How to fix", "mcpmate")}
            </Text>
          )}
          {check.fix && <div className="mb-2">{check.fix}</div>}
          {check.details.map((detail) => (
            <div key={detail.text + (detail.code ?? "")} className="mb-2">
              {detail.text && <div>{detail.text}</div>}
              {detail.code && (
                <CopyCode
                  code={detail.code}
                  label={__("Copy line", "mcpmate")}
                  onCopied={announce}
                />
              )}
            </div>
          ))}
        </div>
      )}
    </li>
  );
}

/**
 * Step 2: catch the server problems behind most failed setups before the
 * user pastes anything. All checks run server-side in one request
 * (admin-ajax `onboarding/checks`), never cached.
 */
export default function Check({ goTo, announce }: StepProps) {
  const { data, error, isFetching, refetch } = useQuery({
    queryKey: CHECKS_QUERY_KEY,
    queryFn: runChecks,
    staleTime: 0,
    gcTime: 0,
    refetchOnMount: "always",
    retry: false,
  });
  const announced = useRef<ChecksResponse | null>(null);

  useEffect(() => {
    if (isFetching) {
      announce(__("Checking your site…", "mcpmate"));
    } else if (data && announced.current !== data) {
      announced.current = data;
      announce(checksSummary(data.summary));
    }
  }, [isFetching, data, announce]);

  const failed = (data?.summary.fail ?? 0) > 0;
  const warned = (data?.summary.warn ?? 0) > 0;
  const rows = data?.checks ?? [];

  return (
    <>
      <StepHeading>{__("Check your site", "mcpmate")}</StepHeading>
      <Paragraph>
        {__(
          "We're checking that AI apps can reach your site and sign in. Your site only asks itself; none of your content leaves your site.",
          "mcpmate",
        )}
      </Paragraph>

      {error && !isFetching ? (
        <>
          <Paragraph strong>
            {__("We couldn't run the checks.", "mcpmate")}
          </Paragraph>
          <AjaxErrorAlert error={error} onRetry={() => void refetch()} />
        </>
      ) : (
        <div aria-busy={isFetching}>
          <ul className="m-0 list-none p-0">
            {isFetching || !data
              ? pendingChecks().map((check) => (
                  <li
                    key={check.id}
                    className="flex flex-wrap items-baseline justify-between gap-2 border-0 border-b border-solid border-[#f0f0f1] py-3"
                  >
                    <Text strong>{check.title}</Text>
                    <StatusWord status="checking" />
                  </li>
                ))
              : rows.map((check) => (
                  <CheckRow key={check.id} check={check} announce={announce} />
                ))}
          </ul>
          {data && !isFetching && (
            <Paragraph strong className="!mt-4">
              {checksSummary(data.summary)}
            </Paragraph>
          )}
        </div>
      )}

      <StepFooter onBack={() => goTo("welcome")}>
        {failed && !isFetching && (
          <Text type="secondary">
            {__("Fix the problems above, then check again.", "mcpmate")}
          </Text>
        )}
        <Button disabled={isFetching} onClick={() => void refetch()}>
          {__("Check again", "mcpmate")}
        </Button>
        <Button
          type="primary"
          disabled={isFetching || !data || failed}
          onClick={() => goTo("safety")}
        >
          {warned
            ? __("Continue anyway", "mcpmate")
            : __("Continue", "mcpmate")}
        </Button>
      </StepFooter>
    </>
  );
}
