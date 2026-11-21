import { act, render } from "@testing-library/react";
import { createMemoryRouter, RouterProvider } from "react-router";
import { describe, expect, it } from "vitest";

import { withParams } from "./logic";
import { useHashQuery } from "./useHashQuery";

type Hook = ReturnType<typeof useHashQuery>;

function setup(initial = "/activity") {
  const hook: { current: Hook | null } = { current: null };
  function Probe() {
    hook.current = useHashQuery();
    return null;
  }
  const router = createMemoryRouter([{ path: "*", element: <Probe /> }], {
    initialEntries: [initial],
  });
  render(<RouterProvider router={router} />);

  return {
    router,
    hook: () => hook.current as Hook,
    url: () => router.state.location.pathname + router.state.location.search,
  };
}

describe("useHashQuery", () => {
  it("builds each change on the newest URL, before the router has rendered it", async () => {
    const { hook, url } = setup("/activity?q=home");

    // A sort, then a page change, in one go: the second must keep the first.
    await act(async () => {
      const { current, go } = hook();
      go(withParams(current(), { sort: "duration" }));
      go(withParams(current(), { page: "2" }));
    });

    expect(url()).toBe("/activity?q=home&sort=duration&page=2");
    expect(hook().current()).toBe("?q=home&sort=duration&page=2");
  });

  it("takes a URL that came from elsewhere (Back, a link)", async () => {
    const { hook, router } = setup("/activity?page=2");

    await act(() => router.navigate("/activity?result=error"));

    expect(hook().search).toBe("?result=error");
    expect(hook().current()).toBe("?result=error");
  });

  it("closes a drawer it opened with Back, and one from a link by removing the key", async () => {
    const { hook, url, router } = setup("/activity?q=x");

    await act(async () => hook().go("?q=x&entry=9", { push: true }));
    expect(router.state.location.state).toEqual({ drawer: true });
    await act(async () => hook().closeDrawer("entry"));
    expect(url()).toBe("/activity?q=x");

    const linked = setup("/activity?entry=9");
    await act(async () => linked.hook().closeDrawer("entry"));
    expect(linked.url()).toBe("/activity");
  });
});
