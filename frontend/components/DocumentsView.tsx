"use client";
import { FormEvent, useCallback, useEffect, useMemo, useState } from "react";
import { Plus, Eye, Pencil, Check, Ban, Trash2 } from "lucide-react";
import AppShell from "./AppShell";
import { Alert, Button, ConfirmDialog, Field, Modal, Pagination, Spinner, StatusBadge, inputCls, useDebounce } from "./ui";
import { api, errorMessage, Paginated } from "../app/lib/api";
import { formatDate, formatDateTime, formatQty, formatUZS } from "../app/lib/format";
import { useAuth } from "../app/lib/auth";
import { DOC_META, DocStatus, DocType, PAYMENT_METHODS, REASONS, newToken, todayISO } from "../app/lib/documents";
import InsightsPanel from "./InsightsPanel";

type Unit = { short_name: string; allow_fraction: boolean };
type DocItem = {
    id: number; product_id: number; quantity: string; price: string | null; unit_cost: string | null; batch_number?: string | null; received_at?: string | null; expires_at?: string | null;
    system_qty: string | null; diff_qty: string | null;
    product?: { id: number; sku: string; name: string; unit?: Unit | null } | null;
};
type Doc = {
    id: number; number: string; type: DocType; status: DocStatus; doc_date: string;
    warehouse_id: number; to_warehouse_id: number | null; supplier_id: number | null; parent_id: number | null;
    discount: string; total: string; paid_amount: string; payment_method: string | null; reason: string | null;
    note: string | null; cancel_reason: string | null; approved_at: string | null; cancelled_at: string | null; created_at: string;
    warehouse?: { id: number; name: string } | null; to_warehouse?: { id: number; name: string } | null;
    supplier?: { id: number; name: string } | null; parent?: { id: number; number: string } | null;
    creator?: { name: string } | null; approver?: { name: string } | null; items?: DocItem[];
};
type Option = { id: number; name: string };
type ProductHit = { id: number; sku: string; name: string; unit?: Unit | null };
type FormItem = { product_id: number; name: string; sku: string; unit: string; fraction: boolean; quantity: string; price: string; batch_number?: string; received_at?: string; expires_at?: string; system?: string; max?: string };
type Returnable = { product_id: number; name: string; sku: string; unit: string; allow_fraction: boolean; purchased: string; returned: string; remaining: string; unit_cost: string | null };
type FormState = {
    id?: number; token: string; warehouse_id: string; to_warehouse_id: string; supplier_id: string; parent_id: string;
    doc_date: string; discount: string; paid_amount: string; payment_method: string; reason: string; note: string; items: FormItem[];
};

const num = (v: string) => Number(String(v).replace(",", ".")) || 0;
const clean = (v: string) => String(v).trim().replace(",", ".");

function ProductPicker({ onPick }: { onPick: (p: ProductHit) => void }) {
    const [q, setQ] = useState("");
    const debounced = useDebounce(q);
    const [hits, setHits] = useState<ProductHit[]>([]);
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        if (!debounced.trim()) { setHits([]); return; }
        let stale = false;
        setBusy(true);
        api<Paginated<ProductHit>>("/products", { query: { search: debounced, status: "active", per_page: 8 } })
            .then((r) => { if (!stale) setHits(r.data); })
            .catch(() => { if (!stale) setHits([]); })
            .finally(() => { if (!stale) setBusy(false); });
        return () => { stale = true; };
    }, [debounced]);

    return (
        <div className="relative">
            <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Mahsulot qo‘shish: nomi, SKU yoki shtrix-kod" className={inputCls} />
            {q.trim() && (
                <div className="absolute z-20 mt-1 max-h-56 w-full overflow-y-auto rounded-lg border border-slate-200 bg-white shadow-lg">
                    {busy && <div className="px-3 py-2 text-sm text-slate-400">Qidirilmoqda...</div>}
                    {!busy && !hits.length && <div className="px-3 py-2 text-sm text-slate-400">Topilmadi</div>}
                    {hits.map((h) => (
                        <button key={h.id} type="button" className="flex w-full items-center justify-between px-3 py-2 text-left text-sm hover:bg-slate-50"
                            onClick={() => { onPick(h); setQ(""); setHits([]); }}>
                            <span>{h.name}</span><span className="text-xs text-slate-400">{h.sku}</span>
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
}

const INSIGHT_DOC_TYPES: string[] = ["inventory", "purchase", "purchase_return", "transfer", "issue"];

export default function DocumentsView({ type }: { type: DocType }) {
    const meta = DOC_META[type];
    const { can } = useAuth();
    const base = `/${meta.prefix}`;
    const m = meta.module;

    const [rows, setRows] = useState<Paginated<Doc> | null>(null);
    const [page, setPage] = useState(1);
    const [search, setSearch] = useState("");
    const [status, setStatus] = useState("");
    const [from, setFrom] = useState("");
    const [to, setTo] = useState("");
    const [whFilter, setWhFilter] = useState("");
    const debounced = useDebounce(search);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState("");
    const [notice, setNotice] = useState("");

    const [warehouses, setWarehouses] = useState<Option[]>([]);
    const [suppliers, setSuppliers] = useState<Option[]>([]);
    const [purchases, setPurchases] = useState<Doc[]>([]);

    const [form, setForm] = useState<FormState | null>(null);
    const [formError, setFormError] = useState("");
    const [saving, setSaving] = useState(false);
    const [newSupplier, setNewSupplier] = useState("");

    const [detail, setDetail] = useState<Doc | null>(null);
    const [detailLoading, setDetailLoading] = useState(false);
    const [confirmApprove, setConfirmApprove] = useState(false);
    const [cancelOpen, setCancelOpen] = useState(false);
    const [cancelReason, setCancelReason] = useState("");
    const [busy, setBusy] = useState(false);
    const [detailError, setDetailError] = useState("");

    const load = useCallback(async () => {
        setLoading(true);
        setError("");
        try {
            setRows(await api<Paginated<Doc>>(base, { query: { type, page, search: debounced, status, date_from: from, date_to: to, warehouse_id: whFilter } }));
        } catch (e) {
            setError(errorMessage(e));
        } finally {
            setLoading(false);
        }
    }, [base, type, page, debounced, status, from, to, whFilter]);

    useEffect(() => { load(); }, [load]);
    useEffect(() => { setPage(1); }, [debounced, status, from, to, whFilter]);

    useEffect(() => {
        api<Option[]>("/warehouses", { query: { all: 1 } }).then(setWarehouses).catch(() => setWarehouses([]));
        if (type === "purchase") api<Option[]>("/suppliers", { query: { all: 1 } }).then(setSuppliers).catch(() => setSuppliers([]));
    }, [type]);

    const warehouseName = (id: number | null) => warehouses.find((w) => w.id === id)?.name ?? "—";

    function openCreate() {
        setFormError("");
        setForm({
            token: newToken(), warehouse_id: warehouses[0] ? String(warehouses[0].id) : "", to_warehouse_id: "", supplier_id: "", parent_id: "",
            doc_date: todayISO(), discount: "", paid_amount: "", payment_method: "cash", reason: "write_off", note: "", items: [],
        });
        if (type === "purchase_return") {
            api<Paginated<Doc>>("/purchases", { query: { type: "purchase", status: "approved", per_page: 100 } })
                .then((r) => setPurchases(r.data)).catch(() => setPurchases([]));
        }
    }

    function openEdit(d: Doc) {
        setFormError("");
        setDetail(null);
        setForm({
            id: d.id, token: "", warehouse_id: String(d.warehouse_id), to_warehouse_id: d.to_warehouse_id ? String(d.to_warehouse_id) : "",
            supplier_id: d.supplier_id ? String(d.supplier_id) : "", parent_id: d.parent_id ? String(d.parent_id) : "",
            doc_date: d.doc_date, discount: Number(d.discount) ? String(Number(d.discount)) : "",
            paid_amount: Number(d.paid_amount) ? String(Number(d.paid_amount)) : "", payment_method: d.payment_method ?? "cash",
            reason: d.reason ?? "write_off", note: d.note ?? "",
            items: (d.items ?? []).map((i) => ({
                product_id: i.product_id, name: i.product?.name ?? "", sku: i.product?.sku ?? "", unit: i.product?.unit?.short_name ?? "",
                fraction: !!i.product?.unit?.allow_fraction, quantity: String(Number(i.quantity)), price: i.price ? String(Number(i.price)) : "", batch_number: i.batch_number ?? "", received_at: i.received_at ?? d.doc_date, expires_at: i.expires_at ?? "",
            })),
        });
        if (type === "purchase_return" && d.parent_id) pickParent(String(d.parent_id), d.items ?? []);
    }

    async function pickParent(parentId: string, existing: DocItem[] = []) {
        setForm((f) => (f ? { ...f, parent_id: parentId, items: [] } : f));
        if (!parentId) return;
        try {
            const r = await api<{ items: Returnable[] }>(`/purchases/${parentId}/returnable`);
            const items: FormItem[] = r.items.filter((x) => Number(x.remaining) > 0).map((x) => ({
                product_id: x.product_id, name: x.name, sku: x.sku, unit: x.unit, fraction: x.allow_fraction,
                quantity: String(Number(existing.find((e) => e.product_id === x.product_id)?.quantity ?? "") || ""),
                price: x.unit_cost ? String(Number(x.unit_cost)) : "", max: x.remaining,
            }));
            setForm((f) => (f ? { ...f, items } : f));
        } catch (e) {
            setFormError(errorMessage(e));
        }
    }

    function addProduct(p: ProductHit) {
        setForm((f) => {
            if (!f || f.items.some((i) => i.product_id === p.id)) return f;
            return { ...f, items: [...f.items, { product_id: p.id, name: p.name, sku: p.sku, unit: p.unit?.short_name ?? "", fraction: !!p.unit?.allow_fraction, quantity: "", price: "", batch_number: "", received_at: f.doc_date, expires_at: "" }] };
        });
    }

    async function fillFromStock() {
        if (!form?.warehouse_id) return;
        setFormError("");
        try {
            const items: FormItem[] = [];
            for (let p = 1; p <= 20; p++) {
                const r = await api<Paginated<{ quantity: string; product: { id: number; sku: string; name: string; unit?: Unit } | null }>>(
                    `/warehouses/${form.warehouse_id}/stocks`, { query: { page: p, per_page: 100 } });
                r.data.forEach((s) => {
                    if (s.product) items.push({
                        product_id: s.product.id, name: s.product.name, sku: s.product.sku, unit: s.product.unit?.short_name ?? "",
                        fraction: !!s.product.unit?.allow_fraction, quantity: String(Number(s.quantity)), price: "", system: s.quantity,
                    });
                });
                if (p >= r.last_page) break;
            }
            setForm((f) => (f ? { ...f, items } : f));
        } catch (e) {
            setFormError(errorMessage(e));
        }
    }

    function setItem(idx: number, patch: Partial<FormItem>) {
        setForm((f) => (f ? { ...f, items: f.items.map((it, i) => (i === idx ? { ...it, ...patch } : it)) } : f));
    }

    const subtotal = useMemo(() => (form ? form.items.reduce((s, i) => s + num(i.quantity) * num(i.price), 0) : 0), [form]);

    async function addSupplier() {
        if (!newSupplier.trim()) return;
        try {
            const s = await api<Option>("/suppliers", { method: "POST", body: { name: newSupplier.trim() } });
            setSuppliers((x) => [...x, s].sort((a, b) => a.name.localeCompare(b.name)));
            setForm((f) => (f ? { ...f, supplier_id: String(s.id) } : f));
            setNewSupplier("");
        } catch (e) {
            setFormError(errorMessage(e));
        }
    }

    async function save(e: FormEvent) {
        e.preventDefault();
        if (!form) return;
        setFormError("");

        const items = form.items.filter((i) => type !== "purchase_return" || num(i.quantity) > 0);
        if (!items.length) { setFormError(type === "purchase_return" ? "Qaytariladigan miqdorni kiriting." : "Hujjatga kamida bitta mahsulot qo‘shing."); return; }
        if (items.some((i) => clean(i.quantity) === "" || Number.isNaN(Number(clean(i.quantity))))) { setFormError("Barcha qatorlarda miqdorni kiriting."); return; }
        if (type === "purchase" && items.some((i) => clean(i.price) === "")) { setFormError("Barcha qatorlarda xarid narxini kiriting."); return; }
        const over = items.find((i) => i.max !== undefined && num(i.quantity) > Number(i.max));
        if (over) { setFormError(`«${over.name}»: qaytarish mumkin bo‘lgan miqdor ${formatQty(over.max, over.unit)}.`); return; }

        const body: Record<string, unknown> = {
            type, doc_date: form.doc_date, note: form.note.trim() || null,
            items: items.map((i) => ({ product_id: i.product_id, quantity: clean(i.quantity), ...(type === "purchase" ? { price: clean(i.price), batch_number: i.batch_number?.trim() || undefined, received_at: form.doc_date, expires_at: i.expires_at || undefined } : {}) })),
        };
        if (type === "purchase_return") body.parent_id = Number(form.parent_id);
        else body.warehouse_id = Number(form.warehouse_id);
        if (type === "transfer") body.to_warehouse_id = Number(form.to_warehouse_id);
        if (type === "issue") body.reason = form.reason;
        if (type === "purchase") {
            body.supplier_id = Number(form.supplier_id);
            body.discount = clean(form.discount) || 0;
            body.paid_amount = clean(form.paid_amount) || 0;
            if (num(form.paid_amount) > 0) body.payment_method = form.payment_method;
        }
        if (!form.id) body.client_token = form.token;

        setSaving(true);
        try {
            await api(form.id ? `${base}/${form.id}` : base, { method: form.id ? "PUT" : "POST", body });
            setForm(null);
            setNotice("Qoralama saqlandi. Ombor qoldig‘i hozircha o‘zgarmadi — hujjatni tasdiqlang.");
            await load();
        } catch (err) {
            setFormError(errorMessage(err));
        } finally {
            setSaving(false);
        }
    }

    async function openDetail(id: number) {
        setDetailError("");
        setDetailLoading(true);
        setDetail({ id } as Doc);
        try {
            setDetail(await api<Doc>(`${base}/${id}`));
        } catch (e) {
            setDetail(null);
            setError(errorMessage(e));
        } finally {
            setDetailLoading(false);
        }
    }

    async function approve() {
        if (!detail) return;
        setBusy(true);
        setDetailError("");
        try {
            const d = await api<Doc>(`${base}/${detail.id}/approve`, { method: "POST" });
            setDetail(d);
            setNotice(`${d.number} tasdiqlandi.`);
            await load();
        } catch (e) {
            setDetailError(errorMessage(e));
        } finally {
            setBusy(false);
            setConfirmApprove(false);
        }
    }

    async function cancel() {
        if (!detail) return;
        setBusy(true);
        setDetailError("");
        try {
            const d = await api<Doc>(`${base}/${detail.id}/cancel`, { method: "POST", body: { reason: cancelReason } });
            setDetail(d);
            setNotice(`${d.number} bekor qilindi.`);
            setCancelOpen(false);
            setCancelReason("");
            await load();
        } catch (e) {
            setDetailError(errorMessage(e));
            setCancelOpen(false);
        } finally {
            setBusy(false);
        }
    }

    const money = type === "purchase" || type === "purchase_return";
    const colspan = money ? 7 : 6;

    return (
        <AppShell title={meta.title} subtitle={meta.subtitle} permission={`${m}.view`}
            actions={can(`${m}.create`) && <Button onClick={openCreate}><Plus size={16} />Yangi hujjat</Button>}>
            <div className="space-y-4">
                {notice && <Alert kind="success">{notice}</Alert>}
                {error && <Alert>{error}</Alert>}
                {INSIGHT_DOC_TYPES.includes(type) && <InsightsPanel endpoint={`/stats/documents?type=${type}`} />}

                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5">
                    <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Raqam yoki izoh" className={inputCls} />
                    <select value={status} onChange={(e) => setStatus(e.target.value)} className={inputCls}>
                        <option value="">Barcha holatlar</option>
                        <option value="draft">Qoralama</option>
                        <option value="approved">Tasdiqlangan</option>
                        <option value="cancelled">Bekor qilingan</option>
                    </select>
                    <select value={whFilter} onChange={(e) => setWhFilter(e.target.value)} className={inputCls}>
                        <option value="">Barcha omborlar</option>
                        {warehouses.map((w) => <option key={w.id} value={w.id}>{w.name}</option>)}
                    </select>
                    <input type="date" value={from} onChange={(e) => setFrom(e.target.value)} className={inputCls} aria-label="Sanadan" />
                    <input type="date" value={to} onChange={(e) => setTo(e.target.value)} className={inputCls} aria-label="Sanagacha" />
                </div>

                <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white">
                    {loading && !rows ? <Spinner /> : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-sm">
                                <thead className="bg-slate-50 text-xs uppercase text-slate-500">
                                    <tr>
                                        <th className="px-5 py-3">Raqam</th><th className="px-5 py-3">Sana</th><th className="px-5 py-3">Ombor</th>
                                        <th className="px-5 py-3">{type === "purchase" ? "Yetkazib beruvchi" : type === "purchase_return" ? "Asl xarid" : type === "issue" ? "Sabab" : "Izoh"}</th>
                                        {money && <th className="px-5 py-3 text-right">Summa</th>}
                                        <th className="px-5 py-3">Holat</th><th className="px-5 py-3 text-right">Amallar</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {rows?.data.map((d) => (
                                        <tr key={d.id}>
                                            <td className="px-5 py-3 font-medium">{d.number}</td>
                                            <td className="px-5 py-3">{formatDate(d.doc_date)}</td>
                                            <td className="px-5 py-3">{d.warehouse?.name}{d.to_warehouse ? ` → ${d.to_warehouse.name}` : ""}</td>
                                            <td className="px-5 py-3 text-slate-600">
                                                {type === "purchase" ? d.supplier?.name : type === "purchase_return" ? d.parent?.number : type === "issue" ? REASONS[d.reason ?? ""] : d.note}
                                            </td>
                                            {money && <td className="px-5 py-3 text-right">{formatUZS(d.total)}</td>}
                                            <td className="px-5 py-3"><StatusBadge status={d.status} /></td>
                                            <td className="px-5 py-3 text-right">
                                                <Button variant="secondary" className="!px-3 !py-1.5" onClick={() => openDetail(d.id)}><Eye size={14} />Ko‘rish</Button>
                                            </td>
                                        </tr>
                                    ))}
                                    {!rows?.data.length && <tr><td colSpan={colspan} className="px-5 py-12 text-center text-slate-400">Hujjat topilmadi.</td></tr>}
                                </tbody>
                            </table>
                        </div>
                    )}
                    {rows && <Pagination page={rows.current_page} lastPage={rows.last_page} total={rows.total} onPage={setPage} />}
                </div>
            </div>

            {/* ---- Yaratish / tahrirlash ---- */}
            <Modal wide open={!!form} title={form?.id ? `${meta.single}ni tahrirlash` : `Yangi: ${meta.single.toLowerCase()}`} onClose={() => setForm(null)}>
                {form && (
                    <form onSubmit={save} className="space-y-4">
                        {formError && <Alert>{formError}</Alert>}
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                            <Field label="Sana"><input type="date" required value={form.doc_date} onChange={(e) => setForm({ ...form, doc_date: e.target.value })} className={inputCls} /></Field>

                            {type === "purchase_return" ? (
                                <div className="sm:col-span-2">
                                    <Field label="Asl xarid hujjati">
                                        <select required value={form.parent_id} disabled={!!form.id} onChange={(e) => pickParent(e.target.value)} className={inputCls}>
                                            <option value="">Tanlang</option>
                                            {purchases.map((p) => <option key={p.id} value={p.id}>{p.number} — {p.supplier?.name} ({formatDate(p.doc_date)})</option>)}
                                            {form.id && form.parent_id && !purchases.some((p) => String(p.id) === form.parent_id) && <option value={form.parent_id}>#{form.parent_id}</option>}
                                        </select>
                                    </Field>
                                </div>
                            ) : (
                                <Field label={type === "transfer" ? "Qaysi ombordan" : "Ombor"}>
                                    <select required value={form.warehouse_id} onChange={(e) => setForm({ ...form, warehouse_id: e.target.value, items: type === "inventory" ? [] : form.items })} className={inputCls}>
                                        <option value="">Tanlang</option>
                                        {warehouses.map((w) => <option key={w.id} value={w.id}>{w.name}</option>)}
                                    </select>
                                </Field>
                            )}

                            {type === "transfer" && (
                                <Field label="Qaysi omborga">
                                    <select required value={form.to_warehouse_id} onChange={(e) => setForm({ ...form, to_warehouse_id: e.target.value })} className={inputCls}>
                                        <option value="">Tanlang</option>
                                        {warehouses.filter((w) => String(w.id) !== form.warehouse_id).map((w) => <option key={w.id} value={w.id}>{w.name}</option>)}
                                    </select>
                                </Field>
                            )}
                            {type === "issue" && (
                                <Field label="Sabab">
                                    <select value={form.reason} onChange={(e) => setForm({ ...form, reason: e.target.value })} className={inputCls}>
                                        {Object.entries(REASONS).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                                    </select>
                                </Field>
                            )}
                            {type === "purchase" && (
                                <div className="sm:col-span-1">
                                    <Field label="Yetkazib beruvchi">
                                        <select required value={form.supplier_id} onChange={(e) => setForm({ ...form, supplier_id: e.target.value })} className={inputCls}>
                                            <option value="">Tanlang</option>
                                            {suppliers.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                                        </select>
                                    </Field>
                                </div>
                            )}
                        </div>

                        {type === "purchase" && can("suppliers.create") && (
                            <div className="flex gap-2">
                                <input value={newSupplier} onChange={(e) => setNewSupplier(e.target.value)} placeholder="Yangi yetkazib beruvchi nomi" className={`${inputCls} max-w-xs`} />
                                <Button type="button" variant="secondary" onClick={addSupplier}>Qo‘shish</Button>
                            </div>
                        )}

                        {type !== "purchase_return" && <ProductPicker onPick={addProduct} />}
                        {type === "inventory" && form.warehouse_id && (
                            <Button type="button" variant="secondary" onClick={fillFromStock}>Ombor qoldig‘idan to‘ldirish</Button>
                        )}

                        <div className="overflow-x-auto rounded-xl border border-slate-200">
                            <table className="w-full text-left text-sm">
                                <thead className="bg-slate-50 text-xs uppercase text-slate-500">
                                    <tr>
                                        <th className="px-3 py-2">Mahsulot</th>
                                        {type === "purchase_return" && <th className="px-3 py-2 text-right">Qaytarish mumkin</th>}
                                        {type === "inventory" && <th className="px-3 py-2 text-right">Tizim qoldig‘i</th>}
                                        <th className="px-3 py-2 w-36">{type === "inventory" ? "Sanalgan miqdor" : "Miqdor"}</th>
                                        {type === "purchase" && <th className="px-3 py-2 w-36">Xarid narxi</th>}
                                        {type === "purchase" && <th className="px-3 py-2 min-w-36">Partiya raqami</th>}
                                        {type === "purchase" && <th className="px-3 py-2 min-w-36">Yaroqlilik muddati</th>}
                                        {type === "purchase_return" && <th className="px-3 py-2 text-right">Narx</th>}
                                        {type !== "purchase_return" && <th className="px-3 py-2 w-10" />}
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {form.items.map((it, idx) => (
                                        <tr key={it.product_id}>
                                            <td className="px-3 py-2"><div className="font-medium">{it.name}</div><div className="text-xs text-slate-400">{it.sku}</div></td>
                                            {type === "purchase_return" && <td className="px-3 py-2 text-right">{formatQty(it.max, it.unit)}</td>}
                                            {type === "inventory" && <td className="px-3 py-2 text-right">{it.system !== undefined ? formatQty(it.system, it.unit) : "—"}</td>}
                                            <td className="px-3 py-2">
                                                <div className="flex items-center gap-1">
                                                    <input inputMode="decimal" value={it.quantity} onChange={(e) => setItem(idx, { quantity: e.target.value })} className={inputCls} placeholder={it.fraction ? "0.000" : "0"} />
                                                    <span className="text-xs text-slate-400">{it.unit}</span>
                                                </div>
                                            </td>
                                            {type === "purchase" && <td className="px-3 py-2"><input inputMode="decimal" value={it.price} onChange={(e) => setItem(idx, { price: e.target.value })} className={inputCls} placeholder="0" /></td>}
                                            {type === "purchase" && <td className="px-3 py-2"><input value={it.batch_number ?? ""} onChange={(e) => setItem(idx, { batch_number: e.target.value })} className={inputCls} placeholder="Avto yaratiladi" /></td>}
                                            {type === "purchase" && <td className="px-3 py-2"><input type="date" value={it.expires_at ?? ""} onChange={(e) => setItem(idx, { expires_at: e.target.value })} className={inputCls} /></td>}
                                            {type === "purchase_return" && <td className="px-3 py-2 text-right">{formatUZS(it.price)}</td>}
                                            {type !== "purchase_return" && (
                                                <td className="px-3 py-2">
                                                    <button type="button" aria-label="Qatorni o‘chirish" className="rounded p-1 text-red-600 hover:bg-red-50"
                                                        onClick={() => setForm({ ...form, items: form.items.filter((_, i) => i !== idx) })}><Trash2 size={15} /></button>
                                                </td>
                                            )}
                                        </tr>
                                    ))}
                                    {!form.items.length && (
                                        <tr><td colSpan={5} className="px-3 py-8 text-center text-slate-400">
                                            {type === "purchase_return" ? "Asl xarid hujjatini tanlang." : "Mahsulot qo‘shing."}
                                        </td></tr>
                                    )}
                                </tbody>
                            </table>
                        </div>

                        {type === "purchase" && (
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-4">
                                <Field label="Chegirma (so‘m)"><input inputMode="decimal" value={form.discount} onChange={(e) => setForm({ ...form, discount: e.target.value })} className={inputCls} placeholder="0" /></Field>
                                <Field label="To‘langan summa"><input inputMode="decimal" value={form.paid_amount} onChange={(e) => setForm({ ...form, paid_amount: e.target.value })} className={inputCls} placeholder="0" /></Field>
                                <Field label="To‘lov usuli">
                                    <select value={form.payment_method} onChange={(e) => setForm({ ...form, payment_method: e.target.value })} className={inputCls} disabled={!num(form.paid_amount)}>
                                        {Object.entries(PAYMENT_METHODS).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                                    </select>
                                </Field>
                                <div className="text-sm">
                                    <div className="text-slate-500">Jami (taxminiy)</div>
                                    <div className="mt-1 text-lg font-bold">{formatUZS(Math.max(subtotal - num(form.discount), 0))}</div>
                                    <div className="text-xs text-slate-500">Qarz: {formatUZS(Math.max(subtotal - num(form.discount) - num(form.paid_amount), 0))}</div>
                                </div>
                            </div>
                        )}
                        <Field label="Izoh"><input value={form.note} onChange={(e) => setForm({ ...form, note: e.target.value })} className={inputCls} maxLength={500} /></Field>

                        <div className="flex justify-end gap-2">
                            <Button type="button" variant="secondary" onClick={() => setForm(null)}>Bekor qilish</Button>
                            <Button type="submit" loading={saving}>Qoralama sifatida saqlash</Button>
                        </div>
                    </form>
                )}
            </Modal>

            {/* ---- Ko'rish / tasdiqlash / bekor qilish ---- */}
            <Modal wide open={!!detail} title={detail?.number ? `${meta.single} ${detail.number}` : meta.single} onClose={() => setDetail(null)}>
                {detailLoading || !detail?.number ? <Spinner /> : (
                    <div className="space-y-4">
                        {detailError && <Alert>{detailError}</Alert>}
                        <div className="grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                            <Info label="Holat"><StatusBadge status={detail.status} /></Info>
                            <Info label="Sana">{formatDate(detail.doc_date)}</Info>
                            <Info label="Ombor">{detail.warehouse?.name}{detail.to_warehouse ? ` → ${detail.to_warehouse.name}` : ""}</Info>
                            {detail.supplier && <Info label="Yetkazib beruvchi">{detail.supplier.name}</Info>}
                            {detail.parent && <Info label="Asl xarid">{detail.parent.number}</Info>}
                            {detail.reason && <Info label="Sabab">{REASONS[detail.reason]}</Info>}
                            {money && <Info label="Jami">{formatUZS(detail.total)}</Info>}
                            {type === "purchase" && <Info label="Chegirma">{formatUZS(detail.discount)}</Info>}
                            {type === "purchase" && <Info label="To‘langan">{formatUZS(detail.paid_amount)}{detail.payment_method ? ` (${PAYMENT_METHODS[detail.payment_method]})` : ""}</Info>}
                            {type === "purchase" && <Info label="Qarz">{formatUZS(Number(detail.total) - Number(detail.paid_amount))}</Info>}
                            <Info label="Yaratdi">{detail.creator?.name ?? "—"}</Info>
                            {detail.approved_at && <Info label="Tasdiqladi">{detail.approver?.name ?? "—"}, {formatDateTime(detail.approved_at)}</Info>}
                            {detail.cancelled_at && <Info label="Bekor qilindi">{formatDateTime(detail.cancelled_at)}</Info>}
                        </div>
                        {detail.cancel_reason && <Alert>Bekor qilish sababi: {detail.cancel_reason}</Alert>}
                        {detail.note && <p className="text-sm text-slate-600">Izoh: {detail.note}</p>}

                        <div className="overflow-x-auto rounded-xl border border-slate-200">
                            <table className="w-full text-left text-sm">
                                <thead className="bg-slate-50 text-xs uppercase text-slate-500">
                                    <tr>
                                        <th className="px-3 py-2">Mahsulot</th>
                                        <th className="px-3 py-2 text-right">{type === "inventory" ? "Sanalgan" : "Miqdor"}</th>
                                        {type === "inventory" && <><th className="px-3 py-2 text-right">Tizim</th><th className="px-3 py-2 text-right">Farq</th></>}
                                        {money && <th className="px-3 py-2 text-right">Narx</th>}
                                        {detail.status === "approved" && type !== "inventory" && <th className="px-3 py-2 text-right">Tannarx</th>}
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {detail.items?.map((i) => (
                                        <tr key={i.id}>
                                            <td className="px-3 py-2"><div className="font-medium">{i.product?.name}</div><div className="text-xs text-slate-400">{i.product?.sku}</div></td>
                                            <td className="px-3 py-2 text-right">{formatQty(i.quantity, i.product?.unit?.short_name)}</td>
                                            {type === "inventory" && <>
                                                <td className="px-3 py-2 text-right">{i.system_qty === null ? "—" : formatQty(i.system_qty)}</td>
                                                <td className={`px-3 py-2 text-right font-medium ${Number(i.diff_qty) < 0 ? "text-red-600" : Number(i.diff_qty) > 0 ? "text-emerald-600" : ""}`}>{i.diff_qty === null ? "—" : formatQty(i.diff_qty)}</td>
                                            </>}
                                            {money && <td className="px-3 py-2 text-right">{formatUZS(i.price)}</td>}
                                            {detail.status === "approved" && type !== "inventory" && <td className="px-3 py-2 text-right">{formatUZS(i.unit_cost)}</td>}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        <div className="flex flex-wrap justify-end gap-2">
                            {detail.status === "draft" && can(`${m}.update`) && <Button variant="secondary" onClick={() => openEdit(detail)}><Pencil size={15} />Tahrirlash</Button>}
                            {detail.status === "draft" && can(`${m}.approve`) && <Button onClick={() => setConfirmApprove(true)}><Check size={15} />Tasdiqlash</Button>}
                            {detail.status !== "cancelled" && can(`${m}.cancel`) && <Button variant="secondary" className="text-red-600" onClick={() => { setCancelReason(""); setCancelOpen(true); }}><Ban size={15} />Bekor qilish</Button>}
                        </div>
                    </div>
                )}
            </Modal>

            <ConfirmDialog open={confirmApprove} title="Hujjatni tasdiqlash" loading={busy} confirmLabel="Tasdiqlash"
                message="Tasdiqlangach ombor qoldig‘i o‘zgaradi va hujjatni tahrirlab bo‘lmaydi (faqat bekor qilish mumkin). Davom etasizmi?"
                onConfirm={approve} onCancel={() => setConfirmApprove(false)} />

            <Modal open={cancelOpen} title="Hujjatni bekor qilish" onClose={() => setCancelOpen(false)}>
                <p className="text-sm text-slate-600">
                    {detail?.status === "approved" ? "Qoldiq teskari harakat bilan tiklanadi (tarix saqlanadi). Sababni kiriting." : "Qoralama bekor qilinadi, qoldiqqa ta’sir qilmaydi."}
                </p>
                <div className="mt-4">
                    <Field label="Sabab"><input value={cancelReason} onChange={(e) => setCancelReason(e.target.value)} className={inputCls} maxLength={500} /></Field>
                </div>
                <div className="mt-6 flex justify-end gap-2">
                    <Button variant="secondary" onClick={() => setCancelOpen(false)}>Yopish</Button>
                    <Button variant="danger" loading={busy} onClick={cancel}>Bekor qilish</Button>
                </div>
            </Modal>
        </AppShell>
    );
}

function Info({ label, children }: { label: string; children: React.ReactNode }) {
    return <div><div className="text-xs uppercase text-slate-400">{label}</div><div className="mt-0.5 font-medium text-slate-800">{children}</div></div>;
}
