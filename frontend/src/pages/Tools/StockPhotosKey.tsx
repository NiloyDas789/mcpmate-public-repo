import { useId, useState } from "react";
import { useMutation, useQueryClient } from "@tanstack/react-query";
import { __, sprintf } from "@wordpress/i18n";

import Button from "@components/ui/Button";
import { useAnnounce } from "@/hooks/useAnnounce";
import { updateStockPhotos } from "@services/api";
import { TOOLS_QUERY_KEY } from "@services/queries";
import { requestErrorText } from "@utils/errors";

/**
 * Tools → Integrations, Stock photos (docs/mcp-in-plugin.md §8): the site
 * owner's own Unsplash access key and app name. The key is write-only: the
 * page only learns whether one is set.
 */
export default function StockPhotosKey({
  configured,
  appName,
}: {
  configured: boolean;
  appName: string;
}) {
  const id = useId();
  const queryClient = useQueryClient();
  const announce = useAnnounce();
  const [key, setKey] = useState("");
  const [name, setName] = useState(appName);

  const save = useMutation({
    mutationFn: (values: { key: string; name: string }) =>
      updateStockPhotos(values.key, values.name),
    onSuccess: (_, values) => {
      setKey("");
      announce(
        values.key === ""
          ? __("Unsplash key removed.", "mcpmate")
          : __("Unsplash key saved.", "mcpmate"),
      );
      void queryClient.invalidateQueries({ queryKey: TOOLS_QUERY_KEY });
    },
  });

  return (
    <form
      className="el-stock-photos"
      onSubmit={(event) => {
        event.preventDefault();
        save.mutate({ key: key.trim(), name: name.trim() });
      }}
    >
      {configured && (
        <p className="el-stock-photos-status">
          {sprintf(
            /* translators: %s: the Unsplash application name. */
            __("A key is saved for the Unsplash app %s.", "mcpmate"),
            appName,
          )}{" "}
          <Button
            variant="secondary"
            size="sm"
            disabled={save.isPending}
            onClick={() => save.mutate({ key: "", name: "" })}
          >
            {__("Remove key", "mcpmate")}
          </Button>
        </p>
      )}
      <div className="el-stock-photos-fields">
        <div>
          <label htmlFor={`${id}-key`} className="el-field-label">
            {configured
              ? __("New access key", "mcpmate")
              : __("Unsplash access key", "mcpmate")}
          </label>
          <input
            id={`${id}-key`}
            type="password"
            className="el-input el-mono"
            autoComplete="off"
            spellCheck={false}
            value={key}
            onChange={(event) => setKey(event.target.value)}
          />
        </div>
        <div>
          <label htmlFor={`${id}-name`} className="el-field-label">
            {__("Unsplash app name", "mcpmate")}
          </label>
          <input
            id={`${id}-name`}
            type="text"
            className="el-input"
            autoComplete="off"
            value={name}
            onChange={(event) => setName(event.target.value)}
          />
        </div>
        <Button
          htmlType="submit"
          variant="primary"
          size="sm"
          disabled={save.isPending || key.trim() === ""}
        >
          {__("Save key", "mcpmate")}
        </Button>
      </div>
      {save.isError && (
        <p className="el-field-error" role="alert">
          {requestErrorText(save.error)}
        </p>
      )}
    </form>
  );
}
