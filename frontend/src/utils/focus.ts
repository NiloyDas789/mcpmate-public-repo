const TABBABLE = [
  "a[href]",
  "button:not([disabled])",
  "input:not([disabled]):not([type='hidden'])",
  "select:not([disabled])",
  "textarea:not([disabled])",
  "[tabindex]",
  "[contenteditable='true']",
].join(",");

/**
 * Elements in `container` that Tab reaches, in document order: no negative
 * tabindex, nothing hidden, inert or inside a closed `<details>`.
 */
export function tabbable(container: HTMLElement | null): HTMLElement[] {
  if (!container) {
    return [];
  }

  return Array.from(container.querySelectorAll<HTMLElement>(TABBABLE)).filter(
    (element) => {
      // The attribute, not `tabIndex`: some DOMs (happy-dom) report -1 for
      // a native control that has none.
      const tabindex = element.getAttribute("tabindex");
      if (
        (tabindex !== null && Number(tabindex) < 0) ||
        element.closest("[hidden], [inert]")
      ) {
        return false;
      }

      // A radio group is one stop: the checked radio, or the first one.
      if (element instanceof HTMLInputElement && element.type === "radio") {
        const group = element.name
          ? Array.from(
              container.querySelectorAll<HTMLInputElement>(
                `input[type='radio'][name='${CSS.escape(element.name)}']`,
              ),
            )
          : [element];
        const stop = group.find((radio) => radio.checked) ?? group[0];
        if (stop !== element) {
          return false;
        }
      }

      const style = window.getComputedStyle(element);
      return style.display !== "none" && style.visibility !== "hidden";
    },
  );
}
