"use client";
import { ReactNode, useEffect, useState } from "react";
import { Loader2, X } from "lucide-react";
import { MessageKey, useI18n } from "../app/lib/i18n";

export const inputCls =
  "w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100 disabled:bg-slate-100";

export function Button({
  variant = "primary",
  loading = false,
  className = "",
  children,
  ...props
}: React.ButtonHTMLAttributes<HTMLButtonElement> & { variant?: "primary" | "secondary" | "danger"; loading?: boolean }) {
  const styles = {
    primary: "bg-blue-600 text-white hover:bg-blue-700",
    secondary: "border border-slate-300 bg-white text-slate-700 hover:bg-slate-50",
    danger: "bg-red-600 text-white hover:bg-red-700",
  }[variant];
  return (
    <button
      {...props}
      disabled={props.disabled || loading}
      className={`inline-flex items-center justify-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold transition disabled:opacity-60 ${styles} ${className}`}
    >
      {loading && <Loader2 size={15} className="animate-spin" />}
      {children}
    </button>
  );
}

export function Field({ label, error, children }: { label: string; error?: string; children: ReactNode }) {
  return (
    <label className="block text-sm font-medium text-slate-700">
      {label}
      <div className="mt-1.5">{children}</div>
      {error && <p className="mt-1 text-xs font-normal text-red-600">{error}</p>}
    </label>
  );
}

export function Alert({ kind = "error", children }: { kind?: "error" | "success"; children: ReactNode }) {
  const cls = kind === "error" ? "border-red-200 bg-red-50 text-red-700" : "border-emerald-200 bg-emerald-50 text-emerald-700";
  return <div className={`rounded-lg border px-4 py-3 text-sm ${cls}`}>{children}</div>;
}

export function Spinner({ label }: { label?: string }) {
  const { t } = useI18n();
  return (
    <div className="flex items-center justify-center gap-2 py-12 text-sm text-slate-500">
      <Loader2 className="animate-spin" size={18} />
      {label ?? t("common.loading")}
    </div>
  );
}

export function Modal({ open, title, onClose, children, wide = false }: { open: boolean; title: string; onClose: () => void; children: ReactNode; wide?: boolean }) {
  const { t } = useI18n();
  useEffect(() => {
    if (!open) return;
    const onKey = (e: KeyboardEvent) => e.key === "Escape" && onClose();
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [open, onClose]);
  if (!open) return null;
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center overflow-hidden bg-slate-900/50 p-2 sm:p-4" onClick={onClose}>
      <div className={`flex max-h-[85vh] max-h-[85dvh] min-h-0 w-full flex-col overflow-hidden rounded-2xl bg-white shadow-xl ${wide ? "max-w-[1100px]" : "max-w-xl"}` } onClick={(e) => e.stopPropagation()}>
        <div className="sticky top-0 z-20 flex shrink-0 items-center justify-between border-b bg-white px-4 py-3 sm:px-5 sm:py-4">
          <h2 className="font-semibold text-slate-900">{title}</h2>
          <button onClick={onClose} aria-label={t("common.close")} className="rounded-lg p-1 hover:bg-slate-100">
            <X size={18} />
          </button>
        </div>
        <div className="min-h-0 flex-1 overflow-y-auto overscroll-contain p-3 sm:p-5">{children}</div>
      </div>
    </div>
  );
}

export function ConfirmDialog({
  open, title, message, confirmLabel, loading, onConfirm, onCancel,
}: { open: boolean; title: string; message: string; confirmLabel?: string; loading?: boolean; onConfirm: () => void; onCancel: () => void }) {
  const { t } = useI18n();
  return (
    <Modal open={open} title={title} onClose={onCancel}>
      <p className="text-sm text-slate-600">{message}</p>
      <div className="mt-6 flex justify-end gap-2">
        <Button variant="secondary" onClick={onCancel}>{t("common.cancel")}</Button>
        <Button variant="danger" loading={loading} onClick={onConfirm}>{confirmLabel ?? t("common.confirm")}</Button>
      </div>
    </Modal>
  );
}

export function Pagination({ page, lastPage, total, onPage }: { page: number; lastPage: number; total: number; onPage: (p: number) => void }) {
  const { t } = useI18n();
  return (
    <div className="flex items-center justify-between border-t px-5 py-3 text-sm text-slate-500">
      <span>{t("common.total")}: {total}</span>
      <div className="flex items-center gap-2">
        <Button variant="secondary" className="!px-3 !py-1.5" disabled={page <= 1} onClick={() => onPage(page - 1)}>{t("common.prev")}</Button>
        <span>{page} / {lastPage}</span>
        <Button variant="secondary" className="!px-3 !py-1.5" disabled={page >= lastPage} onClick={() => onPage(page + 1)}>{t("common.next")}</Button>
      </div>
    </div>
  );
}

const STATUS_STYLE: Record<string, [MessageKey, string]> = {
  active: ["status.active", "bg-emerald-50 text-emerald-700"],
  inactive: ["status.inactive", "bg-slate-100 text-slate-600"],
  leave: ["status.leave", "bg-amber-50 text-amber-700"],
  archived: ["status.archived", "bg-amber-50 text-amber-700"],
  success: ["status.success", "bg-emerald-50 text-emerald-700"],
  failed: ["status.failed", "bg-red-50 text-red-700"],
  draft: ["status.draft", "bg-slate-100 text-slate-700"],
  approved: ["status.approved", "bg-emerald-50 text-emerald-700"],
  cancelled: ["status.cancelled", "bg-red-50 text-red-700"],
  open: ["status.open", "bg-blue-50 text-blue-700"],
  closed: ["status.closed", "bg-slate-100 text-slate-700"],
};

export function StatusBadge({ status }: { status: string }) {
  const { t } = useI18n();
  const entry = STATUS_STYLE[status];
  const label = entry ? t(entry[0]) : status;
  const cls = entry ? entry[1] : "bg-slate-100 text-slate-600";
  return <span className={`rounded-full px-2.5 py-1 text-xs font-semibold ${cls}`}>{label}</span>;
}

export function useDebounce<T>(value: T, delay = 350): T {
  const [v, setV] = useState(value);
  useEffect(() => {
    const t = setTimeout(() => setV(value), delay);
    return () => clearTimeout(t);
  }, [value, delay]);
  return v;
}

export function StatCard({ title, value, hint, tone = "default" }: { title: string; value: string; hint?: string; tone?: "default" | "good" | "bad" | "warn" }) {
  const color = { default: "text-slate-900", good: "text-emerald-700", bad: "text-red-600", warn: "text-amber-600" }[tone];
  return (
    <div className="rounded-2xl border border-slate-200 bg-white p-4">
      <div className="text-xs font-medium uppercase text-slate-500">{title}</div>
      <div className={`mt-1.5 break-words text-xl font-bold ${color}`}>{value}</div>
      {hint && <div className="mt-1 text-xs text-slate-400">{hint}</div>}
    </div>
  );
}

/** Yuklanish paytida kontent o‘rnini egallaydi. */
export function Skeleton({ className = "h-4 w-full" }: { className?: string }) {
  return <div aria-hidden className={`skeleton ${className}`} />;
}
