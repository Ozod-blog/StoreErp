"use client";
import { useEffect } from "react";
const API_URL = process.env.NEXT_PUBLIC_API_URL || "http://127.0.0.1:8000/api/v1";
export default function ClientErrorReporter() {
  useEffect(() => {
    let lastSent = 0;
    const send = (kind: "window_error" | "unhandled_rejection" | "react_error", value: unknown) => {
      const now = Date.now(); if (now - lastSent < 3000) return; lastSent = now;
      const error = value instanceof Error ? value : new Error(String(value ?? "Unknown client error"));
      const message = error.message.replace(/Bearer\s+\S+/gi, "Bearer [REDACTED]").slice(0, 500);
      const stack = (error.stack || "").split("\n").slice(0, 8).join("\n").replace(/Bearer\s+\S+/gi, "Bearer [REDACTED]").slice(0, 4000);
      void fetch(`${API_URL}/client-errors`, { method: "POST", headers: { "Content-Type": "application/json", Accept: "application/json" }, body: JSON.stringify({ kind, message, stack }), keepalive: true }).catch(() => undefined);
    };
    const onError = (event: ErrorEvent) => send("window_error", event.error || event.message);
    const onRejection = (event: PromiseRejectionEvent) => send("unhandled_rejection", event.reason);
    window.addEventListener("error", onError); window.addEventListener("unhandledrejection", onRejection);
    return () => { window.removeEventListener("error", onError); window.removeEventListener("unhandledrejection", onRejection); };
  }, []);
  return null;
}
