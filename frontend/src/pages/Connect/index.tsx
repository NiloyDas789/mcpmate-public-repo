import { useMutation } from "@tanstack/react-query";
import {
  Alert,
  Button,
  Card,
  Checkbox,
  Descriptions,
  Space,
  Tag,
  Typography,
  message,
} from "antd";
import { useState } from "react";
import { __, sprintf } from "@wordpress/i18n";
import { decideConnection } from "@services/api";
import { isSameOrigin } from "@utils/connectLink";
import { goTo } from "@utils/navigate";
import { postSetupMessage } from "@utils/setupChannel";
import { formatUtc } from "@utils/time";
import type { ConnectRequestDetails } from "../../types/api";

const { Title, Paragraph, Text } = Typography;

function Vouching({ vouchedBy }: { vouchedBy: string }) {
  if (vouchedBy.startsWith("cimd:")) {
    return (
      <span>
        {__("Vouched for by", "mcpmate")} <Text code>{vouchedBy.slice(5)}</Text>
      </span>
    );
  }

  return (
    <Tag color="orange">
      {__("Registered automatically, not verified", "mcpmate")}
    </Tag>
  );
}

/**
 * The AI-app approval screen (`#/connect`, opened from
 * `admin.php?page=mcpmate&connect=<request>`). PHP checked the request
 * before the page loaded: an authorization request of the site's own OAuth
 * server. Approve and Deny are nonce-checked admin-ajax calls that check it
 * again, use it up, and return where the browser goes next: the AI app's
 * redirect URI. Approval is never skipped or remembered.
 */
export default function Connect() {
  const request = SERVER_VARIABLES.connectRequest;
  const [readOnly, setReadOnly] = useState(false);

  const decide = useMutation({
    mutationFn: ({ decision }: { decision: "approve" | "reject" }) =>
      decideConnection(request as ConnectRequestDetails, decision, readOnly),
    onSuccess: ({ redirect_url: url, grant }, { decision }) => {
      // Tell a setup wizard open in this browser before leaving the page.
      postSetupMessage({
        type: decision === "approve" ? "grant-approved" : "grant-denied",
        grant_id: grant?.grant_id,
        client_name: (request as ConnectRequestDetails).client.name,
      });

      const expected = (request as ConnectRequestDetails).redirect_origin;

      if (!isSameOrigin(url, expected)) {
        message.error(
          __("The approval returned an unexpected address.", "mcpmate"),
        );
        return;
      }

      goTo(url);
    },
    onError: (error) => {
      message.error(error instanceof Error ? error.message : String(error));
    },
  });

  if (!request) {
    return (
      <div className="max-w-[720px]">
        <Title level={1}>{__("Connect an AI app", "mcpmate")}</Title>
        <Alert
          type="info"
          showIcon
          message={__("There is no approval request on this page.", "mcpmate")}
          description={__(
            "Start connecting from your AI app; it opens this page with the request.",
            "mcpmate",
          )}
        />
      </div>
    );
  }

  if (!request.ok) {
    return (
      <div className="max-w-[720px]">
        <Title level={1}>{__("Connect an AI app", "mcpmate")}</Title>
        <Alert type="error" showIcon message={request.message} />
      </div>
    );
  }

  const canWrite = request.scopes.includes("site:write");
  const done = decide.isSuccess;

  return (
    <div className="max-w-[720px]">
      <Title level={1}>
        {sprintf(
          /* translators: 1: AI app name, 2: site address. */
          __("Connect %1$s to %2$s?", "mcpmate"),
          request.client.name,
          request.site,
        )}
      </Title>

      {request.client.localhost_redirect && (
        <Alert
          type="warning"
          showIcon
          className="mb-4"
          message={__("This app receives access on this computer.", "mcpmate")}
          description={__(
            "Its address is localhost: any program on this computer could be receiving this access. Approve only if you started connecting it yourself.",
            "mcpmate",
          )}
        />
      )}

      <Card className="mb-4" size="small">
        <Descriptions column={1} size="small" bordered>
          <Descriptions.Item label={__("App", "mcpmate")}>
            <Space direction="vertical" size={0}>
              <Text strong>{request.client.name}</Text>
              <Vouching vouchedBy={request.client.vouched_by} />
            </Space>
          </Descriptions.Item>
          <Descriptions.Item label={__("Sends access to", "mcpmate")}>
            <Text code>{request.client.redirect_host}</Text>
          </Descriptions.Item>
          <Descriptions.Item label={__("Acts as", "mcpmate")}>
            {sprintf(
              /* translators: 1: display name, 2: login, 3: role(s). */
              __("You: %1$s (%2$s, %3$s)", "mcpmate"),
              request.user.display_name,
              request.user.login,
              request.user.roles.join(", "),
            )}
          </Descriptions.Item>
          <Descriptions.Item label={__("Request expires", "mcpmate")}>
            {formatUtc(request.expires_at)}
          </Descriptions.Item>
        </Descriptions>
      </Card>

      <Card
        title={__("What the app can do", "mcpmate")}
        className="mb-4"
        size="small"
      >
        <ul className="m-0 pl-5">
          <li>
            <Text strong>{__("Read", "mcpmate")}</Text>
            {": "}
            {__(
              "site information, pages and posts, the audit log, and the inspection features enabled in Settings.",
              "mcpmate",
            )}
          </li>
          {canWrite && !readOnly && (
            <li>
              <Text strong>{__("Make changes", "mcpmate")}</Text>
              {": "}
              {__(
                "drafts, uploads, theme drafts and the other actions enabled in Settings. Destructive actions still wait for your approval, and nothing is published.",
                "mcpmate",
              )}
            </li>
          )}
        </ul>
        {canWrite && (
          <Checkbox
            className="mt-3"
            checked={readOnly}
            onChange={(event) => setReadOnly(event.target.checked)}
          >
            {__("Read only", "mcpmate")}
          </Checkbox>
        )}
        <Paragraph type="secondary" className="mb-0 mt-3">
          {__(
            "The app works with your permissions and you can revoke it at any time under Connected AI apps.",
            "mcpmate",
          )}
        </Paragraph>
      </Card>

      <Space>
        <Button
          type="primary"
          disabled={done}
          loading={decide.isPending && decide.variables?.decision === "approve"}
          onClick={() => decide.mutate({ decision: "approve" })}
        >
          {__("Approve", "mcpmate")}
        </Button>
        <Button
          disabled={done}
          loading={decide.isPending && decide.variables?.decision === "reject"}
          onClick={() => decide.mutate({ decision: "reject" })}
        >
          {__("Deny", "mcpmate")}
        </Button>
      </Space>
    </div>
  );
}
