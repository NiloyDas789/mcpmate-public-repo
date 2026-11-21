import { __, sprintf } from "@wordpress/i18n";
import { formatNumber } from "@utils/format";

/** A design file this page reads, in bytes (DesignTokens::MAX_BYTES). */
export const MAX_DESIGN_BYTES = 65536;

/** What a new design starts from. */
export const NEW_DESIGN = `---
name: New design
description: One sentence on the feel and who it is for.
tokens:
  colors:
    background: "#ffffff"
    text: "#1d1d1f"
    action: "#0b5d7a"
  fonts:
    heading: "Georgia, serif"
    body: "Helvetica Neue, Arial, sans-serif"
  type_scale:
    base: 1rem
    xl: 1.75rem
  spacing:
    sm: 8px
    md: 16px
    lg: 32px
  radii:
    md: 8px
  components:
    button: "Action fill, white text, radius md, 12px 20px padding."
---
# New design

## Overview

## Colour

## Typography

## Layout and spacing

## Do's and Don'ts
`;

const COLOR =
  /^(#([0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})|rgba?\([\d\s.,/%]+\)|hsla?\([\d\s.,/%deg]+\))$/i;

/** Whether a token value is a colour the preview can show (as check_design reads them). */
export function isColor(value: string): boolean {
  return COLOR.test(value.trim());
}

/** Colour tokens the preview can show: [name, value]. */
export function swatches(
  colors: Record<string, string> | undefined,
): [string, string][] {
  return Object.entries(colors ?? {}).filter(([, value]) => isColor(value));
}

/** A length the preview can draw (px, rem or em, or 0), else null. */
export function cssLength(value: string): string | null {
  const trimmed = value.trim();

  return /^(-?\d*\.?\d+(px|rem|em)|0)$/i.test(trimmed) ? trimmed : null;
}

/** The byte length of a design, as the server counts it. */
export function designBytes(content: string): number {
  return new TextEncoder().encode(content).length;
}

/**
 * Reads an uploaded design (.md, .markdown or .txt, at most 64 KB) as
 * text; a refusal comes back as a message.
 */
export async function readDesignFile(
  file: File,
): Promise<{ content: string } | { error: string }> {
  if (!/\.(md|markdown|txt)$/i.test(file.name)) {
    return {
      error: __("Choose a Markdown file (.md).", "mcpmate"),
    };
  }

  if (file.size > MAX_DESIGN_BYTES) {
    return {
      error: sprintf(
        /* translators: %s: the most bytes a design may have. */
        __("The file is larger than %s bytes.", "mcpmate"),
        formatNumber(MAX_DESIGN_BYTES),
      ),
    };
  }

  return { content: await file.text() };
}

/** The default design name for an uploaded file: its name without the extension. */
export function nameFromFile(fileName: string): string {
  return fileName
    .replace(/\.(md|markdown|txt)$/i, "")
    .replace(/[-_]+/g, " ")
    .trim();
}
