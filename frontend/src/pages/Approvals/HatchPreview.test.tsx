import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";

import HatchPreview from "./HatchPreview";
import { isHatchPreview } from "./hatchLogic";

const php = {
  code: "echo '<b>hi</b>';\nreturn 1;",
  bytes: 27,
  lines: 2,
  sha256: "a".repeat(64),
  timeout: 30,
  note: "Read every line.",
};

describe("HatchPreview", () => {
  it("shows execute_php's whole code as text, never as HTML", () => {
    const { container } = render(<HatchPreview tool="php" preview={php} />);

    expect(
      screen.getByRole("region", { name: "PHP code to run" }).textContent,
    ).toBe(php.code);
    expect(container.querySelector("b")).toBeNull();
    expect(screen.getByText(/2 lines, time limit 30 s/)).toBeTruthy();
  });

  it("shows a shell command and who a login link signs in from where", () => {
    render(
      <HatchPreview
        tool="shell_cli"
        preview={{
          command: "wp option update blogdescription 'it'\\''s new'",
          args: ["option", "update", "blogdescription", "it's new"],
          argv: [
            "option",
            "update",
            "blogdescription",
            "it's new",
            "--path=/srv/wp",
          ],
          timeout: 120,
          note: "Runs on the server.",
        }}
      />,
    );
    expect(
      screen.getByRole("region", { name: "WP-CLI command to run" }).textContent,
    ).toBe("wp option update blogdescription 'it'\\''s new'");
    const args = screen.getByRole("list", { name: "WP-CLI arguments" });
    expect(args.textContent).toContain('"it\'s new"');
    expect(args.textContent).toContain('"--path=/srv/wp"');

    render(
      <HatchPreview
        tool="login_link"
        preview={{
          user: { id: 1, login: "admin", display_name: "Ada" },
          app: "Claude Code",
          ip: "203.0.113.7",
          minutes: 5,
          note: "Signs in once.",
        }}
      />,
    );
    expect(screen.getByText(/sign in as Ada/)).toBeTruthy();
    expect(screen.getByText("203.0.113.7")).toBeTruthy();
  });

  it("recognizes only its own tools' previews", () => {
    expect(isHatchPreview("php", php)).toBe(true);
    expect(isHatchPreview("sandbox", php)).toBe(false);
    expect(isHatchPreview("php", { code: 1 })).toBe(false);
    expect(isHatchPreview("login_link", null)).toBe(false);
    expect(
      isHatchPreview("shell_cli", { command: "wp x", args: [], argv: "x" }),
    ).toBe(false);
    // Every rendered field is checked, not only the identifying ones.
    expect(isHatchPreview("php", { ...php, lines: "2" })).toBe(false);
    expect(
      isHatchPreview("shell_cli", {
        command: "wp x",
        args: [1],
        timeout: 30,
        note: "",
      }),
    ).toBe(false);
    expect(
      isHatchPreview("login_link", {
        user: { id: 1, login: "admin", display_name: { html: "x" } },
        app: "Claude",
        ip: "203.0.113.7",
        minutes: 5,
        note: "",
      }),
    ).toBe(false);
  });
});
