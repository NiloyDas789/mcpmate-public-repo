import { BridgeError, type EditorWp } from "./editorBridge";

/** What an open editor gives the bridge: its `wp`, and a copy into its realm. */
export interface OpenEditor {
  wp: EditorWp;
  clone: <T>(value: T) => T;
}

export interface EditorFrame {
  open(url: string): Promise<OpenEditor>;
  close(): void;
}

/**
 * The hidden block editor: one same-origin iframe of
 * `post.php?…&mcpmate_finalizer=1`, out of sight and out of the tab order,
 * inside `container`. A new iframe per post, removed after, so the editor
 * of one post never asks about unsaved changes when the next one opens.
 */
export function createEditorFrame(
  container: HTMLElement,
  { loadTimeoutMs = 60000, startTimeoutMs = 30000, intervalMs = 200 } = {},
): EditorFrame {
  let frame: HTMLIFrameElement | null = null;

  const close = () => {
    frame?.remove();
    frame = null;
  };

  const open = async (url: string): Promise<OpenEditor> => {
    close();

    const target = new URL(url, window.location.href);
    if (target.origin !== window.location.origin) {
      throw new BridgeError(
        "frame_inaccessible",
        "The editor address is not on this site.",
      );
    }

    const iframe = document.createElement("iframe");
    iframe.title = "Block editor (used by MCPMate to finalize a batch)";
    iframe.tabIndex = -1;
    iframe.setAttribute("aria-hidden", "true");
    iframe.className = "el-finalizer-frame";
    frame = iframe;

    await new Promise<void>((resolve, reject) => {
      const timer = window.setTimeout(() => {
        reject(
          new BridgeError(
            "editor_timeout",
            `The block editor did not load in ${Math.round(loadTimeoutMs / 1000)} s.`,
          ),
        );
      }, loadTimeoutMs);
      iframe.addEventListener(
        "load",
        () => {
          window.clearTimeout(timer);
          resolve();
        },
        { once: true },
      );
      iframe.src = target.href;
      container.appendChild(iframe);
    });

    const deadline = Date.now() + startTimeoutMs;
    for (;;) {
      let view: (Window & { wp?: Partial<EditorWp>; JSON: JSON }) | null;
      try {
        view = iframe.contentWindow as
          | (Window & { wp?: Partial<EditorWp>; JSON: JSON })
          | null;
        // Reading a property is what fails when the page is not same-origin after all.
        void view?.location.href;
      } catch {
        throw new BridgeError(
          "frame_inaccessible",
          "This page cannot reach the block editor (the browser isolated it). Check for a security header or plugin that isolates wp-admin pages.",
        );
      }

      if (view?.wp?.blocks && view.wp.data) {
        const frameWindow = view;

        return {
          wp: view.wp as EditorWp,
          clone: <T>(value: T): T =>
            frameWindow.JSON.parse(JSON.stringify(value)) as T,
        };
      }

      if (Date.now() >= deadline) {
        throw new BridgeError(
          "editor_unavailable",
          "The block editor did not start for this post. It may use the classic editor, or the editor page shows an error.",
        );
      }

      await new Promise((resolve) => window.setTimeout(resolve, intervalMs));
    }
  };

  return { open, close };
}
