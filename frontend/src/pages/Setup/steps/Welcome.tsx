import {
  DeleteOutlined,
  EditOutlined,
  SafetyOutlined,
} from "@ant-design/icons";
import { Button, Typography } from "antd";
import { __ } from "@wordpress/i18n";
import { StepFooter, StepHeading, type StepProps } from "../shared";

const { Paragraph, Text } = Typography;

/**
 * Step 1: what the plugin does and what it will never do. These promises
 * hold for the plugin's own tools; other plugins' abilities are the
 * exception (known gap 21), which step 3 says where they appear.
 */
export default function Welcome({ state, goTo }: StepProps) {
  const rerun =
    state.onboarding.source === "rerun" ||
    state.onboarding.status === "completed";

  const promises = [
    {
      icon: <EditOutlined />,
      title: __("Drafts by default.", "mcpmate"),
      text: __(
        "Posts and pages the AI writes are saved as drafts. Publishing stays with you.",
        "mcpmate",
      ),
    },
    {
      icon: <DeleteOutlined />,
      title: __("Deletes go to the trash.", "mcpmate"),
      text: __(
        "The AI can move things to the trash, never delete them for good.",
        "mcpmate",
      ),
    },
    {
      icon: <SafetyOutlined />,
      title: __("Risky actions wait for you.", "mcpmate"),
      text: __(
        "Things like switching themes or changing plugins need your approval on this site first.",
        "mcpmate",
      ),
    },
  ];

  return (
    <>
      <StepHeading>{__("Connect your AI to this site", "mcpmate")}</StepHeading>
      <Paragraph>
        {__(
          "McpMate lets AI apps such as Claude, ChatGPT and Cursor work on your WordPress site, with you in control.",
          "mcpmate",
        )}
      </Paragraph>

      <ul className="m-0 list-none p-0">
        {promises.map((promise) => (
          <li key={promise.title} className="mb-3 flex gap-3">
            <span aria-hidden="true" className="text-lg text-[#2271b1]">
              {promise.icon}
            </span>
            <span>
              <Text strong>{promise.title}</Text> {promise.text}
            </span>
          </li>
        ))}
      </ul>

      {rerun && (
        <Paragraph>
          {__(
            "Your site is already connected. Running setup again changes nothing until you choose to.",
            "mcpmate",
          )}
        </Paragraph>
      )}

      <StepFooter>
        <Text type="secondary">
          {__("Setup takes about 3 minutes.", "mcpmate")}
        </Text>
        <Button type="primary" onClick={() => goTo("check")}>
          {rerun ? __("Start again", "mcpmate") : __("Get started", "mcpmate")}
        </Button>
      </StepFooter>
    </>
  );
}
