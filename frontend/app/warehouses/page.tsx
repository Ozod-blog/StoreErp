"use client";
import { FormEvent, useCallback, useEffect, useState } from "react";
import { Plus, Pencil, Power, Boxes } from "lucide-react";
import AppShell from "../../components/AppShell";
import { Alert, Button, ConfirmDialog, Field, Modal, Pagination, Spinner, StatusBadge, inputCls, useDebounce } from "../../components/ui";
import { api, errorMessage, ApiError, Paginated } from "../lib/api";
import { formatQty } from "../lib/format";
import { useAuth } from "../lib/auth";
import InsightsPanel from "../../components/InsightsPanel";

type Warehouse = {
    id: number; code: string; name: string; address: string | null; branch_id: number | null; is_active: boolean;
    branch?: { id: number; name: string } | null; positions_count?: number;
};
type Branch = { id: number; name: string };
type StockRow = {
    id: number; quantity: string;
    product: { id: number; sku: string; name: string; deleted_at: string | null; unit?: { short_name: string } } | null;
};
type FormState = { id?: number; code: string; name: string; address: string; branch_id: string; is_active: boolean };
const EMPTY: FormState = { code: "", name: "", address: "", branch_id: "", is_active: true };

export default function WarehousesPage() {
    const { can } = useAuth();
    const [rows, setRows] = useState<Paginated<Warehouse> | null>(null);
    const [page, setPage] = useState(1);
    const [search, setSearch] = useState("");
    const [status, setStatus] = useState("");
    const debounced = useDebounce(search);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState("");
    const [notice, setNotice] = useState("");
    const [form, setForm] = useState<FormState | null>(null);
    const [branches, setBranches] = useState<Branch[]>([]);
    const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});
    const [saving, setSaving] = useState(false);
    const [toDeactivate, setToDeactivate] = useState<Warehouse | null>(null);

    // Qoldiqlar oynasi
    const [stockWh, setStockWh] = useState<Warehouse | null>(null);
    const [stocks, setStocks] = useState<Paginated<StockRow> | null>(null);
    const [stockPage, setStockPage] = useState(1);
    const [stockSearch, setStockSearch] = useState("");
    const stockDebounced = useDebounce(stockSearch);
    const [stockLoading, setStockLoading] = useState(false);
    const [stockError, setStockError] = useState("");

    const load = useCallback(async () => {
        setLoading(true);
        setError("");
        try {
            setRows(await api<Paginated<Warehouse>>("/warehouses", { query: { page, search: debounced, status } }));
        } catch (e) {
            setError(errorMessage(e));
        } finally {
            setLoading(false);
        }
    }, [page, debounced, status]);

    useEffect(() => { load(); }, [load]);
    useEffect(() => { setPage(1); }, [debounced, status]);

    useEffect(() => {
        if (!stockWh) return;
        let cancelled = false;
        setStockLoading(true);
        setStockError("");
        api<Paginated<StockRow>>(`/warehouses/${stockWh.id}/stocks`, { query: { page: stockPage, search: stockDebounced } })
            .then((r) => { if (!cancelled) setStocks(r); })
            .catch((e) => { if (!cancelled) setStockError(errorMessage(e)); })
            .finally(() => { if (!cancelled) setStockLoading(false); });
        return () => { cancelled = true; };
    }, [stockWh, stockPage, stockDebounced]);
    useEffect(() => { setStockPage(1); }, [stockDebounced]);

    async function openForm(initial: FormState) {
        setFieldErrors({});
        setForm(initial);
        try {
            setBranches(await api<Branch[]>("/warehouses/branch-options"));
        } catch (e) {
            setError(errorMessage(e));
        }
    }

    function openStocks(w: Warehouse) {
        setStocks(null);
        setStockPage(1);
        setStockSearch("");
        setStockWh(w);
    }

    async function save(e: FormEvent) {
        e.preventDefault();
        if (!form) return;
        setSaving(true);
        setFieldErrors({});
        setError("");
        const body = { code: form.code, name: form.name, address: form.address || null, branch_id: form.branch_id ? Number(form.branch_id) : null, is_active: form.is_active };
        try {
            await api(form.id ? `/warehouses/${form.id}` : "/warehouses", { method: form.id ? "PATCH" : "POST", body });
            setNotice(form.id ? "Ombor yangilandi." : "Ombor qo‘shildi.");
            setForm(null);
            await load();
        } catch (err) {
            if (err instanceof ApiError && err.status === 422) setFieldErrors(err.errors);
            setError(errorMessage(err));
        } finally {
            setSaving(false);
        }
    }

    async function deactivate() {
        if (!toDeactivate) return;
        setSaving(true);
        setError("");
        try {
            await api(`/warehouses/${toDeactivate.id}`, { method: "DELETE" });
            setNotice("Ombor faol emas holatga o‘tkazildi.");
            await load();
        } catch (err) {
            setError(errorMessage(err));
        } finally {
            setToDeactivate(null);
            setSaving(false);
        }
    }

    return (
        <AppShell title="Omborlar" subtitle="Omborlar va ulardagi mahsulot qoldiqlari" permission="warehouses.view"
            actions={can("warehouses.create") && <Button onClick={() => openForm(EMPTY)}><Plus size={16} />Ombor qo‘shish</Button>}>
            <div className="space-y-4">
                {notice && <Alert kind="success">{notice}</Alert>}
                {error && <Alert>{error}</Alert>}
                <InsightsPanel endpoint="/stats/warehouses" />
                <div className="flex flex-wrap gap-3">
                    <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Nomi, kodi yoki manzili" className={`${inputCls} max-w-xs`} />
                    <select value={status} onChange={(e) => setStatus(e.target.value)} className={`${inputCls} max-w-[180px]`}>
                        <option value="">Barcha holatlar</option>
                        <option value="active">Faol</option>
                        <option value="inactive">Faol emas</option>
                    </select>
                </div>

                <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white">
                    {loading && !rows ? <Spinner /> : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-sm">
                                <thead className="bg-slate-50 text-xs uppercase text-slate-500">
                                    <tr><th className="px-5 py-3">Kod</th><th className="px-5 py-3">Nomi</th><th className="px-5 py-3">Filial</th><th className="px-5 py-3">Manzil</th><th className="px-5 py-3">Qoldiqli mahsulotlar</th><th className="px-5 py-3">Holat</th><th className="px-5 py-3 text-right">Amallar</th></tr>
                                </thead>
                                <tbody className="divide-y">
                                    {rows?.data.map((x) => (
                                        <tr key={x.id}>
                                            <td className="px-5 py-3 font-medium">{x.code}</td>
                                            <td className="px-5 py-3">{x.name}</td>
                                            <td className="px-5 py-3">{x.branch?.name ?? "—"}</td>
                                            <td className="px-5 py-3">{x.address || "—"}</td>
                                            <td className="px-5 py-3">{x.positions_count ?? 0}</td>
                                            <td className="px-5 py-3"><StatusBadge status={x.is_active ? "active" : "inactive"} /></td>
                                            <td className="px-5 py-3 text-right">
                                                <div className="flex justify-end gap-2">
                                                    <Button variant="secondary" className="!px-3 !py-1.5" onClick={() => openStocks(x)}><Boxes size={14} />Qoldiqlar</Button>
                                                    {can("warehouses.update") && <Button variant="secondary" className="!px-3 !py-1.5" onClick={() => openForm({ id: x.id, code: x.code, name: x.name, address: x.address ?? "", branch_id: x.branch_id ? String(x.branch_id) : "", is_active: x.is_active })}><Pencil size={14} />Tahrirlash</Button>}
                                                    {can("warehouses.delete") && x.is_active && <Button variant="secondary" className="!px-3 !py-1.5 text-red-600" aria-label="Faolsizlantirish" onClick={() => setToDeactivate(x)}><Power size={14} /></Button>}
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                    {!rows?.data.length && <tr><td colSpan={7} className="px-5 py-12 text-center text-slate-400">Ombor topilmadi.</td></tr>}
                                </tbody>
                            </table>
                        </div>
                    )}
                    {rows && <Pagination page={rows.current_page} lastPage={rows.last_page} total={rows.total} onPage={setPage} />}
                </div>
            </div>

            <Modal open={!!form} title={form?.id ? "Omborni tahrirlash" : "Yangi ombor"} onClose={() => setForm(null)}>
                {form && (
                    <form onSubmit={save} className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <Field label="Ombor kodi" error={fieldErrors.code?.[0]}><input required value={form.code} onChange={(e) => setForm({ ...form, code: e.target.value })} className={inputCls} placeholder="WH-002" /></Field>
                        <Field label="Nomi" error={fieldErrors.name?.[0]}><input required value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} className={inputCls} /></Field>
                        <div className="sm:col-span-2"><Field label="Manzil" error={fieldErrors.address?.[0]}><input value={form.address} onChange={(e) => setForm({ ...form, address: e.target.value })} className={inputCls} /></Field></div>
                        <div className="sm:col-span-2">
                            <Field label="Filial" error={fieldErrors.branch_id?.[0]}>
                                <select value={form.branch_id} onChange={(e) => setForm({ ...form, branch_id: e.target.value })} className={inputCls}>
                                    <option value="">Biriktirilmagan</option>
                                    {branches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
                                </select>
                            </Field>
                        </div>
                        <label className="flex items-center gap-2 text-sm sm:col-span-2"><input type="checkbox" checked={form.is_active} onChange={(e) => setForm({ ...form, is_active: e.target.checked })} />Faol</label>
                        <div className="flex justify-end gap-2 sm:col-span-2">
                            <Button type="button" variant="secondary" onClick={() => setForm(null)}>Bekor qilish</Button>
                            <Button type="submit" loading={saving}>Saqlash</Button>
                        </div>
                    </form>
                )}
            </Modal>

            <Modal open={!!stockWh} title={`Qoldiqlar — ${stockWh?.name ?? ""}`} onClose={() => setStockWh(null)}>
                <div className="space-y-3">
                    <p className="text-xs text-slate-500">Qoldiq faqat ombor hujjatlari (kirim, chiqim, ko‘chirish, inventarizatsiya) orqali o‘zgaradi.</p>
                    {stockError && <Alert>{stockError}</Alert>}
                    <input value={stockSearch} onChange={(e) => setStockSearch(e.target.value)} placeholder="Mahsulot nomi yoki SKU" className={inputCls} />
                    {stockLoading && !stocks ? <Spinner /> : (
                        <div className="max-h-80 overflow-y-auto rounded-lg border">
                            <table className="w-full text-left text-sm">
                                <thead className="bg-slate-50 text-xs uppercase text-slate-500"><tr><th className="px-3 py-2">SKU</th><th className="px-3 py-2">Mahsulot</th><th className="px-3 py-2 text-right">Qoldiq</th></tr></thead>
                                <tbody className="divide-y">
                                    {stocks?.data.map((s) => (
                                        <tr key={s.id}>
                                            <td className="px-3 py-2">{s.product?.sku}</td>
                                            <td className="px-3 py-2">{s.product?.name}{s.product?.deleted_at && <span className="ml-2 text-xs text-amber-600">(arxivda)</span>}</td>
                                            <td className="px-3 py-2 text-right font-medium">{formatQty(s.quantity, s.product?.unit?.short_name)}</td>
                                        </tr>
                                    ))}
                                    {!stocks?.data.length && <tr><td colSpan={3} className="px-3 py-8 text-center text-slate-400">Bu omborda qoldiq yo‘q.</td></tr>}
                                </tbody>
                            </table>
                        </div>
                    )}
                    {stocks && <Pagination page={stocks.current_page} lastPage={stocks.last_page} total={stocks.total} onPage={setStockPage} />}
                </div>
            </Modal>

            <ConfirmDialog open={!!toDeactivate} title="Omborni faolsizlantirish" loading={saving} confirmLabel="Faolsizlantirish"
                message={`“${toDeactivate?.name ?? ""}” faol emas holatga o‘tkaziladi. Omborda qoldiq bo‘lsa, amal rad etiladi.`}
                onConfirm={deactivate} onCancel={() => setToDeactivate(null)} />
        </AppShell>
    );
}
