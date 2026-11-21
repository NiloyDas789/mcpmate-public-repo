import { __, _n, sprintf } from "@wordpress/i18n";
import type {
  ChatPlanCall,
  ChatStreamEvent,
  ChatToolKind,
  ChatToolStatus,
} from "../../types/api";

/** ChatSessions' limits, checked here first with the same words. */
export const MAX_IMAGES = 4;
export const MAX_IMAGE_BYTES = 3 * 1024 * 1024;
export const IMAGE_TYPES = [
  "image/jpeg",
  "image/png",
  "image/gif",
  "image/webp",
];

/** The open conversation lives in the hash query: `#/chat?session=12`. */
export const SESSION_PARAM = "session";

export function sessionFromSearch(search: string): number | null {
  const value = Number(new URLSearchParams(search).get(SESSION_PARAM));

  return Number.isInteger(value) && value > 0 ? value : null;
}

/** Why these files cannot be attached, or null. */
export function imageProblem(
  files: { type: string; size: number }[],
  attached: number,
): string | null {
  if (attached + files.length > MAX_IMAGES) {
    return __("Attach at most 4 images to one message.", "mcpmate");
  }

  if (files.some((file) => !IMAGE_TYPES.includes(file.type))) {
    return __("Images must be JPEG, PNG, GIF or WebP.", "mcpmate");
  }

  if (files.some((file) => file.size > MAX_IMAGE_BYTES)) {
    return __("Each image may be 3 MB at most.", "mcpmate");
  }

  return null;
}

export function kindLabel(kind: ChatToolKind): string {
  switch (kind) {
    case "read":
      return __("Reads", "mcpmate");
    case "destructive":
      return __(
        "Waits for your approval on Approvals before it changes the site",
        "mcpmate",
      );
    default:
      return __("Changes the site once you approve it with Run", "mcpmate");
  }
}

export function statusLabel(status: ChatToolStatus): string {
  switch (status) {
    case "ok":
      return __("Done", "mcpmate");
    case "error":
      return __("Failed", "mcpmate");
    case "declined":
      return __("Skipped", "mcpmate");
    case "approval":
      return __("Waiting for your approval", "mcpmate");
    case "pending":
      return __("Waiting for you", "mcpmate");
    default:
      return __("Not run", "mcpmate");
  }
}

/** The plan's heading: how many calls would change the site. */
export function planTitle(plan: ChatPlanCall[]): string {
  const writes = plan.filter((call) => call.kind !== "read" && !call.invalid);

  return sprintf(
    /* translators: %d: number of tool calls that change the site. */
    _n(
      "Claude wants to make %d change",
      "Claude wants to make %d changes",
      writes.length,
      "mcpmate",
    ),
    writes.length,
  );
}

/** What streams in before the step is done (ChatStreamEvent). */
export interface Live {
  text: string;
  thinking: string;
  tools: {
    id: string;
    name: string;
    status?: ChatToolStatus;
    summary?: string;
  }[];
  notices: string[];
}

export const EMPTY_LIVE: Live = {
  text: "",
  thinking: "",
  tools: [],
  notices: [],
};

export function applyEvent(live: Live, event: ChatStreamEvent): Live {
  switch (event.type) {
    case "text":
      return { ...live, text: live.text + event.data.delta };
    case "thinking":
      return { ...live, thinking: live.thinking + event.data.delta };
    case "tool":
      return live.tools.some((tool) => tool.id === event.data.id)
        ? live
        : {
            ...live,
            tools: [
              ...live.tools,
              { id: event.data.id, name: event.data.name },
            ],
          };
    case "tool_result": {
      const known = live.tools.some((tool) => tool.id === event.data.id);
      const update = { status: event.data.status, summary: event.data.summary };
      return {
        ...live,
        tools: known
          ? live.tools.map((tool) =>
              tool.id === event.data.id ? { ...tool, ...update } : tool,
            )
          : [
              ...live.tools,
              { id: event.data.id, name: event.data.name, ...update },
            ],
      };
    }
    case "fallback":
      return {
        ...live,
        notices: [
          ...live.notices,
          sprintf(
            /* translators: 1: model that declined, 2: model that answered. */
            __("%1$s declined; %2$s continued.", "mcpmate"),
            event.data.from,
            event.data.to,
          ),
        ],
      };
    default:
      return live;
  }
}

/** A file as base64, for `chat/send` (the server reads the type from the bytes). */
export function fileToBase64(file: Blob): Promise<string> {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => {
      const result = String(reader.result ?? "");
      resolve(result.slice(result.indexOf(",") + 1));
    };
    reader.onerror = () => reject(reader.error ?? new Error("read failed"));
    reader.readAsDataURL(file);
  });
}

/** "1.2 MB", "340 kB". */
export function bytesText(bytes: number): string {
  if (bytes >= 1024 * 1024) {
    return sprintf(
      /* translators: %s: size in megabytes. */
      __("%s MB", "mcpmate"),
      (bytes / (1024 * 1024)).toFixed(1),
    );
  }

  return sprintf(
    /* translators: %s: size in kilobytes. */
    __("%s kB", "mcpmate"),
    String(Math.max(1, Math.round(bytes / 1024))),
  );
}
