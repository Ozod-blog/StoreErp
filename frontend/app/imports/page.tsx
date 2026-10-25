"use client";
import { useEffect, useState } from "react";
import { Download, FileUp } from "lucide-react";
import AppShell from "../../components/AppShell";
import { Alert, Button, inputCls } from "../../components/ui";
import { api, errorMessage } from "../lib/api";
import { downloadFile } from "../lib/download";
import { uploadFile } from "../lib/upload";

type ImportType = "products" | "customers" | "opening_stock";
type RowError = { row: number; messages: string[] };
type Payload = {
  message?: string; errors?: RowError[] | Record<string, string[]>;
  total_rows?: number; valid_rows?: number; skipped_rows?: number; errors_total?: number;
  warnings?: string[]; new_categories?: string[];
  created?: number; document?: { id: number; number: string };
};
type Warehouse = { id: number; name: string };

const TYPES: { key: ImportType; label: string; hint: string }[] = [
  { key: "products", label: "Mahsulotlar", hint: "Mavjud kod (SKU) bo‘yicha mahsulotlar o‘tkazib yuboriladi. Yo‘q kategoriya avtomatik yaratiladi." },
  { key: "customers", label: "Mijozlar", hint: "Telefoni mavjud mijozniki bilan bir xil bo‘lsa o‘tkazib yuboriladi." },
  { key: "opening_stock", label: "Boshlang‘ich qoldiqlar", hint: "Mahsulotlar oldindan kiritilgan bo‘lishi kerak. Tanlangan omborga bitta tasdiqlangan hujjat yaratiladi." },
];

export default function ImportsPage() {
  const [type, setType] = useState<ImportType>("products");
  const [file, setFile] = useState<File | null>(null);
  const [warehouses, setWarehouses] = useState<Warehouse[]>([]);
  const [warehouse, setWarehouse] = useState("");
  const [docDate, setDocDate] = useState(new Date().toISOString().slice(0, 10));
  const [preview, setPreview] = useState<Payload | null>(null);
  const [done, setDone] = useState<Payload | null>(null);
  const [busy, setBusy] = useState("");
  const [error, setError] = useState("");

  useEffect(() => {
    api<Warehouse[]>("/warehouses", { query: { all: 1 } }).then(setWarehouses).catch(() => setWarehouses([]));
  }, []);

  function reset() { setPreview(null); setDone(null); setError(""); }
  const fields = (): Record<string, string> => (type === "opening_stock" ? { warehouse_id: warehouse, doc_date: docDate } : {});
  const rowErrors = (p: Payload | null): RowError[] => (Array.isArray(p?.errors) ? (p!.errors as RowError[]) : []);
  const ready = !!file && (type !== "opening_stock" || !!warehouse);

  async function template() {
    setError("");
    try { await downloadFile(`/imports/${type}/template`, {}, `import-${type}-shablon.xlsx`); } catch (e) { setError(errorMessage(e)); }
  }

  async function check() {
    if (!file) return;
    reset(); setBusy("check");
    try {
      const r = await uploadFile<Payload>(`/imports/${type}/preview`, file, fields());
      if (r.status >= 400 && !Array.isArray(r.data.errors)) {
        const fieldErr = r.data.errors && !Array.isArray(r.data.errors) ? Object.values(r.data.errors)[0]?.[0] : undefined;
        setError(fieldErr || r.data.message || "Faylni tekshirib bo‘lmadi.");
      } else setPreview(r.data);
    } catch (e) { setError(errorMessage(e)); } finally { setBusy(""); }
  }

  async function run() {
    if (!file || !preview) return;
    if (!window.confirm(`${preview.valid_rows} ta qator import qilinadi. Davom etamizmi?`)) return;
    setBusy("run"); setError("");
    try {
      const r = await uploadFile<Payload>(`/imports/${type}`, file, fields());
      if (r.status >= 400) {
        if (Array.isArray(r.data.errors)) setPreview(r.data);
        setError(r.data.message || "Import bajarilmadi.");
      } else { setDone(r.data); setPreview(null); setFile(null); }
    } catch (e) { setError(errorMessage(e)); } finally { setBusy(""); }
  }

  const info = TYPES.find((t) => t.key === type)!;
  const errs = rowErrors(preview);

  return (
    <AppShell title="Excel import" subtitle="Mahsulotlar, mijozlar va boshlang‘ich qoldiqlarni fayldan yuklash" permission="products.create">
      <div className="space-y-4">
        <div className="flex flex-wrap gap-2">
          {TYPES.map((t) => (
            <button key={t.key} onClick={() => { setType(t.key); setFile(null); reset(); }}
              className={`rounded-lg border px-3 py-2 text-sm font-medium ${t.key === type ? "border-blue-600 bg-blue-600 text-white" : "border-slate-300 bg-white text-slate-700 hover:bg-slate-50"}`}>
              {t.label}
            </button>
          ))}
        </div>
        <p className="text-sm text-slate-500">{info.hint}</p>

        <div className="space-y-3 rounded-xl border border-slate-200 bg-white p-4">
          <div><Button variant="secondary" onClick={template}><Download size={16} />Shablonni yuklab olish (.xlsx)</Button></div>
          {type === "opening_stock" && (
            <div className="grid gap-3 sm:grid-cols-2">
              <label className="text-sm">Ombor
                <select className={inputCls} value={warehouse} onChange={(e) => { setWarehouse(e.target.value); setPreview(null); }}>
                  <option value="">Tanlang...</option>{warehouses.map((w) => <option key={w.id} value={w.id}>{w.name}</option>)}
                </select>
              </label>
              <label className="text-sm">Hujjat sanasi<input type="date" className={inputCls} value={docDate} onChange={(e) => setDocDate(e.target.value)} /></label>
            </div>
          )}
          <label className="block text-sm">Fayl (.xlsx yoki .csv, 5 MB gacha, 2000 qatorgacha)
            <input type="file" accept=".xlsx,.csv" className={inputCls} onChange={(e) => { setFile(e.target.files?.[0] ?? null); reset(); }} />
          </label>
          <div className="flex flex-wrap gap-2">
            <Button onClick={check} loading={busy === "check"} disabled={!ready}><FileUp size={16} />Tekshirish</Button>
            <Button variant="secondary" onClick={run} loading={busy === "run"} disabled={!preview || (preview.errors_total ?? 0) > 0 || (preview.valid_rows ?? 0) === 0}>Import qilish</Button>
          </div>
        </div>

        {error && <Alert>{error}</Alert>}
        {done && (
          <Alert kind="success">
            Import yakunlandi: {done.created} ta yozuv qo‘shildi{done.skipped_rows ? `, ${done.skipped_rows} tasi o‘tkazib yuborildi` : ""}
            {done.document ? `. Hujjat: ${done.document.number}` : ""}.
          </Alert>
        )}

        {preview && (
          <div className="space-y-3 rounded-xl border border-slate-200 bg-white p-4 text-sm">
            <div className="flex flex-wrap gap-x-6 gap-y-1">
              <span>Jami qator: <b>{preview.total_rows}</b></span>
              <span>Import qilinadi: <b className="text-emerald-700">{preview.valid_rows}</b></span>
              <span>O‘tkazib yuboriladi: <b>{preview.skipped_rows}</b></span>
              <span>Xatolik: <b className={(preview.errors_total ?? 0) > 0 ? "text-red-600" : ""}>{preview.errors_total}</b></span>
            </div>
            {preview.new_categories && preview.new_categories.length > 0 && <p className="text-slate-600">Yangi kategoriyalar yaratiladi: {preview.new_categories.join(", ")}</p>}
            {preview.warnings?.map((w, i) => <Alert key={i}>{w}</Alert>)}
            {errs.length > 0 && (
              <div>
                <p className="mb-1 font-semibold text-red-700">Xatolarni tuzatib, faylni qayta yuklang (hech narsa import qilinmaydi):</p>
                <div className="max-h-72 overflow-auto rounded-lg border border-slate-200">
                  <table className="w-full">
                    <thead className="sticky top-0 bg-slate-100 text-left"><tr><th className="px-3 py-2">Qator</th><th className="px-3 py-2">Xato</th></tr></thead>
                    <tbody>{errs.map((e) => <tr key={e.row} className="border-t border-slate-100"><td className="px-3 py-2">{e.row || "—"}</td><td className="px-3 py-2">{e.messages.join(" ")}</td></tr>)}</tbody>
                  </table>
                </div>
                {(preview.errors_total ?? 0) > errs.length && <p className="mt-1 text-slate-500">Dastlabki {errs.length} ta xato ko‘rsatildi.</p>}
              </div>
            )}
          </div>
        )}
      </div>
    </AppShell>
  );
}
