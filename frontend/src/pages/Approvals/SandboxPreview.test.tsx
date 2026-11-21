import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import { isSandboxPreview } from "../Tools/sandboxLogic";
import SandboxPreview from "./SandboxPreview";

const preview = {
  plugin: { slug: "tweaks", name: "Tweaks", description: "" },
  main: "tweaks.php",
  file_count: 2,
  total_bytes: 1530,
  loader: "missing" as const,
  note: "Approving runs this PHP on every request of the site.",
  files: [
    {
      path: "tweaks.php",
      bytes: 80,
      sha256: "b".repeat(64),
      content:
        "<?php\ndefined('ABSPATH') || exit;\nrequire __DIR__ . '/inc/a.php';\n",
    },
    {
      path: "inc/a.php",
      bytes: 1450,
      sha256: "c".repeat(64),
      content:
        "<?php\ndefined('ABSPATH') || exit;\necho '<script>alert(1)</script>';\n",
    },
  ],
};

describe("A sandbox plugin's approval", () => {
  it("shows every file's code as text, never as HTML", () => {
    render(<SandboxPreview preview={preview} />);

    expect(
      screen.getByText("Approving runs “Tweaks” on every page of this site."),
    ).toBeInTheDocument();
    expect(
      screen.getByText(
        "2 files, 1.5 KB. WordPress loads tweaks.php, which can include the others.",
      ),
    ).toBeInTheDocument();

    const code = screen.getByRole("region", { name: "Code of inc/a.php" });
    expect(code).toHaveTextContent("echo '<script>alert(1)</script>';");
    expect(code.querySelector("script")).toBeNull();
    expect(
      screen.getByRole("region", { name: "Code of tweaks.php" }),
    ).toHaveAttribute("tabindex", "0");
  });

  it("is recognised only with every file's code", () => {
    expect(isSandboxPreview(preview)).toBe(true);
    expect(isSandboxPreview({ files: [{ path: "a.php" }] })).toBe(false);
    expect(isSandboxPreview({ tables: 3 })).toBe(false);
    expect(isSandboxPreview(null)).toBe(false);
  });
});
