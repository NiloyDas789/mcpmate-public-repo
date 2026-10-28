import { useRef, useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { Link, useLocation, useNavigate } from "react-router";
import { CheckCircleOutlined } from "@ant-design/icons";
import { __, _n, sprintf } from "@wordpress/i18n";
import PageHeader from "@components/AppShell/PageHeader";
import Badge from "@components/ui/Badge";
import Button from "@components/ui/Button";
import CheckboxGroup from "@components/ui/CheckboxGroup";
import EmptyState from "@components/ui/EmptyState";
import FilterBar from "@components/ui/FilterBar";
import Notice from "@components/ui/Notice";
import SegmentedControl from "@components/ui/SegmentedControl";
import Skeleton from "@components/ui/Skeleton";
import Tabs from "@components/ui/Tabs";
import Toggle from "@components/ui/Toggle";
import { useAnnounce } from "@/hooks/useAnnounce";
import { fetchTools } from "@services/api";
import { TOOLS_QUERY_KEY } from "@services/queries";
import { requestErrorText } from "@utils/errors";
import { interpolate } from "@utils/i18n";
import type { ToolGroup } from "../../types/api";
import {
  abilityCount,
  buildChange,
  describeChange,
  filterFromParam,
  kindCounts,
  matchCount,
  readShowDescriptions,
  sectionsFor,
  sourceCounts,
  storeShowDescriptions,
  visibleSections,
  type ToolFilter,
  type ToolSection,
  type ToolSource,
} from "./logic";
import StockPhotosKey from "./StockPhotosKey";
import { useToolSaves, type SaveState } from "./useToolSaves";

const SOURCES: { key: ToolSource; to: string; label: () => string }[] = [
  { key: "core", to: "/tools", label: () => __("Core", "mcpmate") },
  {
    key: "integrations",
    to: "/tools/integrations",
    label: () => __("Integrations", "mcpmate"),
  },
  {
    key: "abilities",
    to: "/tools/abilities",
    label: () => __("Abilities", "mcpmate"),
  },
];

/** Groups this size or smaller start open. */
const OPEN_UP_TO = 8;

/** A wp-admin screen next to admin-ajax.php (`plugins.php`). */
const adminUrl = (file: string) =>
  SERVER_VARIABLES.ajaxURL.replace(/admin-ajax\.php$/, file);

function sourceFromPath(pathname: string): ToolSource {
  if (pathname.startsWith("/tools/integrations")) {
    return "integrations";
  }

  return pathname.startsWith("/tools/abilities") ? "abilities" : "core";
}

function countLine(count: number, narrowed: boolean): string {
  return narrowed
    ? sprintf(
        /* translators: %d: number of tools that match the search or filter. */
        _n("%d tool matches.", "%d tools match.", count, "mcpmate"),
        count,
      )
    : sprintf(
        /* translators: %d: number of tools on this tab. */
        _n("%d tool", "%d tools", count, "mcpmate"),
        count,
      );
}

function onCount(on: number, total: number): string {
  return sprintf(
    /* translators: 1: tools turned on, 2: tools in the group. */
    __("%1$d of %2$d on", "mcpmate"),
    on,
    total,
  );
}

// ── A group the site cannot run ─────────────────

function unavailableReason(group: ToolGroup) {
  switch (group.unavailable_reason) {
    case "plugin_inactive":
      return {
        badge: __("Installed, not active", "mcpmate"),
        body: sprintf(
          /* translators: %s: plugin name, e.g. Elementor. */
          __("Activate %s to use these tools.", "mcpmate"),
          group.label,
        ),
        action: {
          label: __("Activate in Plugins", "mcpmate"),
          href: adminUrl("plugins.php"),
        },
      };
    case "file_edit_disallowed":
      return {
        badge: __("Not available on this site", "mcpmate"),
        body: interpolate(
          /* translators: %s: the wp-config.php constant, DISALLOW_FILE_EDIT. */
          __(
            "File editing is turned off on this site (%s), so AI apps can't edit theme drafts.",
            "mcpmate",
          ),
          [
            <bdi dir="ltr" className="el-mono" key="constant">
              DISALLOW_FILE_EDIT
            </bdi>,
          ],
        ),
        action: null,
      };
    case "not_configured":
      return {
        badge: __("Needs an Unsplash key", "mcpmate"),
        body: __(
          "Add your own Unsplash access key to let AI apps search free stock photos. Uploading one still needs Media uploads.",
          "mcpmate",
        ),
        action: {
          label: __("Get a key from Unsplash", "mcpmate"),
          href: "https://unsplash.com/developers",
        },
      };
    case "wordpress_too_old":
      return {
        badge: __("Needs WordPress 6.9 or later", "mcpmate"),
        body: __("Update WordPress to use these tools.", "mcpmate"),
        action: {
          label: __("Update WordPress", "mcpmate"),
          href: adminUrl("update-core.php"),
        },
      };
    default:
      return {
        badge: __("Not installed", "mcpmate"),
        body: sprintf(
          /* translators: %s: plugin name, e.g. Elementor. */
          __("Install %s to use these tools.", "mcpmate"),
          group.label,
        ),
        action: null,
      };
  }
}

/** Greyed, no checkboxes, the reason as text (docs/ui.md §5.3). */
function UnavailableGroup({ section }: { section: ToolSection }) {
  const reason = unavailableReason(section.group);
  const total = section.rows.length;

  return (
    <div className="el-checkbox-group el-tools-unavailable">
      <p className="el-tools-unavailable-head">
        <span className="el-checkbox-group-name">{section.label}</span>
        <Badge variant="unavailable">{reason.badge}</Badge>
        <span className="el-checkbox-group-counts">
          {sprintf(
            /* translators: 1: number of tools, 2: "2 read, 1 write". */
            _n("%1$d tool · %2$s", "%1$d tools · %2$s", total, "mcpmate"),
            total,
            kindCounts(section.rows),
          )}
        </span>
      </p>
      <p className="el-tools-unavailable-body">
        {reason.body}
        {reason.action && (
          <>
            {" "}
            <a href={reason.action.href}>{reason.action.label}</a>
          </>
        )}
      </p>
      {section.group.unavailable_reason === "not_configured" &&
        section.group.id === "stock_photos" && (
          <StockPhotosKey configured={false} appName="" />
        )}
    </div>
  );
}

// ── Save status ─────────────────────────────────

/**
 * Under the list while a save runs or just ended: "Saving…", "Saved." for
 * five seconds, or the error with Retry until the change goes through.
 */
function SaveStatus({
  state,
  onRetry,
}: {
  state: SaveState;
  onRetry: () => void;
}) {
  if (state.status === "idle") {
    return null;
  }

  if (state.status === "failed") {
    return (
      <div className="el-tools-save-status">
        <Notice
          tone="danger"
          live
          title={__("Couldn't save your change.", "mcpmate")}
          action={
            <Button variant="secondary" size="sm" onClick={onRetry}>
              {__("Retry", "mcpmate")}
            </Button>
          }
        >
          {requestErrorText(state.error)}{" "}
          {__("The tool is shown as it was before your change.", "mcpmate")}
        </Notice>
      </div>
    );
  }

  return (
    <div className="el-tools-save-status">
      <p className="el-tools-save-text">
        {state.status === "saving" ? (
          __("Saving…", "mcpmate")
        ) : (
          <>
            <CheckCircleOutlined aria-hidden className="el-tools-saved-icon" />{" "}
            {__(
              "Saved. Connected AI apps may need to reconnect to see the change.",
              "mcpmate",
            )}
          </>
        )}
      </p>
    </div>
  );
}

// ── Page ────────────────────────────────────────

/**
 * Tools (`#/tools`, `/integrations`, `/abilities`; docs/ui.md §5.3): every
 * tool AI apps can use on this site, in tri-state groups, with Read and
 * Write badges. Each change is saved at once (POST /tools) and announced.
 * The filter (`?show=`) and search (`?q=`) live in the hash query and
 * follow the tabs.
 */
export default function Tools() {
  const { pathname, search: query } = useLocation();
  const source = sourceFromPath(pathname);
  const navigate = useNavigate();
  const params = new URLSearchParams(query);
  const filter = filterFromParam(params.get("show"));
  const search = params.get("q") ?? "";
  const narrowed = filter !== "all" || search.trim() !== "";
  const announce = useAnnounce();
  const toolbarRef = useRef<HTMLDivElement>(null);

  const tools = useQuery({
    queryKey: TOOLS_QUERY_KEY,
    queryFn: fetchTools,
    staleTime: 0,
  });
  const { view, state, save, retry } = useToolSaves(tools.data?.groups);
  const [showDescriptions, setShowDescriptions] =
    useState(readShowDescriptions);
  const [expanded, setExpanded] = useState<Record<string, boolean>>({});

  const sections = view ? sectionsFor(view, source) : [];
  const shown = visibleSections(sections, filter, search);
  const count = view ? matchCount(view, source, filter, search) : 0;

  const setQuery = (nextFilter: ToolFilter, nextSearch: string) => {
    // On this tab's own path: a relative `?q=` from this parent route
    // would land on Core.
    const next = new URLSearchParams(query);
    if (nextFilter === "all") {
      next.delete("show");
    } else {
      next.set("show", nextFilter);
    }
    if (nextSearch.trim()) {
      next.set("q", nextSearch);
    } else {
      next.delete("q");
    }
    const encoded = next.toString();
    navigate(
      { pathname, search: encoded ? `?${encoded}` : "" },
      { replace: true },
    );

    if (!view) {
      return;
    }

    // Groups with a match open, so the matches can be seen.
    if (nextSearch.trim()) {
      setExpanded((current) => ({
        ...current,
        ...Object.fromEntries(
          visibleSections(sections, nextFilter, nextSearch).map((section) => [
            section.id,
            true,
          ]),
        ),
      }));
    }

    const matches = matchCount(view, source, nextFilter, nextSearch);
    announce(
      nextFilter === "all" && !nextSearch.trim()
        ? countLine(matches, false)
        : countLine(matches, true),
    );
  };

  const focusIn = (container: HTMLElement | null, selector: string) =>
    window.requestAnimationFrame(() =>
      container?.querySelector<HTMLElement>(selector)?.focus(),
    );

  const clearSearch = () => {
    setQuery(filter, "");
    focusIn(toolbarRef.current, 'input[type="search"]');
  };

  const showAll = () => {
    setQuery("all", search);
    focusIn(toolbarRef.current, 'input[type="radio"]:checked');
  };

  const isOpen = (section: ToolSection) =>
    expanded[section.id] ?? section.rows.length <= OPEN_UP_TO;

  const changeRows = (
    section: ToolSection,
    requested: Record<string, boolean>,
  ) => {
    if (!view || Object.keys(requested).length === 0) {
      return;
    }
    // The whole group (not only the rows a filter leaves) for its count.
    const whole = sections.find((item) => item.id === section.id) ?? section;
    save(buildChange(view, requested), describeChange(whole, requested));
  };

  const otherMatches = view
    ? SOURCES.filter((item) => item.key !== source)
        .map((item) => ({
          ...item,
          count: matchCount(view, item.key, filter, search),
        }))
        .filter((item) => item.count > 0)
    : [];

  const tabItems = SOURCES.map((item) => {
    const counts = view ? sourceCounts(view, item.key) : null;

    return {
      key: item.key,
      label: item.label(),
      to: `${item.to}${query}`,
      ...(counts &&
        counts.total > 0 && {
          count: `${counts.on}/${counts.total}`,
          countLabel: onCount(counts.on, counts.total),
        }),
    };
  });

  const abilitiesGroup = view?.find((group) => group.source === "abilities");

  const renderSection = (section: ToolSection) => {
    if (!section.group.available) {
      return <UnavailableGroup key={section.id} section={section} />;
    }

    const whole = sections.find((item) => item.id === section.id) ?? section;
    const on = whole.rows.filter((row) => row.checked).length;
    const svg = section.group.id === "media" && section.id === "media";

    return (
      <CheckboxGroup
        key={section.id}
        label={section.label}
        extra={
          section.toolName && (
            <bdi dir="ltr" className="el-mono">
              {section.toolName}
            </bdi>
          )
        }
        description={`${onCount(on, whole.rows.length)} · ${kindCounts(whole.rows)}`}
        expanded={isOpen(section)}
        onExpandedChange={(open) =>
          setExpanded((current) => ({ ...current, [section.id]: open }))
        }
        onChange={(requested) => changeRows(section, requested)}
        items={section.rows.map((row) => ({
          key: row.key,
          checked: row.checked,
          disabled: row.alwaysOn,
          label: row.label,
          meta: (
            <>
              <bdi dir="ltr" className="el-mono">
                {row.name}
              </bdi>
              <Badge variant={row.kind} />
              {row.approval && <Badge variant="approval" />}
            </>
          ),
          note: row.alwaysOn
            ? __("Always on", "mcpmate")
            : row.tool === "core_content"
              ? __("No McpMate tool uses this yet.", "mcpmate")
              : undefined,
          description:
            showDescriptions && row.description ? row.description : undefined,
        }))}
        option={
          section.group.id === "stock_photos" ? (
            <StockPhotosKey
              configured={Boolean(section.group.options.configured)}
              appName={section.group.options.app_name ?? ""}
            />
          ) : (
            svg && (
              <Toggle
                label={__("Allow SVG files", "mcpmate")}
                description={__(
                  "Administrators only. Each file is cleaned before it is saved.",
                  "mcpmate",
                )}
                checked={Boolean(section.group.options.svg)}
                onChange={(on) =>
                  save(
                    { tools: {}, items: {}, options: { svg: on } },
                    on
                      ? __("SVG files allowed.", "mcpmate")
                      : __("SVG files not allowed.", "mcpmate"),
                  )
                }
              />
            )
          )
        }
      />
    );
  };

  const renderList = () => {
    if (tools.isPending) {
      return (
        <>
          {[0, 1, 2, 3].map((index) => (
            <div key={index} className="el-checkbox-group">
              <Skeleton shape="text" count={1} announce={index === 0} />
            </div>
          ))}
        </>
      );
    }

    if (!view) {
      return (
        <Notice
          tone="danger"
          title={__("Couldn't load the tools.", "mcpmate")}
          action={
            <Button
              variant="secondary"
              size="sm"
              onClick={() => void tools.refetch()}
            >
              {__("Try again", "mcpmate")}
            </Button>
          }
        >
          {requestErrorText(tools.error)}
        </Notice>
      );
    }

    if (source === "abilities" && abilitiesGroup && !abilitiesGroup.available) {
      return (
        <EmptyState
          title={__("Abilities need WordPress 6.9 or later.", "mcpmate")}
          body={__(
            "Update WordPress to let your other plugins offer abilities to AI apps.",
            "mcpmate",
          )}
          action={{
            label: __("Update WordPress", "mcpmate"),
            href: adminUrl("update-core.php"),
          }}
        />
      );
    }

    if (source === "abilities" && abilityCount(view) === 0) {
      return (
        <EmptyState
          title={__("No abilities yet.", "mcpmate")}
          body={__(
            "Other plugins can offer abilities to AI apps. None of your active plugins does yet.",
            "mcpmate",
          )}
          action={
            SERVER_VARIABLES.links.docs
              ? {
                  label: __("Learn about abilities", "mcpmate"),
                  href: SERVER_VARIABLES.links.docs,
                }
              : undefined
          }
        />
      );
    }

    if (shown.length === 0 && search.trim()) {
      return (
        <EmptyState
          title={sprintf(
            /* translators: %s: what was searched for. */
            __("No tools match “%s”.", "mcpmate"),
            search.trim(),
          )}
          action={{
            label: __("Clear search", "mcpmate"),
            onClick: clearSearch,
          }}
        />
      );
    }

    if (shown.length === 0) {
      return (
        <EmptyState
          title={
            filter === "read"
              ? __("No read-only tools here.", "mcpmate")
              : __("No write tools here.", "mcpmate")
          }
          action={{ label: __("Show all", "mcpmate"), onClick: showAll }}
        />
      );
    }

    return shown.map(renderSection);
  };

  return (
    <>
      <PageHeader
        title={__("Tools", "mcpmate")}
        intro={__(
          "Tools are the actions your AI apps can take on this site. Turn off anything you don't want them to use.",
          "mcpmate",
        )}
      />
      <Notice
        tone="info"
        title={__(
          "When a tool is off, AI apps get an error if they try to use it. Connected AI apps may need to reconnect, or start a new chat, to see your changes.",
          "mcpmate",
        )}
      />
      <Tabs
        label={__("Tool sources", "mcpmate")}
        activeKey={source}
        items={tabItems}
      >
        {source === "integrations" && (
          <p className="el-tools-intro">
            {__(
              "Integrations are tools McpMate brings for other plugins. Tools that a plugin offers itself, such as WooCommerce, are listed under Abilities.",
              "mcpmate",
            )}{" "}
            <Link to={`/tools/abilities${query}`} className="underline">
              {__("Go to Abilities", "mcpmate")}
            </Link>
          </p>
        )}
        {source === "abilities" && (
          <p className="el-tools-intro">
            {__(
              "Abilities come from your other plugins. Each one is off until you turn it on. Read each description first: an ability runs its plugin's own code.",
              "mcpmate",
            )}
          </p>
        )}
        <div ref={toolbarRef} className="el-tools-toolbar">
          <SegmentedControl<ToolFilter>
            label={__("Show", "mcpmate")}
            value={filter}
            onChange={(value) => setQuery(value, search)}
            options={[
              { value: "all", label: __("All", "mcpmate") },
              { value: "read", label: __("Read-only", "mcpmate") },
              { value: "write", label: __("Write", "mcpmate") },
            ]}
          />
          <FilterBar
            layout="wide"
            fields={[
              {
                key: "q",
                type: "search",
                label: __("Search tools", "mcpmate"),
              },
            ]}
            values={{ q: search }}
            onChange={(values) => setQuery(filter, values.q ?? "")}
          />
          <Toggle
            label={__("Show descriptions", "mcpmate")}
            checked={showDescriptions}
            onChange={(on) => {
              setShowDescriptions(on);
              storeShowDescriptions(on);
            }}
          />
        </div>
        {view && (
          <p className="el-tools-count">
            {countLine(count, narrowed)}
            {search.trim() &&
              otherMatches.map((item) => (
                <span key={item.key}>
                  {" "}
                  <Link to={`${item.to}${query}`}>
                    {sprintf(
                      /* translators: 1: number of matching tools, 2: tab name, e.g. Integrations. */
                      _n(
                        "%1$d more in %2$s",
                        "%1$d more in %2$s",
                        item.count,
                        "mcpmate",
                      ),
                      item.count,
                      item.label(),
                    )}
                  </Link>
                </span>
              ))}
          </p>
        )}
        <div className="el-tools-list" aria-busy={tools.isPending}>
          {renderList()}
        </div>
        <SaveStatus state={state} onRetry={retry} />
      </Tabs>
    </>
  );
}
