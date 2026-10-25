"use client";
import { createContext, ReactNode, useCallback, useContext, useMemo, useState } from "react";
import { CheckCircle2, Info, TriangleAlert, ShieldAlert } from "lucide-react";

type ToastKind = "success" | "error" | "info" | "warning" | "security";
type ToastItem = { id: number; title: string; message?: string; kind: ToastKind; duration: number };
type ToastContextValue = { toast: (message: string, kind?: ToastKind, title?: string) => void };
const ToastContext = createContext<ToastContextValue | null>(null);
let nextId = 1;
const durationFor = (kind: ToastKind) => kind === "warning" ? 6000 : kind === "error" || kind === "security" ? 8000 : 4000;
const defaultTitle: Record<ToastKind, string> = {
  success: "Muvaffaqiyatli",
  error: "Xatolik",
  info: "Ma’lumot",
  warning: "Muhim bildirishnoma",
  security: "Xavfsizlik ogohlantirishi",
};

export function ToastProvider({ children }: { children: ReactNode }) {
  const [items, setItems] = useState<ToastItem[]>([]);
  const dismiss = useCallback((id: number) => setItems(old => old.filter(x => x.id !== id)), []);
  const toast = useCallback((message: string, kind: ToastKind = "info", title?: string) => {
    const id = nextId++;
    const duration = durationFor(kind);
    setItems(old => [...old, { id, title: title || defaultTitle[kind], message, kind, duration }].slice(-5));
    window.setTimeout(() => dismiss(id), duration);
  }, [dismiss]);
  const value = useMemo(() => ({ toast }), [toast]);

  return <ToastContext.Provider value={value}>
    {children}
    <div aria-live="polite" aria-atomic="false" className="fixed right-4 top-4 z-[100] flex w-[min(24rem,calc(100vw-2rem))] flex-col gap-2">
      {items.map(item => {
        const Icon = item.kind === "success" ? CheckCircle2 : item.kind === "error" ? TriangleAlert : item.kind === "security" ? ShieldAlert : item.kind === "warning" ? TriangleAlert : Info;
        const iconColor = item.kind === "success" ? "text-emerald-600" : item.kind === "error" || item.kind === "security" ? "text-red-600" : item.kind === "warning" ? "text-amber-600" : "text-blue-600";
        const progressColor = item.kind === "success" ? "bg-emerald-500" : item.kind === "error" || item.kind === "security" ? "bg-red-500" : item.kind === "warning" ? "bg-amber-500" : "bg-blue-500";
        return <div key={item.id} className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-lg">
          <div className="flex gap-3 p-3">
            <Icon size={19} className={`${iconColor} mt-0.5 shrink-0`} />
            <div className="min-w-0 flex-1">
              <p className="text-sm font-semibold text-slate-900">{item.title}</p>
              <p className="mt-0.5 break-words text-sm text-slate-600">{item.message}</p>
            </div>
          </div>
          <div className="h-1 w-full bg-slate-100" aria-hidden="true">
            <div className={`h-full ${progressColor} toast-countdown-progress`} style={{ animationDuration: `${item.duration}ms` }} />
          </div>
        </div>;
      })}
    </div>
    <style jsx>{`
      .toast-countdown-progress {
        width: 100%;
        transform-origin: left center;
        animation-name: toast-countdown;
        animation-timing-function: linear;
        animation-fill-mode: forwards;
      }
      @keyframes toast-countdown {
        from { transform: scaleX(1); }
        to { transform: scaleX(0); }
      }
    `}</style>
  </ToastContext.Provider>;
}

export function useToast() {
  const value = useContext(ToastContext);
  if (!value) throw new Error("useToast ToastProvider ichida ishlatiladi");
  return value.toast;
}
