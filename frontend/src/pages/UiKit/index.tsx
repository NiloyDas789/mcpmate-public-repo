import { useEffect, useRef, useState, type ReactNode } from "react";
import { DeleteOutlined, PlusOutlined } from "@ant-design/icons";
import { useSearchParams } from "react-router";
import PageHeader from "@components/AppShell/PageHeader";
import Badge from "@components/ui/Badge";
import Button from "@components/ui/Button";
import Card from "@components/ui/Card";
import CheckboxGroup from "@components/ui/CheckboxGroup";
import ConfirmDialog from "@components/ui/ConfirmDialog";
import CopyField from "@components/ui/CopyField";
import DiffView, { type DiffField } from "@components/ui/DiffView";
import Drawer from "@components/ui/Drawer";
import EmptyState from "@components/ui/EmptyState";
import FilterBar, { type FilterField } from "@components/ui/FilterBar";
import JsonBlock from "@components/ui/JsonBlock";
import Logo from "@components/ui/Logo";
import Modal from "@components/ui/Modal";
import Notice from "@components/ui/Notice";
import Pagination from "@components/ui/Pagination";
import RelativeTime from "@components/ui/RelativeTime";
import SegmentedControl from "@components/ui/SegmentedControl";
import Skeleton from "@components/ui/Skeleton";
import StatTile from "@components/ui/StatTile";
import StatusPill from "@components/ui/StatusPill";
import StatusText from "@components/ui/StatusText";
import Table, { type TableColumn, type TableSort } from "@components/ui/Table";
import Tabs from "@components/ui/Tabs";
import Toggle from "@components/ui/Toggle";
import { useAnnounce } from "@/hooks/useAnnounce";
import { colors } from "@config/tokens";

/*
 * A developer page (WP_DEBUG only): every component in every state that
 * applies to it. Fixed strings are not translated on purpose; the
 * components' own strings are. Hover and focus are forced with
 * `data-el-state`, which ui.css styles like the real state.
 */

const FORCE_TARGET =
  "a[href], button, input, select, textarea, .ant-checkbox-wrapper, .ant-select, .el-segment";

/** Shows its first control as hovered or focused. */
function Forced({
  state,
  children,
}: {
  state: "hover" | "focus";
  children: ReactNode;
}) {
  const ref = useRef<HTMLDivElement>(null);

  useEffect(() => {
    ref.current
      ?.querySelector(FORCE_TARGET)
      ?.setAttribute("data-el-state", state);
  }, [state]);

  return <div ref={ref}>{children}</div>;
}

function Sample({ state, children }: { state: string; children: ReactNode }) {
  return (
    <div className="el-kit-sample">
      <p className="el-kit-state">{state}</p>
      {children}
    </div>
  );
}

function Section({ title, children }: { title: string; children: ReactNode }) {
  const id = `kit-${title.toLowerCase().replace(/[^a-z]+/g, "-")}`;

  return (
    <section className="el-kit-section" aria-labelledby={id}>
      <h2 id={id} className="el-kit-title">
        {title}
      </h2>
      <div className="el-kit-grid">{children}</div>
    </section>
  );
}

const noop = () => undefined;

// ── Data for the samples ─────────────────────────

interface Row {
  id: string;
  date: string;
  app: string;
  tool: string;
  status: "ok" | "error" | "pending";
}

const ROWS: Row[] = [
  {
    id: "1",
    date: "2026-10-26T10:42:00Z",
    app: "Claude · admin",
    tool: "elementor_save",
    status: "ok",
  },
  {
    id: "2",
    date: "2026-10-26T10:30:00Z",
    app: "Claude · admin",
    tool: "run_wp_cli",
    status: "pending",
  },
  {
    id: "3",
    date: "2026-10-26T09:12:00Z",
    app: "ChatGPT · editor",
    tool: "upload_media",
    status: "error",
  },
];

const STATUS: Record<Row["status"], ReactNode> = {
  ok: <StatusText tone="success">Succeeded</StatusText>,
  error: <StatusText tone="danger">Failed</StatusText>,
  pending: <StatusText tone="warning">Waiting for approval</StatusText>,
};

function columns(onView: (row: Row) => void): TableColumn<Row>[] {
  return [
    {
      key: "date",
      title: "Date",
      rowHeader: true,
      sortable: true,
      firstOrder: "descending",
      orderLabels: { ascending: "oldest first", descending: "newest first" },
      render: (row) => <RelativeTime date={row.date} />,
    },
    { key: "app", title: "AI app", render: (row) => row.app },
    {
      key: "tool",
      title: "Tool",
      sortable: true,
      orderLabels: { ascending: "A to Z", descending: "Z to A" },
      render: (row) => (
        <bdi dir="ltr" className="el-mono">
          {row.tool}
        </bdi>
      ),
    },
    { key: "status", title: "Status", render: (row) => STATUS[row.status] },
    {
      key: "actions",
      title: "Actions",
      hiddenTitle: true,
      render: (row) => (
        <Button
          size="sm"
          label={`View details: ${row.tool}`}
          onClick={() => onView(row)}
        >
          View
        </Button>
      ),
    },
  ];
}

const DIFF: DiffField[] = [
  { key: "title", label: "Title", unchanged: "Home" },
  { key: "status", label: "Status", unchanged: "Draft" },
  {
    key: "content",
    label: "Content",
    ops: [
      { op: "equal", lines: ["<h2>Welcome</h2>"] },
      { op: "remove", lines: ["<p>Old intro.</p>"] },
      {
        op: "add",
        lines: ["<p>New intro text.</p>", "<p>Second paragraph.</p>"],
      },
      {
        op: "equal",
        lines: [
          "<ul>",
          "<li>One</li>",
          "<li>Two</li>",
          "<li>Three</li>",
          "<li>Four</li>",
          "<li>Five</li>",
          "<li>Six</li>",
          "</ul>",
        ],
      },
      { op: "add", lines: ["<p>Contact us.</p>"] },
    ],
  },
  {
    key: "css",
    label: "style.css",
    truncated: true,
    ops: [{ op: "add", lines: ["body { margin: 0; }"] }],
  },
];

const FILTERS: FilterField[] = [
  { key: "search", type: "search", label: "Search" },
  {
    key: "tool",
    type: "select",
    label: "Tool",
    options: [
      { value: "site_info", label: "site_info" },
      { value: "upload_media", label: "upload_media" },
    ],
  },
  {
    key: "result",
    type: "select",
    label: "Status",
    options: [
      { value: "ok", label: "Succeeded" },
      { value: "error", label: "Failed" },
    ],
  },
  { key: "from", type: "date", label: "From" },
];

const TOOLS = [
  { key: "site_info", label: "Get a summary of this site", checked: true },
  { key: "get_page_html", label: "Read a page's HTML", checked: false },
  {
    key: "check_approval",
    label: "Check on an approval request",
    checked: true,
    disabled: true,
  },
];

// ── Interactive samples ──────────────────────────

function DialogSamples() {
  const announce = useAnnounce();
  const [modal, setModal] = useState<"md" | "lg" | null>(null);
  const [confirm, setConfirm] = useState<"default" | "danger" | "error" | null>(
    null,
  );
  const [params, setParams] = useSearchParams();
  const drawerOpen = params.get("drawer") === "demo";

  const openDrawer = () => {
    const next = new URLSearchParams(params);
    next.set("drawer", "demo");
    setParams(next);
  };
  const closeDrawer = () => {
    const next = new URLSearchParams(params);
    next.delete("drawer");
    setParams(next);
  };

  return (
    <>
      <Sample state="Modal">
        <div className="el-kit-row">
          <Button onClick={() => setModal("md")}>Open modal</Button>
          <Button onClick={() => setModal("lg")}>Open large modal</Button>
        </div>
      </Sample>
      <Sample state="ConfirmDialog">
        <div className="el-kit-row">
          <Button onClick={() => setConfirm("default")}>
            Confirm (default)
          </Button>
          <Button
            variant="danger-secondary"
            onClick={() => setConfirm("danger")}
          >
            Confirm (danger)
          </Button>
          <Button
            variant="danger-secondary"
            onClick={() => setConfirm("error")}
          >
            Confirm that fails
          </Button>
        </div>
      </Sample>
      <Sample state="Drawer (?drawer=demo)">
        <Button onClick={openDrawer}>Open drawer</Button>
      </Sample>

      <Modal
        open={modal !== null}
        size={modal ?? "md"}
        title="Connect an AI app"
        onClose={() => setModal(null)}
        footer={
          <div className="el-dialog-actions">
            <Button variant="primary" onClick={() => setModal(null)}>
              Done
            </Button>
          </div>
        }
      >
        <p>1. Copy your MCP URL</p>
        <CopyField
          label="MCP URL"
          value="https://example.com/wp-json/mcpmate/mcp"
        />
      </Modal>

      <ConfirmDialog
        open={confirm !== null}
        tone={confirm === "default" ? "default" : "danger"}
        title={
          confirm === "default"
            ? "Run setup again?"
            : "Revoke access for Claude?"
        }
        body={
          <p>
            {confirm === "default"
              ? "Setup changes nothing until you choose to."
              : "Claude (acting as admin) loses access to this site right away. Its past activity stays in the audit log."}
          </p>
        }
        confirmLabel={
          confirm === "default" ? "Run setup again" : "Revoke access for Claude"
        }
        cancelLabel={confirm === "default" ? "Cancel" : "Keep access"}
        busyLabel={confirm === "default" ? undefined : "Revoking…"}
        onConfirm={() =>
          new Promise<void>((resolve, reject) =>
            window.setTimeout(() => {
              if (confirm === "error") {
                reject(
                  new Error("The site didn't answer. Nothing was changed."),
                );
              } else {
                announce("Claude can no longer use this site.");
                resolve();
              }
            }, 600),
          )
        }
        onClose={() => setConfirm(null)}
      />

      <Drawer
        open={drawerOpen}
        title="elementor_save · 26 Oct 2026, 10:42"
        onClose={closeDrawer}
      >
        <dl className="el-kit-dl">
          <dt>Status</dt>
          <dd>
            <StatusText tone="success">Succeeded</StatusText>
          </dd>
          <dt>Request ID</dt>
          <dd>
            <bdi dir="ltr" className="el-mono">
              4f1c9a2e
            </bdi>
          </dd>
        </dl>
        <h3 className="el-kit-subtitle">Arguments (secrets hidden)</h3>
        <JsonBlock
          label="Arguments"
          value={{ post_id: 41, patches: ["update:a1b2"], token: "[redacted]" }}
        />
      </Drawer>
    </>
  );
}

function TableSamples() {
  const announce = useAnnounce();
  const caption = useRef<HTMLElement>(null);
  const [sort, setSort] = useState<TableSort>({
    key: "date",
    order: "descending",
  });
  const [page, setPage] = useState(2);
  const sorted = [...ROWS].sort((a, b) => {
    const key = sort.key as "date" | "tool";
    const order = a[key] < b[key] ? -1 : a[key] > b[key] ? 1 : 0;
    return sort.order === "ascending" ? order : -order;
  });
  const cols = columns((row) => announce(`Opened ${row.tool}.`));

  return (
    <>
      <Sample state="Default, sortable">
        <Table
          caption="Audit log, newest first. Times in Asia/Dhaka."
          captionRef={caption}
          columns={cols}
          rows={sorted}
          rowKey={(row) => row.id}
          sort={sort}
          onSort={setSort}
        />
        <Pagination
          label="Example table pages"
          page={page}
          pageSize={20}
          total={1240}
          onChange={(next) => {
            setPage(next);
            caption.current?.focus();
            announce(`Page ${next} of 62.`);
          }}
        />
      </Sample>
      <Sample state="Narrow (cards)">
        <Table
          caption="Audit log, newest first (narrow form)."
          columns={cols}
          rows={sorted}
          rowKey={(row) => row.id}
          sort={sort}
          onSort={setSort}
          layout="narrow"
        />
        <Pagination
          label="Example card pages"
          page={page}
          pageSize={20}
          total={1240}
          layout="narrow"
          onChange={setPage}
        />
      </Sample>
      <Sample state="Loading">
        <Table
          caption="Loading table."
          columns={cols}
          rows={[]}
          rowKey={(row) => row.id}
          loading
        />
      </Sample>
      <Sample state="Empty">
        <Table
          caption="Empty table."
          columns={cols}
          rows={[]}
          rowKey={(row) => row.id}
          empty={
            <EmptyState
              title="Nothing logged yet."
              body="Every change an AI app makes is listed here, with who made it and when."
              action={{ label: "Connect an AI app", href: "#/connections" }}
              headingLevel={4}
            />
          }
        />
      </Sample>
      <Sample state="Error">
        <Table
          caption="Table that failed to load."
          columns={cols}
          rows={[]}
          rowKey={(row) => row.id}
          error={
            <Notice
              tone="danger"
              title="Couldn't load the audit log."
              action={<Button size="sm">Try again</Button>}
            >
              The server answered 500.
            </Notice>
          }
        />
      </Sample>
      <Sample state="First page (Previous disabled)">
        <Pagination
          label="Example first page"
          page={1}
          pageSize={20}
          total={45}
          onChange={noop}
        />
      </Sample>
    </>
  );
}

function FormSamples() {
  const [on, setOn] = useState(true);
  const [segment, setSegment] = useState<"all" | "read" | "write">("all");
  const [tools, setTools] = useState(TOOLS);
  const [expanded, setExpanded] = useState(true);
  const [filters, setFilters] = useState<Record<string, string>>({
    tool: "site_info",
    result: "",
  });
  const onCount = tools.filter((tool) => tool.checked).length;

  const segmented = (
    <SegmentedControl
      label="Show"
      value={segment}
      onChange={setSegment}
      options={[
        { value: "all", label: "All" },
        { value: "read", label: "Read-only" },
        { value: "write", label: "Write" },
      ]}
    />
  );

  return (
    <>
      <Section title="Toggle">
        <Sample state="Default (on)">
          <Toggle
            label="Also log read calls"
            description="Needed for the Dashboard numbers."
            checked={on}
            onChange={setOn}
          />
        </Sample>
        <Sample state="Off">
          <Toggle label="Change history" checked={false} onChange={noop} />
        </Sample>
        <Sample state="Hover">
          <Forced state="hover">
            <Toggle label="Allow SVG files" checked={false} onChange={noop} />
          </Forced>
        </Sample>
        <Sample state="Focus">
          <Forced state="focus">
            <Toggle
              label="Remove all data on uninstall"
              checked
              onChange={noop}
            />
          </Forced>
        </Sample>
        <Sample state="Disabled">
          <Toggle
            label="Theme drafts"
            description="File editing is turned off (DISALLOW_FILE_EDIT)."
            checked={false}
            onChange={noop}
            disabled
          />
        </Sample>
        <Sample state="Loading">
          <Toggle label="Media uploads" checked onChange={noop} loading />
        </Sample>
      </Section>

      <Section title="SegmentedControl">
        <Sample state="Default">{segmented}</Sample>
        <Sample state="Hover">
          <Forced state="hover">
            <SegmentedControl
              label="Period"
              value="b"
              onChange={noop}
              options={[
                { value: "a", label: "24 hours" },
                { value: "b", label: "7 days" },
              ]}
            />
          </Forced>
        </Sample>
        <Sample state="Focus">
          <Forced state="focus">
            <SegmentedControl
              label="View"
              value="a"
              onChange={noop}
              options={[
                { value: "a", label: "Side by side" },
                { value: "b", label: "Inline" },
              ]}
            />
          </Forced>
        </Sample>
      </Section>

      <Section title="CheckboxGroup">
        <Sample state={`Mixed (${onCount} of ${tools.length} on), expanded`}>
          <CheckboxGroup
            label="Site overview"
            description={`${onCount} of ${tools.length} on · ${tools.length} read`}
            items={tools.map((tool) => ({
              ...tool,
              meta: (
                <>
                  <bdi dir="ltr" className="el-mono">
                    {tool.key}
                  </bdi>
                  <Badge variant="read" />
                </>
              ),
              note: tool.disabled ? "Always on" : undefined,
            }))}
            onChange={(changes) =>
              setTools((current) =>
                current.map((tool) =>
                  tool.key in changes
                    ? { ...tool, checked: changes[tool.key] }
                    : tool,
                ),
              )
            }
            expanded={expanded}
            onExpandedChange={setExpanded}
          />
        </Sample>
        <Sample state="All on, collapsed, with an option">
          <CheckboxGroup
            label="Media"
            description="1 of 1 on · 1 write"
            items={[
              {
                key: "upload_media",
                label: "Upload an image or PDF",
                checked: true,
              },
            ]}
            onChange={noop}
            expanded={false}
            onExpandedChange={noop}
            option={
              <Toggle label="Allow SVG files" checked={false} onChange={noop} />
            }
          />
        </Sample>
        <Sample state="None, hover">
          <Forced state="hover">
            <CheckboxGroup
              label="Database"
              description="0 of 1 on · 1 read"
              items={[
                {
                  key: "query_db",
                  label: "Look up database rows",
                  checked: false,
                },
              ]}
              onChange={noop}
              expanded={false}
              onExpandedChange={noop}
            />
          </Forced>
        </Sample>
        <Sample state="Focus">
          <Forced state="focus">
            <CheckboxGroup
              label="Blocks"
              description="2 of 2 on · 2 read"
              items={[
                {
                  key: "list_block_types",
                  label: "List block types",
                  checked: true,
                },
                {
                  key: "validate_blocks",
                  label: "Check block markup",
                  checked: true,
                },
              ]}
              onChange={noop}
              expanded={false}
              onExpandedChange={noop}
            />
          </Forced>
        </Sample>
        <Sample state="Disabled (unavailable)">
          <CheckboxGroup
            label="Theme drafts"
            description="Not available on this site"
            items={[
              {
                key: "create_draft_theme",
                label: "Create a draft copy of the theme",
                checked: false,
              },
            ]}
            onChange={noop}
            expanded={false}
            onExpandedChange={noop}
            disabled
          />
        </Sample>
      </Section>

      <Section title="FilterBar">
        <Sample state="Two filters active">
          <FilterBar fields={FILTERS} values={filters} onChange={setFilters} />
        </Sample>
        <Sample state="Empty (no filters)">
          <FilterBar fields={FILTERS.slice(0, 2)} values={{}} onChange={noop} />
        </Sample>
        <Sample state="Narrow (folded)">
          <FilterBar
            fields={FILTERS}
            values={{ tool: "site_info" }}
            onChange={noop}
            layout="narrow"
          />
        </Sample>
        <Sample state="Select hover">
          <Forced state="hover">
            <FilterBar
              fields={FILTERS.slice(1, 2)}
              values={{}}
              onChange={noop}
            />
          </Forced>
        </Sample>
      </Section>

      <Section title="CopyField">
        <Sample state="Default">
          <CopyField
            label="MCP URL"
            value="https://example.com/wp-json/mcpmate/mcp"
          />
        </Sample>
        <Sample state="Narrow (wraps)">
          <CopyField
            label="wp-config.php line"
            copyLabel="Copy the wp-config.php line"
            value="define( 'MCPMATE_PUBLIC_URL', 'https://example.trycloudflare.com' );"
            layout="narrow"
          />
        </Sample>
        <Sample state="Focus">
          <Forced state="focus">
            <CopyField
              label="OAuth issuer"
              value="https://example.com/mcpmate"
            />
          </Forced>
        </Sample>
      </Section>
    </>
  );
}

/**
 * The UI kit (`#/ui-kit`): only registered while WP_DEBUG is on.
 * docs/ui.md §7 lists what each component must do.
 */
export default function UiKit() {
  const [params] = useSearchParams();
  const tab =
    params.get("tab") === "b" ? "b" : params.get("tab") === "c" ? "c" : "a";

  return (
    <div className="el-kit">
      <PageHeader
        title="UI kit"
        intro="Every component in every state. Shown only while WP_DEBUG is on."
      />

      <Section title="Tokens">
        <ul className="el-kit-swatches">
          {Object.entries(colors).map(([name, value]) => (
            <li key={name}>
              <span
                className="el-kit-swatch"
                style={{ background: value }}
                aria-hidden="true"
              />
              <bdi dir="ltr" className="el-mono">
                --el-{name}
              </bdi>{" "}
              <span className="el-kit-hex">{value}</span>
            </li>
          ))}
        </ul>
      </Section>

      <Section title="Logo">
        <Sample state="Default">
          <span className="el-kit-row">
            <Logo /> <Logo size={40} />
          </span>
        </Sample>
      </Section>

      <Section title="Button">
        {(
          [
            "primary",
            "secondary",
            "danger",
            "danger-secondary",
            "link",
          ] as const
        ).map((variant) => (
          <Sample key={variant} state={variant}>
            <div className="el-kit-row">
              <Button variant={variant}>Default</Button>
              <Forced state="hover">
                <Button variant={variant}>Hover</Button>
              </Forced>
              <Forced state="focus">
                <Button variant={variant}>Focus</Button>
              </Forced>
              <Button variant={variant} disabled>
                Disabled
              </Button>
              <Button variant={variant} loading>
                Saving…
              </Button>
            </div>
          </Sample>
        ))}
        <Sample state="Sizes, icons, link">
          <div className="el-kit-row">
            <Button size="sm" icon={<PlusOutlined aria-hidden />}>
              Small
            </Button>
            <Button
              icon={<DeleteOutlined aria-hidden />}
              label="Move to trash"
              variant="danger-secondary"
            />
            <Button href="#/connections" variant="primary">
              Link to Connections
            </Button>
          </div>
          <p className="el-kit-note">No AI apps to revoke.</p>
          <Button variant="danger" disabled>
            Revoke all apps
          </Button>
        </Sample>
      </Section>

      <Section title="StatusPill">
        <Sample state="Tones">
          <div className="el-kit-row">
            <StatusPill tone="success" text="3 AI apps connected" />
            <StatusPill tone="warning" glyph="pause" text="AI access paused" />
            <StatusPill tone="danger" text="Connection needs attention" />
            <StatusPill
              tone="neutral"
              glyph="hollow"
              text="Site not connected"
            />
          </div>
        </Sample>
        <Sample state="Link: default, hover, focus">
          <div className="el-kit-row">
            <StatusPill
              tone="success"
              text="1 AI app connected"
              href="#/connections"
            />
            <Forced state="hover">
              <StatusPill
                tone="neutral"
                glyph="hollow"
                text="No AI apps connected"
                href="#/connections"
              />
            </Forced>
            <Forced state="focus">
              <StatusPill
                tone="danger"
                text="Connection needs attention"
                href="#/connections"
              />
            </Forced>
          </div>
        </Sample>
      </Section>

      <Section title="StatusText">
        <Sample state="Words">
          <ul className="el-kit-list">
            <li>
              <StatusText tone="success">Succeeded</StatusText>
            </li>
            <li>
              <StatusText tone="danger">Failed</StatusText>
            </li>
            <li>
              <StatusText tone="warning">Waiting for approval</StatusText>
            </li>
            <li>
              <StatusText tone="success">Applied after approval</StatusText>
            </li>
            <li>
              <StatusText tone="warning" glyph="pause">
                Paused
              </StatusText>
            </li>
            <li>
              <StatusText tone="neutral" glyph="hollow">
                Waiting for first use
              </StatusText>
            </li>
            <li>
              <StatusText tone="neutral">Revoked</StatusText>
            </li>
          </ul>
        </Sample>
      </Section>

      <Section title="Badge">
        <Sample state="Variants">
          <div className="el-kit-row">
            <Badge variant="read" />
            <Badge variant="write" />
            <Badge variant="approval" />
            <Badge variant="neutral">Custom, 6 tools</Badge>
            <Badge variant="unavailable" />
          </div>
        </Sample>
      </Section>

      <Section title="Card">
        <Sample state="Default, with actions">
          <Card
            title="Your MCP URL"
            headingLevel={3}
            actions={<Button size="sm">Copy</Button>}
          >
            <p className="el-kit-p">
              Paste this into your AI app to connect it.
            </p>
          </Card>
        </Sample>
        <Sample state="Loading">
          <Card title="AI access" headingLevel={3} busy>
            <Skeleton count={3} />
          </Card>
        </Sample>
        <Sample state="Error">
          <Card title="Recent activity" headingLevel={3}>
            <Notice
              tone="danger"
              title="Couldn't load recent activity."
              action={<Button size="sm">Try again</Button>}
            />
          </Card>
        </Sample>
        <Sample state="Empty">
          <Card title="AI apps" headingLevel={3}>
            <EmptyState
              title="No AI apps connected yet."
              body="Connect Claude, ChatGPT, Cursor or another AI app. You approve each one here before it can do anything."
              action={{ label: "Connect an AI app", onClick: noop }}
              headingLevel={4}
            />
          </Card>
        </Sample>
      </Section>

      <Section title="StatTile">
        <Sample state="Count">
          <StatTile
            label="Tool calls"
            value={42}
            meta="Last 24 hours · 30 reads, 12 changes"
          />
        </Sample>
        <Sample state="Percent, with link">
          <StatTile
            label="Success rate"
            value={0.96}
            format="percent"
            meta="Last 24 hours · 2 failed"
            link={{
              label: "View failed calls",
              href: "#/activity?result=error",
            }}
          />
        </Sample>
        <Sample state="Fraction">
          <StatTile
            label="Tools turned on"
            value={18}
            total={31}
            format="fraction"
            link={{ label: "Manage tools", href: "#/tools" }}
          />
        </Sample>
        <Sample state="Empty (no data)">
          <StatTile
            label="Success rate"
            value={null}
            meta="No calls in the last 24 hours"
          />
        </Sample>
        <Sample state="Loading">
          <StatTile label="Tool calls" value={null} loading />
        </Sample>
        <Sample state="Error">
          <StatTile
            label="Tool calls"
            value={null}
            error="Couldn't load the numbers."
          />
        </Sample>
      </Section>

      <Section title="Notice">
        <Sample state="Info, with action">
          <Notice
            tone="info"
            title="2 requests are waiting for your approval."
            action={<Button size="sm">Review requests</Button>}
          />
        </Sample>
        <Sample state="Success">
          <Notice tone="success" title="Saved." />
        </Sample>
        <Sample state="Warning, dismissible">
          <Notice tone="warning" title="AI access is paused." onDismiss={noop}>
            AI apps get an error until you resume it.
          </Notice>
        </Sample>
        <Sample state="Danger (error)">
          <Notice tone="danger" title="Couldn't save. Nothing was changed.">
            The server answered 403.
          </Notice>
        </Sample>
      </Section>

      <Section title="EmptyState">
        <Sample state="Default">
          <EmptyState
            title="No changes yet."
            body="When an AI app creates, updates or trashes something, you'll see it here."
            action={{ label: "View the audit log", href: "#/activity" }}
          />
        </Sample>
      </Section>

      <Section title="Skeleton">
        <Sample state="Text">
          <Skeleton count={3} />
        </Sample>
        <Sample state="Number">
          <Skeleton shape="number" />
        </Sample>
        <Sample state="Row">
          <Skeleton shape="row" count={4} />
        </Sample>
      </Section>

      <Section title="Tabs">
        <Sample state="Routes (?tab=)">
          <Tabs
            label="Example tabs"
            activeKey={tab}
            items={[
              { key: "a", label: "Audit log", to: "/ui-kit?tab=a" },
              { key: "b", label: "Change history", to: "/ui-kit?tab=b" },
              {
                key: "c",
                label: "Approvals",
                to: "/ui-kit?tab=c",
                count: "2",
                countLabel: "2 waiting",
              },
            ]}
          >
            <p className="el-kit-p">
              Panel {tab.toUpperCase()}. Left and Right switch tabs; Home and
              End jump.
            </p>
          </Tabs>
        </Sample>
      </Section>

      <FormSamples />

      <Section title="Table and Pagination">
        <TableSamples />
      </Section>

      <Section title="Modal, ConfirmDialog, Drawer">
        <DialogSamples />
      </Section>

      <Section title="RelativeTime">
        <Sample state="Past and future">
          <ul className="el-kit-list">
            <li>
              <RelativeTime date={new Date(Date.now() - 5 * 60_000)} />
            </li>
            <li>
              <RelativeTime date={new Date(Date.now() - 3 * 3600_000)} />
            </li>
            <li>
              <RelativeTime date={new Date(Date.now() + 9 * 60_000)} />
            </li>
          </ul>
        </Sample>
      </Section>

      <Section title="JsonBlock">
        <Sample state="Default">
          <JsonBlock
            label="Example arguments"
            value={{
              post_id: 41,
              patches: ["update:a1b2", "insert:c3d4"],
              token: "[redacted]",
            }}
          />
        </Sample>
      </Section>

      <Section title="DiffView">
        <Sample state="Inline">
          <DiffView fields={DIFF} layout="narrow" />
        </Sample>
        <Sample state="Side by side (offered from 960 px)">
          <DiffView
            fields={[{ ...DIFF[2], key: "excerpt", label: "Excerpt" }]}
          />
        </Sample>
      </Section>
    </div>
  );
}
