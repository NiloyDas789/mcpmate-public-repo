/**
 * Copy `text` with the Clipboard API. False when it is missing (plain
 * http on a host other than localhost is not a secure context) or refused,
 * so the caller can fall back to selecting the text.
 */
export async function copyText(text: string): Promise<boolean> {
  if (typeof navigator === "undefined" || !navigator.clipboard?.writeText) {
    return false;
  }

  try {
    await navigator.clipboard.writeText(text);
    return true;
  } catch {
    return false;
  }
}

/** Select the contents of `element` so Ctrl+C / ⌘C copies them. */
export function selectContents(element: HTMLElement | null): void {
  if (!element) {
    return;
  }

  if (
    element instanceof HTMLInputElement ||
    element instanceof HTMLTextAreaElement
  ) {
    element.focus();
    element.select();
    return;
  }

  const selection = window.getSelection();
  if (selection) {
    const range = document.createRange();
    range.selectNodeContents(element);
    selection.removeAllRanges();
    selection.addRange(range);
  }
}
