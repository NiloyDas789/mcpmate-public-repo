import { useId, useState } from "react";
import { DownOutlined } from "@ant-design/icons";
import { __, sprintf } from "@wordpress/i18n";
import Button from "@components/ui/Button";
import Card from "@components/ui/Card";
import CopyField from "@components/ui/CopyField";
import Notice from "@components/ui/Notice";
import Skeleton from "@components/ui/Skeleton";
import { requestErrorText } from "@utils/errors";
import type { ConnectionSummary } from "../../types/api";

function TechnicalDetails({ connection }: { connection: ConnectionSummary }) {
  const [open, setOpen] = useState(false);
  const id = useId();

  return (
    <div>
      <button
        type="button"
        className="el-disclosure"
        aria-expanded={open}
        aria-controls={id}
        onClick={() => setOpen(!open)}
      >
        {open
          ? __("Hide technical details", "mcpmate")
          : __("Show technical details", "mcpmate")}
        <DownOutlined aria-hidden className="el-disclosure-icon" />
      </button>
      <div id={id} hidden={!open}>
        <dl className="el-table-card m-0 mt-2 grid gap-1">
          <div className="el-table-card-row">
            <dt>{__("Sign-in address (OAuth issuer)", "mcpmate")}</dt>
            <dd>
              <bdi dir="ltr" className="el-mono">
                {connection.issuer}
              </bdi>
            </dd>
          </div>
          <div className="el-table-card-row">
            <dt>{__("AI apps may register themselves", "mcpmate")}</dt>
            <dd>
              {connection.open_registration
                ? __("Yes", "mcpmate")
                : __("No", "mcpmate")}
            </dd>
          </div>
        </dl>
      </div>
    </div>
  );
}

/**
 * Your MCP URL (docs/ui.md §5.2): the address AI apps add as a connector.
 * The plugin is the MCP server and its own sign-in (OAuth) server, so
 * nothing is registered anywhere first.
 */
export default function McpUrlCard({
  connection,
  loading,
  error,
  onRetry,
}: {
  connection: ConnectionSummary | undefined;
  loading: boolean;
  error: unknown;
  onRetry: () => void;
}) {
  const title = __("Your MCP URL", "mcpmate");

  if (loading || (!connection && !error)) {
    return (
      <Card title={title} busy>
        <Skeleton count={2} />
      </Card>
    );
  }

  if (!connection) {
    return (
      <Card title={title}>
        <Notice
          tone="danger"
          title={__("Couldn't load this site's MCP URL.", "mcpmate")}
          action={
            <Button variant="secondary" size="sm" onClick={onRetry}>
              {__("Try again", "mcpmate")}
            </Button>
          }
        >
          {requestErrorText(error)}
        </Notice>
      </Card>
    );
  }

  return (
    <Card title={title}>
      <div className="el-stack">
        <p className="m-0">
          {__(
            "Add this address to your AI app. The app connects to this site directly, and you approve it here.",
            "mcpmate",
          )}
        </p>

        <CopyField
          value={connection.mcp_url}
          label={__("MCP URL", "mcpmate")}
        />

        {connection.public_url && (
          <p className="m-0 text-el-text-secondary">
            {sprintf(
              /* translators: %s: the tunnel's address (MCPMATE_PUBLIC_URL). */
              __(
                "AI apps reach this site through your tunnel, %s (MCPMATE_PUBLIC_URL).",
                "mcpmate",
              ),
              connection.public_url,
            )}
          </p>
        )}

        <TechnicalDetails connection={connection} />
      </div>
    </Card>
  );
}
