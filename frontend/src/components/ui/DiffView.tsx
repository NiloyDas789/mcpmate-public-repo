import { Fragment, useId, useState, type ReactNode } from "react";
import { __, _n, sprintf } from "@wordpress/i18n";
import { useContainerWidth, type Layout } from "@/hooks/useContainerWidth";
import { breakpoints } from "@config/tokens";
import { interpolate } from "@utils/i18n";
import SegmentedControl from "./SegmentedControl";

/** One run of lines, as the server's diff returns it (docs/ui.md B7). */
export interface DiffOp {
  op: "equal" | "add" | "remove";
  lines: string[];
}

export interface DiffField {
  key: string;
  label: string;
  /** Set when the field did not change: shown on one line. */
  unchanged?: string;
  ops?: DiffOp[];
  /** The snapshot was cut at 512 KB. */
  truncated?: boolean;
}

interface DiffViewProps {
  fields: DiffField[];
  /** Side by side is offered from a 960 px container. */
  layout?: Layout;
  /** Unchanged runs longer than this fold behind a Show button. */
  collapseAfter?: number;
}

type Mode = "inline" | "split";

function Line({ op, text }: { op: DiffOp["op"]; text: string }) {
  if (op === "add") {
    return (
      <ins className="el-diff-line is-add">
        <span className="el-diff-sign" aria-hidden="true">
          +
        </span>
        <span className="sr-only">{__("Added line:", "mcpmate")} </span>
        {text}
      </ins>
    );
  }

  if (op === "remove") {
    return (
      <del className="el-diff-line is-remove">
        <span className="el-diff-sign" aria-hidden="true">
          −
        </span>
        <span className="sr-only">{__("Removed line:", "mcpmate")} </span>
        {text}
      </del>
    );
  }

  return (
    <span className="el-diff-line">
      <span className="el-diff-sign" aria-hidden="true" />
      {text}
    </span>
  );
}

function CollapsedRun({
  count,
  expanded,
  onToggle,
  controls,
}: {
  count: number;
  expanded: boolean;
  onToggle: () => void;
  controls: string;
}) {
  const runText = sprintf(
    /* translators: %d: number of lines that did not change. */
    _n("%d unchanged line", "%d unchanged lines", count, "mcpmate"),
    count,
  );

  return (
    <span className="el-diff-collapsed">
      <span aria-hidden="true">{runText}</span>{" "}
      <button
        type="button"
        className="el-disclosure"
        aria-expanded={expanded}
        aria-controls={controls}
        onClick={onToggle}
      >
        {interpolate(
          expanded
            ? /* translators: %s: "12 unchanged lines", read by screen readers only. */
              __("Hide %s", "mcpmate")
            : /* translators: %s: "12 unchanged lines", read by screen readers only. */
              __("Show %s", "mcpmate"),
          [
            <span className="sr-only" key="run">
              {runText}
            </span>,
          ],
        )}
      </button>
    </span>
  );
}

function FieldDiff({
  field,
  mode,
  collapseAfter,
}: {
  field: DiffField;
  mode: Mode;
  collapseAfter: number;
}) {
  const id = useId();
  const [open, setOpen] = useState<Record<number, boolean>>({});
  const ops = field.ops ?? [];
  const regionLabel = sprintf(
    /* translators: %s: field name, e.g. "Content". */
    __("Changes to %s", "mcpmate"),
    field.label,
  );

  const runs = ops.map((op, index) => {
    const folds = op.op === "equal" && op.lines.length > collapseAfter;
    return { op, index, folds, shown: !folds || open[index] };
  });
  const toggle = (index: number) =>
    setOpen((previous) => ({ ...previous, [index]: !previous[index] }));

  if (mode === "inline") {
    return (
      <div
        className="el-diff-lines"
        role="region"
        tabIndex={0}
        aria-label={regionLabel}
        dir="ltr"
      >
        {runs.map(({ op, index, folds, shown }) => (
          <Fragment key={index}>
            {folds && (
              <CollapsedRun
                count={op.lines.length}
                expanded={Boolean(open[index])}
                onToggle={() => toggle(index)}
                controls={`${id}-run-${index}`}
              />
            )}
            <span
              id={`${id}-run-${index}`}
              hidden={!shown}
              className="el-diff-run"
            >
              {op.lines.map((line, n) => (
                <Line key={n} op={op.op} text={line} />
              ))}
            </span>
          </Fragment>
        ))}
      </div>
    );
  }

  // Side by side: a removed run next to the added run that follows it.
  const rows: ReactNode[] = [];
  for (let i = 0; i < runs.length; i++) {
    const { op, index, folds, shown } = runs[i];

    if (op.op === "equal") {
      if (folds) {
        rows.push(
          <tr key={`fold-${index}`}>
            <td colSpan={2}>
              <CollapsedRun
                count={op.lines.length}
                expanded={Boolean(open[index])}
                onToggle={() => toggle(index)}
                controls={`${id}-run-${index}`}
              />
            </td>
          </tr>,
        );
      }
      rows.push(
        <tr key={`run-${index}`} id={`${id}-run-${index}`} hidden={!shown}>
          <td>
            {op.lines.map((line, n) => (
              <Line key={n} op="equal" text={line} />
            ))}
          </td>
          <td>
            {op.lines.map((line, n) => (
              <Line key={n} op="equal" text={line} />
            ))}
          </td>
        </tr>,
      );
      continue;
    }

    const removed = op.op === "remove" ? op.lines : [];
    let added = op.op === "add" ? op.lines : [];
    if (op.op === "remove" && runs[i + 1]?.op.op === "add") {
      added = runs[i + 1].op.lines;
      i++;
    }
    rows.push(
      <tr key={`change-${index}`}>
        <td>
          {removed.map((line, n) => (
            <Line key={n} op="remove" text={line} />
          ))}
        </td>
        <td>
          {added.map((line, n) => (
            <Line key={n} op="add" text={line} />
          ))}
        </td>
      </tr>,
    );
  }

  return (
    <div
      className="el-diff-lines"
      role="region"
      tabIndex={0}
      aria-label={regionLabel}
      dir="ltr"
    >
      <table className="el-diff-table">
        <thead>
          <tr>
            <th scope="col">{__("Before", "mcpmate")}</th>
            <th scope="col">{__("After", "mcpmate")}</th>
          </tr>
        </thead>
        <tbody>{rows}</tbody>
      </table>
    </div>
  );
}

/**
 * Before and after, field by field (docs/ui.md §5.4.2). Lines are `<ins>` and
 * `<del>` with a visible + or − and hidden "Added line:" / "Removed line:",
 * so the diff never relies on colour. Unchanged fields take one line.
 */
export default function DiffView({
  fields,
  layout = "auto",
  collapseAfter = 6,
}: DiffViewProps) {
  const [widthRef, width] = useContainerWidth();
  const canSplit =
    layout === "wide" ||
    (layout === "auto" && width !== undefined && width >= breakpoints.lg);
  const [mode, setMode] = useState<Mode>("inline");
  const shownMode: Mode = canSplit ? mode : "inline";

  const count = (kind: DiffOp["op"]) =>
    fields.reduce(
      (sum, field) =>
        sum +
        (field.ops ?? [])
          .filter((op) => op.op === kind)
          .reduce((lines, op) => lines + op.lines.length, 0),
      0,
    );
  const added = count("add");
  const removed = count("remove");

  return (
    <div ref={widthRef} className="el-diff">
      <div className="el-diff-toolbar">
        {canSplit && (
          <SegmentedControl<Mode>
            label={__("View", "mcpmate")}
            value={mode}
            onChange={setMode}
            options={[
              { value: "split", label: __("Side by side", "mcpmate") },
              { value: "inline", label: __("Inline", "mcpmate") },
            ]}
          />
        )}
        <p className="el-diff-summary">
          {sprintf(
            /* translators: 1: "3 lines added", 2: "1 removed". */
            __("%1$s, %2$s", "mcpmate"),
            sprintf(
              /* translators: %d: number of lines. */
              _n("%d line added", "%d lines added", added, "mcpmate"),
              added,
            ),
            sprintf(
              /* translators: %d: number of lines. */
              _n("%d removed", "%d removed", removed, "mcpmate"),
              removed,
            ),
          )}
        </p>
      </div>
      <dl className="el-diff-fields">
        {fields.map((field) => (
          <div key={field.key} className="el-diff-field">
            <dt>{field.label}</dt>
            <dd>
              {field.unchanged !== undefined ? (
                <>
                  <span className="el-diff-unchanged">
                    {__("(unchanged)", "mcpmate")}
                  </span>{" "}
                  {field.unchanged}
                </>
              ) : (
                <>
                  <FieldDiff
                    field={field}
                    mode={shownMode}
                    collapseAfter={collapseAfter}
                  />
                  {field.truncated && (
                    <p className="el-diff-note">
                      {__(
                        "This snapshot was too large to keep in full (over 512 KB). The first 512 KB are shown.",
                        "mcpmate",
                      )}
                    </p>
                  )}
                </>
              )}
            </dd>
          </div>
        ))}
      </dl>
    </div>
  );
}
