import { __, sprintf } from "@wordpress/i18n";
import PageHeader from "@components/AppShell/PageHeader";
import Button from "@components/ui/Button";
import Card from "@components/ui/Card";
import CopyField from "@components/ui/CopyField";
import Notice from "@components/ui/Notice";
import { useContainerWidth } from "@/hooks/useContainerWidth";
import { breakpoints } from "@config/tokens";
import { useConnectionSummary } from "@services/queries";
import { STEPS } from "@pages/Setup/labels";
import { cx } from "@utils/classNames";
import AccessCard from "./AccessCard";
import HelpCard from "./HelpCard";
import RecentActivity from "./RecentActivity";
import SiteChecks from "./SiteChecks";
import StatsRow from "./StatsRow";

/**
 * "Finish setting up" while setup is not completed; "Setup was skipped"
 * after Skip setup. Nothing once it is completed.
 */
function SetupNotice() {
  const { status, step } = SERVER_VARIABLES.onboarding;

  if (status === "completed") {
    return null;
  }

  const skipped = status === "skipped";

  return (
    <Notice
      tone="info"
      title={
        skipped
          ? __("Setup was skipped.", "mcpmate")
          : __("Finish setting up.", "mcpmate")
      }
      action={
        <Button variant="primary" size="sm" href="#/setup">
          {skipped
            ? __("Start setup", "mcpmate")
            : __("Resume setup", "mcpmate")}
        </Button>
      }
    >
      {skipped
        ? __("Connect your AI in about 3 minutes.", "mcpmate")
        : sprintf(
            /* translators: 1: current step number, 2: number of steps. */
            __("You're on step %1$d of %2$d.", "mcpmate"),
            Math.max(STEPS.indexOf(step), 0) + 1,
            STEPS.length,
          )}
    </Notice>
  );
}

function McpUrlCard() {
  const connection = useConnectionSummary();

  return (
    <Card title={__("Your MCP URL", "mcpmate")}>
      <div className="grid gap-3">
        <p className="m-0">
          {__(
            "Paste this into your AI app to connect it. It isn't a password: every app still needs your approval here.",
            "mcpmate",
          )}
        </p>
        <CopyField
          value={connection.mcp_url}
          label={__("MCP URL", "mcpmate")}
        />
      </div>
    </Card>
  );
}

/**
 * Dashboard (`#/`, docs/ui.md §5.1): what AI apps are doing on this site,
 * and the way to stop them. One column in a narrow container; two from
 * 960 px (the stat tiles share a row from 600 px).
 */
export default function Dashboard() {
  const [ref, width] = useContainerWidth();
  const wide = width !== undefined && width >= breakpoints.lg;
  const tilesInRow = width !== undefined && width >= breakpoints.sm;
  const columns = cx("grid gap-4", wide && "grid-cols-[2fr_1fr]");

  return (
    <>
      <PageHeader
        title={__("Dashboard", "mcpmate")}
        intro={__(
          "See what your AI apps are doing on this site, and stop them at any time.",
          "mcpmate",
        )}
      />
      <div ref={ref} className="el-stack">
        <SetupNotice />
        <div className={columns}>
          <AccessCard />
          <McpUrlCard />
        </div>
        <StatsRow columns={tilesInRow ? 3 : 1} />
        <div className={cx(columns, "items-start")}>
          <RecentActivity />
          <div className="grid gap-4">
            <SiteChecks />
            <HelpCard />
          </div>
        </div>
      </div>
    </>
  );
}
