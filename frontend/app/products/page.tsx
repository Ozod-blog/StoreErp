"use client";
import { FormEvent, useCallback, useEffect, useState } from "react";
import Link from "next/link";
import { Plus, Pencil, Archive, ArchiveRestore, Download, Printer, Wand2 } from "lucide-react";
import AppShell from "../../components/AppShell";
import { Alert, Button, ConfirmDialog, Field, Modal, Pagination, Spinner, StatCard, StatusBadge, inputCls, useDebounce } from "../../components/ui";
import { api, errorMessage, ApiError, Paginated } from "../lib/api";
import { formatQty, formatUZS } from "../lib/format";
import { useAuth } from "../lib/auth";
import { downloadCsv, todayStamp } from "../lib/csv";
import { ean13Check } from "../lib/barcode";
import InsightsPanel from "../../components/InsightsPanel";

type Unit = { id: number; name: string; short_name: string; allow_fraction: boolean };
type Category = { id: number; name: string };
type Product = {
    id: number; sku: string; barcode: string | null; name: string; category_id: number | null; unit_id: number;
    purchase_price: string; sale_price: string; wholesale_price: string | null; min_stock: string; description: string | null;
    is_active: boolean; deleted_at: string | null; total_stock: string | number | null;
    category?: Category | null; unit?: Unit;
};
type FormState = {
    id?: number; sku: string; barcode: string; name: string; category_id: string; unit_id: string;
    purchase_price: string; sale_price: string; wholesale_price: string; min_stock: string; description: string; is_active: boolean;
    categoryName?: string; unitName?: string;
};
const EMPTY: FormState = {
    sku: "", barcode: "", name: "", category_id: "", unit_id: "", purchase_price: "0", sale_price: "0",
    wholesale_price: "", min_stock: "0", description: "", is_active: true,
};
const SORTS: Record<string, { label: string; sort: string; dir: string }> = {
    name_asc: { label: "Nomi (A–Z)", sort: "name", dir: "asc" },
    new: { label: "Yangi avval", sort: "created_at", dir: "desc" },
    price_asc: { label: "Narx: arzondan", sort: "sale_price", dir: "asc" },
    price_desc: { label: "Narx: qimmatdan", sort: "sale_price", dir: "desc" },
};

/** Yangi (hali ID si yo'q) mahsulot uchun tasodifiy ichki EAN-13. Takrorlansa server 422 qaytaradi — qayta bosiladi. */
function randomInternalEan(): string {
    let base = "2";
    for (let i = 0; i < 11; i++) base += Math.floor(Math.random() * 10);
    return base + ean13Check(base);
}

/** Ustama foizi = (sotuv − xarid) / xarid. Xarid narxi 0 bo'lsa — ko'rsatilmaydi. */
function markup(x: Pick<Product, "purchase_price" | "sale_price">): string | null {
    const buy = Number(x.purchase_price);
    if (!buy) return null;
    return (((Number(x.sale_price) - buy) / buy) * 100).toFixed(1);
}

export default function ProductsPage() {
    const { can } = useAuth();
    const [rows, setRows] = useState<Paginated<Product> | null>(null);
    const [page, setPage] = useState(1);
    const [search, setSearch] = useState("");
    const [category, setCategory] = useState("");
    const [status, setStatus] = useState(""); // "", active, inactive, archived
    const [low, setLow] = useState(false);
    const [sortKey, setSortKey] = useState("name_asc");
    const debounced = useDebounce(search);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState("");
    const [notice, setNotice] = useState("");
    const [categories, setCategories] = useState<Category[]>([]);
    const [units, setUnits] = useState<Unit[]>([]);
    const [form, setForm] = useState<FormState | null>(null);
    const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});
    const [saving, setSaving] = useState(false);
    const [toArchive, setToArchive] = useState<Product | null>(null);
    const [stats, setStats] = useState<{ all: number; low: number; inactive: number; archived: number } | null>(null);

    const loadStats = useCallback(async () => {
        const count = (query: Record<string, string | number>) =>
            api<Paginated<Product>>("/products", { query: { per_page: 5, ...query } }).then((r) => r.total);
        try {
            const [all, low, inactive, archived] = await Promise.all([
                count({}), count({ low_stock: 1 }), count({ status: "inactive" }), count({ archived: 1 }),
            ]);
            setStats({ all, low, inactive, archived });
        } catch { setStats(null); }
    }, []);
    useEffect(() => { loadStats(); }, [loadStats]);

    function exportCsv() {
        downloadCsv(`mahsulotlar-${todayStamp()}.csv`, [
            ["SKU", "Shtrix-kod", "Nomi", "Kategoriya", "Xarid narxi", "Sotuv narxi", "Ustama %", "Jami qoldiq", "Minimal qoldiq", "Holat"],
            ...(rows?.data ?? []).map((x) => [
                x.sku, x.barcode, x.name, x.category?.name, x.purchase_price, x.sale_price, markup(x) ?? "",
                x.total_stock ?? 0, x.min_stock, x.deleted_at ? "Arxivda" : x.is_active ? "Faol" : "Faol emas",
            ]),
        ]);
    }

    // /products?category=ID havolasidan kelgan filtr
    useEffect(() => {
        const c = new URLSearchParams(window.location.search).get("category");
        if (c) setCategory(c);
    }, []);

    useEffect(() => {
        Promise.all([api<Category[]>("/categories", { query: { all: 1 } }), api<Unit[]>("/units", { query: { all: 1 } })])
            .then(([c, u]) => { setCategories(c); setUnits(u); })
            .catch((e) => setError(errorMessage(e)));
    }, []);

    const load = useCallback(async () => {
        setLoading(true);
        setError("");
        const s = SORTS[sortKey];
        try {
            setRows(await api<Paginated<Product>>("/products", {
                query: {
                    page, search: debounced, category_id: category, sort: s.sort, dir: s.dir,
                    status: status === "archived" ? "" : status, archived: status === "archived" ? 1 : "", low_stock: low ? 1 : "",
                },
            }));
        } catch (e) {
            setError(errorMessage(e));
        } finally {
            setLoading(false);
        }
    }, [page, debounced, category, status, low, sortKey]);

    useEffect(() => { load(); }, [load]);
    useEffect(() => { setPage(1); }, [debounced, category, status, low, sortKey]);

    function openEdit(x: Product) {
        setFieldErrors({});
        setForm({
            id: x.id, sku: x.sku, barcode: x.barcode ?? "", name: x.name, category_id: x.category_id ? String(x.category_id) : "",
            unit_id: String(x.unit_id), purchase_price: x.purchase_price, sale_price: x.sale_price, wholesale_price: x.wholesale_price ?? "",
            min_stock: String(Number(x.min_stock)), description: x.description ?? "", is_active: x.is_active,
            categoryName: x.category?.name, unitName: x.unit?.name,
        });
    }

    /** Mavjud mahsulot: server ID asosida kod beradi va saqlaydi. Yangi mahsulot: forma maydoniga tasodifiy kod qo'yiladi. */
    async function generateBarcode() {
        if (!form) return;
        setFieldErrors({});
        if (!form.id) {
            setForm({ ...form, barcode: randomInternalEan() });
            return;
        }
        try {
            const updated = await api<Product>(`/products/${form.id}/barcode`, { method: "POST" });
            setForm((f) => f && { ...f, barcode: updated.barcode ?? "" });
            setNotice("Shtrix-kod yaratildi va saqlandi.");
            await load();
        } catch (err) {
            setFieldErrors({ barcode: [errorMessage(err)] });
        }
    }

    async function save(e: FormEvent) {
        e.preventDefault();
        if (!form) return;
        setSaving(true);
        setFieldErrors({});
        setError("");
        const body = {
            sku: form.sku, barcode: form.barcode || null, name: form.name,
            category_id: form.category_id ? Number(form.category_id) : null, unit_id: Number(form.unit_id),
            purchase_price: Number(form.purchase_price || 0), sale_price: Number(form.sale_price || 0),
            wholesale_price: form.wholesale_price === "" ? null : Number(form.wholesale_price),
            min_stock: form.min_stock === "" ? 0 : Number(form.min_stock),
            description: form.description || null, is_active: form.is_active,
        };
        try {
            await api(form.id ? `/products/${form.id}` : "/products", { method: form.id ? "PATCH" : "POST", body });
            setNotice(form.id ? "Mahsulot yangilandi." : "Mahsulot qo‘shildi.");
            setForm(null);
            await Promise.all([load(), loadStats()]);
        } catch (err) {
            if (err instanceof ApiError && err.status === 422) setFieldErrors(err.errors);
            setError(errorMessage(err));
        } finally {
            setSaving(false);
        }
    }

    async function archive() {
        if (!toArchive) return;
        setSaving(true);
        setError("");
        try {
            await api(`/products/${toArchive.id}`, { method: "DELETE" });
            setNotice("Mahsulot arxivlandi.");
            await Promise.all([load(), loadStats()]);
        } catch (err) {
            setError(errorMessage(err));
        } finally {
            setToArchive(null);
            setSaving(false);
        }
    }

    async function restore(x: Product) {
        setError("");
        try {
            await api(`/products/${x.id}/restore`, { method: "POST" });
            setNotice("Mahsulot arxivdan qaytarildi.");
            await Promise.all([load(), loadStats()]);
        } catch (err) {
            setError(errorMessage(err));
        }
    }

    const set = (k: keyof FormState) => (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>) =>
        setForm((f) => f && { ...f, [k]: e.target.value });
    const selectedUnit = units.find((u) => String(u.id) === form?.unit_id);

    return (
        <AppShell title="Mahsulotlar" subtitle="Mahsulotlar ro‘yxati va narxlari. Qoldiq ombor hujjatlari orqali o‘zgaradi." permission="products.view"
            actions={<div className="flex gap-2">
                <Button variant="secondary" onClick={exportCsv} disabled={!rows?.data.length}><Download size={16} />CSV</Button>
                {can("products.create") && <Button onClick={() => { setFieldErrors({}); setForm({ ...EMPTY, unit_id: units[0] ? String(units[0].id) : "" }); }}><Plus size={16} />Mahsulot qo‘shish</Button>}
            </div>}>
            <div className="space-y-4">
                {notice && <Alert kind="success">{notice}</Alert>}
                {error && <Alert>{error}</Alert>}
                <InsightsPanel endpoint="/stats/products" />
                {stats && (
                    <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                        <StatCard title="Jami mahsulot" value={String(stats.all)} />
                        <StatCard title="Kam qolgan" value={String(stats.low)} tone={stats.low ? "bad" : "default"} hint="Minimal qoldiqdan past" />
                        <StatCard title="Faol emas" value={String(stats.inactive)} tone={stats.inactive ? "warn" : "default"} />
                        <StatCard title="Arxivda" value={String(stats.archived)} />
                    </div>
                )}
                <div className="flex flex-wrap items-center gap-3">
                    <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Nomi, SKU yoki shtrix-kod" className={`${inputCls} max-w-xs`} />
                    <select value={category} onChange={(e) => setCategory(e.target.value)} className={`${inputCls} max-w-[200px]`}>
                        <option value="">Barcha kategoriyalar</option>
                        {categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                    </select>
                    <select value={status} onChange={(e) => setStatus(e.target.value)} className={`${inputCls} max-w-[170px]`}>
                        <option value="">Barcha holatlar</option>
                        <option value="active">Faol</option>
                        <option value="inactive">Faol emas</option>
                        <option value="archived">Arxivda</option>
                    </select>
                    <select value={sortKey} onChange={(e) => setSortKey(e.target.value)} className={`${inputCls} max-w-[190px]`}>
                        {Object.entries(SORTS).map(([k, v]) => <option key={k} value={k}>{v.label}</option>)}
                    </select>
                    <label className="flex items-center gap-2 text-sm text-slate-700"><input type="checkbox" checked={low} onChange={(e) => setLow(e.target.checked)} />Faqat kam qolganlar</label>
                </div>

                <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white">
                    {loading && !rows ? <Spinner /> : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-sm">
                                <thead className="bg-slate-50 text-xs uppercase text-slate-500">
                                    <tr><th className="px-5 py-3">SKU</th><th className="px-5 py-3">Nomi</th><th className="px-5 py-3">Kategoriya</th><th className="px-5 py-3 text-right">Xarid narxi</th><th className="px-5 py-3 text-right">Sotuv narxi</th><th className="px-5 py-3 text-right">Ustama</th><th className="px-5 py-3 text-right">Jami qoldiq</th><th className="px-5 py-3">Holat</th><th className="px-5 py-3 text-right">Amallar</th></tr>
                                </thead>
                                <tbody className="divide-y">
                                    {rows?.data.map((x) => {
                                        const total = Number(x.total_stock ?? 0);
                                        const isLow = Number(x.min_stock) > 0 && total < Number(x.min_stock);
                                        return (
                                            <tr key={x.id}>
                                                <td className="px-5 py-3 font-medium">{x.sku}{x.barcode && <div className="text-xs font-normal text-slate-400">{x.barcode}</div>}</td>
                                                <td className="px-5 py-3">{x.name}</td>
                                                <td className="px-5 py-3">{x.category?.name ?? "—"}</td>
                                                <td className="px-5 py-3 text-right">{formatUZS(x.purchase_price)}</td>
                                                <td className="px-5 py-3 text-right">{formatUZS(x.sale_price)}</td>
                                                <td className={`px-5 py-3 text-right ${Number(markup(x) ?? 0) < 0 ? "text-red-600" : "text-slate-600"}`}>{markup(x) === null ? "—" : `${markup(x)}%`}</td>
                                                <td className={`px-5 py-3 text-right font-medium ${isLow ? "text-red-600" : ""}`}>
                                                    {formatQty(total, x.unit?.short_name)}
                                                    {isLow && <div className="text-xs font-normal">Kam qolgan (min: {formatQty(x.min_stock)})</div>}
                                                </td>
                                                <td className="px-5 py-3"><StatusBadge status={x.deleted_at ? "archived" : x.is_active ? "active" : "inactive"} /></td>
                                                <td className="px-5 py-3 text-right">
                                                    <div className="flex justify-end gap-2">
                                                        {!x.deleted_at && <Link href={`/labels?add=${x.id}`} aria-label="Yorliq chop etish" title="Yorliq chop etish" className="inline-flex items-center rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-slate-700 hover:bg-slate-50"><Printer size={14} /></Link>}
                                                        {!x.deleted_at && can("products.update") && <Button variant="secondary" className="!px-3 !py-1.5" onClick={() => openEdit(x)}><Pencil size={14} />Tahrirlash</Button>}
                                                        {!x.deleted_at && can("products.delete") && <Button variant="secondary" className="!px-3 !py-1.5 text-red-600" aria-label="Arxivlash" onClick={() => setToArchive(x)}><Archive size={14} /></Button>}
                                                        {x.deleted_at && can("products.delete") && <Button variant="secondary" className="!px-3 !py-1.5" onClick={() => restore(x)}><ArchiveRestore size={14} />Qaytarish</Button>}
                                                    </div>
                                                </td>
                                            </tr>
                                        );
                                    })}
                                    {!rows?.data.length && <tr><td colSpan={9} className="px-5 py-12 text-center text-slate-400">Mahsulot topilmadi.</td></tr>}
                                </tbody>
                            </table>
                        </div>
                    )}
                    {rows && <Pagination page={rows.current_page} lastPage={rows.last_page} total={rows.total} onPage={setPage} />}
                </div>
            </div>

            <Modal open={!!form} title={form?.id ? "Mahsulotni tahrirlash" : "Yangi mahsulot"} onClose={() => setForm(null)}>
                {form && (
                    <form onSubmit={save} className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <Field label="SKU / ichki kod" error={fieldErrors.sku?.[0]}><input required value={form.sku} onChange={set("sku")} className={inputCls} placeholder="P-0001" /></Field>
                        <Field label="Shtrix-kod (ixtiyoriy)" error={fieldErrors.barcode?.[0]}>
                            <div className="flex gap-2">
                                <input value={form.barcode} onChange={set("barcode")} className={inputCls} />
                                {!form.barcode && can("products.update") && (
                                    <Button type="button" variant="secondary" className="shrink-0 !px-3" onClick={generateBarcode} title="Standart EAN-13 shtrix-kod yaratish"><Wand2 size={15} />Yaratish</Button>
                                )}
                            </div>
                        </Field>
                        <div className="sm:col-span-2"><Field label="Nomi" error={fieldErrors.name?.[0]}><input required value={form.name} onChange={set("name")} className={inputCls} /></Field></div>
                        <Field label="Kategoriya" error={fieldErrors.category_id?.[0]}>
                            <select value={form.category_id} onChange={set("category_id")} className={inputCls}>
                                <option value="">Kategoriyasiz</option>
                                {form.category_id && !categories.some((c) => String(c.id) === form.category_id) && <option value={form.category_id}>{form.categoryName ?? "Arxivdagi kategoriya"}</option>}
                                {categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                            </select>
                        </Field>
                        <Field label="O‘lchov birligi" error={fieldErrors.unit_id?.[0]}>
                            <select required value={form.unit_id} onChange={set("unit_id")} className={inputCls}>
                                <option value="">Tanlang...</option>
                                {form.unit_id && !units.some((u) => String(u.id) === form.unit_id) && <option value={form.unit_id}>{form.unitName ?? "Faol emas birlik"}</option>}
                                {units.map((u) => <option key={u.id} value={u.id}>{u.name} ({u.short_name})</option>)}
                            </select>
                        </Field>
                        <Field label="Xarid narxi (so‘m)" error={fieldErrors.purchase_price?.[0]}><input required type="number" min="0" step="0.01" value={form.purchase_price} onChange={set("purchase_price")} className={inputCls} /></Field>
                        <Field label="Sotuv narxi (so‘m)" error={fieldErrors.sale_price?.[0]}><input required type="number" min="0" step="0.01" value={form.sale_price} onChange={set("sale_price")} className={inputCls} /></Field>
                        <Field label="Ulgurji narx (ixtiyoriy)" error={fieldErrors.wholesale_price?.[0]}><input type="number" min="0" step="0.01" value={form.wholesale_price} onChange={set("wholesale_price")} className={inputCls} /></Field>
                        <Field label={`Minimal qoldiq (jami${selectedUnit ? `, ${selectedUnit.short_name}` : ""})`} error={fieldErrors.min_stock?.[0]}>
                            <input type="number" min="0" step={selectedUnit?.allow_fraction ? "0.001" : "1"} value={form.min_stock} onChange={set("min_stock")} className={inputCls} />
                        </Field>
                        <div className="sm:col-span-2"><Field label="Tavsif" error={fieldErrors.description?.[0]}><textarea rows={2} value={form.description} onChange={set("description")} className={inputCls} /></Field></div>
                        <label className="flex items-center gap-2 text-sm sm:col-span-2"><input type="checkbox" checked={form.is_active} onChange={(e) => setForm({ ...form, is_active: e.target.checked })} />Faol (sotuvda ko‘rinadi)</label>
                        <div className="flex justify-end gap-2 sm:col-span-2">
                            <Button type="button" variant="secondary" onClick={() => setForm(null)}>Bekor qilish</Button>
                            <Button type="submit" loading={saving}>Saqlash</Button>
                        </div>
                    </form>
                )}
            </Modal>

            <ConfirmDialog open={!!toArchive} title="Mahsulotni arxivlash" loading={saving} confirmLabel="Arxivlash"
                message={`“${toArchive?.name ?? ""}” arxivlanadi. Tarixiy savdo va hujjatlar saqlanadi, mahsulotni keyin qaytarish mumkin.`}
                onConfirm={archive} onCancel={() => setToArchive(null)} />
        </AppShell>
    );
}
