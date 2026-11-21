import { describe, expect, it, vi } from "vitest";
import { AjaxRequestError } from "@services/api";
import type { BlockBatch, BlockQueueClaim } from "../../types/api";
import { BridgeError, type EditorWp } from "./editorBridge";
import { runFinalization, type RunnerDeps, type RunProgress } from "./runner";

const LEASE = "a".repeat(64);

const batch = (status: BlockBatch["status"]): BlockBatch =>
  ({ id: 7, status, items: [] }) as unknown as BlockBatch;

const claim: BlockQueueClaim = {
  lease: LEASE,
  lease_expires_at: "2026-11-09T09:00:00Z",
  batch: batch("running"),
  items: [
    {
      id: 12,
      position: 0,
      post_id: 45,
      post_title: "Tea menu",
      mode: "blocks",
      editor_url: "/wp-admin/post.php?post=45&action=edit&mcpmate_finalizer=1",
    },
    {
      id: 13,
      position: 1,
      post_id: 46,
      post_title: "About",
      mode: "resave",
      editor_url: "/wp-admin/post.php?post=46&action=edit&mcpmate_finalizer=1",
    },
  ],
};

/** Payloads by change: the tree comes in two pieces. */
const PAYLOADS: Record<number, string[]> = {
  12: [
    '[{"name":"core/heading","attributes":{"cont',
    'ent":"Menu"},"innerBlocks":[]}]',
  ],
  13: ["<!-- wp:paragraph --><p>About</p><!-- /wp:paragraph -->"],
};

function deps(overrides: Partial<RunnerDeps> = {}) {
  const wp = {} as EditorWp;
  const progress: RunProgress[] = [];
  const base: RunnerDeps = {
    claim: vi.fn(async () => claim),
    payload: vi.fn(
      async (_id: number, item: number, _lease: string, offset: number) => {
        const pieces = PAYLOADS[item];
        const total = pieces.join("").length;
        let start = 0;
        for (const piece of pieces) {
          if (start === offset) {
            return {
              piece,
              offset,
              next_offset: offset + piece.length,
              total_bytes: total,
              mode: item === 12 ? "blocks" : "resave",
            } as const;
          }
          start += piece.length;
        }
        throw new Error(`No piece at ${offset}`);
      },
    ),
    prepare: vi.fn(async () => batch("running")),
    fail: vi.fn(async () => batch("failed")),
    commit: vi.fn(async () => batch("finalized")),
    openEditor: vi.fn(async () => ({ wp, clone: <T>(value: T) => value })),
    closeEditor: vi.fn(),
    finalize: vi.fn(async (_wp, job) => ({
      content:
        job.mode === "blocks"
          ? `built:${JSON.stringify(job.blocks)}`
          : `resaved:${job.content}`,
      recovered: job.mode === "blocks" ? 0 : 1,
      registered: ["core/heading"],
    })),
    onProgress: (step) => progress.push(step),
    ...overrides,
  };

  return { deps: base, progress };
}

describe("runFinalization", () => {
  it("builds every change in its editor, has each checked, then commits once", async () => {
    const { deps: d, progress } = deps();

    const outcome = await runFinalization(7, d);

    expect(outcome).toEqual({ ok: true, batch: batch("finalized") });
    expect(d.openEditor).toHaveBeenNthCalledWith(1, claim.items[0].editor_url);
    expect(d.finalize).toHaveBeenNthCalledWith(
      1,
      expect.anything(),
      {
        post_id: 45,
        mode: "blocks",
        blocks: [
          {
            name: "core/heading",
            attributes: { content: "Menu" },
            innerBlocks: [],
          },
        ],
      },
      expect.objectContaining({ clone: expect.any(Function) }),
    );
    expect(d.finalize).toHaveBeenNthCalledWith(
      2,
      expect.anything(),
      { post_id: 46, mode: "resave", content: PAYLOADS[13][0] },
      expect.anything(),
    );
    expect(d.prepare).toHaveBeenNthCalledWith(
      2,
      7,
      13,
      LEASE,
      `resaved:${PAYLOADS[13][0]}`,
      { recovered: 1, registered: ["core/heading"] },
    );
    expect(d.commit).toHaveBeenCalledWith(7, LEASE);
    expect(d.closeEditor).toHaveBeenCalledTimes(2);
    expect(d.fail).not.toHaveBeenCalled();
    expect(progress.map((p) => `${p.step}:${p.index}`)).toEqual([
      "claiming:0",
      "loading:0",
      "building:0",
      "checking:0",
      "loading:1",
      "building:1",
      "checking:1",
      "saving:0",
    ]);
  });

  it("reports what went wrong in the editor, and saves nothing", async () => {
    const { deps: d } = deps({
      finalize: vi.fn(async () => {
        throw new BridgeError(
          "missing_block_registration",
          "The block editor does not register acme/x.",
        );
      }),
    });

    const outcome = await runFinalization(7, d);

    expect(outcome).toMatchObject({ ok: false, batch: batch("failed") });
    expect(d.fail).toHaveBeenCalledWith(
      7,
      12,
      LEASE,
      "missing_block_registration",
      "The block editor does not register acme/x.",
    );
    expect(d.closeEditor).toHaveBeenCalledTimes(1);
    expect(d.prepare).not.toHaveBeenCalled();
    expect(d.commit).not.toHaveBeenCalled();
  });

  it("stops at a change the server refuses, which the server already failed", async () => {
    const refused = new AjaxRequestError(
      "Nothing was saved: …",
      "refused",
      409,
      { code: "mcpmate_content_loss", message: "Nothing was saved: …" },
    );
    const { deps: d } = deps({
      prepare: vi.fn(async () => Promise.reject(refused)),
    });

    const outcome = await runFinalization(7, d);

    expect(outcome).toEqual({ ok: false, batch: null, error: refused });
    expect(d.fail).not.toHaveBeenCalled();
    expect(d.openEditor).toHaveBeenCalledTimes(1);
    expect(d.commit).not.toHaveBeenCalled();
  });

  it("fails a change whose payload never arrives in full, and a tree that is not JSON", async () => {
    const stuck = deps({
      payload: vi.fn(async () => ({
        piece: "",
        offset: 0,
        next_offset: 0,
        total_bytes: 10,
        mode: "blocks" as const,
      })),
    });
    await runFinalization(7, stuck.deps);
    expect(stuck.deps.fail).toHaveBeenCalledWith(
      7,
      12,
      LEASE,
      "network",
      "The change could not be read from the site in full.",
    );
    expect(stuck.deps.openEditor).not.toHaveBeenCalled();

    const garbled = deps({
      payload: vi.fn(async () => ({
        piece: "[{",
        offset: 0,
        next_offset: 2,
        total_bytes: 2,
        mode: "blocks" as const,
      })),
    });
    await runFinalization(7, garbled.deps);
    expect(garbled.deps.fail).toHaveBeenCalledWith(
      7,
      12,
      LEASE,
      "invalid_block_spec",
      "The queued block tree is not valid JSON.",
    );
  });

  it("gives up without a claim, and when even reporting fails the lease is left to run out", async () => {
    const taken = new AjaxRequestError(
      "Another browser is finalizing this batch right now.",
      "refused",
      409,
      { code: "mcpmate_block_batch_running", message: "" },
    );
    const none = deps({ claim: vi.fn(async () => Promise.reject(taken)) });
    expect(await runFinalization(7, none.deps)).toEqual({
      ok: false,
      batch: null,
      error: taken,
    });
    expect(none.deps.openEditor).not.toHaveBeenCalled();

    const offline = deps({
      openEditor: vi.fn(async () =>
        Promise.reject(
          new BridgeError(
            "editor_timeout",
            "The block editor did not load in 60 s.",
          ),
        ),
      ),
      fail: vi.fn(async () =>
        Promise.reject(
          new AjaxRequestError("Request failed: network error", "network", 0),
        ),
      ),
    });
    const outcome = await runFinalization(7, offline.deps);
    expect(outcome).toMatchObject({ ok: false, batch: null });
    expect(offline.deps.fail).toHaveBeenCalledWith(
      7,
      12,
      LEASE,
      "editor_timeout",
      "The block editor did not load in 60 s.",
    );
  });

  it("stops before the next change once the page is left, and saves nothing", async () => {
    const signal = { aborted: false };
    const { deps: d } = deps({
      signal,
      prepare: vi.fn(async () => {
        signal.aborted = true;
        return batch("running");
      }),
    });

    const outcome = await runFinalization(7, d);

    expect(outcome).toMatchObject({ ok: false, batch: batch("failed") });
    expect(d.prepare).toHaveBeenCalledTimes(1);
    expect(d.fail).toHaveBeenCalledWith(
      7,
      13,
      LEASE,
      "network",
      "Finalizing stopped: the site admin left the page.",
    );
    expect(d.commit).not.toHaveBeenCalled();
  });
});
