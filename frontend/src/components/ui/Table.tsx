import { useId, type HTMLAttributes, type ReactNode, type Ref } from "react";
import { Table as AntTable } from "antd";
import type { TableColumnsType } from "antd";
import { __, sprintf } from "@wordpress/i18n";
import { useAnnounce } from "@/hooks/useAnnounce";
import {
  isNarrow,
  useContainerWidth,
  type Layout,
} from "@/hooks/useContainerWidth";
import Skeleton from "./Skeleton";
import { cx } from "@utils/classNames";

export type SortOrder = "ascending" | "descending";

export interface TableSort {
  key: string;
  order: SortOrder;
}

export interface TableColumn<T> {
  key: string;
  title: string;
  render: (row: T) => ReactNode;
  sortable?: boolean;
  /** The order a first click sorts in. */
  firstOrder?: SortOrder;
  /** How each order reads: `{ ascending: "oldest first", descending: "newest first" }`. */
  orderLabels?: Record<SortOrder, string>;
  /** The cell names its row: `<th scope="row">`, and the card's heading when narrow. */
  rowHeader?: boolean;
  /** The header exists for screen readers only (an actions column). */
  hiddenTitle?: boolean;
}

interface TableProps<T> {
  caption: string;
  hideCaption?: boolean;
  /** On the caption's text, so a page change can move focus there. */
  captionRef?: Ref<HTMLElement>;
  columns: TableColumn<T>[];
  rows: T[];
  rowKey: (row: T) => string;
  sort?: TableSort;
  onSort?: (sort: TableSort) => void;
  loading?: boolean;
  /** Skeleton rows while loading. */
  loadingRows?: number;
  /** An EmptyState, shown in the table body when there are no rows. */
  empty?: ReactNode;
  /** A Notice, shown instead of the rows. */
  error?: ReactNode;
  /** Cards below a 720 px container. */
  layout?: Layout;
  cardHeadingLevel?: 3 | 4;
}

const NARROW_BELOW = 720;

function orderLabel<T>(column: TableColumn<T>, order: SortOrder): string {
  return (
    column.orderLabels?.[order] ??
    (order === "ascending"
      ? __("ascending", "mcpmate")
      : __("descending", "mcpmate"))
  );
}

function nextOrder<T>(column: TableColumn<T>, sort?: TableSort): SortOrder {
  if (sort?.key === column.key) {
    return sort.order === "ascending" ? "descending" : "ascending";
  }

  return column.firstOrder ?? "ascending";
}

function sortButtonName<T>(column: TableColumn<T>, sort?: TableSort): string {
  const next = orderLabel(column, nextOrder(column, sort));

  if (sort?.key === column.key) {
    return sprintf(
      /* translators: 1: column name, 2: current order, e.g. "newest first", 3: the other order. */
      __("%1$s, sorted %2$s. Select to sort %3$s.", "mcpmate"),
      column.title,
      orderLabel(column, sort.order),
      next,
    );
  }

  return sprintf(
    /* translators: 1: column name, 2: an order, e.g. "oldest first". */
    __("%1$s, not sorted. Select to sort %2$s.", "mcpmate"),
    column.title,
    next,
  );
}

/**
 * A data table (docs/ui.md §7): a real `<table>` with a caption, column
 * headers with scope, row headers, and sort buttons in the headers with
 * `aria-sort`. Below a 720 px container it becomes a list of cards, with
 * sorting in a "Sort by" select. Only one form is in the DOM at a time.
 */
export default function Table<T>({
  caption,
  hideCaption = false,
  captionRef,
  columns,
  rows,
  rowKey,
  sort,
  onSort,
  loading = false,
  loadingRows = 3,
  empty,
  error,
  layout = "auto",
  cardHeadingLevel = 3,
}: TableProps<T>) {
  const id = useId();
  const announce = useAnnounce();
  const [widthRef, width] = useContainerWidth();
  const narrow = isNarrow(layout, width, NARROW_BELOW);
  const sortable = columns.filter((column) => column.sortable);

  const sortBy = (column: TableColumn<T>, order: SortOrder) => {
    onSort?.({ key: column.key, order });
    announce(
      sprintf(
        /* translators: 1: column name, 2: order, e.g. "longest first". */
        __("Sorted by %1$s, %2$s.", "mcpmate"),
        column.title,
        orderLabel(column, order),
      ),
    );
  };

  const captionText = (
    <span ref={captionRef} tabIndex={-1} className="el-table-caption-text">
      {caption}
    </span>
  );

  if (error) {
    return (
      <div ref={widthRef} className="el-table">
        <p className={hideCaption ? "sr-only" : "el-table-caption"}>
          {captionText}
        </p>
        {error}
      </div>
    );
  }

  if (narrow) {
    const captionId = `${id}-caption`;
    const rowHeader = columns.find((column) => column.rowHeader) ?? columns[0];
    const Heading = `h${cardHeadingLevel}` as "h3" | "h4";

    return (
      <div
        ref={widthRef}
        className="el-table el-table-narrow"
        aria-busy={loading || undefined}
      >
        <p
          id={captionId}
          className={hideCaption ? "sr-only" : "el-table-caption"}
        >
          {captionText}
        </p>
        {sortable.length > 0 && onSort && (
          <div className="el-table-sort">
            <label htmlFor={`${id}-sort`} className="el-field-label">
              {__("Sort by", "mcpmate")}
            </label>
            <select
              id={`${id}-sort`}
              className="el-input el-native-select"
              value={sort ? `${sort.key}:${sort.order}` : ""}
              onChange={(event) => {
                const [key, order] = event.target.value.split(":");
                const column = sortable.find((item) => item.key === key);
                if (column) {
                  sortBy(column, order as SortOrder);
                }
              }}
            >
              {!sort && (
                <option value="">{__("Default order", "mcpmate")}</option>
              )}
              {sortable.flatMap((column) =>
                (["descending", "ascending"] as SortOrder[]).map((order) => (
                  <option
                    key={`${column.key}:${order}`}
                    value={`${column.key}:${order}`}
                  >
                    {sprintf(
                      /* translators: 1: column name, 2: order, e.g. "newest first". */
                      __("%1$s, %2$s", "mcpmate"),
                      column.title,
                      orderLabel(column, order),
                    )}
                  </option>
                )),
              )}
            </select>
          </div>
        )}
        {loading ? (
          <Skeleton shape="text" count={loadingRows} />
        ) : rows.length === 0 ? (
          empty
        ) : (
          <ul className="el-table-cards" aria-labelledby={captionId}>
            {rows.map((row) => (
              <li key={rowKey(row)} className="el-table-card">
                <Heading className="el-table-card-title">
                  {rowHeader.render(row)}
                </Heading>
                <dl>
                  {columns
                    .filter((column) => column !== rowHeader)
                    .map((column) => (
                      <div key={column.key} className="el-table-card-row">
                        <dt
                          className={column.hiddenTitle ? "sr-only" : undefined}
                        >
                          {column.title}
                        </dt>
                        <dd>{column.render(row)}</dd>
                      </div>
                    ))}
                </dl>
              </li>
            ))}
          </ul>
        )}
      </div>
    );
  }

  const antColumns: TableColumnsType<T> = columns.map((column) => {
    const sorted = sort?.key === column.key ? sort.order : undefined;

    return {
      key: column.key,
      title: column.sortable ? (
        <button
          type="button"
          className="el-sort-button"
          aria-label={sortButtonName(column, sort)}
          onClick={() => sortBy(column, nextOrder(column, sort))}
        >
          {column.title}
          <span
            className={cx("el-sort-icon", sorted && `is-${sorted}`)}
            aria-hidden="true"
          />
        </button>
      ) : column.hiddenTitle ? (
        <span className="sr-only">{column.title}</span>
      ) : (
        column.title
      ),
      // Skeleton rows name nothing, so they get no row header.
      rowScope: column.rowHeader && !loading ? "row" : undefined,
      onHeaderCell: () =>
        ({ scope: "col", "aria-sort": sorted }) as HTMLAttributes<HTMLElement>,
      render: loading
        ? () => <Skeleton announce={false} />
        : (_value: unknown, row: T) => column.render(row),
    };
  });

  const placeholders = Array.from({ length: loadingRows }, (_, index) => ({
    __skeleton: index,
  })) as unknown as T[];

  return (
    <div ref={widthRef} className="el-table" aria-busy={loading || undefined}>
      {loading && <span className="sr-only">{__("Loading…", "mcpmate")}</span>}
      <AntTable<T>
        caption={
          <span className={hideCaption ? "sr-only" : undefined}>
            {captionText}
          </span>
        }
        columns={antColumns}
        dataSource={loading ? placeholders : rows}
        rowKey={
          loading
            ? (row) => `skeleton-${(row as { __skeleton: number }).__skeleton}`
            : rowKey
        }
        pagination={false}
        showSorterTooltip={false}
        locale={{ emptyText: empty ?? __("Nothing here yet.", "mcpmate") }}
        tableLayout="auto"
      />
    </div>
  );
}
