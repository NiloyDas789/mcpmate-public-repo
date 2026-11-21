import { useRef, useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { Link, useLocation } from "react-router";
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
import { withParams } from "../Activity/logic";
import { useHashQuery } from "../Activity/useHashQuery";
import {
  abilityCount,
  buildChange,
  describeChange,
  filterFromParam,
  kindCounts,
  ledgerSelection,
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
import AbilitiesLedger, { AbilitiesLedgerSkeleton } from "./AbilitiesLedger";
import DesignPanel from "./DesignPanel";
import MemoryPanel from "./MemoryPanel";
import SandboxPanel from "./SandboxPanel";
import SkillsPanel from "./SkillsPanel";
import IntegrationGrid, { IntegrationGridSkeleton } from "./IntegrationGrid";
import IntegrationModal from "./IntegrationModal";
import {
  DISCOVERY_SECTION,
  pluginTiles,
  tileFromParam,
  type PluginTile,
} from "./integrationLogic";
import StockPhotosKey from "./StockPhotosKey";
import { GroupCounts, SaveStatus, UnavailableGroup } from "./ToolSectionParts";
import { adminUrl, onCount } from "./toolText";
import { useToolSaves } from "./useToolSaves";

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

// ── Page ────────────────────────────────────────

/**
 * Tools (`#/tools`, `/integrations`, `/abilities`; docs/ui.md §5.3): every
 * tool AI apps can use on this site, in tri-state groups, with Read and
 * Write badges. Each change is saved at once (POST /tools) and announced.
 * The filter (`?show=`) and search (`?q=`) live in the hash query and
 * follow the tabs. Integrations are a tile per plugin; the open one's
 * dialog is `?plugin=<id>`, so Back closes it.
 */
export default function Tools() {
  const { pathname, search: query } = useLocation();
  const source = sourceFromPath(pathname);
  // Each change builds on the newest URL this page wrote (a quick key press
  // can come before the router renders the last one).
  const hash = useHashQuery();
  const params = new URLSearchParams(query);
  const filter = filterFromParam(params.get("show"));
  const search = params.get("q") ?? "";
  const pluginParam = params.get("plugin");
  const sourceParam = params.get("source");
  // The list's own query, for the tabs and links: without the open dialog
  // or the open ability source.
  const listParams = new URLSearchParams(query);
  listParams.delete("plugin");
  listParams.delete("source");
  const listQuery = listParams.toString() ? `?${listParams.toString()}` : "";
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
  const tiles =
    view && source === "integrations"
      ? pluginTiles(view, sections, filter, search)
      : [];
  const openTile =
    source === "integrations" ? tileFromParam(tiles, pluginParam) : null;
  const count = view ? matchCount(view, source, filter, search) : 0;

  const setQuery = (nextFilter: ToolFilter, nextSearch: string) => {
    // On this tab's own path (useHashQuery keeps it): a relative `?q=`
    // from this parent route would land on Core.
    hash.go(
      withParams(hash.current(), {
        show: nextFilter === "all" ? null : nextFilter,
        q: nextSearch.trim() ? nextSearch : null,
      }),
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

  // In a plugin's dialog every group starts open.
  const isOpen = (section: ToolSection, inDialog = false) =>
    expanded[section.id] ?? (inDialog || section.rows.length <= OPEN_UP_TO);

  // The whole group (not only the rows a filter leaves) for its count, as
  // its plugin's dialog names it.
  const wholeOf = (section: ToolSection) =>
    tiles
      .flatMap((tile) => tile.sections)
      .find((item) => item.id === section.id) ??
    sections.find((item) => item.id === section.id) ??
    section;

  const changeRows = (
    section: ToolSection,
    requested: Record<string, boolean>,
  ) => {
    if (!view || Object.keys(requested).length === 0) {
      return;
    }
    save(
      buildChange(view, requested),
      describeChange(wholeOf(section), requested),
    );
  };

  // ── A plugin's dialog, kept in `?plugin=` ──
  const openPlugin = (tile: PluginTile) => {
    hash.go(withParams(hash.current(), { plugin: tile.id }), { push: true });
  };

  // Back when this page opened it, else drop the key; Modal returns focus to the tile.
  const closePlugin = () => hash.closeDrawer("plugin");

  // ── The Abilities ledger's open source, kept in `?source=` ──
  const sourceHref = (section: ToolSection) =>
    `/tools/abilities${withParams(query, { source: section.id })}`;

  const selectSource = (section: ToolSection) => {
    hash.go(withParams(hash.current(), { source: section.id }));
  };

  const showAllInDialog = () => {
    setQuery("all", "");
  };

  const otherMatches = view
    ? SOURCES.filter((item) => item.key !== source)
        .map((item) => ({
          ...item,
          count: matchCount(view, item.key, filter, search),
        }))
        .filter((item) => item.count > 0)
    : [];

  // Tools → Skills, and Memory (MCPMate Pro): what AI apps saved, not tools; no count.
  const onSkills = pathname.startsWith("/tools/skills");
  const withMemory = Boolean(SERVER_VARIABLES.pro?.active);
  const onMemory = withMemory && pathname.startsWith("/tools/memory");
  // Tools → Design (MCPMate Pro): the design system AI apps follow.
  const onDesign = withMemory && pathname.startsWith("/tools/design");
  // Tools → Sandbox (MCPMate Pro): the PHP plugins AI apps wrote, and whether each runs.
  const onSandbox = withMemory && pathname.startsWith("/tools/sandbox");

  const sourceItems = SOURCES.map((item) => {
    const counts = view ? sourceCounts(view, item.key) : null;

    return {
      key: item.key,
      label: item.label(),
      to: `${item.to}${listQuery}`,
      ...(counts &&
        counts.total > 0 && {
          count: `${counts.on}/${counts.total}`,
          countLabel: onCount(counts.on, counts.total),
        }),
    };
  });

  const tabItems = [
    ...sourceItems,
    { key: "skills", label: __("Skills", "mcpmate"), to: "/tools/skills" },
    ...(withMemory
      ? [
          {
            key: "memory",
            label: __("Memory", "mcpmate"),
            to: "/tools/memory",
          },
          {
            key: "design",
            label: __("Design", "mcpmate"),
            to: "/tools/design",
          },
          {
            key: "sandbox",
            label: __("Sandbox", "mcpmate"),
            to: "/tools/sandbox",
          },
        ]
      : []),
  ];

  const abilitiesGroup = view?.find((group) => group.source === "abilities");

  const renderSection = (section: ToolSection, inDialog = false) => {
    if (!section.group.available) {
      return <UnavailableGroup key={section.id} section={section} />;
    }

    const whole = wholeOf(section);
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
        description={
          <GroupCounts
            on={onCount(on, whole.rows.length)}
            kinds={kindCounts(whole.rows)}
          />
        }
        expanded={isOpen(section, inDialog)}
        onExpandedChange={(open) =>
          setExpanded((current) => ({ ...current, [section.id]: open }))
        }
        onChange={(requested) => changeRows(section, requested)}
        items={section.rows.map((row) => ({
          key: row.key,
          checked: row.checked,
          disabled: row.alwaysOn,
          label: row.label,
          // Two cells of the switchboard's columns: the name, then the access.
          meta: (
            <>
              <span className="el-tool-name">
                <bdi dir="ltr" className="el-mono">
                  {row.name}
                </bdi>
              </span>
              <span className="el-tool-access">
                <Badge variant={row.kind} />
                {row.approval && <Badge variant="approval" />}
              </span>
            </>
          ),
          note: row.alwaysOn
            ? __("Always on", "mcpmate")
            : row.tool === "core_content"
              ? __("No MCPMate tool uses this yet.", "mcpmate")
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
    if (tools.isPending && source === "integrations") {
      return (
        <>
          <Skeleton shape="text" count={1} />
          <IntegrationGridSkeleton />
        </>
      );
    }

    if (tools.isPending && source === "abilities") {
      return <AbilitiesLedgerSkeleton />;
    }

    if (tools.isPending) {
      return (
        <div className="el-switchboard">
          {[0, 1, 2, 3].map((index) => (
            <div key={index} className="el-checkbox-group">
              <Skeleton shape="text" count={1} announce={index === 0} />
            </div>
          ))}
        </div>
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

    const ledgerSource =
      source === "abilities" ? ledgerSelection(shown, sourceParam) : null;

    if (ledgerSource) {
      return (
        <AbilitiesLedger
          shown={shown}
          selected={ledgerSource}
          wholeOf={wholeOf}
          hrefFor={sourceHref}
          onSelect={selectSource}
          narrowed={narrowed}
          showDescriptions={showDescriptions}
          onChange={changeRows}
        />
      );
    }

    if (source === "integrations") {
      const discovery = shown.find(
        (section) => section.id === DISCOVERY_SECTION,
      );

      return (
        <>
          {discovery && renderSection(discovery)}
          <IntegrationGrid
            tiles={tiles}
            narrowed={narrowed}
            onOpen={openPlugin}
          />
        </>
      );
    }

    // Core: one switchboard, each group a band over its tools in columns.
    return (
      <div className="el-switchboard" data-testid="core-switchboard">
        {shown.map((section) => renderSection(section))}
      </div>
    );
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
        activeKey={
          onSkills
            ? "skills"
            : onMemory
              ? "memory"
              : onDesign
                ? "design"
                : onSandbox
                  ? "sandbox"
                  : source
        }
        items={tabItems}
      >
        {onSkills ? (
          <SkillsPanel />
        ) : onMemory ? (
          <MemoryPanel />
        ) : onDesign ? (
          <DesignPanel />
        ) : onSandbox ? (
          <SandboxPanel />
        ) : (
          <>
            {source === "integrations" && (
              <p className="el-tools-intro">
                {__(
                  "Integrations are tools MCPMate brings for other plugins. Tools that a plugin offers itself, such as WooCommerce, are listed under Abilities.",
                  "mcpmate",
                )}{" "}
                <Link to={`/tools/abilities${listQuery}`} className="underline">
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
                      <Link to={`${item.to}${listQuery}`}>
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
            {!openTile && <SaveStatus state={state} onRetry={retry} />}
            {source === "integrations" && (
              <IntegrationModal
                tile={openTile}
                onClose={closePlugin}
                renderSection={(section) => renderSection(section, true)}
                narrowed={narrowed}
                onShowAll={showAllInDialog}
                saveStatus={<SaveStatus state={state} onRetry={retry} />}
              />
            )}
          </>
        )}
      </Tabs>
    </>
  );
}
