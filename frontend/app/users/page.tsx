"use client";
import { FormEvent, useCallback, useEffect, useState } from "react";
import { Plus, Pencil, UserX } from "lucide-react";
import AppShell from "../../components/AppShell";
import { Alert, Button, ConfirmDialog, Field, Modal, Pagination, Spinner, StatusBadge, inputCls, useDebounce } from "../../components/ui";
import { api, errorMessage, ApiError, Paginated } from "../lib/api";
import { AuthUser, Role, useAuth } from "../lib/auth";

type FormState = { id?: number; name: string; email: string; password: string; status: "active" | "inactive"; role_ids: number[] };
const EMPTY: FormState = { name: "", email: "", password: "", status: "active", role_ids: [] };

export default function UsersPage() {
  const { can, user: me } = useAuth();
  const [rows, setRows] = useState<Paginated<AuthUser> | null>(null);
  const [roles, setRoles] = useState<Role[]>([]);
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
  const [toDeactivate, setToDeactivate] = useState<AuthUser | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    setError("");
    try {
      setRows(await api<Paginated<AuthUser>>("/users", { query: { page, search: debounced, status } }));
    } catch (e) {
      setError(errorMessage(e));
    } finally {
      setLoading(false);
    }
  }, [page, debounced, status]);

  useEffect(() => { load(); }, [load]);
  useEffect(() => { setPage(1); }, [debounced, status]);
  useEffect(() => {
    api<Role[]>("/roles").then(setRoles).catch(() => setRoles([]));
  }, []);

  function openEdit(u: AuthUser) {
    setFieldErrors({});
    setForm({ id: u.id, name: u.name, email: u.email, password: "", status: u.status as "active" | "inactive", role_ids: u.roles.map((r) => r.id) });
  }

  async function save(e: FormEvent) {
    e.preventDefault();
    if (!form) return;
    setSaving(true);
    setFieldErrors({});
    const body: Record<string, unknown> = { name: form.name, email: form.email, status: form.status, role_ids: form.role_ids };
    if (form.password) body.password = form.password;
    try {
      await api(form.id ? `/users/${form.id}` : "/users", { method: form.id ? "PATCH" : "POST", body });
      setNotice(form.id ? "Foydalanuvchi yangilandi." : "Foydalanuvchi yaratildi.");
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
      await api(`/users/${toDeactivate.id}`, { method: "DELETE" });
      setNotice("Foydalanuvchi faol emas holatga o‘tkazildi.");
      setToDeactivate(null);
      await load();
    } catch (err) {
      setError(errorMessage(err));
      setToDeactivate(null);
    } finally {
      setSaving(false);
    }
  }

  const toggleRole = (id: number) =>
    setForm((f) => f && { ...f, role_ids: f.role_ids.includes(id) ? f.role_ids.filter((r) => r !== id) : [...f.role_ids, id] });

  return (
    <AppShell title="Foydalanuvchilar" subtitle="Tizimga kiruvchi hisoblar va ularning rollari" permission="users.view"
      actions={can("users.create") && <Button onClick={() => { setFieldErrors({}); setForm(EMPTY); }}><Plus size={16} />Yangi foydalanuvchi</Button>}>
      <div className="space-y-4">
        {notice && <Alert kind="success">{notice}</Alert>}
        {error && <Alert>{error}</Alert>}

        <div className="flex flex-wrap gap-3">
          <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Ism yoki email bo‘yicha qidirish" className={`${inputCls} max-w-xs`} />
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
                  <tr><th className="px-5 py-3">F.I.Sh.</th><th className="px-5 py-3">Email</th><th className="px-5 py-3">Rollar</th><th className="px-5 py-3">Holat</th><th className="px-5 py-3 text-right">Amallar</th></tr>
                </thead>
                <tbody className="divide-y">
                  {rows?.data.map((u) => (
                    <tr key={u.id}>
                      <td className="px-5 py-3 font-medium">{u.name}</td>
                      <td className="px-5 py-3 text-slate-600">{u.email}</td>
                      <td className="px-5 py-3 text-slate-600">{u.roles.map((r) => r.name).join(", ") || "—"}</td>
                      <td className="px-5 py-3"><StatusBadge status={u.status} /></td>
                      <td className="px-5 py-3 text-right">
                        <div className="flex justify-end gap-2">
                          {can("users.update") && <Button variant="secondary" className="!px-3 !py-1.5" onClick={() => openEdit(u)}><Pencil size={14} />Tahrirlash</Button>}
                          {can("users.delete") && u.status === "active" && u.id !== me?.id && (
                            <Button variant="secondary" className="!px-3 !py-1.5 text-red-600" onClick={() => setToDeactivate(u)}><UserX size={14} />Faolsizlantirish</Button>
                          )}
                        </div>
                      </td>
                    </tr>
                  ))}
                  {!rows?.data.length && <tr><td colSpan={5} className="px-5 py-12 text-center text-slate-400">Foydalanuvchi topilmadi.</td></tr>}
                </tbody>
              </table>
            </div>
          )}
          {rows && <Pagination page={rows.current_page} lastPage={rows.last_page} total={rows.total} onPage={setPage} />}
        </div>
      </div>

      <Modal open={!!form} title={form?.id ? "Foydalanuvchini tahrirlash" : "Yangi foydalanuvchi"} onClose={() => setForm(null)}>
        {form && (
          <form onSubmit={save} className="space-y-4">
            <Field label="F.I.Sh." error={fieldErrors.name?.[0]}>
              <input required value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} className={inputCls} />
            </Field>
            <Field label="Email" error={fieldErrors.email?.[0]}>
              <input required type="email" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} className={inputCls} />
            </Field>
            <Field label={form.id ? "Yangi parol (o‘zgartirmasangiz bo‘sh qoldiring)" : "Parol"} error={fieldErrors.password?.[0]}>
              <input type="password" autoComplete="new-password" required={!form.id} value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })} className={inputCls} />
              <p className="mt-1 text-xs font-normal text-slate-400">Kamida 10 belgi: katta va kichik harf hamda raqam.</p>
            </Field>
            <Field label="Holat">
              <select value={form.status} onChange={(e) => setForm({ ...form, status: e.target.value as "active" | "inactive" })} className={inputCls}>
                <option value="active">Faol</option>
                <option value="inactive">Faol emas</option>
              </select>
            </Field>
            <fieldset>
              <legend className="mb-2 text-sm font-medium text-slate-700">Rollar</legend>
              <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                {roles.map((r) => (
                  <label key={r.id} className="flex items-center gap-2 rounded-lg border border-slate-200 px-3 py-2 text-sm">
                    <input type="checkbox" checked={form.role_ids.includes(r.id)} onChange={() => toggleRole(r.id)} />{r.name}
                  </label>
                ))}
              </div>
            </fieldset>
            <div className="flex justify-end gap-2 pt-2">
              <Button type="button" variant="secondary" onClick={() => setForm(null)}>Bekor qilish</Button>
              <Button type="submit" loading={saving}>Saqlash</Button>
            </div>
          </form>
        )}
      </Modal>

      <ConfirmDialog open={!!toDeactivate} title="Foydalanuvchini faolsizlantirish" loading={saving} confirmLabel="Faolsizlantirish"
        message={`${toDeactivate?.name ?? ""} tizimga kira olmaydi va barcha sessiyalari yopiladi. Tarixiy ma’lumotlar saqlanadi.`}
        onConfirm={deactivate} onCancel={() => setToDeactivate(null)} />
    </AppShell>
  );
}
