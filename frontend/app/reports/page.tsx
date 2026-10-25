"use client";
import { useCallback, useEffect, useState } from "react";
import { FileSpreadsheet, FileText } from "lucide-react";
import AppShell from "../../components/AppShell";
import { Alert, Button, Spinner, inputCls, useDebounce } from "../../components/ui";
import { api, errorMessage } from "../lib/api";
import { downloadFile } from "../lib/download";

type Catalog = { key: string; title: string; description: string; filters: string[] };
type Options = {
  reports: Catalog[];
  branches: { id: number; name: string }[];
  warehouses: { id: number; name: string; branch_id: number | null }[];
  users: { id: number; name: string }[];
};
type Column = { key: string; label: string; type: "text" | "money" | "qty" | "int" | "percent" };
type Report = {
  key: string; title: string; period: { date_from: string; date_to: string } | null;
  columns: Column[]; rows: Record<string, string | number>[]; totals: Record<string, string | number | null> | null;
};
type ProductOpt = { id: number; sku: string; name: string };

const nf2 = new Intl.NumberFormat("ru-RU", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const nf3 = new Intl.NumberFormat("ru-RU", { minimumFractionDigits: 3, maximumFractionDigits: 3 });

function cell(col: Column, v: string | number | null | undefined): string {
  if (v === null || v === undefined || v === "") return "";
  if (col.type === "money") return nf2.format(Number(v));
  if (col.type === "qty") return nf3.format(Number(v));
  if (col.type === "percent") return `${nf2.format(Number(v))}%`;
  return String(v);
}

const today = () => new Date().toISOString().slice(0, 10);
const monthStart = () => today().slice(0, 8) + "01";

export default function ReportsPage() {
  const [opts, setOpts] = useState<Options | null>(null);
  const [key, setKey] = useState("profit_by_product");
  const [from, setFrom] = useState(monthStart());
  const [to, setTo] = useState(today());
  const [branch, setBranch] = useState("");
  const [warehouse, setWarehouse] = useState("");
  const [user, setUser] = useState("");
  const [product, setProduct] = useState<ProductOpt | null>(null);
  const [search, setSearch] = useState("");
  const debounced = useDebounce(search);
  const [found, setFound] = useState<ProductOpt[]>([]);
  const [report, setReport] = useState<Report | null>(null);
  const [loading, setLoading] = useState(false);
  const [exporting, setExporting] = useState("");
  const [error, setError] = useState("");

  const current = opts?.reports.find((r) => r.key === key);
  const has = (f: string) => current?.filters.includes(f) ?? false;
  const warehouses = (opts?.warehouses ?? []).filter((w) => !branch || String(w.branch_id) === branch);

  const query = {
    date_from: has("date_from") ? from : "", date_to: has("date_to") ? to : "",
    branch_id: has("branch_id") ? branch : "", warehouse_id: has("warehouse_id") ? warehouse : "",
    user_id: has("user_id") ? user : "", product_id: has("product_id") && product ? product.id : "",
  };

  useEffect(() => {
    api<Options>("/reports/center").then(setOpts).catch((e) => setError(errorMessage(e)));
  }, []);

  useEffect(() => {
    if (!debounced.trim()) { setFound([]); return; }
    api<ProductOpt[]>("/reports/product-options", { query: { search: debounced } }).then(setFound).catch(() => setFound([]));
  }, [debounced]);

  const load = useCallback(async () => {
    setLoading(true);
    setError("");
    try {
      setReport(await api<Report>(`/reports/center/${key}`, { query }));
    } catch (e) {
      setReport(null);
      setError(errorMessage(e));
    } finally {
      setLoading(false);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [key, from, to, branch, warehouse, user, product]);

  useEffect(() => { if (opts) load(); }, [opts, load]);

  async function exportAs(format: "xlsx" | "pdf") {
    setExporting(format);
    setError("");
    try {
      await downloadFile(`/reports/center/${key}/export`, { ...query, format }, `hisobot.${format}`);
    } catch (e) {
      setError(errorMessage(e));
    } finally {
      setExporting("");
    }
  }

  return (
    <AppShell title="Hisobotlar markazi" subtitle="Filtrlash va Excel/PDF ga eksport" permission="reports.view">
      {!opts ? <Spinner /> : (
        <div className="space-y-4">
          <div className="flex flex-wrap gap-2">
            {opts.reports.map((r) => (
              <button key={r.key} onClick={() => setKey(r.key)} title={r.description}
                className={`rounded-lg border px-3 py-2 text-sm font-medium ${r.key === key ? "border-blue-600 bg-blue-600 text-white" : "border-slate-300 bg-white text-slate-700 hover:bg-slate-50"}`}>
                {r.title}
              </button>
            ))}
          </div>
          {current && <p className="text-sm text-slate-500">{current.description}</p>}

          <div className="grid gap-3 rounded-xl border border-slate-200 bg-white p-4 sm:grid-cols-2 lg:grid-cols-4">
            {has("date_from") && <label className="text-sm">Dan<input type="date" className={inputCls} value={from} max={to} onChange={(e) => setFrom(e.target.value)} /></label>}
            {has("date_to") && <label className="text-sm">Gacha<input type="date" className={inputCls} value={to} min={from} onChange={(e) => setTo(e.target.value)} /></label>}
            {has("branch_id") && (
              <label className="text-sm">Filial
                <select className={inputCls} value={branch} onChange={(e) => { setBranch(e.target.value); setWarehouse(""); }}>
                  <option value="">Hammasi</option>{opts.branches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
                </select>
              </label>
            )}
            {has("warehouse_id") && (
              <label className="text-sm">Ombor
                <select className={inputCls} value={warehouse} onChange={(e) => setWarehouse(e.target.value)}>
                  <option value="">Hammasi</option>{warehouses.map((w) => <option key={w.id} value={w.id}>{w.name}</option>)}
                </select>
              </label>
            )}
            {has("user_id") && (
              <label className="text-sm">Xodim
                <select className={inputCls} value={user} onChange={(e) => setUser(e.target.value)}>
                  <option value="">Hammasi</option>{opts.users.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
                </select>
              </label>
            )}
            {has("product_id") && (
              <div className="relative text-sm">Mahsulot
                {product ? (
                  <div className="flex items-center gap-2">
                    <span className={`${inputCls} truncate`}>{product.sku} — {product.name}</span>
                    <Button variant="secondary" onClick={() => { setProduct(null); setSearch(""); }}>×</Button>
                  </div>
                ) : (
                  <>
                    <input className={inputCls} placeholder="Nomi yoki kodi..." value={search} onChange={(e) => setSearch(e.target.value)} />
                    {found.length > 0 && (
                      <ul className="absolute z-10 mt-1 max-h-56 w-full overflow-auto rounded-lg border border-slate-200 bg-white shadow-lg">
                        {found.map((p) => (
                          <li key={p.id}><button className="w-full px-3 py-2 text-left hover:bg-slate-50" onClick={() => { setProduct(p); setFound([]); }}>{p.sku} — {p.name}</button></li>
                        ))}
                      </ul>
                    )}
                  </>
                )}
              </div>
            )}
          </div>

          <div className="flex flex-wrap gap-2">
            <Button variant="secondary" onClick={() => exportAs("xlsx")} loading={exporting === "xlsx"} disabled={!report}><FileSpreadsheet size={16} />Excel</Button>
            <Button variant="secondary" onClick={() => exportAs("pdf")} loading={exporting === "pdf"} disabled={!report}><FileText size={16} />PDF</Button>
          </div>

          {error && <Alert>{error}</Alert>}
          {loading && <Spinner />}
          {!loading && report && (
            <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white">
              <table className="w-full text-sm">
                <thead className="sticky top-0 bg-slate-100 text-left">
                  <tr>{report.columns.map((c) => <th key={c.key} className={`px-3 py-2 font-semibold ${c.type === "text" ? "" : "text-right"}`}>{c.label}</th>)}</tr>
                </thead>
                <tbody>
                  {report.rows.length === 0 && <tr><td colSpan={report.columns.length} className="px-3 py-8 text-center text-slate-500">Tanlangan davrda ma’lumot yo‘q.</td></tr>}
                  {report.rows.map((r, i) => (
                    <tr key={i} className="border-t border-slate-100 hover:bg-slate-50">
                      {report.columns.map((c) => <td key={c.key} className={`px-3 py-2 ${c.type === "text" ? "" : "text-right tabular-nums"}`}>{cell(c, r[c.key])}</td>)}
                    </tr>
                  ))}
                  {report.totals && (
                    <tr className="border-t-2 border-slate-300 bg-slate-50 font-semibold">
                      {report.columns.map((c, i) => <td key={c.key} className={`px-3 py-2 ${c.type === "text" ? "" : "text-right tabular-nums"}`}>{i === 0 ? "JAMI" : c.type === "int" ? String(report.totals?.[c.key] ?? "") : cell(c, report.totals?.[c.key])}</td>)}
                    </tr>
                  )}
                </tbody>
              </table>
            </div>
          )}
        </div>
      )}
    </AppShell>
  );
}
