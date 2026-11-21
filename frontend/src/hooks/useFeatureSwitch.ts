import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { ajaxRequest } from "@services/api";

/**
 * One admin feature switch on admin-ajax: `<route>` reads it and
 * `<route>/update` saves it (`{enabled}`, or e.g. `{svg}` for the SVG
 * sub-switch). Shared by the Settings cards and the setup wizard, under the
 * same React Query key, so both always show the saved value.
 */
export function useFeatureSwitch<T extends { enabled: boolean }>(
  route: string,
  { enabled = true }: { enabled?: boolean } = {},
) {
  const queryClient = useQueryClient();
  const queryKey = [route];

  const query = useQuery({
    queryKey,
    enabled,
    queryFn: async () => {
      const response = await ajaxRequest<T>(route);

      if (!response.success) {
        throw new Error(`Failed to load ${route}`);
      }

      return response.data;
    },
  });

  const save = useMutation({
    mutationFn: async (body: Record<string, unknown>) => {
      const response = await ajaxRequest<T>(`${route}/update`, "POST", body);

      if (!response.success) {
        throw new Error(`Failed to save ${route}`);
      }

      return response.data;
    },
    onSuccess: (data) => {
      queryClient.setQueryData(queryKey, data);
    },
  });

  return { ...query, save };
}
