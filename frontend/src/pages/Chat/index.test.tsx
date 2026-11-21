import { screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, describe, expect, it, vi } from "vitest";

import { calledRoutes, location, renderPage } from "../../test/renderPage";
import type { FakeSiteOptions } from "../../test/fakeSite";
import Chat from "./index";

afterEach(() => {
  SERVER_VARIABLES.chat = undefined;
  vi.unstubAllGlobals();
});

const renderChat = (chat: FakeSiteOptions["chat"] = {}) =>
  renderPage(<Chat />, { path: "/chat", route: "chat", chat });

const announced = () =>
  screen
    .getAllByRole("status")
    .map((region) => region.textContent ?? "")
    .join(" ");

describe("Chat", () => {
  it("leads to Settings while Chat is not set up", () => {
    renderChat({ ready: false, enabled: false });

    expect(screen.getByText("Chat isn't set up")).toBeVisible();
    expect(
      screen.getByRole("link", { name: "Open Chat settings" }),
    ).toHaveAttribute("href", "#/settings?section=chat");
  });

  it("asks for the cost notice first, then opens the workspace", async () => {
    const user = userEvent.setup();
    const { fetchMock } = renderChat({ consent: false, connected: false });

    expect(
      screen.getByText(/billed to that key's Anthropic account/),
    ).toBeVisible();
    await user.click(screen.getByRole("button", { name: "Start using Chat" }));

    expect(
      await screen.findByRole("textbox", { name: "Message" }),
    ).toBeVisible();
    expect(calledRoutes(fetchMock)).toContain("chat/consent");
  });

  it("streams an answer into a new conversation", async () => {
    const user = userEvent.setup();
    const { fetchMock } = renderChat();

    await user.type(
      await screen.findByRole("textbox", { name: "Message" }),
      "Which WordPress version?",
    );
    await user.keyboard("{Enter}");

    expect(
      await screen.findByText("Your site runs WordPress 6.9."),
    ).toBeVisible();
    expect(calledRoutes(fetchMock)).toContain("chat/send");
    await waitFor(() => expect(location.current).toBe("/chat?session=1"));
    await waitFor(() => expect(announced()).toContain("Claude answered."));
  });

  it("gives the message back to the composer when it was not saved", async () => {
    const user = userEvent.setup();
    renderChat();

    const box = await screen.findByRole("textbox", { name: "Message" });
    await user.type(box, "Refuse this one");
    await user.keyboard("{Enter}");

    expect(await screen.findByText("Too many tool calls.")).toBeVisible();
    await waitFor(() => expect(box).toHaveValue("Refuse this one"));
  });

  it("keeps the composer empty when the message was saved before the error", async () => {
    const user = userEvent.setup();
    renderChat();

    const box = await screen.findByRole("textbox", { name: "Message" });
    await user.type(box, "Drop this one");
    await user.keyboard("{Enter}");

    expect(await screen.findByText("Too many tool calls.")).toBeVisible();
    expect(box).toHaveValue("");
  });

  it("shows a plan for a change and runs it only after Run", async () => {
    const user = userEvent.setup();
    const { fetchMock } = renderChat();

    await user.type(
      await screen.findByRole("textbox", { name: "Message" }),
      "Update the About draft's title",
    );
    await user.click(screen.getByRole("button", { name: "Send" }));

    const plan = await screen.findByRole("region", {
      name: "Claude wants to make 1 change",
    });
    expect(within(plan).getByText("update_post")).toBeVisible();
    expect(calledRoutes(fetchMock)).not.toContain("chat/run");

    await user.click(within(plan).getByRole("button", { name: "Run" }));

    expect(
      await screen.findByText("Done: the draft has its new title."),
    ).toBeVisible();
    expect(screen.getByText("Done")).toBeVisible();
    expect(
      screen.queryByRole("region", { name: "Claude wants to make 1 change" }),
    ).toBeNull();
  });

  it("skips a change when asked", async () => {
    const user = userEvent.setup();
    renderChat();

    await user.type(
      await screen.findByRole("textbox", { name: "Message" }),
      "Add a FAQ to the draft",
    );
    await user.keyboard("{Enter}");
    const plan = await screen.findByRole("region", {
      name: "Claude wants to make 1 change",
    });
    await user.click(within(plan).getByRole("button", { name: "Skip" }));

    expect(await screen.findByText("OK, I left it as it was.")).toBeVisible();
    expect(screen.getByText("Skipped")).toBeVisible();
  });

  it("refuses an image that is too large before sending it", async () => {
    const user = userEvent.setup({ applyAccept: false });
    renderChat();

    const big = new File([new Uint8Array(4 * 1024 * 1024)], "huge.png", {
      type: "image/png",
    });
    const input =
      document.querySelector<HTMLInputElement>('input[type="file"]');
    expect(input).not.toBeNull();
    await user.upload(input as HTMLInputElement, big);

    expect(
      await screen.findByText("Each image may be 3 MB at most."),
    ).toHaveAttribute("role", "alert");
  });
});
