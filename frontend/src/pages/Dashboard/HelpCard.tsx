import { ExportOutlined } from "@ant-design/icons";
import { useMutation } from "@tanstack/react-query";
import { useNavigate } from "react-router";
import { __ } from "@wordpress/i18n";
import Button from "@components/ui/Button";
import Card from "@components/ui/Card";
import Notice from "@components/ui/Notice";
import { updateOnboarding } from "@services/api";
import { requestErrorText } from "@utils/errors";

function External({ href, children }: { href: string; children: string }) {
  return (
    <a href={href} target="_blank" rel="noopener noreferrer">
      {children}
      <ExportOutlined aria-hidden className="el-external-icon" />
      <span className="sr-only">{__("(opens in a new tab)", "mcpmate")}</span>
    </a>
  );
}

/**
 * Help (docs/ui.md §5.1): the footer's links in the footer's order, plus
 * Run setup again. A link whose URL is not configured is hidden.
 */
export default function HelpCard() {
  const navigate = useNavigate();
  const { links } = SERVER_VARIABLES;
  const rerun = useMutation({
    mutationFn: () =>
      updateOnboarding({ step: "welcome", status: "in_progress" }),
    onSuccess: () => navigate("/setup"),
  });

  return (
    <Card title={__("Help", "mcpmate")}>
      <ul className="m-0 grid list-none gap-2 p-0">
        {links?.docs && (
          <li>
            <External href={links.docs}>
              {__("Read the documentation", "mcpmate")}
            </External>
          </li>
        )}
        <li>
          <Button
            variant="link"
            loading={rerun.isPending}
            onClick={() => rerun.mutate()}
          >
            {__("Run setup again", "mcpmate")}
          </Button>
        </li>
        {links?.support && (
          <li>
            <External href={links.support}>
              {__("Get support", "mcpmate")}
            </External>
          </li>
        )}
        {links?.feedback && (
          <li>
            <External href={links.feedback}>
              {__("Share feedback", "mcpmate")}
            </External>
          </li>
        )}
      </ul>
      {rerun.isError && (
        <Notice
          tone="danger"
          title={__("Couldn't start setup again.", "mcpmate")}
          live
        >
          {requestErrorText(rerun.error)}
        </Notice>
      )}
    </Card>
  );
}
