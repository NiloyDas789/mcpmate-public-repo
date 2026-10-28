/* eslint-disable react-refresh/only-export-components -- a test helper, never hot-reloaded. */
import type { ReactElement } from "react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { render } from "@testing-library/react";
import {
  createMemoryRouter,
  Route,
  RouterProvider,
  Routes,
  useLocation,
} from "react-router";
import { vi } from "vitest";
import Announcer from "@components/ui/Announcer";
import {
  connectionFor,
  createFakeSite,
  fakeFetch,
  type FakeSiteOptions,
} from "./fakeSite";

/** The current location, for assertions on hash-query state. */
export const location = { current: "" };

function LocationProbe() {
  const { pathname, search } = useLocation();
  location.current = `${pathname}${search}`;

  return null;
}

/**
 * Render a page as the app does (React Query, router, live regions) with
 * `fetch` answered by a fake site, and `SERVER_VARIABLES` set to match it.
 */
export function renderPage(
  page: ReactElement,
  options: FakeSiteOptions & { path?: string; route?: string } = {},
) {
  const site = createFakeSite(options);
  const fetchMock = vi.fn(fakeFetch(site));
  vi.stubGlobal("fetch", fetchMock);

  SERVER_VARIABLES.connection = connectionFor();
  SERVER_VARIABLES.access = site.access();
  SERVER_VARIABLES.grants_live = site
    .grants()
    .filter((grant) => grant.status !== "revoked").length;

  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });

  // A data router, as main.tsx has, so useBlocker works.
  const router = createMemoryRouter(
    [
      {
        path: "*",
        element: (
          <Announcer>
            <Routes>
              <Route path={options.route ?? "*"} element={page} />
            </Routes>
            <LocationProbe />
          </Announcer>
        ),
      },
    ],
    { initialEntries: [options.path ?? "/"] },
  );

  const view = render(
    <QueryClientProvider client={client}>
      <RouterProvider router={router} />
    </QueryClientProvider>,
  );

  return { ...view, site, fetchMock, client, router };
}

/** Every admin-ajax action and REST path the page called. */
export function calledRoutes(fetchMock: ReturnType<typeof vi.fn>): string[] {
  return fetchMock.mock.calls.map(([url, init]) =>
    init?.body instanceof FormData
      ? String(init.body.get("action")).replace(/^MCPMATE_/, "")
      : String(url)
          .replace(/^.*\/mcpmate\/v1\//, "")
          .split("?")[0],
  );
}
