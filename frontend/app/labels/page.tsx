"use client";
import { useCallback, useEffect, useMemo, useState } from "react";
import { Barcode, Plus, Printer, Trash2, Wand2 } from "lucide-react";
import AppShell from "../../components/AppShell";
import BarcodeSvg from "../../components/BarcodeSvg";
import { Alert, Button, Field, Spinner, inputCls, useDebounce } from "../../components/ui";
import { api, errorMessage, Paginated } from "../lib/api";
import { useAuth } from "../lib/auth";
import { formatUZS } from "../lib/format";

type Product = { id: number; sku: string; barcode: string | null; name: string; sale_price: string; unit?: { short_name: string } | null };
type Picked = { product: Product; copies: number };

/** mm. "roll" — termal yorliq printeri (har yorliq alohida varaq); "sheet" — A4 da to'r. */
const SIZES: Record<string, { label: string; w: number; h: number; mode: "roll" | "sheet" }> = {
    "58x40": { label: "58×40 mm (rulon)", w: 58, h: 40, mode: "roll" },
    "40x30": { label: "40×30 mm (rulon)", w: 40, h: 30, mode: "roll" },
    "30x20": { label: "30×20 mm (rulon)", w: 30, h: 20, mode: "roll" },
    "a4-3x8": { label: "A4 varaq: 63×34 mm (3×8)", w: 63, h: 34, mode: "sheet" },
};
const MAX_LABELS = 500;

export default function LabelsPage() {
    const { can } = useAuth();
    const [search, setSearch] = useState("");
    const debounced = useDebounce(search);
    const [results, setResults] = useState<Product[]>([]);
    const [searching, setSearching] = useState(false);
    const [picked, setPicked] = useState<Picked[]>([]);
    const [sizeKey, setSizeKey] = useState("58x40");
    const [showPrice, setShowPrice] = useState(true);
    const [showSku, setShowSku] = useState(true);
    const [error, setError] = useState("");
    const [busyId, setBusyId] = useState<number | null>(null);

    const size = SIZES[sizeKey];

    useEffect(() => {
        let cancelled = false;
        setSearching(true);
        api<Paginated<Product>>("/products", { query: { search: debounced, per_page: 20, status: "active" } })
            .then((r) => { if (!cancelled) setResults(r.data); })
            .catch((e) => { if (!cancelled) setError(errorMessage(e)); })
            .finally(() => { if (!cancelled) setSearching(false); });
        return () => { cancelled = true; };
    }, [debounced]);

    // /labels?add=ID — mahsulotlar sahifasidan to'g'ridan-to'g'ri qo'shish
    useEffect(() => {
        const id = new URLSearchParams(window.location.search).get("add");
        if (!id) return;
        api<Product>(`/products/${id}`).then((p) => setPicked((prev) => (prev.some((x) => x.product.id === p.id) ? prev : [...prev, { product: p, copies: 1 }]))).catch((e) => setError(errorMessage(e)));
    }, []);

    const add = useCallback((p: Product) => {
        setPicked((prev) => (prev.some((x) => x.product.id === p.id) ? prev : [...prev, { product: p, copies: 1 }]));
    }, []);

    function setCopies(id: number, v: string) {
        const n = Math.max(1, Math.min(MAX_LABELS, Math.floor(Number(v) || 1)));
        setPicked((prev) => prev.map((x) => (x.product.id === id ? { ...x, copies: n } : x)));
    }

    async function generate(p: Product) {
        setBusyId(p.id);
        setError("");
        try {
            const updated = await api<Product>(`/products/${p.id}/barcode`, { method: "POST" });
            setPicked((prev) => prev.map((x) => (x.product.id === p.id ? { ...x, product: { ...x.product, barcode: updated.barcode } } : x)));
            setResults((prev) => prev.map((x) => (x.id === p.id ? { ...x, barcode: updated.barcode } : x)));
        } catch (e) {
            setError(errorMessage(e));
        } finally {
            setBusyId(null);
        }
    }

    const labels = useMemo(() => picked.flatMap((x) => Array.from({ length: x.copies }, () => x.product)), [picked]);
    const total = labels.length;
    const overLimit = total > MAX_LABELS;
    const noBarcode = picked.filter((x) => !x.product.barcode);

    // @page o'lchami printerga yorliq o'lchamini aytadi; brauzer chop etish oynasida "Masshtab: 100%" va "Sahifa chegarasi: yo'q" bo'lishi kerak.
    const printCss = `
@media print {
  body * { visibility: hidden !important; }
  #label-sheet, #label-sheet * { visibility: visible !important; }
  #label-sheet { position: absolute; left: 0; top: 0; margin: 0; padding: 0; background: #fff; }
  .label-item { box-shadow: none !important; border: 0 !important; margin: 0 !important; }
  ${size.mode === "roll"
        ? `@page { size: ${size.w}mm ${size.h}mm; margin: 0; } .label-item { break-after: page; page-break-after: always; } #label-sheet { display: block !important; }`
        : `@page { size: A4; margin: 8mm 7mm; } #label-sheet { display: flex !important; flex-wrap: wrap; } .label-item { break-inside: avoid; }`}
}`;

    return (
        <AppShell title="Yorliq (etiket) chop etish" subtitle="Mahsulotlarni tanlang, o‘lcham va nusxalar sonini belgilang, so‘ng chop eting" permission="products.view"
            actions={<Button onClick={() => window.print()} disabled={!total || overLimit}><Printer size={16} />Chop etish ({total})</Button>}>
            <style>{printCss}</style>
            <div className="space-y-4 print:hidden">
                {error && <Alert>{error}</Alert>}
                {overLimit && <Alert>Bir martada {MAX_LABELS} tadan ko‘p yorliq chop etib bo‘lmaydi (hozir {total}).</Alert>}
                {noBarcode.length > 0 && (
                    <Alert>
                        {noBarcode.length} ta tanlangan mahsulotda shtrix-kod yo‘q — yorlig‘iga SKU (Code 128) bosiladi. Skaner SKU ni ham taniydi, lekin
                        {can("products.update") ? " “Yaratish” tugmasi bilan standart EAN-13 kod berish yaxshiroq." : " shtrix-kod berish uchun mahsulotni tahrirlash ruxsati kerak."}
                    </Alert>
                )}

                <div className="grid gap-5 lg:grid-cols-2">
                    <section className="rounded-2xl border border-slate-200 bg-white p-4">
                        <h2 className="mb-3 font-semibold text-slate-900">1. Mahsulotlar</h2>
                        <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Nomi, SKU yoki shtrix-kod" className={inputCls} aria-label="Mahsulot qidirish" />
                        <div className="mt-3 max-h-80 overflow-y-auto rounded-lg border">
                            {searching && !results.length ? <Spinner label="Qidirilmoqda..." /> : (
                                <ul className="divide-y">
                                    {results.map((p) => (
                                        <li key={p.id} className="flex items-center justify-between gap-3 px-3 py-2 text-sm">
                                            <div className="min-w-0">
                                                <div className="truncate font-medium">{p.name}</div>
                                                <div className="text-xs text-slate-400">{p.sku}{p.barcode ? ` · ${p.barcode}` : " · shtrix-kod yo‘q"}</div>
                                            </div>
                                            <Button variant="secondary" className="!px-3 !py-1.5" onClick={() => add(p)} disabled={picked.some((x) => x.product.id === p.id)}>
                                                <Plus size={14} />Qo‘shish
                                            </Button>
                                        </li>
                                    ))}
                                    {!results.length && <li className="px-3 py-8 text-center text-sm text-slate-400">Mahsulot topilmadi.</li>}
                                </ul>
                            )}
                        </div>
                    </section>

                    <section className="rounded-2xl border border-slate-200 bg-white p-4">
                        <h2 className="mb-3 font-semibold text-slate-900">2. Tanlanganlar va sozlamalar</h2>
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <Field label="Yorliq o‘lchami">
                                <select value={sizeKey} onChange={(e) => setSizeKey(e.target.value)} className={inputCls}>
                                    {Object.entries(SIZES).map(([k, v]) => <option key={k} value={k}>{v.label}</option>)}
                                </select>
                            </Field>
                            <div className="flex items-end gap-4 pb-2 text-sm text-slate-700">
                                <label className="flex items-center gap-2"><input type="checkbox" checked={showPrice} onChange={(e) => setShowPrice(e.target.checked)} />Narx</label>
                                <label className="flex items-center gap-2"><input type="checkbox" checked={showSku} onChange={(e) => setShowSku(e.target.checked)} />SKU</label>
                            </div>
                        </div>
                        <div className="mt-3 max-h-64 overflow-y-auto rounded-lg border">
                            <ul className="divide-y">
                                {picked.map(({ product: p, copies }) => (
                                    <li key={p.id} className="flex items-center justify-between gap-3 px-3 py-2 text-sm">
                                        <div className="min-w-0">
                                            <div className="truncate font-medium">{p.name}</div>
                                            <div className="text-xs text-slate-400">{p.barcode ?? `SKU: ${p.sku}`}</div>
                                        </div>
                                        <div className="flex shrink-0 items-center gap-2">
                                            {!p.barcode && can("products.update") && (
                                                <Button variant="secondary" className="!px-2.5 !py-1.5" loading={busyId === p.id} onClick={() => generate(p)} title="Standart EAN-13 shtrix-kod yaratish">
                                                    <Wand2 size={14} />Yaratish
                                                </Button>
                                            )}
                                            <input type="number" min={1} max={MAX_LABELS} value={copies} onChange={(e) => setCopies(p.id, e.target.value)} className={`${inputCls} !w-20 text-center`} aria-label="Nusxalar soni" />
                                            <button type="button" aria-label="Olib tashlash" onClick={() => setPicked((prev) => prev.filter((x) => x.product.id !== p.id))} className="rounded-md border p-1.5 text-red-600 hover:bg-red-50"><Trash2 size={14} /></button>
                                        </div>
                                    </li>
                                ))}
                                {!picked.length && <li className="px-3 py-8 text-center text-sm text-slate-400">Chap tomondan mahsulot qo‘shing.</li>}
                            </ul>
                        </div>
                    </section>
                </div>

                <section className="rounded-2xl border border-slate-200 bg-white p-4">
                    <h2 className="mb-1 flex items-center gap-2 font-semibold text-slate-900"><Barcode size={18} />3. Chop etish bo‘yicha eslatma</h2>
                    <p className="mb-3 text-xs text-slate-500">
                        Pastdagi namuna aynan chop etiladigan ko‘rinish. Chop etish oynasida: masshtab 100%, “Sahifa chegarasi” (margins) — yo‘q, “Sarlavha va pastki yozuvlar” — o‘chiq. Termal printer uchun yorliq o‘lchami
                        printer drayveridagi qog‘oz o‘lchamiga mos bo‘lishi kerak.
                    </p>
                </section>
            </div>

            {/* Chop etiladigan varaq: ekranda ko‘rinish, chop etishda faqat shu qism qoladi */}
            <div id="label-sheet" className="mt-2 flex flex-wrap gap-2 print:mt-0 print:gap-0">
                {labels.slice(0, MAX_LABELS).map((p, i) => (
                    <div key={`${p.id}-${i}`} className="label-item flex flex-col justify-between overflow-hidden rounded border border-dashed border-slate-300 bg-white text-black"
                        style={{ width: `${size.w}mm`, height: `${size.h}mm`, padding: "1.2mm 1.5mm", boxSizing: "border-box" }}>
                        <div style={{ fontSize: size.h <= 20 ? "6pt" : "8pt", lineHeight: 1.1, maxHeight: "2.3em", overflow: "hidden", fontWeight: 600 }}>{p.name}</div>
                        <div style={{ flex: 1, minHeight: 0, display: "flex", flexDirection: "column", justifyContent: "center" }}>
                            <BarcodeSvg value={p.barcode ?? p.sku} height={`${Math.max(size.h * 0.38, 7)}mm`} />
                            <div style={{ textAlign: "center", fontSize: "6.5pt", letterSpacing: "0.08em", fontFamily: "monospace", lineHeight: 1.1 }}>{p.barcode ?? p.sku}</div>
                        </div>
                        <div style={{ display: "flex", justifyContent: "space-between", alignItems: "baseline", gap: "2mm" }}>
                            {showSku ? <span style={{ fontSize: "6pt" }}>{p.sku}</span> : <span />}
                            {showPrice && <span style={{ fontSize: size.h <= 20 ? "8pt" : "11pt", fontWeight: 700 }}>{formatUZS(p.sale_price)}</span>}
                        </div>
                    </div>
                ))}
            </div>
        </AppShell>
    );
}
