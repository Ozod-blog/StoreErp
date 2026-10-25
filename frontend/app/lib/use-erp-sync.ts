"use client";

import { useEffect, useRef } from "react";
import type { ErpChangeEvent } from "./erp-realtime";

/**
 * Subscribe a page to global ERP data changes and rerun its existing loader.
 * Example: useErpSync(() => { void loadProducts(); });
 */
export function useErpSync(refresh: () => void | Promise<void>, options: { enabled?: boolean; debounceMs?: number } = {}) {
  const refreshRef = useRef(refresh);
  refreshRef.current = refresh;
  const enabled = options.enabled ?? true;
  const debounceMs = options.debounceMs ?? 150;

  useEffect(() => {
    if (!enabled) return;
    let timer: ReturnType<typeof setTimeout> | undefined;
    const onChanged = (_event: Event) => {
      if (timer) clearTimeout(timer);
      timer = setTimeout(() => { void refreshRef.current(); }, debounceMs);
    };
    window.addEventListener("erp:data-changed", onChanged);
    return () => {
      window.removeEventListener("erp:data-changed", onChanged);
      if (timer) clearTimeout(timer);
    };
  }, [enabled, debounceMs]);
}
