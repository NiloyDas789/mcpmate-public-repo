import { useId, useState } from "react";
import { ExportOutlined } from "@ant-design/icons";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { __, sprintf } from "@wordpress/i18n";

import Button from "@components/ui/Button";
import Card from "@components/ui/Card";
import ConfirmDialog from "@components/ui/ConfirmDialog";
import Notice from "@components/ui/Notice";
import Skeleton from "@components/ui/Skeleton";
import { useAnnounce } from "@/hooks/useAnnounce";
import {
  activateLicense,
  deactivateLicense,
  fetchLicense,
} from "@services/api";
import { requestErrorText } from "@utils/errors";
import { formatDate } from "@utils/format";
import type { LicenseStatus } from "../../types/api";

const LICENSE_QUERY_KEY = ["license"];

/**
 * Settings → License, shown while MCPMate Pro is active. The key is
 * write-only: the page sees it masked. Its routes (admin-ajax `license/*`)
 * come from the pro add-on, so nothing here runs without it.
 */
export default function LicenseCard() {
  const license = useQuery({
    queryKey: LICENSE_QUERY_KEY,
    queryFn: fetchLicense,
  });

  let content;

  if (license.isPending) {
    content = <Skeleton count={2} />;
  } else if (license.isError) {
    content = (
      <Notice
        tone="danger"
        title={__("The license could not be checked.", "mcpmate")}
        action={
          <Button size="sm" onClick={() => void license.refetch()}>
            {__("Try again", "mcpmate")}
          </Button>
        }
      >
        {requestErrorText(license.error)}
      </Notice>
    );
  } else if (license.data.valid) {
    content = <ActiveLicense license={license.data} />;
  } else {
    content = <LicenseForm license={license.data} />;
  }

  return (
    <Card title={__("License", "mcpmate")} busy={license.isPending}>
      {content}
    </Card>
  );
}

function ActiveLicense({ license }: { license: LicenseStatus }) {
  const queryClient = useQueryClient();
  const announce = useAnnounce();
  const [confirm, setConfirm] = useState(false);

  const remove = useMutation({
    mutationFn: deactivateLicense,
    onSuccess: (status) => {
      queryClient.setQueryData(LICENSE_QUERY_KEY, status);
      announce(__("License removed from this site.", "mcpmate"));
    },
  });

  const updatesUntil = license.update_expires_at
    ? formatDate(license.update_expires_at, { dateStyle: "long" })
    : "";

  return (
    <div className="el-license">
      <p className="el-license-status">
        {sprintf(
          /* translators: %s: the site's domain, e.g. "example.com". */
          __("MCPMate Pro is active for %s.", "mcpmate"),
          license.domain,
        )}
      </p>
      <dl className="el-license-details">
        <dt>{__("License key", "mcpmate")}</dt>
        <dd className="el-mono">{license.license_key}</dd>
        <dt>{__("Updates", "mcpmate")}</dt>
        <dd>
          {!license.update_entitled
            ? __("Not included any more", "mcpmate")
            : updatesUntil
              ? sprintf(
                  /* translators: %s: a date, e.g. "November 2, 2027". */
                  __("Until %s", "mcpmate"),
                  updatesUntil,
                )
              : __("Included", "mcpmate")}
        </dd>
      </dl>
      <Button
        variant="secondary"
        size="sm"
        disabled={remove.isPending}
        onClick={() => setConfirm(true)}
      >
        {__("Remove license", "mcpmate")}
      </Button>
      {remove.isError && (
        <p className="el-field-error" role="alert">
          {requestErrorText(remove.error)}
        </p>
      )}
      <ConfirmDialog
        open={confirm}
        tone="danger"
        title={__("Remove the license from this site?", "mcpmate")}
        body={
          <p>
            {__(
              "MCPMate Pro's features stop working here until a license key is entered again.",
              "mcpmate",
            )}
          </p>
        }
        confirmLabel={__("Remove license", "mcpmate")}
        cancelLabel={__("Keep it", "mcpmate")}
        onConfirm={() => remove.mutateAsync().then(() => undefined)}
        onClose={() => setConfirm(false)}
      />
    </div>
  );
}

function LicenseForm({ license }: { license: LicenseStatus }) {
  const id = useId();
  const queryClient = useQueryClient();
  const announce = useAnnounce();
  const [key, setKey] = useState("");
  const upgradeUrl = SERVER_VARIABLES.links.upgrade;

  const activate = useMutation({
    mutationFn: activateLicense,
    onSuccess: (status) => {
      setKey("");
      queryClient.setQueryData(LICENSE_QUERY_KEY, status);
      announce(__("License activated.", "mcpmate"));
    },
  });

  return (
    <form
      className="el-license"
      onSubmit={(event) => {
        event.preventDefault();
        activate.mutate(key.trim());
      }}
    >
      {license.has_key && (
        <Notice
          tone="warning"
          title={__("The saved license is not active.", "mcpmate")}
        >
          {__(
            "MCPMate Pro's features are off. Enter an active license key.",
            "mcpmate",
          )}
        </Notice>
      )}
      <div className="el-license-fields">
        <div>
          <label htmlFor={`${id}-key`} className="el-field-label">
            {__("License key", "mcpmate")}
          </label>
          <input
            id={`${id}-key`}
            type="password"
            className="el-input el-mono"
            autoComplete="off"
            spellCheck={false}
            value={key}
            onChange={(event) => setKey(event.target.value)}
          />
        </div>
        <Button
          htmlType="submit"
          variant="primary"
          size="sm"
          disabled={activate.isPending || key.trim() === ""}
        >
          {__("Activate license", "mcpmate")}
        </Button>
      </div>
      {activate.isError && (
        <p className="el-field-error" role="alert">
          {requestErrorText(activate.error)}
        </p>
      )}
      {upgradeUrl !== "" && (
        <p className="el-license-upgrade">
          <a href={upgradeUrl} target="_blank" rel="noopener noreferrer">
            {__("Get a license key", "mcpmate")}
            <ExportOutlined aria-hidden className="el-external-icon" />
            <span className="sr-only">
              {__("(opens in a new tab)", "mcpmate")}
            </span>
          </a>
        </p>
      )}
    </form>
  );
}
