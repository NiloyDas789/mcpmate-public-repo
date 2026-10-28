import { useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { Alert, Button, Typography } from "antd";
import { Link } from "react-router";
import { __, sprintf } from "@wordpress/i18n";
import FeatureSwitchRow, {
  type SwitchRowStatus,
} from "@components/FeatureSwitchRow";
import { useFeatureSwitch } from "@/hooks/useFeatureSwitch";
import { ajaxRequest } from "@services/api";
import type {
  CliCommandsResponse,
  CoreProxyResponse,
  DbInspectResponse,
  ElementorSettingResponse,
  MediaUploadResponse,
  ThemeDraftsResponse,
} from "../../../types/api";
import { StepFooter, StepHeading, type StepProps } from "../shared";
import { applyBundle, type Bundle, bundleState } from "../logic";

const { Paragraph, Text } = Typography;

type Row =
  | "core_proxy"
  | "cli_read"
  | "cli_write"
  | "db_inspect"
  | "media"
  | "svg"
  | "theme_drafts"
  | "elementor";

/** Same key and shape as the Settings → CLI commands card. */
const CLI_QUERY_KEY = ["cli-commands"];

/**
 * Step 3: the safety choices, made before any AI gets access. Everything
 * starts off except Posts and pages (first run only); saving calls the
 * existing `…/update` routes, one after the other, for changed rows only.
 */
export default function Safety({ state, goTo, announce }: StepProps) {
  const queryClient = useQueryClient();
  const { features } = state;
  const firstRun = !state.onboarding.features_defaulted;

  const coreProxy = useFeatureSwitch<CoreProxyResponse>("core-proxy");
  const dbInspect = useFeatureSwitch<DbInspectResponse>("db-inspect");
  const media = useFeatureSwitch<MediaUploadResponse>("media-upload");
  const themeDrafts = useFeatureSwitch<ThemeDraftsResponse>("theme-drafts");
  const elementor = useFeatureSwitch<ElementorSettingResponse>("elementor", {
    enabled: features.elementor_available,
  });
  const cli = useQuery({
    queryKey: CLI_QUERY_KEY,
    queryFn: async () => {
      const response = await ajaxRequest<CliCommandsResponse>("cli-commands");

      if (!response.success) {
        throw new Error("Failed to load commands");
      }

      return response.data.commands;
    },
  });

  const [touched, setTouched] = useState<Partial<Record<Row, boolean>>>({});
  const [statuses, setStatuses] = useState<
    Partial<Record<Row, SwitchRowStatus>>
  >({});
  const [saving, setSaving] = useState(false);

  const commands = cli.data ?? [];
  const readBundle = bundleState(commands, "read");
  const writeBundle = bundleState(commands, "write");

  const saved: Record<Row, boolean | undefined> = {
    core_proxy: coreProxy.data?.enabled,
    cli_read: cli.data ? readBundle === "on" : undefined,
    cli_write: cli.data ? writeBundle === "on" : undefined,
    db_inspect: dbInspect.data?.enabled,
    media: media.data?.enabled,
    svg: media.data?.svg_enabled,
    theme_drafts: themeDrafts.data?.enabled,
    elementor: elementor.data?.enabled,
  };

  // The wizard's one default: Posts and pages on, offered on the first run only.
  const value = (row: Row): boolean =>
    touched[row] ??
    (row === "core_proxy" && firstRun ? true : (saved[row] ?? false));

  const visible: Row[] = [
    "core_proxy",
    "cli_read",
    "cli_write",
    "db_inspect",
    "media",
    "svg",
    ...(features.can_edit_themes ? (["theme_drafts"] as Row[]) : []),
    ...(features.elementor_available ? (["elementor"] as Row[]) : []),
  ];

  const loading =
    coreProxy.isLoading ||
    dbInspect.isLoading ||
    media.isLoading ||
    themeDrafts.isLoading ||
    cli.isLoading ||
    (features.elementor_available && elementor.isLoading);

  const changed = (row: Row): boolean =>
    saved[row] !== undefined &&
    value(row) !== saved[row] &&
    !(row === "cli_read" && readBundle === "custom") &&
    !(row === "cli_write" && writeBundle === "custom");

  const set = (row: Row) => (on: boolean) => {
    setTouched((current) => ({ ...current, [row]: on }));
    setStatuses((current) => ({ ...current, [row]: null }));
  };

  /** One save per route, in order; each changed row reports its own outcome. */
  const save = async (rows: Row[]) => {
    setSaving(true);
    const failed: Row[] = [];
    const mark = (list: Row[], status: SwitchRowStatus) =>
      setStatuses((current) => ({
        ...current,
        ...Object.fromEntries(list.map((row) => [row, status])),
      }));

    const calls: [Row[], () => Promise<unknown>][] = [];
    const simple: [
      Row,
      {
        save: {
          mutateAsync: (body: Record<string, unknown>) => Promise<unknown>;
        };
      },
      Record<string, unknown>,
    ][] = [
      ["core_proxy", coreProxy, { enabled: value("core_proxy") }],
      ["db_inspect", dbInspect, { enabled: value("db_inspect") }],
      ["media", media, { enabled: value("media") }],
      ["svg", media, { svg: value("svg") }],
      ["theme_drafts", themeDrafts, { enabled: value("theme_drafts") }],
      ["elementor", elementor, { enabled: value("elementor") }],
    ];

    for (const [row, feature, body] of simple) {
      if (rows.includes(row)) {
        calls.push([[row], () => feature.save.mutateAsync(body)]);
      }
    }

    const cliRows = rows.filter(
      (row) => row === "cli_read" || row === "cli_write",
    );
    if (cliRows.length > 0) {
      calls.splice(1, 0, [
        cliRows,
        async () => {
          let enabled = commands.filter((c) => c.enabled).map((c) => c.name);
          if (cliRows.includes("cli_read")) {
            enabled = applyBundle(enabled, commands, "read", value("cli_read"));
          }
          if (cliRows.includes("cli_write")) {
            enabled = applyBundle(
              enabled,
              commands,
              "write",
              value("cli_write"),
            );
          }
          const response = await ajaxRequest<CliCommandsResponse>(
            "cli-commands/update",
            "POST",
            { enabled },
          );
          if (!response.success) {
            throw new Error("Failed to save commands");
          }
          queryClient.setQueryData(CLI_QUERY_KEY, response.data.commands);
        },
      ]);
    }

    for (const [list, call] of calls) {
      mark(list, "saving");
      try {
        await call();
        mark(list, "saved");
      } catch {
        mark(list, "error");
        failed.push(...list);
      }
    }

    setSaving(false);

    if (failed.length === 0) {
      setTouched({});
      announce(__("Settings saved.", "mcpmate"));
      goTo("add_ai");
    } else {
      announce(__("Some settings couldn't be saved.", "mcpmate"));
    }
  };

  const toSave = visible.filter(changed);
  const failedRows = visible.filter((row) => statuses[row] === "error");

  const bundleControl = (bundle: Bundle, row: Row) =>
    bundle === "custom" ? (
      <span className="whitespace-nowrap">
        <Text>
          {sprintf(
            /* translators: %d: number of commands switched on. */
            __("Custom (%d on)", "mcpmate"),
            commands.filter(
              (c) =>
                c.enabled && c.risk === (row === "cli_read" ? "read" : "write"),
            ).length,
          )}
        </Text>{" "}
        <Link to="/tools">{__("Change on the Tools page", "mcpmate")}</Link>
      </span>
    ) : undefined;

  const rowProps = (row: Row) => ({
    checked: value(row),
    onChange: set(row),
    loading: loading || saved[row] === undefined,
    disabled: saving,
    status: statuses[row] ?? null,
  });

  return (
    <>
      <StepHeading>{__("Choose what your AI can do", "mcpmate")}</StepHeading>
      <Paragraph>
        {__(
          "Everything starts switched off except what's marked. You can change these at any time on the Tools page.",
          "mcpmate",
        )}
      </Paragraph>

      <Alert
        type="info"
        className="mb-4"
        message={
          <>
            <Text strong>{__("Always allowed (read-only):", "mcpmate")}</Text>{" "}
            {__(
              "site details and health, reading your pages, checking block markup, and your audit log.",
              "mcpmate",
            )}
          </>
        }
      />

      <div aria-busy={loading}>
        <FeatureSwitchRow
          label={__("Posts and pages", "mcpmate")}
          note={__(
            "Read posts and pages, and write drafts. Never publishes; deletes go to the trash.",
            "mcpmate",
          )}
          {...rowProps("core_proxy")}
        />
        <FeatureSwitchRow
          label={__("Read-only commands", "mcpmate")}
          note={__(
            "List plugins, themes, users and scheduled tasks; check WordPress core files.",
            "mcpmate",
          )}
          control={bundleControl(readBundle, "cli_read")}
          {...rowProps("cli_read")}
        />
        <FeatureSwitchRow
          label={__("Maintenance commands", "mcpmate")}
          note={__(
            "Flush caches and permalinks, delete transients, change allowed settings and drafts.",
            "mcpmate",
          )}
          control={bundleControl(writeBundle, "cli_write")}
          {...rowProps("cli_write")}
        />
        <FeatureSwitchRow
          label={__("Database inspection", "mcpmate")}
          note={__(
            "Read database tables without running SQL. Passwords and keys are never shown.",
            "mcpmate",
          )}
          {...rowProps("db_inspect")}
        />
        <FeatureSwitchRow
          label={__("Media uploads", "mcpmate")}
          note={__(
            "Add images and PDFs. Uploaded files are public as soon as they're added.",
            "mcpmate",
          )}
          {...rowProps("media")}
        />
        <div className="ml-6">
          <FeatureSwitchRow
            label={__("SVG images", "mcpmate")}
            note={__(
              "SVG files can carry code; McpMate cleans them first.",
              "mcpmate",
            )}
            {...rowProps("svg")}
            disabled={saving || !value("media")}
          />
        </div>
        <FeatureSwitchRow
          label={__("Theme drafts", "mcpmate")}
          note={__(
            "Edit a copy of your theme and preview it. Going live always needs your approval.",
            "mcpmate",
          )}
          unavailable={
            features.can_edit_themes
              ? undefined
              : __(
                  "File editing is turned off on this site (DISALLOW_FILE_EDIT).",
                  "mcpmate",
                )
          }
          {...rowProps("theme_drafts")}
          checked={features.can_edit_themes && value("theme_drafts")}
        />
        {features.elementor_available && (
          <FeatureSwitchRow
            label={__("Elementor pages", "mcpmate")}
            note={__("Build and edit Elementor pages as drafts.", "mcpmate")}
            {...rowProps("elementor")}
          />
        )}
        <FeatureSwitchRow
          label={__("Other plugins' actions", "mcpmate")}
          checked={false}
          onChange={() => undefined}
          control={
            features.abilities_supported ? (
              <Link to="/tools/abilities">
                {__("Choose on the Tools page →", "mcpmate")}
              </Link>
            ) : (
              <span />
            )
          }
          note={
            features.abilities_supported
              ? sprintf(
                  /* translators: %d: number of actions. */
                  __(
                    "%d actions from other plugins are available. Each must be turned on by name, after you read what it does. They follow their own plugin's rules, which may publish or delete.",
                    "mcpmate",
                  ),
                  features.abilities_available,
                )
              : undefined
          }
          unavailable={
            features.abilities_supported
              ? undefined
              : __("Needs WordPress 6.9.", "mcpmate")
          }
        />
      </div>

      <StepFooter onBack={saving ? undefined : () => goTo("check")}>
        {failedRows.length > 0 && !saving && (
          <>
            <Button onClick={() => void save(failedRows)}>
              {__("Try again", "mcpmate")}
            </Button>
            <Button onClick={() => goTo("add_ai")}>
              {__("Continue without saving these", "mcpmate")}
            </Button>
          </>
        )}
        <Button
          type="primary"
          loading={saving}
          disabled={loading}
          onClick={() =>
            toSave.length > 0 ? void save(toSave) : goTo("add_ai")
          }
        >
          {__("Save and continue", "mcpmate")}
        </Button>
      </StepFooter>
    </>
  );
}
