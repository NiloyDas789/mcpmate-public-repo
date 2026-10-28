import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { ReactQueryDevtools } from "@tanstack/react-query-devtools";
import { StrictMode } from "react";
import { createRoot } from "react-dom/client";
import { createHashRouter, RouterProvider } from "react-router";
import { message } from "antd";

import AppRoutes from "./AppRoutes";
import { approvalRoute } from "./utils/approvalLink";
import { connectRoute } from "./utils/connectLink";
import { withoutEntryArgs } from "./utils/entryArgs";
import { setupRoute } from "./utils/setupLink";
import { bootstrapI18n } from "./utils/i18n";
import { APP_ID, portalContainer } from "./utils/portal";
// Inter ships with the plugin (OFL), so wp-admin never asks Google Fonts for it.
import "@fontsource-variable/inter/wght.css";
import "./resource/styles/global.css";
import "./resource/styles/ui.css";

// Load the active locale before any component renders a translated string
bootstrapI18n();

// Offset Ant Design messages below the WordPress admin bar, inside the
// portal container so the app's styles reach them.
message.config({ top: 40, getContainer: portalContainer });

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      refetchOnWindowFocus: false,
      retry: 1,
      staleTime: 5 * 60 * 1000, // 5 minutes
    },
  },
});

// An approval_url (`&approval=<id>`) opens that request's review page, an
// AI-app approval request (`&connect=<token>`) the approval screen, and
// `&setup=1` the setup wizard. `approval` and `setup` then leave the
// address, so a reload stays on the current route.
const initialRoute =
  approvalRoute(window.location.search, window.location.hash) ??
  connectRoute(window.location.search, window.location.hash) ??
  setupRoute(window.location.search, window.location.hash);
const entryQuery = withoutEntryArgs(window.location.search);
if (initialRoute || entryQuery !== null) {
  window.history.replaceState(
    null,
    "",
    window.location.pathname +
      (entryQuery ?? window.location.search) +
      (initialRoute ?? window.location.hash),
  );
}

// Views\Body renders #mcpmate-app (with the loading screen) and the
// portal container side by side inside #mcpmate-root.
const rootElement = document.getElementById(APP_ID);

if (rootElement) {
  const root = createRoot(rootElement);
  // A data router, so a page with unsaved changes can hold a navigation
  // (useBlocker, hooks/useLeaveGuard). The app's own <Routes> sit under
  // one catch-all route.
  const router = createHashRouter([
    {
      path: "*",
      element: (
        <QueryClientProvider client={queryClient}>
          <AppRoutes />
          <ReactQueryDevtools initialIsOpen={false} />
        </QueryClientProvider>
      ),
    },
  ]);

  root.render(
    <StrictMode>
      <RouterProvider router={router} />
    </StrictMode>,
  );
}
