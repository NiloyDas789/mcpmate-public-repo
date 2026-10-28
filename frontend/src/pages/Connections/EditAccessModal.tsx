import {
  useEffect,
  useId,
  useMemo,
  useRef,
  useState,
  type RefObject,
} from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { __, _n, sprintf } from "@wordpress/i18n";
import Button from "@components/ui/Button";
import Modal from "@components/ui/Modal";
import Notice from "@components/ui/Notice";
import Skeleton from "@components/ui/Skeleton";
import { useAnnounce } from "@/hooks/useAnnounce";
import { fetchTools, updateGrantAccess } from "@services/api";
import { GRANTS_QUERY_KEY, TOOLS_QUERY_KEY } from "@services/queries";
import ToolPicker from "@pages/Tools/ToolPicker";
import { alwaysOnNames, offGroups, pickableGroups } from "@pages/Tools/logic";
import { requestErrorText } from "@utils/errors";
import type {
  Grant,
  GrantAccess,
  GrantsResponse,
  ToolsResponse,
} from "../../types/api";
import { actsAs, formatDay, initialSelection } from "./logic";

const LEVELS: {
  value: GrantAccess;
  label: () => string;
  hint: () => string;
}[] = [
  {
    value: "full",
    label: () => __("Full access", "mcpmate"),
    hint: () =>
      __(
        "Can read and make changes with every tool that's turned on.",
        "mcpmate",
      ),
  },
  {
    value: "read_only",
    label: () => __("Read-only", "mcpmate"),
    hint: () => __("Can only read. It can't change anything.", "mcpmate"),
  },
  {
    value: "custom",
    label: () => __("Custom", "mcpmate"),
    hint: () => __("Choose the tools it can use.", "mcpmate"),
  },
];

function CustomTools({
  grant,
  selected,
  onChange,
  error,
  pickerRef,
}: {
  grant: Grant;
  selected: Set<string> | null;
  onChange: (next: Set<string>) => void;
  error: string;
  pickerRef: RefObject<HTMLDivElement>;
}) {
  const {
    data,
    isLoading,
    isError,
    error: loadError,
    refetch,
  } = useQuery({
    queryKey: TOOLS_QUERY_KEY,
    queryFn: fetchTools,
    staleTime: 0,
  });
  const groups = useMemo(
    () => (data ? pickableGroups(data.groups) : []),
    [data],
  );

  useEffect(() => {
    if (data && selected === null) {
      onChange(initialSelection(grant, groups));
    }
  }, [data, selected, grant, groups, onChange]);

  if (isLoading) {
    return (
      <div aria-busy="true">
        <Skeleton count={4} />
      </div>
    );
  }

  if (isError || !data) {
    return (
      <Notice
        tone="danger"
        title={__("Couldn't load the tools.", "mcpmate")}
        action={
          <Button variant="secondary" size="sm" onClick={() => void refetch()}>
            {__("Try again", "mcpmate")}
          </Button>
        }
      >
        {requestErrorText(loadError)}
      </Notice>
    );
  }

  const off = offGroups(data.groups);
  const current = selected ?? new Set<string>();

  return (
    <div ref={pickerRef}>
      {groups.length === 0 ? (
        <p className="m-0">
          {__("No tools are turned on for AI apps.", "mcpmate")}
        </p>
      ) : (
        <ToolPicker
          groups={groups}
          isChecked={(tool) => tool.always_on || current.has(tool.name)}
          onChange={(changes) => {
            const next = new Set(current);
            Object.entries(changes).forEach(([name, on]) =>
              on ? next.add(name) : next.delete(name),
            );
            onChange(next);
          }}
          countText={(checked, total) =>
            sprintf(
              /* translators: 1: tools chosen, 2: tools in the group. */
              __("%1$d of %2$d chosen", "mcpmate"),
              checked,
              total,
            )
          }
        />
      )}
      {error && <Notice tone="danger" title={error} live />}
      {off.length > 0 && (
        <div className="mt-4 text-el-text-secondary">
          <p className="m-0">{__("Off for all apps:", "mcpmate")}</p>
          <ul className="m-0 mb-1 ps-5">
            {off.map(({ group, off: count, whole }) => (
              <li key={group.id}>
                {whole
                  ? group.label
                  : sprintf(
                      /* translators: 1: tool group, 2: number of its tools that are off. */
                      _n(
                        "%1$s: %2$d tool",
                        "%1$s: %2$d tools",
                        count,
                        "mcpmate",
                      ),
                      group.label,
                      count,
                    )}
              </li>
            ))}
          </ul>
          <a href="#/tools">{__("Turn them on in Tools", "mcpmate")}</a>
        </div>
      )}
    </div>
  );
}

/**
 * Access for <app> (docs/ui.md §5.2, B2): Full, Read-only, or Custom with
 * the Tools page's picker over the tools that are on site-wide. Saving
 * applies to the app's next request and writes an audit row.
 */
export default function EditAccessModal({
  grant: requested,
  onClose,
}: {
  /** The app to edit; null closes the modal. */
  grant: Grant | null;
  onClose: () => void;
}) {
  // Keeps the last app while the modal closes.
  const [grant, setGrant] = useState<Grant | null>(requested);
  const queryClient = useQueryClient();
  const announce = useAnnounce();
  const id = useId();
  const checkedRadio = useRef<HTMLInputElement>(null);
  const picker = useRef<HTMLDivElement>(null);
  const [access, setAccess] = useState<GrantAccess>("full");
  const [selected, setSelected] = useState<Set<string> | null>(null);
  const [saving, setSaving] = useState(false);
  const [saveError, setSaveError] = useState("");
  const [pickError, setPickError] = useState("");

  useEffect(() => {
    if (requested) {
      setGrant(requested);
      setAccess(requested.access);
      setSelected(null);
      setSaveError("");
      setPickError("");
    }
  }, [requested]);

  if (!grant) {
    return null;
  }

  const save = async () => {
    const tools = queryClient.getQueryData<ToolsResponse>(TOOLS_QUERY_KEY);
    const always = alwaysOnNames(tools?.groups ?? []);
    const chosen =
      access === "custom"
        ? Array.from(selected ?? []).filter((name) => !always.includes(name))
        : [];

    if (access === "custom" && chosen.length === 0) {
      setPickError(__("Choose at least one tool.", "mcpmate"));
      picker.current?.querySelector<HTMLInputElement>("input")?.focus();
      return;
    }

    setPickError("");
    setSaveError("");
    setSaving(true);

    try {
      const updated = await updateGrantAccess(
        grant.grant_id,
        access,
        access === "custom" ? [...always, ...chosen] : [],
      );
      queryClient.setQueryData<GrantsResponse>(GRANTS_QUERY_KEY, (current) =>
        current
          ? {
              ...current,
              grants: current.grants.map((item) =>
                item.grant_id === updated.grant_id ? updated : item,
              ),
            }
          : current,
      );
      announce(
        sprintf(
          /* translators: %s: AI app name. */
          __("Access for %s saved.", "mcpmate"),
          grant.client_name,
        ),
      );
      setSaving(false);
      onClose();
    } catch (caught) {
      setSaving(false);
      setSaveError(requestErrorText(caught));
    }
  };

  return (
    <Modal
      open={requested !== null}
      size="lg"
      title={sprintf(
        /* translators: %s: AI app name. */
        __("Access for %s", "mcpmate"),
        grant.client_name,
      )}
      onClose={onClose}
      closable={!saving}
      initialFocus={checkedRadio}
      footer={
        <div className="el-dialog-actions">
          <Button
            variant="primary"
            loading={saving}
            onClick={() => void save()}
          >
            {saving ? __("Saving…", "mcpmate") : __("Save access", "mcpmate")}
          </Button>
          <Button variant="secondary" onClick={onClose} disabled={saving}>
            {__("Cancel", "mcpmate")}
          </Button>
        </div>
      }
    >
      <div className="el-stack">
        <p className="m-0 text-el-text-secondary">
          {sprintf(
            /* translators: 1: "acts as admin", 2: approval date. */
            __("%1$s · approved %2$s", "mcpmate"),
            actsAs(grant),
            formatDay(grant.created_at),
          )}
        </p>

        <fieldset className="m-0 border-0 p-0">
          <legend className="el-field-label">
            {__("Access level", "mcpmate")}
          </legend>
          <div className="grid gap-3">
            {LEVELS.map((level) => (
              <div key={level.value}>
                <label className="flex items-start gap-2">
                  <input
                    ref={
                      level.value === grant.access ? checkedRadio : undefined
                    }
                    type="radio"
                    name={`${id}-access`}
                    value={level.value}
                    checked={access === level.value}
                    onChange={() => setAccess(level.value)}
                    aria-describedby={`${id}-${level.value}`}
                    className="m-0 mt-1 accent-el-brand"
                  />
                  <span>
                    <span className="block font-semibold">{level.label()}</span>
                    <span
                      id={`${id}-${level.value}`}
                      className="block text-el-text-secondary"
                    >
                      {level.hint()}
                    </span>
                  </span>
                </label>
                {level.value === "custom" && access === "custom" && (
                  <div className="mt-3 ps-6">
                    <CustomTools
                      grant={grant}
                      selected={selected}
                      onChange={setSelected}
                      error={pickError}
                      pickerRef={picker}
                    />
                  </div>
                )}
              </div>
            ))}
          </div>
        </fieldset>

        <p className="m-0">
          {sprintf(
            /* translators: %s: AI app name. */
            __(
              "%s may need to reconnect, or start a new chat, to see the change.",
              "mcpmate",
            ),
            grant.client_name,
          )}
        </p>

        {saveError && (
          <Notice
            tone="danger"
            title={__("Couldn't save the access.", "mcpmate")}
            live
          >
            {saveError}
          </Notice>
        )}
      </div>
    </Modal>
  );
}
