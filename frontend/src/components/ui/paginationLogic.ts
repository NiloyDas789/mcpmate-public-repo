/** 1 … 4 5 6 … 62: the first, the last, and the current page's neighbours. */
export function pageList(page: number, pages: number): (number | "gap")[] {
  const shown = new Set([1, pages, page - 1, page, page + 1]);
  const list: (number | "gap")[] = [];

  for (let n = 1; n <= pages; n++) {
    if (shown.has(n)) {
      list.push(n);
    } else if (list[list.length - 1] !== "gap") {
      list.push("gap");
    }
  }

  return list;
}
