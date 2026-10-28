import { Fragment } from "react";

/**
 * Text with `**…**` shown in bold (vendor menu labels in the client steps).
 * Rendered as text nodes, never as HTML.
 */
export default function RichText({ text }: { text: string }) {
  const parts = text.split(/\*\*(.+?)\*\*/g);

  return (
    <>
      {parts.map((part, index) =>
        index % 2 === 1 ? (
          <strong key={index}>{part}</strong>
        ) : (
          <Fragment key={index}>{part}</Fragment>
        ),
      )}
    </>
  );
}
