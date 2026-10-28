import { StyleProvider } from "@ant-design/cssinjs";
import { ConfigProvider, theme } from "antd";
import { useAtomValue } from "jotai";
import { lazy } from "react";
import { Navigate, Route, Routes, useLocation, useParams } from "react-router";

import { $isDarkTheme } from "@common/globalStates";
import { darkThemeConfig, lightThemeConfig } from "@config/theme";
import AppShell, { MinimalShell } from "@components/AppShell";
import Announcer from "@components/ui/Announcer";
import { useReducedMotion } from "@/hooks/useReducedMotion";
import { portalContainer } from "@utils/portal";

const Dashboard = lazy(() => import("@pages/Dashboard"));
const Connections = lazy(() => import("@pages/Connections"));
const Connect = lazy(() => import("@pages/Connect"));
const Tools = lazy(() => import("@pages/Tools"));
const Activity = lazy(() => import("@pages/Activity"));
const AuditLog = lazy(() => import("@pages/AuditLog"));
const Changes = lazy(() => import("@pages/Changes"));
const Approvals = lazy(() => import("@pages/Approvals"));
const ApprovalDetail = lazy(() => import("@pages/Approvals/Detail"));
const Settings = lazy(() => import("@pages/Settings"));
const Setup = lazy(() => import("@pages/Setup"));
const UiKit = lazy(() => import("@pages/UiKit"));
const Error404 = lazy(() => import("@pages/Error404"));

const { darkAlgorithm, defaultAlgorithm } = theme;

/** An old route that moved: same query (filters), replaced in history. */
function Moved({ to }: { to: string }) {
  const { search } = useLocation();

  return <Navigate to={{ pathname: to, search }} replace />;
}

function MovedApproval() {
  const { id } = useParams();

  return <Navigate to={`/activity/approvals/${id ?? ""}`} replace />;
}

/**
 * Every route of the admin app (docs/ui.md §2). Old links (bookmarks, the
 * setup wizard, the removed submenu items) redirect to their new home.
 */
export function AppRouteTable() {
  return (
    <Routes>
      <Route element={<AppShell />}>
        <Route index element={<Dashboard />} />
        <Route path="connections" element={<Connections />} />
        {/* One page for the three tabs, so a save in flight survives a tab change. */}
        <Route path="tools" element={<Tools />}>
          <Route index element={null} />
          <Route path="integrations" element={null} />
          <Route path="abilities" element={null} />
        </Route>
        <Route path="activity" element={<Activity />}>
          <Route index element={<AuditLog />} />
          <Route path="changes" element={<Changes />} />
          <Route path="approvals" element={<Approvals />} />
          <Route path="approvals/:id" element={<ApprovalDetail />} />
        </Route>
        <Route path="settings" element={<Settings />} />
        {SERVER_VARIABLES.debug && <Route path="ui-kit" element={<UiKit />} />}

        <Route path="connection" element={<Moved to="/connections" />} />
        <Route path="connected-apps" element={<Moved to="/connections" />} />
        <Route path="audit-log" element={<Moved to="/activity" />} />
        <Route path="approvals" element={<Moved to="/activity/approvals" />} />
        <Route path="approvals/:id" element={<MovedApproval />} />

        <Route path="*" element={<Error404 />} />
      </Route>

      <Route element={<MinimalShell />}>
        <Route path="setup" element={<Setup />} />
        <Route path="connect" element={<Connect />} />
      </Route>
    </Routes>
  );
}

export default function AppRoutes() {
  const isDarkTheme = useAtomValue($isDarkTheme);
  const reducedMotion = useReducedMotion();
  const themeTokens = isDarkTheme ? darkThemeConfig : lightThemeConfig;
  const themeAlgorithm = isDarkTheme ? darkAlgorithm : defaultAlgorithm;

  return (
    <ConfigProvider
      direction={SERVER_VARIABLES.rtl ? "rtl" : "ltr"}
      getPopupContainer={portalContainer}
      theme={{
        algorithm: themeAlgorithm,
        token: { ...themeTokens, motion: !reducedMotion },
      }}
    >
      <StyleProvider hashPriority="high">
        <Announcer>
          <AppRouteTable />
        </Announcer>
      </StyleProvider>
    </ConfigProvider>
  );
}
