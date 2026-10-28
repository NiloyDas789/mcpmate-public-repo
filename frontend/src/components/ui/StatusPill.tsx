import { StatusDot, type StatusGlyph, type StatusTone } from "./StatusDot";

interface StatusPillProps {
  tone: StatusTone;
  text: string;
  /** Makes the pill a link; its accessible name is the text. */
  href?: string;
  glyph?: StatusGlyph;
}

/** Dot plus text on a tinted pill (docs/ui.md §7): never colour alone. */
export default function StatusPill({
  tone,
  text,
  href,
  glyph,
}: StatusPillProps) {
  const content = (
    <>
      <StatusDot tone={tone} glyph={glyph} />
      <span>{text}</span>
    </>
  );
  const className = `el-pill el-pill-${tone}`;

  return href ? (
    <a href={href} className={`${className} el-pill-link`}>
      {content}
    </a>
  ) : (
    <span className={className}>{content}</span>
  );
}
