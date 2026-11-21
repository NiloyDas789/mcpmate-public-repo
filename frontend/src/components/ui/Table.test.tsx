import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { useState } from "react";
import { describe, expect, it } from "vitest";

import Announcer from "./Announcer";
import Table, { type TableColumn, type TableSort } from "./Table";

interface Row {
  id: string;
  date: string;
  tool: string;
}

const ROWS: Row[] = [
  { id: "1", date: "26 Oct", tool: "site_info" },
  { id: "2", date: "25 Oct", tool: "upload_media" },
];

const COLUMNS: TableColumn<Row>[] = [
  {
    key: "date",
    title: "Date",
    rowHeader: true,
    sortable: true,
    firstOrder: "descending",
    orderLabels: { ascending: "oldest first", descending: "newest first" },
    render: (row) => row.date,
  },
  { key: "tool", title: "Tool", render: (row) => row.tool },
  {
    key: "actions",
    title: "Actions",
    hiddenTitle: true,
    render: () => <button type="button">View</button>,
  },
];

function Demo(props: Partial<Parameters<typeof Table<Row>>[0]>) {
  const [sort, setSort] = useState<TableSort>({
    key: "date",
    order: "descending",
  });

  return (
    <Announcer>
      <Table
        caption="Audit log, newest first."
        columns={COLUMNS}
        rows={ROWS}
        rowKey={(row) => row.id}
        sort={sort}
        onSort={setSort}
        {...props}
      />
    </Announcer>
  );
}

describe("Table", () => {
  it("is a captioned table with column and row headers", () => {
    render(<Demo />);

    const table = screen.getByRole("table", {
      name: "Audit log, newest first.",
    });
    const headers = within(table).getAllByRole("columnheader");
    expect(headers.map((header) => header.getAttribute("scope"))).toEqual([
      "col",
      "col",
      "col",
    ]);
    expect(
      within(table).getByRole("columnheader", { name: "Actions" }),
    ).toBeInTheDocument();
    expect(
      within(table).getByRole("rowheader", { name: "26 Oct" }),
    ).toHaveAttribute("scope", "row");
  });

  it("sorts with a header button and announces the new order", async () => {
    const user = userEvent.setup();
    render(<Demo />);

    const header = screen.getByRole("columnheader", { name: /Date/ });
    expect(header).toHaveAttribute("aria-sort", "descending");
    const button = screen.getByRole("button", {
      name: "Date, sorted newest first. Select to sort oldest first.",
    });

    await user.click(button);

    expect(header).toHaveAttribute("aria-sort", "ascending");
    expect(screen.getByRole("status")).toHaveTextContent(
      "Sorted by Date, oldest first.",
    );
    expect(
      screen.getByRole("button", {
        name: "Date, sorted oldest first. Select to sort newest first.",
      }),
    ).toBeInTheDocument();
  });

  it("becomes a list of cards when narrow, with a Sort by select", async () => {
    const user = userEvent.setup();
    render(<Demo layout="narrow" />);

    expect(screen.queryByRole("table")).toBeNull();
    const list = screen.getByRole("list", { name: "Audit log, newest first." });
    expect(within(list).getAllByRole("listitem")).toHaveLength(2);
    expect(
      within(list).getByRole("heading", { level: 3, name: "26 Oct" }),
    ).toBeInTheDocument();

    await user.selectOptions(
      screen.getByRole("combobox", { name: "Sort by" }),
      "date:ascending",
    );
    expect(screen.getByRole("status")).toHaveTextContent(
      "Sorted by Date, oldest first.",
    );
  });

  it("shows skeleton rows while loading", () => {
    render(<Demo loading rows={[]} />);

    expect(screen.getByText("Loading…")).toBeInTheDocument();
    expect(screen.getAllByRole("row")).toHaveLength(1 + 3);
  });

  it("shows its empty state and its error", () => {
    const { rerender } = render(
      <Demo rows={[]} empty={<p>Nothing logged yet.</p>} />,
    );
    expect(screen.getByText("Nothing logged yet.")).toBeInTheDocument();

    rerender(
      <Demo rows={[]} error={<p>{"Couldn't load the audit log."}</p>} />,
    );
    expect(
      screen.getByText("Couldn't load the audit log."),
    ).toBeInTheDocument();
    expect(screen.queryByRole("table")).toBeNull();
  });
});
