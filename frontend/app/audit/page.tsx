"use client";
import { useCallback, useEffect, useState } from "react";
import AppShell from "../../components/AppShell";
import { Alert, Button, Modal, Pagination, Spinner, StatusBadge, inputCls, useDebounce } from "../../components/ui";
import { api, errorMessage, Paginated } from "../lib/api";
import { formatDateTime } from "../lib/format";
import { tokenStore } from "../lib/api";
import { Download } from "lucide-react";

type Log = {
  id: number; action: string; auditable_type: string | null; auditable_id: number | null;
  old_values: Record<string, unknown> | null; new_values: Record<string, unknown> | null;
  result: string; ip_address: string | null; created_at: string; user?: { id: number; name: string; email: string } | null;
};

export default function AuditPage() {
  const [rows, setRows] = useState<Paginated<Log> | null>(null);
  const [page, setPage] = useState(1);
  const [action, setAction] = useState("");
  const [result, setResult] = useState("");
  const [from, setFrom] = useState("");
  const [to, setTo] = useState("");
  const debounced = useDebounce(action);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [detail, setDetail] = useState<Log | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    setError("");
    try {
      setRows(await api<Paginated<Log>>("/audit-logs", { query: { page, action: debounced, result, from, to } }));
    } catch (e) {
      setError(errorMessage(e));
    } finally {
      setLoading(false);
    }
  }, [page, debounced, result, from, to]);

  const exportCsv = async () => {
    setError("");
    try {
      const base = process.env.NEXT_PUBLIC_API_URL || "http://127.0.0.1:8000/api/v1";
      const url = new URL(`${base}/audit-logs/export`);
      Object.entries({ action: debounced, result, from, to }).forEach(([k,v]) => { if (v) url.searchParams.set(k,v); });
      const response = await fetch(url.toString(), { headers: { Authorization: `Bearer ${tokenStore.get() || ""}`, Accept: "text/csv" } });
      if (!response.ok) throw new Error("Audit eksporti bajarilmadi");
      const blob = await response.blob(); const href = URL.createObjectURL(blob); const a = document.createElement("a"); a.href = href; a.download = "store-erp-audit.csv"; a.click(); URL.revokeObjectURL(href);
    } catch (e) { setError(errorMessage(e)); }
  };

  useEffect(() => { load(); }, [load]);
  useEffect(() => { setPage(1); }, [debounced, result, from, to]);

  return (
    <AppShell title="Audit jurnali" subtitle="Tizimdagi muhim amallar tarixi (vaqt Toshkent bo‘yicha)" permission="audit.view"
      actions={<div className="flex gap-2"><Button variant="secondary" onClick={exportCsv}><Download size={15}/> CSV eksport</Button><Button variant="secondary" onClick={load}>Yangilash</Button></div>}>
      <div className="space-y-4">
        {error && <Alert>{error}</Alert>}
        <div className="flex flex-wrap gap-3">
          <input value={action} onChange={(e) => setAction(e.target.value)} placeholder="Amal (masalan: users.)" className={`${inputCls} max-w-[220px]`} />
          <select value={result} onChange={(e) => setResult(e.target.value)} className={`${inputCls} max-w-[180px]`}>
            <option value="">Barcha natijalar</option><option value="success">Muvaffaqiyatli</option><option value="failed">Xato / rad etildi</option>
          </select>
          <input type="date" value={from} onChange={(e) => setFrom(e.target.value)} className={`${inputCls} max-w-[170px]`} aria-label="Boshlanish sanasi" />
          <input type="date" value={to} onChange={(e) => setTo(e.target.value)} className={`${inputCls} max-w-[170px]`} aria-label="Tugash sanasi" />
        </div>

        <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white">
          {loading && !rows ? <Spinner /> : (
            <div className="overflow-x-auto">
              <table className="w-full text-left text-sm">
                <thead className="bg-slate-50 text-xs uppercase text-slate-500">
                  <tr><th className="px-5 py-3">Vaqt</th><th className="px-5 py-3">Foydalanuvchi</th><th className="px-5 py-3">Amal</th><th className="px-5 py-3">Obyekt</th><th className="px-5 py-3">Natija</th><th className="px-5 py-3">IP</th><th className="px-5 py-3" /></tr>
                </thead>
                <tbody className="divide-y">
                  {rows?.data.map((l) => (
                    <tr key={l.id}>
                      <td className="whitespace-nowrap px-5 py-3">{formatDateTime(l.created_at)}</td>
                      <td className="px-5 py-3">{l.user?.name ?? "—"}</td>
                      <td className="px-5 py-3 font-mono text-xs">{l.action}</td>
                      <td className="px-5 py-3 text-slate-500">{l.auditable_type ? `${l.auditable_type.split("\\").pop()} #${l.auditable_id}` : "—"}</td>
                      <td className="px-5 py-3"><StatusBadge status={l.result} /></td>
                      <td className="px-5 py-3 text-slate-500">{l.ip_address ?? "—"}</td>
                      <td className="px-5 py-3 text-right">
                        {(l.old_values || l.new_values) && <Button variant="secondary" className="!px-3 !py-1.5" onClick={() => setDetail(l)}>Batafsil</Button>}
                      </td>
                    </tr>
                  ))}
                  {!rows?.data.length && <tr><td colSpan={7} className="px-5 py-12 text-center text-slate-400">Yozuv topilmadi.</td></tr>}
                </tbody>
              </table>
            </div>
          )}
          {rows && <Pagination page={rows.current_page} lastPage={rows.last_page} total={rows.total} onPage={setPage} />}
        </div>
      </div>

      <Modal open={!!detail} title={detail?.action ?? ""} onClose={() => setDetail(null)}>
        <div className="space-y-4 text-xs">
          <div><div className="mb-1 font-semibold text-slate-600">Oldingi qiymatlar</div><pre className="overflow-x-auto rounded-lg bg-slate-50 p-3">{JSON.stringify(detail?.old_values, null, 2) ?? "—"}</pre></div>
          <div><div className="mb-1 font-semibold text-slate-600">Yangi qiymatlar</div><pre className="overflow-x-auto rounded-lg bg-slate-50 p-3">{JSON.stringify(detail?.new_values, null, 2) ?? "—"}</pre></div>
        </div>
      </Modal>
    </AppShell>
  );
}
