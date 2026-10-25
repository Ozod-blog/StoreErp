"use client";
import { useEffect } from "react";
const API_URL = process.env.NEXT_PUBLIC_API_URL || "http://127.0.0.1:8000/api/v1";
export default function GlobalError({ error, reset }: { error: Error & { digest?: string }; reset: () => void }) {
  useEffect(() => {
    const message = (error?.message || "Unhandled React rendering error").replace(/Bearer\s+\S+/gi, "Bearer [REDACTED]").slice(0, 500);
    const stack = (error?.stack || "").split("\n").slice(0, 8).join("\n").replace(/Bearer\s+\S+/gi, "Bearer [REDACTED]").slice(0, 4000);
    void fetch(`${API_URL}/client-errors`, { method: "POST", headers: { "Content-Type": "application/json", Accept: "application/json" }, body: JSON.stringify({ kind: "react_error", message, stack }), keepalive: true }).catch(() => undefined);
  }, [error]);
  return <html lang="uz"><body className="min-h-screen bg-slate-50 p-8 text-slate-900"><main className="mx-auto max-w-xl rounded-2xl border bg-white p-6"><h1 className="text-xl font-bold">Kutilmagan xatolik</h1><p className="mt-2 text-sm text-slate-600">Xatolik qayd etildi. Sahifani qayta yuklab ko‘ring.</p><button onClick={() => reset()} className="mt-4 rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white">Qayta urinish</button></main></body></html>;
}
