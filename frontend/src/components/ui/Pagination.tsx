import { LeftOutlined, RightOutlined } from "@ant-design/icons";
import { __, sprintf } from "@wordpress/i18n";
import {
  isNarrow,
  useContainerWidth,
  type Layout,
} from "@/hooks/useContainerWidth";
import { breakpoints } from "@config/tokens";
import { formatNumber } from "@utils/format";
import Button from "./Button";
import { pageList } from "./paginationLogic";
import { cx } from "@utils/classNames";

interface PaginationProps {
  /** Names the navigation: "Audit log pages". */
  label: string;
  page: number;
  pageSize: number;
  total: number;
  /** Then move focus to the table caption and announce "Page 2 of 62." */
  onChange: (page: number) => void;
  layout?: Layout;
}

/**
 * Pages of a list as a `<nav>` (docs/ui.md §7), with "Showing 21–40 of
 * 1,240". Below 600 px only Previous, "Page 2 of 62" and Next.
 */
export default function Pagination({
  label,
  page,
  pageSize,
  total,
  onChange,
  layout = "auto",
}: PaginationProps) {
  const [widthRef, width] = useContainerWidth<HTMLElement>();
  const narrow = isNarrow(layout, width, breakpoints.sm);
  const pages = Math.max(1, Math.ceil(total / pageSize));
  const from = total === 0 ? 0 : (page - 1) * pageSize + 1;
  const to = Math.min(total, page * pageSize);

  return (
    <nav ref={widthRef} aria-label={label} className="el-pagination">
      <p className="el-pagination-range">
        {sprintf(
          /* translators: 1: first item shown, 2: last item shown, 3: total. */
          __("Showing %1$s–%2$s of %3$s", "mcpmate"),
          formatNumber(from),
          formatNumber(to),
          formatNumber(total),
        )}
      </p>
      <ul className="el-pagination-list">
        <li>
          <Button
            size="sm"
            icon={<LeftOutlined aria-hidden className="el-flip" />}
            disabled={page <= 1}
            onClick={() => onChange(page - 1)}
          >
            {__("Previous", "mcpmate")}
          </Button>
        </li>
        {narrow ? (
          <li className="el-pagination-position">
            {sprintf(
              /* translators: 1: current page, 2: number of pages. */
              __("Page %1$s of %2$s", "mcpmate"),
              formatNumber(page),
              formatNumber(pages),
            )}
          </li>
        ) : (
          pageList(page, pages).map((item, index) =>
            item === "gap" ? (
              <li
                key={`gap-${index}`}
                className="el-pagination-gap"
                aria-hidden="true"
              >
                …
              </li>
            ) : (
              <li key={item}>
                <button
                  type="button"
                  className={cx("el-page", item === page && "is-current")}
                  aria-current={item === page ? "page" : undefined}
                  aria-label={sprintf(
                    /* translators: %s: page number. */
                    __("Page %s", "mcpmate"),
                    formatNumber(item),
                  )}
                  onClick={() => item !== page && onChange(item)}
                >
                  {formatNumber(item)}
                </button>
              </li>
            ),
          )
        )}
        <li>
          <Button
            size="sm"
            icon={<RightOutlined aria-hidden className="el-flip" />}
            iconPosition="end"
            disabled={page >= pages}
            onClick={() => onChange(page + 1)}
          >
            {__("Next", "mcpmate")}
          </Button>
        </li>
      </ul>
    </nav>
  );
}
