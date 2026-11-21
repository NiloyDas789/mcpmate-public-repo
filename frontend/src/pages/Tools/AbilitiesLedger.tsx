import { useId } from "react";
import { Checkbox } from "antd";
import { Link } from "react-router";
import { __, sprintf } from "@wordpress/i18n";
import Badge from "@components/ui/Badge";
import Skeleton from "@components/ui/Skeleton";
import Table from "@components/ui/Table";
import { groupState, toggleGroup } from "@components/ui/checkboxGroupLogic";
import { isNarrow, useContainerWidth } from "@/hooks/useContainerWidth";
import { cx } from "@utils/classNames";
import { kindCounts, type ToolRow, type ToolSection } from "./logic";
import { onCount } from "./toolText";

/** Below this container width the source list becomes a select. */
const NARROW_BELOW = 960;

interface AbilitiesLedgerProps {
  /** The sources a filter and search leave, with only their matching rows. */
  shown: ToolSection[];
  /** The open source (`ledgerSelection()`). */
  selected: ToolSection;
  /** The whole source, for its counts. */
  wholeOf: (section: ToolSection) => ToolSection;
  /** Where a source's link goes: this tab with `?source=`. */
  hrefFor: (section: ToolSection) => string;
  /** The select's choice (narrow containers). */
  onSelect: (section: ToolSection) => void;
  /** A filter or search is on: counts are matches. */
  narrowed: boolean;
  showDescriptions: boolean;
  onChange: (section: ToolSection, requested: Record<string, boolean>) => void;
}

/**
 * Tools → Abilities as a ledger (docs/ui.md §5.3): the sources (each
 * plugin's category, or MCPMate's own tool) beside a table of the open
 * source's abilities. The open source is `?source=`. Each checkbox saves
 * at once through the page's `changeRows`; ability IDs are never shown
 * (search still finds them).
 */
export default function AbilitiesLedger({
  shown,
  selected,
  wholeOf,
  hrefFor,
  onSelect,
  narrowed,
  showDescriptions,
  onChange,
}: AbilitiesLedgerProps) {
  const id = useId();
  const [widthRef, width] = useContainerWidth();
  const narrow = isNarrow("auto", width, NARROW_BELOW);
  const headingId = `${id}-heading`;
  const summaryId = `${id}-summary`;

  const whole = wholeOf(selected);
  const on = whole.rows.filter((row) => row.checked).length;
  const state = groupState(selected.rows);
  const available = selected.rows.filter((row) => !row.alwaysOn);

  const sourceCount = (section: ToolSection) => {
    const all = wholeOf(section).rows;

    return narrowed
      ? {
          text: String(section.rows.length),
          label: sprintf(
            /* translators: %d: abilities of this source that match the search or filter. */
            __("%d matching", "mcpmate"),
            section.rows.length,
          ),
        }
      : {
          text: `${all.filter((row) => row.checked).length}/${all.length}`,
          label: onCount(all.filter((row) => row.checked).length, all.length),
        };
  };

  return (
    <div
      ref={widthRef}
      className={cx("el-ledger", narrow && "is-narrow")}
      data-testid="abilities-ledger"
    >
      {narrow ? (
        <div className="el-ledger-select">
          <label htmlFor={`${id}-source`} className="el-field-label">
            {__("Source", "mcpmate")}
          </label>
          <select
            id={`${id}-source`}
            className="el-input el-native-select"
            value={selected.id}
            onChange={(event) => {
              const next = shown.find((item) => item.id === event.target.value);
              if (next) {
                onSelect(next);
              }
            }}
          >
            {shown.map((section) => (
              <option key={section.id} value={section.id}>
                {sprintf(
                  /* translators: 1: source name, e.g. Yoast SEO, 2: how many are on, e.g. "3 of 7 on". */
                  __("%1$s (%2$s)", "mcpmate"),
                  section.label,
                  sourceCount(section).label,
                )}
              </option>
            ))}
          </select>
        </div>
      ) : (
        <nav
          className="el-ledger-sources"
          aria-label={__("Ability sources", "mcpmate")}
        >
          <ul>
            {shown.map((section) => {
              const count = sourceCount(section);
              const current = section.id === selected.id;

              return (
                <li key={section.id}>
                  <Link
                    to={hrefFor(section)}
                    replace
                    className={cx("el-ledger-source", current && "is-current")}
                    aria-current={current ? "true" : undefined}
                  >
                    {/* As the tabs name their counts: "Shop, 0 of 2 on". */}
                    <span className="sr-only">
                      {section.label}, {count.label}
                    </span>
                    <span className="el-ledger-source-name" aria-hidden="true">
                      {section.label}
                    </span>
                    <span className="el-ledger-source-count" aria-hidden="true">
                      {count.text}
                    </span>
                  </Link>
                </li>
              );
            })}
          </ul>
        </nav>
      )}

      <section className="el-ledger-panel" aria-labelledby={headingId}>
        <div className="el-ledger-head">
          <div>
            <h2 id={headingId} className="el-ledger-title">
              {selected.label}
            </h2>
            <p id={summaryId} className="el-ledger-summary">
              <span className="el-ledger-summary-on">
                {onCount(on, whole.rows.length)}
              </span>{" "}
              <span className="el-ledger-summary-kinds">
                {kindCounts(whole.rows)}
              </span>
            </p>
          </div>
          {available.length > 1 && (
            <Checkbox
              className="el-ledger-all"
              checked={state === "all"}
              indeterminate={state === "some"}
              aria-checked={state === "some" ? "mixed" : undefined}
              aria-describedby={summaryId}
              onChange={() => {
                const next = toggleGroup(selected.rows);
                onChange(
                  selected,
                  Object.fromEntries(
                    Object.entries(next).filter(
                      ([key, value]) =>
                        selected.rows.find((row) => row.key === key)
                          ?.checked !== value,
                    ),
                  ),
                );
              }}
            >
              {narrowed
                ? __("All matching on", "mcpmate")
                : __("All on", "mcpmate")}
            </Checkbox>
          )}
        </div>

        <Table<ToolRow>
          caption={selected.label}
          hideCaption
          cardHeadingLevel={3}
          rows={selected.rows}
          rowKey={(row) => row.key}
          columns={[
            {
              key: "on",
              title: __("On", "mcpmate"),
              render: (row) => (
                <Checkbox
                  checked={row.checked}
                  disabled={row.alwaysOn}
                  aria-label={row.label}
                  onChange={(event) =>
                    onChange(selected, { [row.key]: event.target.checked })
                  }
                />
              ),
            },
            {
              key: "ability",
              title: __("Ability", "mcpmate"),
              rowHeader: true,
              render: (row) => (
                <span className="el-ledger-ability">
                  <span className="el-ledger-ability-label">{row.label}</span>
                  {row.alwaysOn && (
                    <span className="el-ledger-ability-note">
                      {__("Always on", "mcpmate")}
                    </span>
                  )}
                  {showDescriptions && row.description && (
                    <span className="el-ledger-ability-description">
                      {row.description}
                    </span>
                  )}
                </span>
              ),
            },
            {
              key: "kind",
              title: __("Access", "mcpmate"),
              render: (row) => (
                <span className="el-ledger-kind">
                  <Badge variant={row.kind} />
                  {row.approval && <Badge variant="approval" />}
                </span>
              ),
            },
          ]}
        />
      </section>
    </div>
  );
}

/** The ledger while the tools load: a source list and a table, in grey. */
export function AbilitiesLedgerSkeleton() {
  return (
    <div className="el-ledger">
      <div className="el-ledger-sources">
        <Skeleton shape="text" count={5} />
      </div>
      <div className="el-ledger-panel">
        <Skeleton shape="text" count={1} />
        <Skeleton shape="text" count={6} announce={false} />
      </div>
    </div>
  );
}
