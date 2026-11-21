import { describe, expect, it, vi } from "vitest";
import {
  blocksFromMarkup,
  blocksFromSpecs,
  BridgeError,
  finalizeInEditor,
  namesIn,
  SAVE_LOCK,
  settle,
  waitFor,
  type EditorBlock,
  type EditorWp,
} from "./editorBridge";

/** Block types the fake editor registers, with their attribute names. */
const TYPES: Record<string, string[]> = {
  "core/heading": ["content", "level"],
  "core/paragraph": ["content", "dropCap"],
  "core/group": ["layout", "tagName"],
  "acme/price-table": ["uniqueID", "plans"],
};

let ids = 0;
const block = (
  name: string,
  attributes: Record<string, unknown> = {},
  innerBlocks: EditorBlock[] = [],
  isValid = true,
): EditorBlock => ({
  name,
  attributes,
  innerBlocks,
  isValid,
  clientId: `c${++ids}`,
});

const serialize = (blocks: EditorBlock[]): string =>
  blocks
    .map(
      (b) =>
        `<!-- wp:${b.name} ${JSON.stringify(b.attributes)} -->${serialize(b.innerBlocks)}<!-- /wp:${b.name} -->`,
    )
    .join("\n");

/**
 * A fake post editor: ready after `readyAfter` polls, its block-editor
 * store follows core/editor one poll after a reset, and acme/price-table
 * sets its uniqueID in an effect after it mounts (as Kadence-like blocks
 * do), so a read right after the reset differs from a settled one.
 */
function fakeEditor({
  postId = 45,
  readyAfter = 2,
  parsed = {} as Record<string, EditorBlock[]>,
} = {}) {
  let polls = 0;
  let pending: EditorBlock[] | null = null;
  let blocks: EditorBlock[] = [block("core/paragraph", { content: "Old" })];
  let reads = 0;
  const locks: string[] = [];

  const settleEffects = () => {
    if (pending) {
      blocks = pending;
      pending = null;
      reads = 0;
    }
  };

  const wp: EditorWp = {
    blocks: {
      createBlock: vi.fn(
        (
          name: string,
          attributes: Record<string, unknown> = {},
          innerBlocks: EditorBlock[] = [],
        ) => {
          if (!TYPES[name]) {
            throw new Error(`Block type "${name}" is not registered.`);
          }
          return block(name, { ...attributes }, innerBlocks);
        },
      ),
      parse: (content: string) => parsed[content] ?? [],
      serialize,
      getBlockType: (name: string) =>
        TYPES[name]
          ? {
              name,
              attributes: Object.fromEntries(
                TYPES[name].map((key) => [key, {}]),
              ),
            }
          : undefined,
      getBlockTypes: () => Object.keys(TYPES).map((name) => ({ name })),
    },
    data: {
      select: (store: string) => {
        if (store === "core/editor") {
          return {
            __unstableIsEditorReady: () => ++polls > readyAfter,
            getCurrentPostId: () => postId,
            getEditedPostContent: () => {
              settleEffects();
              reads++;
              // The effect runs between the first and second read.
              if (reads === 2) {
                blocks
                  .filter((b) => b.name === "acme/price-table")
                  .forEach((b) => (b.attributes.uniqueID = "u1"));
              }
              return serialize(blocks);
            },
          };
        }
        return { getBlocks: () => (settleEffects(), blocks) };
      },
      dispatch: (store: string) =>
        store === "core/editor"
          ? {
              lockPostSaving: (name: string) => locks.push(`saving:${name}`),
              lockPostAutosaving: (name: string) =>
                locks.push(`autosaving:${name}`),
              resetEditorBlocks: (next: EditorBlock[]) => {
                pending = next;
              },
            }
          : { resetBlocks: vi.fn() },
    },
  };

  return { wp, locks, blocks: () => blocks };
}

/** A clock that moves only when the bridge sleeps. */
function fakeTime() {
  let time = 0;
  return {
    now: () => time,
    sleep: async (ms: number) => {
      time += ms;
    },
  };
}

describe("blocksFromSpecs", () => {
  it("builds nested blocks with the editor's createBlock, each value cloned into its realm", () => {
    const { wp } = fakeEditor();
    const clone = vi.fn(
      <T>(value: T): T => JSON.parse(JSON.stringify(value)) as T,
    );

    const blocks = blocksFromSpecs(
      wp.blocks,
      [
        { name: "core/heading", attributes: { level: 2, content: "Menu" } },
        {
          name: "core/group",
          attributes: {},
          innerBlocks: [
            {
              name: "core/paragraph",
              attributes: { content: "Tea", className: "big" },
            },
          ],
        },
      ],
      clone,
    );

    expect(namesIn(blocks)).toEqual([
      "core/heading",
      "core/group",
      "core/paragraph",
    ]);
    expect(blocks[1].innerBlocks[0].attributes).toEqual({
      content: "Tea",
      className: "big",
    });
    expect(clone).toHaveBeenCalledTimes(3);
  });

  it("names every block the editor does not register", () => {
    const { wp } = fakeEditor();

    expect(() =>
      blocksFromSpecs(wp.blocks, [
        { name: "acme/nope" },
        { name: "core/group", innerBlocks: [{ name: "other/gone" }] },
      ]),
    ).toThrow(
      expect.objectContaining({
        reason: "missing_block_registration",
        message: "The block editor does not register acme/nope, other/gone.",
      }),
    );
  });

  it("refuses attributes a block does not have, which createBlock would drop with their text", () => {
    const { wp } = fakeEditor();

    try {
      blocksFromSpecs(wp.blocks, [
        {
          name: "core/group",
          innerBlocks: [
            { name: "core/paragraph", attributes: { text: "Tea" } },
          ],
        },
      ]);
      expect.unreachable();
    } catch (error) {
      expect(error).toBeInstanceOf(BridgeError);
      expect((error as BridgeError).reason).toBe("invalid_block_spec");
      expect((error as BridgeError).message).toContain(
        'blocks[0].innerBlocks[0] core/paragraph: no attribute "text" (it has content, dropCap)',
      );
    }
  });

  it("reports a block createBlock throws on as an invalid tree", () => {
    const { wp } = fakeEditor();
    wp.blocks.createBlock = () => {
      throw new Error("bad value");
    };

    expect(() =>
      blocksFromSpecs(wp.blocks, [{ name: "core/paragraph" }]),
    ).toThrow(
      expect.objectContaining({
        reason: "invalid_block_spec",
        message: "core/paragraph could not be built: bad value",
      }),
    );
  });
});

describe("blocksFromMarkup", () => {
  it("rebuilds every invalid block from its attributes, inner blocks first", () => {
    const child = block("core/paragraph", { content: "Tea" }, [], false);
    const parent = block("core/group", {}, [child]);
    const heading = block(
      "core/heading",
      { content: "Menu", level: 2 },
      [],
      false,
    );
    const { wp } = fakeEditor({ parsed: { markup: [heading, parent] } });

    const { blocks, recovered } = blocksFromMarkup(wp.blocks, "markup");

    expect(recovered).toBe(2);
    expect(blocks[0]).not.toBe(heading);
    expect(blocks[0]).toMatchObject({
      name: "core/heading",
      attributes: { content: "Menu", level: 2 },
      isValid: true,
    });
    expect(blocks[1]).toBe(parent);
    expect(blocks[1].innerBlocks[0]).toMatchObject({
      name: "core/paragraph",
      isValid: true,
    });
  });

  it("names a block the editor turned into core/missing instead of dropping it", () => {
    const { wp } = fakeEditor({
      parsed: {
        markup: [
          block("core/group", {}, [
            block("core/missing", { originalName: "acme/old" }),
          ]),
        ],
      },
    });

    expect(() => blocksFromMarkup(wp.blocks, "markup")).toThrow(
      expect.objectContaining({
        reason: "missing_block_registration",
        message: "The block editor does not register acme/old.",
      }),
    );
  });
});

describe("waiting", () => {
  it("waitFor gives the first value and times out with editor_timeout", async () => {
    const time = fakeTime();
    let n = 0;

    await expect(waitFor(() => (++n > 2 ? n : false), "x", time)).resolves.toBe(
      3,
    );
    await expect(
      waitFor(() => null, "Opening the post", { ...time, timeoutMs: 1000 }),
    ).rejects.toMatchObject({
      reason: "editor_timeout",
      message: "Opening the post took longer than 1 s.",
    });
  });

  it("settle waits for two equal reads", async () => {
    const reads = ["a", "b", "b", "c"];

    await expect(settle(() => reads.shift() ?? "z", fakeTime())).resolves.toBe(
      "b",
    );
  });
});

describe("finalizeInEditor", () => {
  it("waits for the editor, locks its saving, and returns the content once the blocks settled", async () => {
    const editor = fakeEditor({ readyAfter: 3 });

    const result = await finalizeInEditor(
      editor.wp,
      {
        post_id: 45,
        mode: "blocks",
        blocks: [
          { name: "core/heading", attributes: { level: 2, content: "Menu" } },
          { name: "acme/price-table" },
        ],
      },
      fakeTime(),
    );

    expect(editor.locks).toEqual([
      `saving:${SAVE_LOCK}`,
      `autosaving:${SAVE_LOCK}`,
    ]);
    // The mount effect's uniqueID is in the content: it was read after the blocks settled.
    expect(result.content).toContain(
      '<!-- wp:acme/price-table {"uniqueID":"u1"} -->',
    );
    expect(result.content).toContain(
      '<!-- wp:core/heading {"level":2,"content":"Menu"} -->',
    );
    expect(result).toMatchObject({
      recovered: 0,
      registered: ["core/heading", "acme/price-table"],
    });
    expect(editor.blocks().map((b) => b.name)).toEqual([
      "core/heading",
      "acme/price-table",
    ]);
  });

  it("re-saves markup with its invalid blocks rebuilt", async () => {
    const editor = fakeEditor({
      parsed: {
        stored: [block("core/paragraph", { content: "Tea" }, [], false)],
      },
    });

    const result = await finalizeInEditor(
      editor.wp,
      { post_id: 45, mode: "resave", content: "stored" },
      fakeTime(),
    );

    expect(result.recovered).toBe(1);
    expect(result.content).toBe(
      '<!-- wp:core/paragraph {"content":"Tea"} --><!-- /wp:core/paragraph -->',
    );
  });

  it("times out when the editor shows another post or never gets ready", async () => {
    await expect(
      finalizeInEditor(
        fakeEditor({ postId: 99 }).wp,
        { post_id: 45, mode: "content", content: "" },
        { ...fakeTime(), readyTimeoutMs: 2000 },
      ),
    ).rejects.toMatchObject({ reason: "editor_timeout" });
  });

  it("refuses to hand back blocks the editor still finds invalid", async () => {
    const editor = fakeEditor({
      parsed: { stored: [block("core/paragraph", { content: "Tea" })] },
    });
    editor.wp.blocks.createBlock = (name, attributes = {}, inner = []) =>
      block(name, attributes, inner, false);
    editor.wp.blocks.parse = () => [
      block("core/paragraph", {}, [], true),
      block("core/heading", {}, [], false),
    ];

    await expect(
      finalizeInEditor(
        editor.wp,
        { post_id: 45, mode: "resave", content: "stored" },
        fakeTime(),
      ),
    ).rejects.toMatchObject({
      reason: "serializer_failed",
      message:
        "The block editor still finds these blocks invalid: core/heading.",
    });
  });
});
