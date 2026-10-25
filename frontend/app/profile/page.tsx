"use client";
import { FormEvent, ReactNode, useEffect, useState } from "react";
import { api, errorMessage } from "../lib/api";
import { useAuth } from "../lib/auth";
import AppShell from "../../components/AppShell";
import { Activity, CalendarDays, CheckCircle2, Clock3, KeyRound, Mail, ShieldCheck, UserRound, XCircle } from "lucide-react";

type ProfileData = {
  user: { id: number; name: string; email: string; status: string; roles: { id: number; name: string; slug: string }[]; created_at?: string; last_login_at?: string | null };
  permissions: string[];
  two_factor_enabled: boolean;
  created_at?: string;
  last_login_at?: string | null;
  activity: { id: number; action: string; result: string; created_at: string; auditable_type?: string | null; auditable_id?: number | null }[];
};

const dateText = (value?: string | null) => value ? new Date(value).toLocaleString("uz-UZ", { dateStyle: "medium", timeStyle: "short" }) : "Ma’lumot yo‘q";
const actionText = (value: string) => value.replace(/[._]/g, " ").replace(/\b\w/g, (c) => c.toUpperCase());

export default function ProfilePage() {
  const { user } = useAuth();
  const [data, setData] = useState<ProfileData | null>(null);
  const [loading, setLoading] = useState(true);
  const [name, setName] = useState("");
  const [newEmail, setNewEmail] = useState("");
  const [emailCode, setEmailCode] = useState("");
  const [emailCodeSent, setEmailCodeSent] = useState(false);
  const [profileSaving, setProfileSaving] = useState(false);
  const [currentPassword, setCurrentPassword] = useState("");
  const [password, setPassword] = useState("");
  const [passwordConfirmation, setPasswordConfirmation] = useState("");
  const [saving, setSaving] = useState(false);
  const [notice, setNotice] = useState<{ type: "success" | "error"; text: string } | null>(null);

  useEffect(() => { api<ProfileData>("/auth/profile").then((d) => { setData(d); setName(d.user.name); setNewEmail(d.user.email); }).catch((e) => setNotice({ type: "error", text: errorMessage(e) })).finally(() => setLoading(false)); }, []);

  async function saveProfile(e: FormEvent) {
    e.preventDefault(); setNotice(null); setProfileSaving(true);
    try {
      const r = await api<{ message: string; user: ProfileData["user"] }>("/auth/profile", { method: "PUT", body: { name } });
      setData((old) => old ? { ...old, user: { ...old.user, ...r.user } } : old);
      setNotice({ type: "success", text: r.message });
    } catch (e) { setNotice({ type: "error", text: errorMessage(e) }); }
    finally { setProfileSaving(false); }
  }

  async function sendEmailCode(e: FormEvent) {
    e.preventDefault(); setNotice(null); setSaving(true);
    try {
      const r = await api<{ message: string }>("/auth/profile/email-code", { method: "POST", body: { email: newEmail } });
      setEmailCodeSent(true); setEmailCode(""); setNotice({ type: "success", text: r.message });
    } catch (e) { setNotice({ type: "error", text: errorMessage(e) }); }
    finally { setSaving(false); }
  }

  async function confirmEmail(e: FormEvent) {
    e.preventDefault(); setNotice(null); setSaving(true);
    try {
      const r = await api<{ message: string; user: ProfileData["user"] }>("/auth/profile/confirm-email", { method: "POST", body: { code: emailCode } });
      setData((old) => old ? { ...old, user: { ...old.user, ...r.user } } : old);
      setNewEmail(r.user.email); setEmailCode(""); setEmailCodeSent(false);
      setNotice({ type: "success", text: r.message });
    } catch (e) { setNotice({ type: "error", text: errorMessage(e) }); }
    finally { setSaving(false); }
  }

  async function changePassword(e: FormEvent) {
    e.preventDefault(); setNotice(null);
    if (password !== passwordConfirmation) { setNotice({ type: "error", text: "Yangi parollar bir xil emas." }); return; }
    setSaving(true);
    try {
      const r = await api<{ message: string }>("/auth/password", { method: "PUT", body: { current_password: currentPassword, password, password_confirmation: passwordConfirmation } });
      setNotice({ type: "success", text: r.message }); setCurrentPassword(""); setPassword(""); setPasswordConfirmation("");
      const fresh = await api<ProfileData>("/auth/profile"); setData(fresh);
    } catch (e) { setNotice({ type: "error", text: errorMessage(e) }); }
    finally { setSaving(false); }
  }

  return <AppShell title="Mening profilim" subtitle="Shaxsiy ma’lumotlar, tizimdagi faollik va xavfsizlik">
    <div className="grid gap-5 xl:grid-cols-[minmax(0,1fr)_minmax(320px,0.85fr)]">
      <div className="space-y-5">
        <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
          <div className="flex items-center gap-4 border-b border-slate-100 pb-5">
            <div className="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl bg-blue-100 text-blue-700"><UserRound size={27}/></div>
            <div className="min-w-0"><h2 className="text-xl font-bold text-slate-900">{data?.user.name || user?.name || "Foydalanuvchi"}</h2><p className="break-all text-sm text-slate-500">{data?.user.email || user?.email}</p></div>
            <span className={`ml-auto rounded-full px-3 py-1 text-xs font-semibold ${data?.user.status === "active" ? "bg-emerald-50 text-emerald-700" : "bg-amber-50 text-amber-700"}`}>{data?.user.status === "active" ? "Faol" : data?.user.status || "—"}</span>
          </div>
          <div className="grid gap-4 pt-5 sm:grid-cols-2">
            <Info icon={<Mail size={17}/>} label="Email" value={data?.user.email || user?.email || "—"}/>
            <Info icon={<ShieldCheck size={17}/>} label="Rol(lar)" value={data?.user.roles?.map(r => r.name).join(", ") || "Rol belgilanmagan"}/>
            <Info icon={<CalendarDays size={17}/>} label="Ro‘yxatdan o‘tgan" value={dateText(data?.created_at || data?.user.created_at)}/>
            <Info icon={<Clock3 size={17}/>} label="Oxirgi kirish" value={dateText(data?.last_login_at || data?.user.last_login_at)}/>
          </div>
        </section>

        <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
          <div className="mb-4 flex items-center gap-2"><UserRound size={20} className="text-blue-600"/><h2 className="text-lg font-bold text-slate-900">Shaxsiy ma’lumotlarni tahrirlash</h2></div>
          {notice && <div role="status" className={`mb-4 rounded-xl p-3 text-sm ${notice.type === "success" ? "bg-emerald-50 text-emerald-700" : "bg-rose-50 text-rose-700"}`}>{notice.text}</div>}
          <form onSubmit={saveProfile} className="space-y-4">
            <label className="block"><span className="mb-1.5 block text-sm font-medium text-slate-700">Ism va familiya</span><input required minLength={2} maxLength={120} value={name} onChange={e => setName(e.target.value)} className="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100" /></label>
            <button disabled={profileSaving} className="w-full rounded-xl bg-blue-600 px-4 py-3 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-60">{profileSaving ? "Saqlanmoqda…" : "Ismni saqlash"}</button>
          </form>
          <div className="my-5 border-t border-slate-100"/>
          <form onSubmit={emailCodeSent ? confirmEmail : sendEmailCode} className="space-y-4">
            <label className="block"><span className="mb-1.5 block text-sm font-medium text-slate-700">Email manzili</span><input required type="email" maxLength={254} value={newEmail} onChange={e => { setNewEmail(e.target.value); setEmailCodeSent(false); }} className="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100" /></label>
            {emailCodeSent && <label className="block"><span className="mb-1.5 block text-sm font-medium text-slate-700">Emailga yuborilgan 6 xonali kod</span><input required inputMode="numeric" pattern="[0-9]{6}" maxLength={6} value={emailCode} onChange={e => setEmailCode(e.target.value.replace(/\D/g, "").slice(0, 6))} className="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm tracking-[0.3em] text-slate-900 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100" /></label>}
            <p className="text-xs text-slate-500">Email manzili faqat yangi emailga yuborilgan kod tasdiqlangandan keyin almashtiriladi. Kod 5 daqiqa amal qiladi.</p>
            <button disabled={saving} className="w-full rounded-xl bg-slate-900 px-4 py-3 text-sm font-semibold text-white hover:bg-slate-800 disabled:opacity-60">{saving ? "Bajarilmoqda…" : emailCodeSent ? "Kodni tasdiqlash va emailni almashtirish" : "Tasdiqlash kodini yuborish"}</button>
          </form>
        </section>

        <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
          <div className="mb-4 flex items-center gap-2"><Activity size={20} className="text-blue-600"/><h2 className="text-lg font-bold text-slate-900">Tizimdagi faollik</h2></div>
          <p className="mb-4 text-sm text-slate-500">Rolingiz va ruxsatlaringiz doirasida o‘zingiz bajargan oxirgi amallar.</p>
          {loading ? <p className="py-6 text-sm text-slate-500">Yuklanmoqda…</p> : !data?.activity?.length ? <p className="rounded-xl bg-slate-50 p-4 text-sm text-slate-500">Hozircha faollik tarixi mavjud emas.</p> : <div className="space-y-3">{data.activity.map((item) => <div key={item.id} className="flex gap-3 rounded-xl border border-slate-100 p-3">
            <div className={`mt-0.5 ${item.result === "success" ? "text-emerald-600" : "text-rose-600"}`}>{item.result === "success" ? <CheckCircle2 size={18}/> : <XCircle size={18}/>}</div>
            <div className="min-w-0 flex-1"><div className="font-medium text-slate-800">{actionText(item.action)}</div><div className="mt-1 text-xs text-slate-500">{dateText(item.created_at)} · {item.result === "success" ? "Muvaffaqiyatli" : item.result === "failed" ? "Bajarilmadi" : item.result}</div></div>
          </div>)}</div>}
        </section>
      </div>

      <div className="space-y-5">
        <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
          <div className="mb-2 flex items-center gap-2"><KeyRound size={20} className="text-blue-600"/><h2 className="text-lg font-bold text-slate-900">Parolni almashtirish</h2></div>
          <p className="mb-5 text-sm text-slate-500">Xavfsizlik uchun joriy parolni kiriting. Yangi parol kamida 8 ta belgidan iborat bo‘lishi kerak.</p>
          {notice && <div role="status" className={`mb-4 rounded-xl p-3 text-sm ${notice.type === "success" ? "bg-emerald-50 text-emerald-700" : "bg-rose-50 text-rose-700"}`}>{notice.text}</div>}
          <form onSubmit={changePassword} className="space-y-4">
            <Field label="Joriy parol" value={currentPassword} setValue={setCurrentPassword}/>
            <Field label="Yangi parol" value={password} setValue={setPassword}/>
            <Field label="Yangi parolni takrorlang" value={passwordConfirmation} setValue={setPasswordConfirmation}/>
            <button disabled={saving} className="w-full rounded-xl bg-blue-600 px-4 py-3 text-sm font-semibold text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-60">{saving ? "Saqlanmoqda…" : "Yangi parolni saqlash"}</button>
          </form>
        </section>
        <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
          <h2 className="mb-3 text-lg font-bold text-slate-900">Ruxsatlaringiz</h2>
          <p className="mb-3 text-sm text-slate-500">Mavjud bo‘limlar sizga biriktirilgan rol va ruxsatlarga bog‘liq.</p>
          <div className="flex flex-wrap gap-2">{data?.permissions?.length ? data.permissions.map(p => <span key={p} className="rounded-lg bg-slate-100 px-2.5 py-1.5 text-xs font-medium text-slate-700">{p}</span>) : <span className="text-sm text-slate-500">Ruxsatlar yuklanmoqda yoki belgilanmagan.</span>}</div>
          <div className="mt-4 flex items-center gap-2 text-sm text-slate-600"><ShieldCheck size={17} className={data?.two_factor_enabled ? "text-emerald-600" : "text-slate-400"}/>Ikki bosqichli himoya: {data?.two_factor_enabled ? "Yoqilgan" : "O‘chirilgan"}</div>
        </section>
      </div>
    </div>
  </AppShell>;
}

function Info({ icon, label, value }: { icon: ReactNode; label: string; value: string }) { return <div className="flex gap-3"><span className="mt-0.5 text-slate-400">{icon}</span><div className="min-w-0"><div className="text-xs text-slate-500">{label}</div><div className="break-words text-sm font-medium text-slate-800">{value}</div></div></div>; }
function Field({ label, value, setValue }: { label: string; value: string; setValue: (v: string) => void }) { return <label className="block"><span className="mb-1.5 block text-sm font-medium text-slate-700">{label}</span><input type="password" autoComplete="current-password" required minLength={label === "Joriy parol" ? undefined : 8} value={value} onChange={e => setValue(e.target.value)} className="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 outline-none transition focus:border-blue-500 focus:ring-2 focus:ring-blue-100" /></label>; }
