import { __, sprintf } from "@wordpress/i18n";
import Notice from "@components/ui/Notice";
import { isLoginLinkPreview, isPhpPreview, isShellPreview } from "./hatchLogic";

/**
 * The previews of MCPMate Pro's opt-in hatches (AGENTS.md, rules 6 and 9):
 * execute_php's whole code, a shell WP-CLI command's argv, and who a login
 * link signs in, from where. Code and commands are plain text, never
 * rendered.
 */

/** The preview for a hatch request, or null for any other tool. */
export default function HatchPreview({
  tool,
  preview,
}: {
  tool: string;
  preview: unknown;
}) {
  if (tool === "php" && isPhpPreview(preview)) {
    return (
      <div className="el-stack">
        <Notice
          tone="warning"
          title={__(
            "Approving runs this PHP once with full access to the site.",
            "mcpmate",
          )}
        >
          {preview.note}
        </Notice>
        <p className="m-0">
          {sprintf(
            /* translators: 1: number of lines, 2: time limit in seconds, 3: start of the SHA-256. */
            __("%1$d lines, time limit %2$d s, SHA-256 %3$s…", "mcpmate"),
            preview.lines,
            preview.timeout,
            preview.sha256.slice(0, 12),
          )}
        </p>
        <pre
          className="el-json el-code-block"
          tabIndex={0}
          role="region"
          aria-label={__("PHP code to run", "mcpmate")}
          dir="ltr"
        >
          {preview.code}
        </pre>
      </div>
    );
  }

  if (tool === "shell_cli" && isShellPreview(preview)) {
    return (
      <div className="el-stack">
        <Notice
          tone="warning"
          title={__(
            "Approving runs this WP-CLI command on the server.",
            "mcpmate",
          )}
        >
          {preview.note}
        </Notice>
        <pre
          className="el-json el-code-block"
          tabIndex={0}
          role="region"
          aria-label={__("WP-CLI command to run", "mcpmate")}
          dir="ltr"
        >
          {preview.command}
        </pre>
        <p className="m-0 text-el-text-secondary">
          {sprintf(
            /* translators: %d: time limit in seconds. */
            __(
              "Each argument WP-CLI receives, one per line, exactly as passed (no shell reads them). MCPMate adds this site's path and URL and the AI app's user. Time limit %d s.",
              "mcpmate",
            ),
            preview.timeout,
          )}
        </p>
        <ol
          className="el-stack-tight m-0 ps-5"
          aria-label={__("WP-CLI arguments", "mcpmate")}
        >
          {(preview.argv ?? preview.args).map((arg, index) => (
            <li key={index}>
              <bdi dir="ltr" className="el-mono">
                {JSON.stringify(arg)}
              </bdi>
            </li>
          ))}
        </ol>
      </div>
    );
  }

  if (tool === "login_link" && isLoginLinkPreview(preview)) {
    return (
      <div className="el-stack">
        <Notice
          tone="warning"
          title={sprintf(
            /* translators: %s: user's display name. */
            __("Approving lets an AI app's browser sign in as %s.", "mcpmate"),
            preview.user.display_name || preview.user.login,
          )}
        >
          {preview.note}
        </Notice>
        <dl className="el-stack-tight m-0">
          <div>
            <dt>{__("AI app", "mcpmate")}</dt>
            <dd>{preview.app || __("Unnamed app", "mcpmate")}</dd>
          </div>
          <div>
            <dt>{__("Only from this address", "mcpmate")}</dt>
            <dd>
              <bdi dir="ltr" className="el-mono">
                {preview.ip}
              </bdi>
            </dd>
          </div>
          <div>
            <dt>{__("Valid after approval", "mcpmate")}</dt>
            <dd>
              {sprintf(
                /* translators: %d: minutes. */
                __("%d minutes, once", "mcpmate"),
                preview.minutes,
              )}
            </dd>
          </div>
        </dl>
      </div>
    );
  }

  return null;
}
