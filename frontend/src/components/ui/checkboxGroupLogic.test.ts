import { describe, expect, it } from "vitest";

import { groupState, toggleGroup } from "./checkboxGroupLogic";

describe("groupState", () => {
  it("is all, some or none", () => {
    expect(
      groupState([
        { key: "a", checked: true },
        { key: "b", checked: true },
      ]),
    ).toBe("all");
    expect(
      groupState([
        { key: "a", checked: true },
        { key: "b", checked: false },
      ]),
    ).toBe("some");
    expect(groupState([{ key: "a", checked: false }])).toBe("none");
    expect(groupState([])).toBe("none");
  });
});

describe("toggleGroup", () => {
  it("turns everything on from mixed or none", () => {
    expect(
      toggleGroup([
        { key: "a", checked: true },
        { key: "b", checked: false },
      ]),
    ).toEqual({
      a: true,
      b: true,
    });
    expect(toggleGroup([{ key: "a", checked: false }])).toEqual({ a: true });
  });

  it("turns everything off when all are on", () => {
    expect(
      toggleGroup([
        { key: "a", checked: true },
        { key: "b", checked: true },
      ]),
    ).toEqual({
      a: false,
      b: false,
    });
  });

  it("never changes a disabled child", () => {
    const always = { key: "check_approval", checked: true, disabled: true };
    const off = { key: "site_info", checked: false };

    expect(toggleGroup([always, off])).toEqual({
      check_approval: true,
      site_info: true,
    });
    expect(toggleGroup([always, { ...off, checked: true }])).toEqual({
      check_approval: true,
      site_info: false,
    });
  });
});
