"use client";

import { tokenStore } from "./api";

type EchoClient = {
  "private": (channel: string) => { listen: (event: string, callback: (payload: ErpChangeEvent) => void) => unknown };
  leave: (channel: string) => void;
  disconnect: () => void;
};

export type ErpChangeEvent = {
  eventId: string;
  path: string;
  method: string;
  actorId: number | null;
  changedAt: string;
};

let echo: EchoClient | null = null;
let starting: Promise<void> | null = null;

/** Starts one private-channel connection for the browser session. */
export async function startErpRealtime(): Promise<void> {
  if (typeof window === "undefined" || echo || starting) return starting ?? Promise.resolve();
  const key = process.env.NEXT_PUBLIC_REVERB_APP_KEY;
  const host = process.env.NEXT_PUBLIC_REVERB_HOST;
  const port = Number(process.env.NEXT_PUBLIC_REVERB_PORT || 8080);
  if (!key || !host) return; // realtime is opt-in until Reverb environment is configured

  starting = (async () => {
    const token = tokenStore.get();
    if (!token) return;
    const { default: Echo } = await import("laravel-echo");
    const Pusher = (await import("pusher-js")).default;
    (window as Window & { Pusher?: unknown }).Pusher = Pusher;
    const apiBase = (process.env.NEXT_PUBLIC_API_URL || "http://127.0.0.1:8000/api/v1").replace(/\/$/, "");
    const client = new Echo({
      broadcaster: "reverb",
      key,
      wsHost: host,
      wsPort: port,
      wssPort: Number(process.env.NEXT_PUBLIC_REVERB_WSS_PORT || 443),
      forceTLS: (process.env.NEXT_PUBLIC_REVERB_SCHEME || "http") === "https",
      enabledTransports: ["ws", "wss"],
      authEndpoint: `${apiBase}/broadcasting/auth`,
      auth: { headers: { Authorization: `Bearer ${token}`, Accept: "application/json" } },
    }) as EchoClient;
    echo = client;
    client.private("erp.sync").listen(".erp.data.changed", (payload) => {
      // All locally cached GET data is invalidated. Open pages can subscribe to this event
      // and re-run their own loader without forcing a destructive full-page reload.
      window.dispatchEvent(new CustomEvent<ErpChangeEvent>("erp:data-changed", { detail: payload }));
    });
  })().finally(() => { starting = null; });
  return starting;
}

export function stopErpRealtime(): void {
  if (!echo) return;
  echo.leave("erp.sync");
  echo.disconnect();
  echo = null;
}
