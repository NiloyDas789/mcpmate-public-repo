/**
 * The block editor queue's bridge to a real block editor (known gap 23):
 * given the `wp` global of a post's editor (the hidden iframe the
 * finalizer opens), it builds the queued blocks with that editor's own
 * block types, puts them in the editor's stores with @wordpress/data, lets
 * every block's edit() settle, and reads the content back as the editor
 * would save it, so each block's markup is written by its own JavaScript
 * save(). It never saves: the editor's saving is locked, and the content
 * goes to the server, which checks it and saves the draft.
 *
 * A block tree (`blocks`) is built with createBlock(); markup (`content`,
 * and the post's own content for a re-save) is parsed, and every block the
 * editor finds invalid is rebuilt from its attributes, as the editor's
 * "Attempt block recovery" does. A block the editor does not register is
 * an error (`missing_block_registration`), never dropped.
 */

/** A block as @wordpress/blocks holds it. */
export interface EditorBlock {
  name: string;
  attributes: Record<string, unknown>;
  innerBlocks: EditorBlock[];
  isValid?: boolean;
  clientId?: string;
}

/** A block type as the editor registered it (its attribute names are what we use). */
export interface EditorBlockType {
  name: string;
  attributes?: Record<string, unknown>;
}

/* eslint-disable @typescript-eslint/no-explicit-any -- @wordpress/data's selectors and actions vary by store and version. */
/** The parts of the editor's `wp` global the bridge uses. */
export interface EditorWp {
  blocks: {
    createBlock(
      name: string,
      attributes?: Record<string, unknown>,
      innerBlocks?: EditorBlock[],
    ): EditorBlock;
    parse(content: string): EditorBlock[];
    serialize(blocks: EditorBlock[]): string;
    getBlockType(name: string): EditorBlockType | undefined | null;
    getBlockTypes?(): EditorBlockType[];
  };
  data: {
    select(store: string): any;
    dispatch(store: string): any;
  };
}
/* eslint-enable @typescript-eslint/no-explicit-any */

/** A queued block tree node (the server's BlockSpec: full names, every key). */
export interface BlockSpecNode {
  name: string;
  attributes?: Record<string, unknown>;
  innerBlocks?: BlockSpecNode[];
}

/** Why the editor could not do it; the server keeps the reason (BlockQueueController::REASONS). */
export type BridgeReason =
  | "editor_unavailable"
  | "editor_timeout"
  | "frame_inaccessible"
  | "missing_block_registration"
  | "invalid_block_spec"
  | "serializer_failed"
  | "network";

export class BridgeError extends Error {
  readonly reason: BridgeReason;

  constructor(reason: BridgeReason, message: string) {
    super(message);
    this.name = "BridgeError";
    this.reason = reason;
  }
}

/** One change to apply: a tree, or markup (content, and a re-save's current content). */
export type BridgeJob =
  | { post_id: number; mode: "blocks"; blocks: BlockSpecNode[] }
  | { post_id: number; mode: "content" | "resave"; content: string };

export interface BridgeResult {
  /** The content as the editor saves it. */
  content: string;
  /** Blocks the editor found invalid and rebuilt from their attributes. */
  recovered: number;
  /** Block names in the content that the editor registers. */
  registered: string[];
}

export interface BridgeOptions {
  /** How long the editor may take to load the post. */
  readyTimeoutMs?: number;
  /** How long blocks may take to settle after they are mounted. */
  settleTimeoutMs?: number;
  intervalMs?: number;
  /**
   * Copies a value into the editor's own JavaScript realm (the iframe's
   * JSON), so its code never meets another window's objects.
   */
  clone?: <T>(value: T) => T;
  sleep?: (ms: number) => Promise<void>;
  now?: () => number;
}

/** The lock name the bridge holds the editor's saving with. */
export const SAVE_LOCK = "mcpmate-finalizer";

/** Attributes every block accepts whether or not its type lists them. */
const SHARED_ATTRIBUTES = ["lock", "metadata", "className", "style", "anchor"];

const defaultSleep = (ms: number) =>
  new Promise<void>((resolve) => {
    setTimeout(resolve, ms);
  });

// ──────────────────────────────────────────────
//  Building blocks
// ──────────────────────────────────────────────

/**
 * The editor's blocks for a queued tree. Every name must be registered and
 * every attribute one its type has (createBlock() would drop it silently,
 * and the text it held with it); each problem is named with its path.
 */
export function blocksFromSpecs(
  api: EditorWp["blocks"],
  specs: BlockSpecNode[],
  clone: <T>(value: T) => T = (value) => value,
): EditorBlock[] {
  const missing = new Set<string>();
  const unknown: string[] = [];

  const check = (nodes: BlockSpecNode[], path: string) => {
    nodes.forEach((node, index) => {
      const at = `${path}[${index}]`;
      const type = api.getBlockType(node.name);

      if (!type) {
        missing.add(node.name);
      } else {
        const known = Object.keys(type.attributes ?? {});
        for (const key of Object.keys(node.attributes ?? {})) {
          if (!known.includes(key) && !SHARED_ATTRIBUTES.includes(key)) {
            unknown.push(
              `${at} ${node.name}: no attribute "${key}" (it has ${known.join(", ") || "none"})`,
            );
          }
        }
      }

      check(node.innerBlocks ?? [], `${at}.innerBlocks`);
    });
  };
  check(specs, "blocks");

  if (missing.size > 0) {
    throw new BridgeError(
      "missing_block_registration",
      `The block editor does not register ${[...missing].join(", ")}.`,
    );
  }

  if (unknown.length > 0) {
    throw new BridgeError(
      "invalid_block_spec",
      `Unknown attributes: ${unknown.slice(0, 5).join("; ")}${unknown.length > 5 ? ` (and ${unknown.length - 5} more)` : ""}.`,
    );
  }

  const build = (node: BlockSpecNode): EditorBlock => {
    try {
      return api.createBlock(
        node.name,
        clone(node.attributes ?? {}),
        (node.innerBlocks ?? []).map(build),
      );
    } catch (error) {
      throw new BridgeError(
        "invalid_block_spec",
        `${node.name} could not be built: ${errorText(error)}`,
      );
    }
  };

  return specs.map(build);
}

/**
 * The editor's blocks for markup: parsed, and every block it finds
 * invalid rebuilt from its attributes and (rebuilt) inner blocks.
 */
export function blocksFromMarkup(
  api: EditorWp["blocks"],
  content: string,
): { blocks: EditorBlock[]; recovered: number } {
  let parsed: EditorBlock[];
  try {
    parsed = api.parse(content);
  } catch (error) {
    throw new BridgeError(
      "serializer_failed",
      `The block editor could not read the content: ${errorText(error)}`,
    );
  }

  const missing = new Set<string>();
  const findMissing = (blocks: EditorBlock[]) => {
    for (const block of blocks) {
      if (block.name === "core/missing") {
        missing.add(
          String(block.attributes.originalName ?? "an unknown block"),
        );
      }
      findMissing(block.innerBlocks ?? []);
    }
  };
  findMissing(parsed);

  if (missing.size > 0) {
    throw new BridgeError(
      "missing_block_registration",
      `The block editor does not register ${[...missing].join(", ")}.`,
    );
  }

  let recovered = 0;
  const recover = (block: EditorBlock): EditorBlock => {
    const inner = (block.innerBlocks ?? []).map(recover);

    if (block.isValid === false) {
      recovered++;

      return api.createBlock(block.name, block.attributes, inner);
    }

    // The parsed tree is ours: keep each block in the editor's own realm.
    block.innerBlocks = inner;

    return block;
  };

  return { blocks: parsed.map(recover), recovered };
}

/** Every block name in a tree, once each, in order. */
export function namesIn(blocks: EditorBlock[]): string[] {
  const names = new Set<string>();
  const walk = (list: EditorBlock[]) => {
    for (const block of list) {
      names.add(block.name);
      walk(block.innerBlocks ?? []);
    }
  };
  walk(blocks);

  return [...names];
}

/** Blocks the editor still finds invalid, by name. */
export function invalidIn(blocks: EditorBlock[]): string[] {
  const invalid: string[] = [];
  const walk = (list: EditorBlock[]) => {
    for (const block of list) {
      if (block.isValid === false) {
        invalid.push(block.name);
      }
      walk(block.innerBlocks ?? []);
    }
  };
  walk(blocks);

  return invalid;
}

// ──────────────────────────────────────────────
//  Waiting
// ──────────────────────────────────────────────

/**
 * Polls `check` until it gives a value (not null, undefined or false);
 * after `timeoutMs`, throws `editor_timeout` with `what`.
 */
export async function waitFor<T>(
  check: () => T | null | undefined | false,
  what: string,
  {
    timeoutMs = 30000,
    intervalMs = 200,
    sleep = defaultSleep,
    now = Date.now,
  }: {
    timeoutMs?: number;
    intervalMs?: number;
    sleep?: (ms: number) => Promise<void>;
    now?: () => number;
  } = {},
): Promise<T> {
  const deadline = now() + timeoutMs;

  for (;;) {
    const value = check();
    if (value !== null && value !== undefined && value !== false) {
      return value;
    }
    if (now() >= deadline) {
      throw new BridgeError(
        "editor_timeout",
        `${what} took longer than ${Math.round(timeoutMs / 1000)} s.`,
      );
    }
    await sleep(intervalMs);
  }
}

/**
 * The content once two reads in a row are the same (blocks' edit() effects
 * can change attributes for a few frames after they mount), or the last
 * read after `timeoutMs`.
 */
export async function settle(
  read: () => string,
  {
    timeoutMs = 5000,
    intervalMs = 250,
    sleep = defaultSleep,
    now = Date.now,
  }: {
    timeoutMs?: number;
    intervalMs?: number;
    sleep?: (ms: number) => Promise<void>;
    now?: () => number;
  } = {},
): Promise<string> {
  const deadline = now() + timeoutMs;
  let previous = read();

  while (now() < deadline) {
    await sleep(intervalMs);
    const current = read();
    if (current === previous) {
      return current;
    }
    previous = current;
  }

  return previous;
}

// ──────────────────────────────────────────────
//  In the editor
// ──────────────────────────────────────────────

/**
 * Applies one change in the editor of `job.post_id` and returns the
 * content as that editor saves it. The editor's own saving and autosaving
 * stay locked throughout.
 */
export async function finalizeInEditor(
  wp: EditorWp,
  job: BridgeJob,
  options: BridgeOptions = {},
): Promise<BridgeResult> {
  const timing = {
    intervalMs: options.intervalMs ?? 200,
    sleep: options.sleep ?? defaultSleep,
    now: options.now ?? Date.now,
  };

  const editorSelect = () => wp.data.select("core/editor");
  const blockSelect = () => wp.data.select("core/block-editor");

  await waitFor(
    () => {
      const editor = editorSelect();
      if (!editor || !blockSelect()) {
        return false;
      }
      if (
        typeof editor.__unstableIsEditorReady === "function" &&
        !editor.__unstableIsEditorReady()
      ) {
        return false;
      }
      if (
        typeof wp.blocks.getBlockTypes === "function" &&
        wp.blocks.getBlockTypes().length === 0
      ) {
        return false;
      }

      return Number(editor.getCurrentPostId?.()) === job.post_id;
    },
    "Opening the post in the block editor",
    { ...timing, timeoutMs: options.readyTimeoutMs ?? 30000 },
  );

  const editorDispatch = wp.data.dispatch("core/editor");
  // The editor itself must never save this experiment.
  editorDispatch.lockPostSaving?.(SAVE_LOCK);
  editorDispatch.lockPostAutosaving?.(SAVE_LOCK);

  const built =
    job.mode === "blocks"
      ? {
          blocks: blocksFromSpecs(wp.blocks, job.blocks, options.clone),
          recovered: 0,
        }
      : blocksFromMarkup(wp.blocks, job.content);

  try {
    // In the post editor the block-editor store follows core/editor, so the
    // blocks go in through core/editor (a direct resetBlocks() would be
    // replaced by the post's own blocks).
    if (typeof editorDispatch.resetEditorBlocks === "function") {
      editorDispatch.resetEditorBlocks(built.blocks);
    } else {
      wp.data.dispatch("core/block-editor").resetBlocks(built.blocks);
    }
  } catch (error) {
    throw new BridgeError(
      "serializer_failed",
      `The block editor refused the blocks: ${errorText(error)}`,
    );
  }

  const expected = built.blocks.map((block) => block.name).join("\n");
  await waitFor(
    () =>
      (blockSelect().getBlocks() as EditorBlock[])
        .map((block) => block.name)
        .join("\n") === expected,
    "Loading the blocks into the editor",
    { ...timing, timeoutMs: 5000 },
  );

  const serialize = (): string => {
    const editor = editorSelect();
    if (typeof editor.getEditedPostContent === "function") {
      return String(editor.getEditedPostContent());
    }

    return wp.blocks.serialize(blockSelect().getBlocks());
  };

  let content: string;
  try {
    content = await settle(serialize, {
      ...timing,
      intervalMs: 250,
      timeoutMs: options.settleTimeoutMs ?? 5000,
    });
  } catch (error) {
    throw new BridgeError(
      "serializer_failed",
      `The block editor could not write the blocks: ${errorText(error)}`,
    );
  }

  const final = blockSelect().getBlocks() as EditorBlock[];
  const invalid = invalidIn(final);
  if (invalid.length > 0) {
    throw new BridgeError(
      "serializer_failed",
      `The block editor still finds these blocks invalid: ${[...new Set(invalid)].join(", ")}.`,
    );
  }

  return {
    content,
    recovered: built.recovered,
    registered: namesIn(final).filter((name) =>
      Boolean(wp.blocks.getBlockType(name)),
    ),
  };
}

export function errorText(error: unknown): string {
  return error instanceof Error ? error.message : String(error);
}
