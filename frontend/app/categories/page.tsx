"use client";
import Link from "next/link";
import { FormEvent, useCallback, useEffect, useState } from "react";
import { Plus, Pencil, Archive, ArchiveRestore, Package } from "lucide-react";
import AppShell from "../../components/AppShell";
import { Alert, Button, ConfirmDialog, Field, Modal, Pagination, Spinner, StatusBadge, inputCls, useDebounce } from "../../components/ui";
import { api, errorMessage, ApiError, Paginated } from "../lib/api";
import { useAuth } from "../lib/auth";
import InsightsPanel from "../../components/InsightsPanel";

type Category = { id: number; name: string; description: string | null; is_active: boolean; products_count?: number };
type FormState = { id?: number; name: string; description: string; is_active: boolean };
const EMPTY: FormState = { name: "", description: "", is_active: true };

export default function CategoriesPage() {
    const { can } = useAuth();
    const [rows, setRows] = useState<Paginated<Category> | null>(null);
    const [page, setPage] = useState(1);
    const [search, setSearch] = useState("");
    const [status, setStatus] = useState("");
    const debounced = useDebounce(search);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState("");
    const [notice, setNotice] = useState("");
    const [form, setForm] = useState<FormState | null>(null);
    const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});
    const [saving, setSaving] = useState(false);
    const [toArchive, setToArchive] = useState<Category | null>(null);

    const load = useCallback(async () => {
        setLoading(true);
        setError("");
        try {
            setRows(await api<Paginated<Category>>("/categories", { query: { page, search: debounced, status } }));
        } catch (e) {
            setError(errorMessage(e));
        } finally {
            setLoading(false);
        }
    }, [page, debounced, status]);

    useEffect(() => { load(); }, [load]);
    useEffect(() => { setPage(1); }, [debounced, status]);

    async function save(e: FormEvent) {
        e.preventDefault();
        if (!form) return;
        setSaving(true);
        setFieldErrors({});
        setError("");
        const body = { name: form.name, description: form.description || null, is_active: form.is_active };
        try {
            await api(form.id ? `/categories/${form.id}` : "/categories", { method: form.id ? "PATCH" : "POST", body });
            setNotice(form.id ? "Kategoriya yangilandi." : "Kategoriya qo‘shildi.");
            setForm(null);
            await load();
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
            await api(`/categories/${toArchive.id}`, { method: "DELETE" });
            setNotice("Kategoriya arxivlandi.");
            await load();
        } catch (err) {
            setError(errorMessage(err));
        } finally {
            setToArchive(null);
            setSaving(false);
        }
    }

    async function activate(c: Category) {
        setError("");
        try {
            await api(`/categories/${c.id}`, { method: "PATCH", body: { name: c.name, description: c.description, is_active: true } });
            setNotice("Kategoriya qayta faollashtirildi.");
            await load();
        } catch (err) {
            setError(errorMessage(err));
        }
    }

    return (
        <AppShell title="Kategoriyalar" subtitle="Mahsulotlar guruhlari" permission="products.view"
            actions={can("products.create") && <Button onClick={() => { setFieldErrors({}); setForm(EMPTY); }}><Plus size={16} />Kategoriya qo‘shish</Button>}>
            <div className="space-y-4">
                {notice && <Alert kind="success">{notice}</Alert>}
                {error && <Alert>{error}</Alert>}
                <InsightsPanel endpoint="/stats/categories" />
                <div className="flex flex-wrap gap-3">
                    <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Kategoriya nomi" className={`${inputCls} max-w-xs`} />
                    <select value={status} onChange={(e) => setStatus(e.target.value)} className={`${inputCls} max-w-[180px]`}>
                        <option value="">Barcha holatlar</option>
                        <option value="active">Faol</option>
                        <option value="inactive">Arxivda</option>
                    </select>
                </div>

                <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white">
                    {loading && !rows ? <Spinner /> : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-sm">
                                <thead className="bg-slate-50 text-xs uppercase text-slate-500">
                                    <tr><th className="px-5 py-3">Nomi</th><th className="px-5 py-3">Izoh</th><th className="px-5 py-3">Mahsulotlar</th><th className="px-5 py-3">Holat</th><th className="px-5 py-3 text-right">Amallar</th></tr>
                                </thead>
                                <tbody className="divide-y">
                                    {rows?.data.map((x) => (
                                        <tr key={x.id}>
                                            <td className="px-5 py-3 font-medium">{x.name}</td>
                                            <td className="px-5 py-3 text-slate-500">{x.description || "—"}</td>
                                            <td className="px-5 py-3">{x.products_count ?? 0}</td>
                                            <td className="px-5 py-3"><StatusBadge status={x.is_active ? "active" : "archived"} /></td>
                                            <td className="px-5 py-3 text-right">
                                                <div className="flex justify-end gap-2">
                                                    <Link href={`/products?category=${x.id}`} className="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 px-3 py-1.5 text-sm font-semibold text-slate-700 hover:bg-slate-50"><Package size={14} />Mahsulotlar</Link>
                                                    {can("products.update") && <Button variant="secondary" className="!px-3 !py-1.5" onClick={() => { setFieldErrors({}); setForm({ id: x.id, name: x.name, description: x.description ?? "", is_active: x.is_active }); }}><Pencil size={14} />Tahrirlash</Button>}
                                                    {can("products.delete") && x.is_active && <Button variant="secondary" className="!px-3 !py-1.5 text-red-600" aria-label="Arxivlash" onClick={() => setToArchive(x)}><Archive size={14} /></Button>}
                                                    {can("products.update") && !x.is_active && <Button variant="secondary" className="!px-3 !py-1.5" aria-label="Qayta faollashtirish" onClick={() => activate(x)}><ArchiveRestore size={14} />Faollashtirish</Button>}
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                    {!rows?.data.length && <tr><td colSpan={5} className="px-5 py-12 text-center text-slate-400">Kategoriya topilmadi.</td></tr>}
                                </tbody>
                            </table>
                        </div>
                    )}
                    {rows && <Pagination page={rows.current_page} lastPage={rows.last_page} total={rows.total} onPage={setPage} />}
                </div>
            </div>

            <Modal open={!!form} title={form?.id ? "Kategoriyani tahrirlash" : "Yangi kategoriya"} onClose={() => setForm(null)}>
                {form && (
                    <form onSubmit={save} className="space-y-4">
                        <Field label="Nomi" error={fieldErrors.name?.[0]}><input required value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} className={inputCls} /></Field>
                        <Field label="Izoh" error={fieldErrors.description?.[0]}><textarea rows={3} value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} className={inputCls} /></Field>
                        <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={form.is_active} onChange={(e) => setForm({ ...form, is_active: e.target.checked })} />Faol</label>
                        <div className="flex justify-end gap-2">
                            <Button type="button" variant="secondary" onClick={() => setForm(null)}>Bekor qilish</Button>
                            <Button type="submit" loading={saving}>Saqlash</Button>
                        </div>
                    </form>
                )}
            </Modal>

            <ConfirmDialog open={!!toArchive} title="Kategoriyani arxivlash" loading={saving} confirmLabel="Arxivlash"
                message={`“${toArchive?.name ?? ""}” arxivlanadi. Mahsulotlar saqlanib qoladi, kategoriyani keyin qayta faollashtirish mumkin.`}
                onConfirm={archive} onCancel={() => setToArchive(null)} />
        </AppShell>
    );
}
