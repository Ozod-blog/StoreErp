"use client";
import { FormEvent, useCallback, useEffect, useState } from "react";
import { Plus, Pencil, Trash2 } from "lucide-react";
import AppShell from "../../components/AppShell";
import { Alert, Button, ConfirmDialog, Field, Modal, Pagination, Spinner, StatusBadge, inputCls, useDebounce } from "../../components/ui";
import { api, errorMessage, ApiError, Paginated } from "../lib/api";
import { useAuth } from "../lib/auth";

type Unit = { id: number; name: string; short_name: string; allow_fraction: boolean; is_active: boolean; products_count?: number };
type FormState = { id?: number; name: string; short_name: string; allow_fraction: boolean; is_active: boolean };
const EMPTY: FormState = { name: "", short_name: "", allow_fraction: false, is_active: true };

export default function UnitsPage() {
    const { can } = useAuth();
    const [rows, setRows] = useState<Paginated<Unit> | null>(null);
    const [page, setPage] = useState(1);
    const [search, setSearch] = useState("");
    const debounced = useDebounce(search);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState("");
    const [notice, setNotice] = useState("");
    const [form, setForm] = useState<FormState | null>(null);
    const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});
    const [saving, setSaving] = useState(false);
    const [toDelete, setToDelete] = useState<Unit | null>(null);

    const load = useCallback(async () => {
        setLoading(true);
        setError("");
        try {
            setRows(await api<Paginated<Unit>>("/units", { query: { page, search: debounced } }));
        } catch (e) {
            setError(errorMessage(e));
        } finally {
            setLoading(false);
        }
    }, [page, debounced]);

    useEffect(() => { load(); }, [load]);
    useEffect(() => { setPage(1); }, [debounced]);

    async function save(e: FormEvent) {
        e.preventDefault();
        if (!form) return;
        setSaving(true);
        setFieldErrors({});
        setError("");
        const body = { name: form.name, short_name: form.short_name, allow_fraction: form.allow_fraction, is_active: form.is_active };
        try {
            await api(form.id ? `/units/${form.id}` : "/units", { method: form.id ? "PATCH" : "POST", body });
            setNotice(form.id ? "O‘lchov birligi yangilandi." : "O‘lchov birligi qo‘shildi.");
            setForm(null);
            await load();
        } catch (err) {
            if (err instanceof ApiError && err.status === 422) setFieldErrors(err.errors);
            setError(errorMessage(err));
        } finally {
            setSaving(false);
        }
    }

    async function remove() {
        if (!toDelete) return;
        setSaving(true);
        setError("");
        try {
            await api(`/units/${toDelete.id}`, { method: "DELETE" });
            setNotice("O‘lchov birligi o‘chirildi.");
            await load();
        } catch (err) {
            setError(errorMessage(err));
        } finally {
            setToDelete(null);
            setSaving(false);
        }
    }

    return (
        <AppShell title="O‘lchov birliklari" subtitle="Dona, kg, litr va boshqa birliklar" permission="products.view"
            actions={can("products.create") && <Button onClick={() => { setFieldErrors({}); setForm(EMPTY); }}><Plus size={16} />Birlik qo‘shish</Button>}>
            <div className="space-y-4">
                {notice && <Alert kind="success">{notice}</Alert>}
                {error && <Alert>{error}</Alert>}
                <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Nomi yoki qisqartmasi" className={`${inputCls} max-w-xs`} />

                <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white">
                    {loading && !rows ? <Spinner /> : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-sm">
                                <thead className="bg-slate-50 text-xs uppercase text-slate-500">
                                    <tr><th className="px-5 py-3">Nomi</th><th className="px-5 py-3">Qisqartma</th><th className="px-5 py-3">Kasrli miqdor</th><th className="px-5 py-3">Mahsulotlar</th><th className="px-5 py-3">Holat</th><th className="px-5 py-3 text-right">Amallar</th></tr>
                                </thead>
                                <tbody className="divide-y">
                                    {rows?.data.map((x) => (
                                        <tr key={x.id}>
                                            <td className="px-5 py-3 font-medium">{x.name}</td>
                                            <td className="px-5 py-3">{x.short_name}</td>
                                            <td className="px-5 py-3">{x.allow_fraction ? "Ha" : "Yo‘q (butun)"}</td>
                                            <td className="px-5 py-3">{x.products_count ?? 0}</td>
                                            <td className="px-5 py-3"><StatusBadge status={x.is_active ? "active" : "inactive"} /></td>
                                            <td className="px-5 py-3 text-right">
                                                <div className="flex justify-end gap-2">
                                                    {can("products.update") && <Button variant="secondary" className="!px-3 !py-1.5" onClick={() => { setFieldErrors({}); setForm({ id: x.id, name: x.name, short_name: x.short_name, allow_fraction: x.allow_fraction, is_active: x.is_active }); }}><Pencil size={14} />Tahrirlash</Button>}
                                                    {can("products.delete") && <Button variant="secondary" className="!px-3 !py-1.5 text-red-600" aria-label="O‘chirish" onClick={() => setToDelete(x)}><Trash2 size={14} /></Button>}
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                    {!rows?.data.length && <tr><td colSpan={6} className="px-5 py-12 text-center text-slate-400">O‘lchov birligi topilmadi.</td></tr>}
                                </tbody>
                            </table>
                        </div>
                    )}
                    {rows && <Pagination page={rows.current_page} lastPage={rows.last_page} total={rows.total} onPage={setPage} />}
                </div>
            </div>

            <Modal open={!!form} title={form?.id ? "Birlikni tahrirlash" : "Yangi o‘lchov birligi"} onClose={() => setForm(null)}>
                {form && (
                    <form onSubmit={save} className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <Field label="Nomi" error={fieldErrors.name?.[0]}><input required value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} className={inputCls} placeholder="Kilogramm" /></Field>
                        <Field label="Qisqartma" error={fieldErrors.short_name?.[0]}><input required value={form.short_name} onChange={(e) => setForm({ ...form, short_name: e.target.value })} className={inputCls} placeholder="kg" /></Field>
                        <label className="flex items-center gap-2 text-sm sm:col-span-2">
                            <input type="checkbox" checked={form.allow_fraction} onChange={(e) => setForm({ ...form, allow_fraction: e.target.checked })} />
                            Kasrli miqdorga ruxsat (masalan, 1.5 kg)
                        </label>
                        <label className="flex items-center gap-2 text-sm sm:col-span-2">
                            <input type="checkbox" checked={form.is_active} onChange={(e) => setForm({ ...form, is_active: e.target.checked })} />
                            Faol
                        </label>
                        <div className="flex justify-end gap-2 sm:col-span-2">
                            <Button type="button" variant="secondary" onClick={() => setForm(null)}>Bekor qilish</Button>
                            <Button type="submit" loading={saving}>Saqlash</Button>
                        </div>
                    </form>
                )}
            </Modal>

            <ConfirmDialog open={!!toDelete} title="Birlikni o‘chirish" loading={saving} confirmLabel="O‘chirish"
                message={`“${toDelete?.name ?? ""}” o‘chiriladi. Mahsulotlarda ishlatilgan birlikni o‘chirib bo‘lmaydi — u holda faol emas holatga o‘tkazing.`}
                onConfirm={remove} onCancel={() => setToDelete(null)} />
        </AppShell>
    );
}
