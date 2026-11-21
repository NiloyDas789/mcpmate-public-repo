import { describe, expect, it, vi, beforeEach } from "vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";

import GhostModeCard from "./GhostModeCard";
import type { GhostModeView } from "../../types/api";

const api = vi.hoisted(() => ({
  fetchGhostMode: vi.fn(),
  updateGhostMode: vi.fn(),
}));

vi.mock("@services/api", () => api);

const view: GhostModeView = {
  available: true,
  constant: "MCPMATE_ENABLE_GHOST_MODE",
  users: [],
  at: null,
  by: null,
  admins: [
    { id: 1, name: "Ada", login: "ada", self: true, hideable: false },
    { id: 2, name: "Grace", login: "grace", self: false, hideable: true },
  ],
};

function mount() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });

  return render(
    <QueryClientProvider client={client}>
      <GhostModeCard />
    </QueryClientProvider>,
  );
}

describe("GhostModeCard", () => {
  beforeEach(() => {
    api.fetchGhostMode.mockResolvedValue(view);
    api.updateGhostMode.mockImplementation(async (users: number[]) => ({
      ...view,
      users,
      at: "2026-11-16T10:00:00Z",
      by: { id: 1, name: "Ada" },
    }));
  });

  it("never lets you hide MCPMate from yourself", async () => {
    mount();

    const self = await screen.findByRole("checkbox", { name: /Ada/ });
    expect((self as HTMLInputElement).disabled).toBe(true);
    expect(screen.getByText("(you: never hidden)")).toBeTruthy();
  });

  it("saves the administrators to hide", async () => {
    mount();

    await userEvent.click(
      await screen.findByRole("checkbox", { name: /Grace/ }),
    );
    await userEvent.click(
      screen.getByRole("button", { name: "Save Ghost Mode" }),
    );

    await waitFor(() => expect(api.updateGhostMode).toHaveBeenCalledWith([2]));
    expect(await screen.findByText(/Hidden from 1 administrator/)).toBeTruthy();
  });

  it("keeps edits locked until a slow save answers, so the answer overwrites nothing", async () => {
    let answer: (view: GhostModeView) => void = () => undefined;
    api.updateGhostMode.mockImplementation(
      () => new Promise<GhostModeView>((resolve) => (answer = resolve)),
    );
    const dirty = vi.fn();
    const client = new QueryClient({
      defaultOptions: { queries: { retry: false } },
    });
    render(
      <QueryClientProvider client={client}>
        <GhostModeCard onDirtyChange={dirty} />
      </QueryClientProvider>,
    );

    const grace = await screen.findByRole("checkbox", { name: /Grace/ });
    await userEvent.click(grace);
    expect(dirty).toHaveBeenLastCalledWith(1);
    await userEvent.click(
      screen.getByRole("button", { name: "Save Ghost Mode" }),
    );

    await waitFor(() =>
      expect((grace as HTMLInputElement).disabled).toBe(true),
    );
    fireEvent.click(grace);
    expect((grace as HTMLInputElement).checked).toBe(true);

    answer({
      ...view,
      users: [2],
      at: "2026-11-16T10:00:00Z",
      by: { id: 1, name: "Ada" },
    });

    await waitFor(() =>
      expect((grace as HTMLInputElement).disabled).toBe(false),
    );
    expect((grace as HTMLInputElement).checked).toBe(true);
    expect(dirty).toHaveBeenLastCalledWith(0);
  });

  it("drops saved IDs of users who are no longer administrators, so a save never sends them", async () => {
    api.fetchGhostMode.mockResolvedValue({
      ...view,
      users: [2, 99],
      at: "2026-11-16T10:00:00Z",
      by: { id: 1, name: "Ada" },
    });
    mount();

    const grace = await screen.findByRole("checkbox", { name: /Grace/ });
    expect((grace as HTMLInputElement).checked).toBe(true);
    await userEvent.click(grace);
    await userEvent.click(
      screen.getByRole("button", { name: "Save Ghost Mode" }),
    );

    await waitFor(() => expect(api.updateGhostMode).toHaveBeenCalledWith([]));
  });
});
