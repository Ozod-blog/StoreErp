"use client";
import { FormEvent, useCallback, useEffect, useState } from "react";
import { Plus, Pencil, Power, Building2, RefreshCw } from "lucide-react";
import AppShell from "../../components/AppShell";
import { Alert, Button, ConfirmDialog, Field, Modal, Pagination, Spinner, StatusBadge, inputCls, useDebounce } from "../../components/ui";
import { api, errorMessage, ApiError, Paginated } from "../lib/api";
import { useAuth } from "../lib/auth";
import InsightsPanel from "../../components/InsightsPanel";

type Branch = {
  id: number; code: string | null; name: string; address: string | null; phone: string | null;
  manager_name: string | null; is_active: boolean; warehouses_count?: number; active_warehouses_count?: number;
};
type FormState = { id?: number; code: string; name: string; address: string; phone: string; manager_name: string; is_active: boolean };
const EMPTY: FormState = { code: "", name: "", address: "", phone: "", manager_name: "", is_active: true };

export default function BranchesPage() {
  const { can } = useAuth();
  const [rows, setRows] = useState<Paginated<Branch> | null>(null);
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
  const [toDeactivate, setToDeactivate] = useState<Branch | null>(null);

  const load = useCallback(async () => {
    setLoading(true); setError("");
    try { setRows(await api<Paginated<Branch>>("/branches", { query: { page, search: debounced, status } })); }
    catch (e) { setError(errorMessage(e)); }
    finally { setLoading(false); }
  }, [page, debounced, status]);
  useEffect(() => { void load(); }, [load]);
  useEffect(() => { setPage(1); }, [debounced, status]);

  async function save(e: FormEvent) {
    e.preventDefault(); if (!form) return;
    setSaving(true); setFieldErrors({}); setError("");
    const body = { code: form.code, name: form.name, address: form.address || null, phone: form.phone || null, manager_name: form.manager_name || null, is_active: form.is_active };
    try {
      await api(form.id ? `/branches/${form.id}` : "/branches", { method: form.id ? "PATCH" : "POST", body });
      setNotice(form.id ? "Filial ma’lumotlari yangilandi." : "Yangi filial yaratildi."); setForm(null); await load();
    } catch (err) {
      if (err instanceof ApiError && err.status === 422) setFieldErrors(err.errors);
      setError(errorMessage(err));
    } finally { setSaving(false); }
  }

  async function deactivate() {
    if (!toDeactivate) return;
    setSaving(true); setError("");
    try { await api(`/branches/${toDeactivate.id}`, { method: "DELETE" }); setNotice("Filial faol emas holatga o‘tkazildi."); await load(); }
    catch (err) { setError(errorMessage(err)); }
    finally { setToDeactivate(null); setSaving(false); }
  }

  return (
    <AppShell title="Filiallar" subtitle="Filiallarni yaratish va do‘kon infratuzilmasini filiallar bo‘yicha boshqarish"
      permission="branches.view" actions={can("branches.create") && <Button onClick={() => { setFieldErrors({}); setForm(EMPTY); }}><Plus size={16} />Filial yaratish</Button>}>
      <div className="space-y-4">
        {notice && <Alert kind="success">{notice}</Alert>}
        {error && <Alert>{error}</Alert>}
                <InsightsPanel endpoint="/stats/branches" />
        <div className="flex flex-wrap gap-3">
          <input value={search} onChange={e => setSearch(e.target.value)} placeholder="Filial nomi, kodi, manzili yoki telefoni" className={`${inputCls} min-w-[240px] max-w-md`} />
          <select value={status} onChange={e => setStatus(e.target.value)} className={`${inputCls} max-w-[180px]`}>
            <option value="">Barcha holatlar</option><option value="active">Faol</option><option value="inactive">Faol emas</option>
          </select>
          <Button variant="secondary" onClick={() => void load()}><RefreshCw size={15} />Yangilash</Button>
        </div>
        <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white">
          {loading && !rows ? <Spinner /> : <div className="overflow-x-auto">
            <table className="w-full text-left text-sm">
              <thead className="bg-slate-50 text-xs uppercase text-slate-500"><tr>
                <th className="px-5 py-3">Kodi</th><th className="px-5 py-3">Filial nomi</th><th className="px-5 py-3">Manzil</th><th className="px-5 py-3">Telefon</th><th className="px-5 py-3">Mas’ul</th><th className="px-5 py-3">Omborlar</th><th className="px-5 py-3">Holat</th><th className="px-5 py-3 text-right">Amallar</th>
              </tr></thead>
              <tbody className="divide-y">
                {rows?.data.map(x => <tr key={x.id}>
                  <td className="px-5 py-3 font-medium">{x.code || `BR-${String(x.id).padStart(4, "0")}`}</td>
                  <td className="px-5 py-3"><div className="flex items-center gap-2"><Building2 size={16} className="text-slate-400" />{x.name}</div></td>
                  <td className="px-5 py-3">{x.address || "—"}</td><td className="px-5 py-3">{x.phone || "—"}</td><td className="px-5 py-3">{x.manager_name || "—"}</td>
                  <td className="px-5 py-3">{x.active_warehouses_count ?? 0} faol / {x.warehouses_count ?? 0} jami</td>
                  <td className="px-5 py-3"><StatusBadge status={x.is_active ? "active" : "inactive"} /></td>
                  <td className="px-5 py-3 text-right"><div className="flex justify-end gap-2">
                    {can("branches.update") && <Button variant="secondary" className="!px-3 !py-1.5" onClick={() => { setFieldErrors({}); setForm({ id: x.id, code: x.code || `BR-${String(x.id).padStart(4, "0")}`, name: x.name, address: x.address || "", phone: x.phone || "", manager_name: x.manager_name || "", is_active: x.is_active }); }}><Pencil size={14} />Tahrirlash</Button>}
                    {can("branches.delete") && x.is_active && <Button variant="secondary" className="!px-3 !py-1.5 text-red-600" onClick={() => setToDeactivate(x)}><Power size={14} />Faolsizlantirish</Button>}
                  </div></td>
                </tr>)}
                {!rows?.data.length && <tr><td colSpan={8} className="px-5 py-12 text-center text-slate-400">Filiallar topilmadi. «Filial yaratish» tugmasi orqali birinchi filialni qo‘shing.</td></tr>}
              </tbody>
            </table>
          </div>}
          {rows && <Pagination page={rows.current_page} lastPage={rows.last_page} total={rows.total} onPage={setPage} />}
        </div>
        <p className="text-xs text-slate-500">Filialni butunlay o‘chirish o‘rniga faolsizlantirish qo‘llanadi. Faol ombor, kassa yoki bank hisobi biriktirilgan filialni avval ulardan ajratish kerak.</p>
      </div>

      <Modal open={!!form} title={form?.id ? "Filialni tahrirlash" : "Yangi filial yaratish"} onClose={() => !saving && setForm(null)}>
        {form && <form onSubmit={save} className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <Field label="Filial kodi" error={fieldErrors.code?.[0]}><input required maxLength={30} value={form.code} onChange={e => setForm({ ...form, code: e.target.value.toUpperCase() })} className={inputCls} placeholder="BR-0001" /></Field>
          <Field label="Filial nomi" error={fieldErrors.name?.[0]}><input required maxLength={120} value={form.name} onChange={e => setForm({ ...form, name: e.target.value })} className={inputCls} placeholder="Toshkent filiali" /></Field>
          <Field label="Telefon" error={fieldErrors.phone?.[0]}><input maxLength={40} value={form.phone} onChange={e => setForm({ ...form, phone: e.target.value })} className={inputCls} placeholder="+998 ..." /></Field>
          <Field label="Mas’ul rahbar" error={fieldErrors.manager_name?.[0]}><input maxLength={120} value={form.manager_name} onChange={e => setForm({ ...form, manager_name: e.target.value })} className={inputCls} placeholder="F.I.Sh." /></Field>
          <div className="sm:col-span-2"><Field label="Manzil" error={fieldErrors.address?.[0]}><textarea maxLength={255} value={form.address} onChange={e => setForm({ ...form, address: e.target.value })} className={`${inputCls} min-h-20`} placeholder="Viloyat, shahar, ko‘cha..." /></Field></div>
          {form.id && <label className="flex items-center gap-2 text-sm sm:col-span-2"><input type="checkbox" checked={form.is_active} onChange={e => setForm({ ...form, is_active: e.target.checked })} />Filial faol</label>}
          <div className="flex justify-end gap-2 sm:col-span-2"><Button type="button" variant="secondary" onClick={() => setForm(null)} disabled={saving}>Bekor qilish</Button><Button type="submit" disabled={saving}>{saving ? "Saqlanmoqda…" : form.id ? "Saqlash" : "Filial yaratish"}</Button></div>
        </form>}
      </Modal>
      <ConfirmDialog open={!!toDeactivate} title="Filialni faolsizlantirish" message={`“${toDeactivate?.name ?? ""}” filialini faol emas holatga o‘tkazasizmi? Tarixiy hujjatlar saqlanadi.`} confirmLabel="Faolsizlantirish" onCancel={() => setToDeactivate(null)} onConfirm={deactivate} loading={saving} />
    </AppShell>
  );
}
