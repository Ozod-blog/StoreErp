"use client";
import { useEffect, useState } from "react";
import AppShell from "../../components/AppShell";
import { Alert, Button, Spinner } from "../../components/ui";
import { api, errorMessage } from "../lib/api";
import { useAuth } from "../lib/auth";

type Perm = { id: number; name: string; slug: string };
type RoleFull = { id: number; name: string; slug: string; description: string | null; users_count: number; permissions: Perm[] };
type Group = { module: string; label: string; permissions: Perm[] };

export default function RolesPage() {
  const { can } = useAuth();
  const [roles, setRoles] = useState<RoleFull[]>([]);
  const [groups, setGroups] = useState<Group[]>([]);
  const [selected, setSelected] = useState<number | null>(null);
  const [checked, setChecked] = useState<Set<string>>(new Set());
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");

  async function load(keep?: number) {
    try {
      const [r, g] = await Promise.all([api<RoleFull[]>("/roles"), api<Group[]>("/permissions")]);
      setRoles(r);
      setGroups(g);
      const id = keep ?? r[0]?.id ?? null;
      setSelected(id);
      setChecked(new Set(r.find((x) => x.id === id)?.permissions.map((p) => p.slug) ?? []));
    } catch (e) {
      setError(errorMessage(e));
    } finally {
      setLoading(false);
    }
  }
  useEffect(() => { load(); }, []);

  const role = roles.find((r) => r.id === selected);
  const locked = role?.slug === "admin" || !can("roles.update");

  function select(id: number) {
    setNotice("");
    setSelected(id);
    setChecked(new Set(roles.find((r) => r.id === id)?.permissions.map((p) => p.slug) ?? []));
  }

  function toggle(slug: string) {
    setChecked((prev) => {
      const next = new Set(prev);
      next.has(slug) ? next.delete(slug) : next.add(slug);
      return next;
    });
  }

  async function save() {
    if (!role) return;
    setSaving(true);
    setError("");
    try {
      await api(`/roles/${role.id}/permissions`, { method: "PUT", body: { permissions: Array.from(checked) } });
      setNotice(`“${role.name}” roli ruxsatlari saqlandi.`);
      await load(role.id);
    } catch (e) {
      setError(errorMessage(e));
    } finally {
      setSaving(false);
    }
  }

  return (
    <AppShell title="Rollar va ruxsatlar" subtitle="Har bir rol qaysi amallarni bajara olishini belgilang" permission="roles.view">
      {loading ? <Spinner /> : (
        <div className="space-y-4">
          {notice && <Alert kind="success">{notice}</Alert>}
          {error && <Alert>{error}</Alert>}
          <div className="grid gap-5 lg:grid-cols-[260px_1fr]">
            <div className="space-y-2">
              {roles.map((r) => (
                <button key={r.id} onClick={() => select(r.id)}
                  className={`w-full rounded-xl border px-4 py-3 text-left text-sm ${r.id === selected ? "border-blue-500 bg-blue-50" : "border-slate-200 bg-white hover:bg-slate-50"}`}>
                  <div className="font-semibold text-slate-900">{r.name}</div>
                  <div className="text-xs text-slate-500">{r.users_count} foydalanuvchi · {r.permissions.length} ruxsat</div>
                </button>
              ))}
            </div>

            {role && (
              <div className="rounded-2xl border border-slate-200 bg-white p-5">
                <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                  <div>
                    <h2 className="font-semibold text-slate-900">{role.name}</h2>
                    <p className="text-xs text-slate-500">{role.description}</p>
                    {role.slug === "admin" && <p className="mt-1 text-xs text-amber-600">Administrator roli doim barcha ruxsatlarga ega va o‘zgartirilmaydi.</p>}
                  </div>
                  {!locked && <Button loading={saving} onClick={save}>Saqlash</Button>}
                </div>
                <div className="space-y-5">
                  {groups.map((g) => (
                    <div key={g.module}>
                      <div className="mb-2 text-sm font-semibold text-slate-700">{g.label}</div>
                      <div className="grid gap-2 sm:grid-cols-2">
                        {g.permissions.map((p) => (
                          <label key={p.slug} className="flex items-center gap-2 text-sm text-slate-600">
                            <input type="checkbox" disabled={locked} checked={role.slug === "admin" || checked.has(p.slug)} onChange={() => toggle(p.slug)} />
                            {p.name.split(" — ")[1] ?? p.name}
                          </label>
                        ))}
                      </div>
                    </div>
                  ))}
                </div>
              </div>
            )}
          </div>
        </div>
      )}
    </AppShell>
  );
}
