"use client";

import { useEffect } from "react";
import { startErpRealtime, stopErpRealtime } from "../app/lib/erp-realtime";
import { tokenStore } from "../app/lib/api";

export default function ErpRealtimeBridge() {
  useEffect(() => {
    let cancelled = false;
    const connect = () => {
      if (tokenStore.get() && !cancelled) void startErpRealtime().catch((error) => console.error("ERP realtime connection failed", error));
    };
    connect();
    const onStorage = (event: StorageEvent) => {
      if (event.key === "store_erp_token") {
        stopErpRealtime();
        connect();
      }
    };
    window.addEventListener("storage", onStorage);
    return () => {
      cancelled = true;
      window.removeEventListener("storage", onStorage);
      stopErpRealtime();
    };
  }, []);
  return null;
}
