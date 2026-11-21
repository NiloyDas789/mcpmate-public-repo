import { afterEach, describe, expect, it, vi } from "vitest";

import { ajaxRequest, restRequest } from "./api";

function mockFetch(body: unknown, init: ResponseInit = { status: 200 }) {
  const fetchMock = vi.fn().mockResolvedValue(
    new Response(JSON.stringify(body), {
      ...init,
      headers: { "content-type": "application/json" },
    }),
  );
  vi.stubGlobal("fetch", fetchMock);

  return fetchMock;
}

afterEach(() => {
  vi.unstubAllGlobals();
});

describe("ajaxRequest", () => {
  it("posts the prefixed action and nonce to admin-ajax", async () => {
    const fetchMock = mockFetch({ success: true, data: { ok: true } });

    const result = await ajaxRequest<{ ok: boolean }>("settings", "POST", {
      settings: { enabled: true },
    });

    expect(result).toEqual({ success: true, data: { ok: true } });

    const [url, init] = fetchMock.mock.calls[0];
    const form = init.body as FormData;
    expect(url).toBe("http://localhost/wp-admin/admin-ajax.php");
    expect(form.get("action")).toBe("MCPMATE_settings");
    expect(form.get("_ajax_nonce")).toBe("test-nonce");
    expect(form.get("settings")).toBe('{"enabled":true}');
  });

  it("returns the error payload for non-2xx JSON responses", async () => {
    mockFetch(
      {
        success: false,
        data: { code: "mcpmate_forbidden", message: "No" },
      },
      { status: 403 },
    );

    const result = await ajaxRequest("settings");

    expect(result.success).toBe(false);
  });
});

describe("restRequest", () => {
  it("calls the plugin namespace with the REST nonce", async () => {
    const fetchMock = mockFetch({ settings: {} });

    await restRequest("settings");

    const [url, init] = fetchMock.mock.calls[0];
    expect(url).toBe("http://localhost/wp-json/mcpmate/v1/settings");
    expect(init.method).toBe("GET");
    expect(init.headers["X-WP-Nonce"]).toBe("test-rest-nonce");
  });

  it("throws on non-2xx responses", async () => {
    mockFetch({ code: "rest_forbidden" }, { status: 401 });

    await expect(restRequest("settings")).rejects.toThrow("401");
  });
});
