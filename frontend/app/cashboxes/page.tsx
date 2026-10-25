"use client";
import { FormEvent, useCallback, useEffect, useState } from "react";
import { Plus, Pencil, Power, History, ArrowDownToLine, ArrowUpFromLine } from "lucide-react";
import AppShell from "../../components/AppShell";
import { Alert, Button, ConfirmDialog, Field, Modal, Pagination, Spinner, StatusBadge, inputCls, useDebounce } from "../../components/ui";
import { CashOperationModal, TransactionsTable } from "../../components/CashParts";
import { api, errorMessage, ApiError, Paginated } from "../lib/api";
import { CashboxRow } from "../lib/cash";
import { formatUZS } from "../lib/format";
import { useAuth } from "../lib/auth";
import InsightsPanel from "../../components/InsightsPanel";

type Branch = { id: number; name: string };
type FormState = { id?: number; code: string; name: string; branch_id: string; is_active: boolean };
const EMPTY: FormState = { code: "", name: "", branch_id: "", is_active: true };

export default function CashboxesPage() {
    const { can } = useAuth();
    const [rows, setRows] = useState<Paginated<CashboxRow> | null>(null);
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
    const [toDeactivate, setToDeactivate] = useState<CashboxRow | null>(null);
    const [history, setHistory] = useState<CashboxRow | null>(null);
    const [op, setOp] = useState<{ cashbox: CashboxRow; direction: "in" | "out" } | null>(null);
    const [activeBoxes, setActiveBoxes] = useState<CashboxRow[]>([]);

    const load = useCallback(async () => {
        setLoading(true);
        setError("");
        try {
            setRows(await api<Paginated<CashboxRow>>("/cashboxes", { query: { page, search: debounced, status } }));
        } catch (e) {
            setError(errorMessage(e));
        } finally {
            setLoading(false);
        }
    }, [page, debounced, status]);

    useEffect(() => { load(); }, [load]);
    useEffect(() => { setPage(1); }, [debounced, status]);

    async function openOp(cashbox: CashboxRow, direction: "in" | "out") {
        try {
            setActiveBoxes(await api<CashboxRow[]>("/cashboxes", { query: { all: 1 } }));
            setOp({ cashbox, direction });
        } catch (e) {
            setError(errorMessage(e));
        }
    }

    async function openForm(initial: FormState) {
        setFieldErrors({});
        setForm(initial);
        try {
            setBranches(await api<Branch[]>("/cashboxes/branch-options"));
        } catch (e) {
            setError(errorMessage(e));
        }
    }

    async function save(e: FormEvent) {
        e.preventDefault();
        if (!form) return;
        setSaving(true);
        setFieldErrors({});
        setError("");
        const body = { code: form.code, name: form.name, branch_id: form.branch_id ? Number(form.branch_id) : null, is_active: form.is_active };
        try {
            await api(form.id ? `/cashboxes/${form.id}` : "/cashboxes", { method: form.id ? "PATCH" : "POST", body });
            setNotice(form.id ? "Kassa yangilandi." : "Kassa qo‘shildi.");
            setForm(null);
            await load();
        } catch (err) {
            if (err instanceof ApiError && err.status === 422) setFieldErrors(err.errors);
            setError(errorMessage(err));
            if (!(err instanceof ApiError && err.status === 422 && Object.keys(err.errors).length)) setForm(null);
        } finally {
            setSaving(false);
        }
    }

    async function deactivate() {
        if (!toDeactivate) return;
        setSaving(true);
        setError("");
        try {
            await api(`/cashboxes/${toDeactivate.id}`, { method: "DELETE" });
            setNotice("Kassa faol emas holatga o‘tkazildi.");
            await load();
        } catch (err) {
            setError(errorMessage(err));
        } finally {
            setToDeactivate(null);
            setSaving(false);
        }
    }

    return (
        <AppShell title="Kassalar" subtitle="Kassalar, naqd pul qoldig‘i va kassa daftari" permission="cashboxes.view"
            actions={can("cashboxes.create") && <Button onClick={() => openForm(EMPTY)}><Plus size={16} />Kassa qo‘shish</Button>}>
            <div className="space-y-4">
                {notice && <Alert kind="success">{notice}</Alert>}
                {error && <Alert>{error}</Alert>}
                <InsightsPanel endpoint="/stats/cashboxes" />
                <div className="flex flex-wrap gap-3">
                    <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Nomi yoki kodi" className={`${inputCls} max-w-xs`} />
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
                                    <tr><th className="px-5 py-3">Kod</th><th className="px-5 py-3">Nomi</th><th className="px-5 py-3">Filial</th><th className="px-5 py-3 text-right">Naqd pul qoldig‘i</th><th className="px-5 py-3">Faol smena</th><th className="px-5 py-3">Holat</th><th className="px-5 py-3 text-right">Amallar</th></tr>
                                </thead>
                                <tbody className="divide-y">
                                    {rows?.data.map((x) => (
                                        <tr key={x.id}>
                                            <td className="px-5 py-3 font-medium">{x.code}</td>
                                            <td className="px-5 py-3">{x.name}</td>
                                            <td className="px-5 py-3">{x.branch?.name ?? "—"}</td>
                                            <td className="whitespace-nowrap px-5 py-3 text-right font-semibold">{formatUZS(x.balance)}</td>
                                            <td className="px-5 py-3">{x.open_shift ? x.open_shift.user?.name ?? "Ochiq" : <span className="text-slate-400">Yo‘q</span>}</td>
                                            <td className="px-5 py-3"><StatusBadge status={x.is_active ? "active" : "inactive"} /></td>
                                            <td className="px-5 py-3 text-right">
                                                <div className="flex flex-wrap justify-end gap-2">
                                                    <Button variant="secondary" className="!px-3 !py-1.5" onClick={() => setHistory(x)}><History size={14} />Tarix</Button>
                                                    {can("shifts.update") && x.is_active && <Button variant="secondary" className="!px-3 !py-1.5" onClick={() => openOp(x, "in")}><ArrowDownToLine size={14} />Kirim</Button>}
                                                    {can("shifts.update") && x.is_active && <Button variant="secondary" className="!px-3 !py-1.5" onClick={() => openOp(x, "out")}><ArrowUpFromLine size={14} />Chiqim</Button>}
                                                    {can("cashboxes.update") && <Button variant="secondary" className="!px-3 !py-1.5" aria-label="Tahrirlash" onClick={() => openForm({ id: x.id, code: x.code, name: x.name, branch_id: x.branch_id ? String(x.branch_id) : "", is_active: x.is_active })}><Pencil size={14} /></Button>}
                                                    {can("cashboxes.delete") && x.is_active && <Button variant="secondary" className="!px-3 !py-1.5 text-red-600" aria-label="Faolsizlantirish" onClick={() => setToDeactivate(x)}><Power size={14} /></Button>}
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                    {!rows?.data.length && <tr><td colSpan={7} className="px-5 py-12 text-center text-slate-400">Kassa topilmadi.</td></tr>}
                                </tbody>
                            </table>
                        </div>
                    )}
                    {rows && <Pagination page={rows.current_page} lastPage={rows.last_page} total={rows.total} onPage={setPage} />}
                </div>
            </div>

            <Modal open={!!form} title={form?.id ? "Kassani tahrirlash" : "Yangi kassa"} onClose={() => setForm(null)}>
                {form && (
                    <form onSubmit={save} className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <Field label="Kassa kodi" error={fieldErrors.code?.[0]}><input required value={form.code} onChange={(e) => setForm({ ...form, code: e.target.value })} className={inputCls} placeholder="KS-002" /></Field>
                        <Field label="Nomi" error={fieldErrors.name?.[0]}><input required value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} className={inputCls} /></Field>
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

            <Modal open={!!history} wide title={`Kassa tarixi — ${history?.name ?? ""}`} onClose={() => setHistory(null)}>
                {history && <TransactionsTable query={{ cashbox_id: history.id }} />}
            </Modal>

            <CashOperationModal open={!!op} cashboxes={activeBoxes} defaultCashboxId={op?.cashbox.id} defaultDirection={op?.direction}
                onClose={() => setOp(null)} onDone={(m) => { setOp(null); setNotice(m); load(); }} />

            <ConfirmDialog open={!!toDeactivate} title="Kassani faolsizlantirish" loading={saving} confirmLabel="Faolsizlantirish"
                message={`“${toDeactivate?.name ?? ""}” faol emas holatga o‘tkaziladi. Ochiq smena yoki naqd pul qoldig‘i bo‘lsa, amal rad etiladi.`}
                onConfirm={deactivate} onCancel={() => setToDeactivate(null)} />
        </AppShell>
    );
}
