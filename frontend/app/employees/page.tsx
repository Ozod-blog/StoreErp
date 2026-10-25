"use client";
import { FormEvent, useCallback, useEffect, useState } from "react";
import { Plus, Pencil, UserX } from "lucide-react";
import AppShell from "../../components/AppShell";
import { Alert, Button, ConfirmDialog, Field, Modal, Pagination, Spinner, StatusBadge, inputCls, useDebounce } from "../../components/ui";
import { api, errorMessage, ApiError, Paginated } from "../lib/api";
import { formatDate, formatUZS } from "../lib/format";
import { useAuth } from "../lib/auth";

type Employee = {
    id: number; user_id: number; employee_code: string; phone: string | null; position: string;
    hire_date: string | null; salary: string | null; status: "active" | "inactive" | "leave"; notes: string | null;
    user?: { id: number; name: string; email: string };
};
type Option = { id: number; name: string; email: string };
type FormState = { id?: number; user_id: string; employee_code: string; phone: string; position: string; hire_date: string; salary: string; status: string; notes: string };
const EMPTY: FormState = { user_id: "", employee_code: "", phone: "", position: "", hire_date: "", salary: "", status: "active", notes: "" };

export default function EmployeesPage() {
    const { can } = useAuth();
    const [rows, setRows] = useState<Paginated<Employee> | null>(null);
    const [page, setPage] = useState(1);
    const [search, setSearch] = useState("");
    const [status, setStatus] = useState("");
    const debounced = useDebounce(search);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState("");
    const [notice, setNotice] = useState("");
    const [form, setForm] = useState<FormState | null>(null);
    const [users, setUsers] = useState<Option[]>([]);
    const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});
    const [saving, setSaving] = useState(false);
    const [toDeactivate, setToDeactivate] = useState<Employee | null>(null);

    const load = useCallback(async () => {
        setLoading(true);
        setError("");
        try {
            setRows(await api<Paginated<Employee>>("/employees", { query: { page, search: debounced, status } }));
        } catch (e) {
            setError(errorMessage(e));
        } finally {
            setLoading(false);
        }
    }, [page, debounced, status]);

    useEffect(() => { load(); }, [load]);
    useEffect(() => { setPage(1); }, [debounced, status]);

    async function openCreate() {
        setFieldErrors({});
        setForm(EMPTY);
        try {
            setUsers(await api<Option[]>("/employees/available-users"));
        } catch (e) {
            setError(errorMessage(e));
        }
    }

    function openEdit(x: Employee) {
        setFieldErrors({});
        setForm({
            id: x.id, user_id: String(x.user_id), employee_code: x.employee_code, phone: x.phone ?? "", position: x.position,
            hire_date: x.hire_date ? x.hire_date.slice(0, 10) : "", salary: x.salary ?? "", status: x.status, notes: x.notes ?? "",
        });
    }

    async function save(e: FormEvent) {
        e.preventDefault();
        if (!form) return;
        setSaving(true);
        setFieldErrors({});
        const body: Record<string, unknown> = {
            employee_code: form.employee_code, position: form.position, status: form.status,
            phone: form.phone || null, hire_date: form.hire_date || null, salary: form.salary === "" ? null : Number(form.salary), notes: form.notes || null,
        };
        if (!form.id) body.user_id = Number(form.user_id);
        try {
            await api(form.id ? `/employees/${form.id}` : "/employees", { method: form.id ? "PATCH" : "POST", body });
            setNotice(form.id ? "Xodim ma’lumotlari yangilandi." : "Xodim qo‘shildi.");
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
        try {
            await api(`/employees/${toDeactivate.id}`, { method: "DELETE" });
            setNotice("Xodim faol emas holatga o‘tkazildi.");
            await load();
        } catch (err) {
            setError(errorMessage(err));
        } finally {
            setToDeactivate(null);
            setSaving(false);
        }
    }

    const set = (k: keyof FormState) => (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>) =>
        setForm((f) => f && { ...f, [k]: e.target.value });

    return (
        <AppShell title="Xodimlar" subtitle="Xodim kartochkalari va ish holati" permission="employees.view"
            actions={can("employees.create") && <Button onClick={openCreate}><Plus size={16} />Xodim qo‘shish</Button>}>
            <div className="space-y-4">
                {notice && <Alert kind="success">{notice}</Alert>}
                {error && <Alert>{error}</Alert>}
                <div className="flex flex-wrap gap-3">
                    <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Ism, kod, telefon yoki lavozim" className={`${inputCls} max-w-xs`} />
                    <select value={status} onChange={(e) => setStatus(e.target.value)} className={`${inputCls} max-w-[180px]`}>
                        <option value="">Barcha holatlar</option>
                        <option value="active">Faol</option>
                        <option value="leave">Ta’tilda</option>
                        <option value="inactive">Faol emas</option>
                    </select>
                </div>

                <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white">
                    {loading && !rows ? <Spinner /> : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-sm">
                                <thead className="bg-slate-50 text-xs uppercase text-slate-500">
                                    <tr><th className="px-5 py-3">Kod</th><th className="px-5 py-3">F.I.Sh.</th><th className="px-5 py-3">Lavozim</th><th className="px-5 py-3">Telefon</th><th className="px-5 py-3">Ishga qabul</th><th className="px-5 py-3">Oylik</th><th className="px-5 py-3">Holat</th><th className="px-5 py-3 text-right">Amallar</th></tr>
                                </thead>
                                <tbody className="divide-y">
                                    {rows?.data.map((x) => (
                                        <tr key={x.id}>
                                            <td className="px-5 py-3 font-medium">{x.employee_code}</td>
                                            <td className="px-5 py-3">{x.user?.name}<div className="text-xs text-slate-400">{x.user?.email}</div></td>
                                            <td className="px-5 py-3">{x.position}</td>
                                            <td className="px-5 py-3">{x.phone || "—"}</td>
                                            <td className="px-5 py-3">{formatDate(x.hire_date)}</td>
                                            <td className="px-5 py-3">{formatUZS(x.salary)}</td>
                                            <td className="px-5 py-3"><StatusBadge status={x.status} /></td>
                                            <td className="px-5 py-3 text-right">
                                                <div className="flex justify-end gap-2">
                                                    {can("employees.update") && <Button variant="secondary" className="!px-3 !py-1.5" onClick={() => openEdit(x)}><Pencil size={14} />Tahrirlash</Button>}
                                                    {can("employees.delete") && x.status !== "inactive" && <Button variant="secondary" className="!px-3 !py-1.5 text-red-600" onClick={() => setToDeactivate(x)}><UserX size={14} /></Button>}
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                    {!rows?.data.length && <tr><td colSpan={8} className="px-5 py-12 text-center text-slate-400">Xodim topilmadi.</td></tr>}
                                </tbody>
                            </table>
                        </div>
                    )}
                    {rows && <Pagination page={rows.current_page} lastPage={rows.last_page} total={rows.total} onPage={setPage} />}
                </div>
            </div>

            <Modal open={!!form} title={form?.id ? "Xodimni tahrirlash" : "Yangi xodim"} onClose={() => setForm(null)}>
                {form && (
                    <form onSubmit={save} className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        {!form.id && (
                            <div className="sm:col-span-2">
                                <Field label="Foydalanuvchi hisobi" error={fieldErrors.user_id?.[0]}>
                                    <select required value={form.user_id} onChange={set("user_id")} className={inputCls}>
                                        <option value="">Tanlang...</option>
                                        {users.map((u) => <option key={u.id} value={u.id}>{u.name} ({u.email})</option>)}
                                    </select>
                                    {!users.length && <p className="mt-1 text-xs font-normal text-slate-400">Bo‘sh hisob yo‘q — avval “Foydalanuvchilar” bo‘limida hisob yarating.</p>}
                                </Field>
                            </div>
                        )}
                        <Field label="Xodim kodi" error={fieldErrors.employee_code?.[0]}><input required value={form.employee_code} onChange={set("employee_code")} className={inputCls} placeholder="EMP-001" /></Field>
                        <Field label="Lavozim" error={fieldErrors.position?.[0]}><input required value={form.position} onChange={set("position")} className={inputCls} /></Field>
                        <Field label="Telefon" error={fieldErrors.phone?.[0]}><input value={form.phone} onChange={set("phone")} className={inputCls} placeholder="+998 90 123 45 67" /></Field>
                        <Field label="Ishga qabul sanasi" error={fieldErrors.hire_date?.[0]}><input type="date" value={form.hire_date} onChange={set("hire_date")} className={inputCls} /></Field>
                        <Field label="Oylik (so‘m)" error={fieldErrors.salary?.[0]}><input type="number" min="0" value={form.salary} onChange={set("salary")} className={inputCls} /></Field>
                        <Field label="Holat">
                            <select value={form.status} onChange={set("status")} className={inputCls}>
                                <option value="active">Faol</option><option value="leave">Ta’tilda</option><option value="inactive">Faol emas</option>
                            </select>
                        </Field>
                        <div className="sm:col-span-2"><Field label="Izoh" error={fieldErrors.notes?.[0]}><textarea rows={2} value={form.notes} onChange={set("notes")} className={inputCls} /></Field></div>
                        <div className="flex justify-end gap-2 sm:col-span-2">
                            <Button type="button" variant="secondary" onClick={() => setForm(null)}>Bekor qilish</Button>
                            <Button type="submit" loading={saving}>Saqlash</Button>
                        </div>
                    </form>
                )}
            </Modal>

            <ConfirmDialog open={!!toDeactivate} title="Xodimni faolsizlantirish" loading={saving} confirmLabel="Faolsizlantirish"
                message={`${toDeactivate?.user?.name ?? "Xodim"} faol emas holatga o‘tkaziladi. Tarixiy ma’lumotlar saqlanadi.`}
                onConfirm={deactivate} onCancel={() => setToDeactivate(null)} />
        </AppShell>
    );
}
