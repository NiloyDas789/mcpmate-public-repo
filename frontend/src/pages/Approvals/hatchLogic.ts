/**
 * The previews MCPMate Pro's opt-in hatches store with their approvals
 * (PhpController, ShellCliController, LoginLinkController), and the guards
 * the Approvals page uses before it shows one.
 */

export interface PhpApprovalPreview {
  code: string;
  bytes: number;
  lines: number;
  sha256: string;
  timeout: number;
  note: string;
}

export interface ShellApprovalPreview {
  command: string;
  args: string[];
  /** Every argument WP-CLI receives, MCPMate's own included, exactly as passed. */
  argv?: string[];
  timeout: number;
  note: string;
}

export interface LoginLinkApprovalPreview {
  user: { id: number; login: string; display_name: string };
  app: string;
  ip: string;
  minutes: number;
  note: string;
}

const isObject = (value: unknown): value is Record<string, unknown> =>
  typeof value === "object" && value !== null;

const isString = (value: unknown): value is string => typeof value === "string";

const isNumber = (value: unknown): value is number =>
  typeof value === "number" && Number.isFinite(value);

const isStringList = (value: unknown): value is string[] =>
  Array.isArray(value) && value.every(isString);

// Every field the preview renders is checked, so a row from another version
// (or a damaged one) falls back to the generic preview instead of breaking it.
export function isPhpPreview(value: unknown): value is PhpApprovalPreview {
  return (
    isObject(value) &&
    isString(value.code) &&
    isString(value.sha256) &&
    isNumber(value.bytes) &&
    isNumber(value.lines) &&
    isNumber(value.timeout) &&
    isString(value.note)
  );
}

export function isShellPreview(value: unknown): value is ShellApprovalPreview {
  return (
    isObject(value) &&
    isString(value.command) &&
    isStringList(value.args) &&
    (value.argv === undefined || isStringList(value.argv)) &&
    isNumber(value.timeout) &&
    isString(value.note)
  );
}

export function isLoginLinkPreview(
  value: unknown,
): value is LoginLinkApprovalPreview {
  return (
    isObject(value) &&
    isObject(value.user) &&
    isNumber(value.user.id) &&
    isString(value.user.login) &&
    isString(value.user.display_name) &&
    isString(value.app) &&
    isString(value.ip) &&
    isNumber(value.minutes) &&
    isString(value.note)
  );
}

/** Whether $preview is the preview of a hatch request of $tool. */
export function isHatchPreview(tool: string, preview: unknown): boolean {
  return (
    (tool === "php" && isPhpPreview(preview)) ||
    (tool === "shell_cli" && isShellPreview(preview)) ||
    (tool === "login_link" && isLoginLinkPreview(preview))
  );
}
