import { useState } from "react";
import { __ } from "@wordpress/i18n";
import Badge from "@components/ui/Badge";
import CheckboxGroup from "@components/ui/CheckboxGroup";
import type { Tool } from "../../types/api";
import { kindCounts, type PickableGroup } from "./logic";

interface ToolPickerProps {
  groups: PickableGroup[];
  isChecked: (tool: Tool) => boolean;
  /** The new state of every tool that changed. */
  onChange: (changes: Record<string, boolean>) => void;
  /** "3 of 8 on", "3 of 8 chosen": the first part of each group's counts. */
  countText: (checked: number, total: number) => string;
  /** Groups that start open. */
  initiallyExpanded?: string[];
}

/**
 * Tool groups as tri-state checkbox groups (docs/ui.md §5.3): each tool with
 * its mono name, a Read or Write badge and "Asks you first"; always-on tools
 * are checked, disabled, and say why in visible text.
 */
export default function ToolPicker({
  groups,
  isChecked,
  onChange,
  countText,
  initiallyExpanded = [],
}: ToolPickerProps) {
  const [expanded, setExpanded] = useState<Record<string, boolean>>(() =>
    Object.fromEntries(initiallyExpanded.map((id) => [id, true])),
  );

  return (
    <div>
      {groups.map(({ group, tools }) => {
        const checked = tools.filter(isChecked).length;
        const single = tools.length === 1 ? tools[0] : null;

        return (
          <CheckboxGroup
            key={group.id}
            label={group.label}
            extra={
              single && (
                <bdi dir="ltr" className="el-mono">
                  {single.name}
                </bdi>
              )
            }
            description={`${countText(checked, tools.length)} · ${kindCounts(tools)}`}
            expanded={Boolean(expanded[group.id])}
            onExpandedChange={(open) =>
              setExpanded((current) => ({ ...current, [group.id]: open }))
            }
            onChange={onChange}
            items={tools.map((tool) => ({
              key: tool.name,
              checked: tool.always_on || isChecked(tool),
              disabled: tool.always_on,
              label: tool.label,
              meta: (
                <>
                  <bdi dir="ltr" className="el-mono">
                    {tool.name}
                  </bdi>
                  <Badge variant={tool.kind === "read" ? "read" : "write"} />
                  {tool.approval && <Badge variant="approval" />}
                </>
              ),
              note: tool.always_on ? __("Always on", "mcpmate") : undefined,
            }))}
          />
        );
      })}
    </div>
  );
}
