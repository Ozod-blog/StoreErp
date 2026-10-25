"use client";
import { useEffect, useState } from "react";
import { ShieldCheck, LockKeyhole, Copy, KeyRound } from "lucide-react";
import { QRCodeSVG } from "qrcode.react";
import AppShell from "../../../components/AppShell";
import { api, errorMessage } from "../../lib/api";
import { Alert, Button, Field, inputCls } from "../../../components/ui";
import InsightsPanel from "../../../components/InsightsPanel";

type SetupResponse = { secret: string; otpauth_url: string; message: string };
type ConfirmResponse = { enabled: boolean; recovery_codes: string[]; message: string };

export default function SecurityPage() {
  const [setup, setSetup] = useState<SetupResponse | null>(null);
  const [enabled, setEnabled] = useState(false);
  const [code, setCode] = useState("");
  const [password, setPassword] = useState("");
  const [disableCode, setDisableCode] = useState("");
  const [recoveryCodes, setRecoveryCodes] = useState<string[]>([]);
  const [message, setMessage] = useState("");
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);
  useEffect(() => { api<{ two_factor_enabled?: boolean }>("/auth/me").then(r => setEnabled(!!r.two_factor_enabled)).catch(() => undefined); }, []);

  async function beginSetup() {
    setError(""); setMessage(""); setBusy(true);
    try { setSetup(await api<SetupResponse>("/auth/two-factor/setup", { method: "POST", body: {} })); }
    catch (e) { setError(errorMessage(e)); } finally { setBusy(false); }
  }
  async function confirmSetup() {
    setError(""); setMessage(""); setBusy(true);
    try { const r = await api<ConfirmResponse>("/auth/two-factor/confirm", { method: "POST", body: { code } }); setEnabled(r.enabled); setRecoveryCodes(r.recovery_codes); setSetup(null); setCode(""); setMessage(r.message); }
    catch (e) { setError(errorMessage(e)); } finally { setBusy(false); }
  }
  async function disable() {
    setError(""); setMessage(""); setBusy(true);
    try { await api("/auth/two-factor/disable", { method: "POST", body: { password, code: disableCode } }); setEnabled(false); setRecoveryCodes([]); setPassword(""); setDisableCode(""); setMessage("Ikki bosqichli himoya o‘chirildi."); }
    catch (e) { setError(errorMessage(e)); } finally { setBusy(false); }
  }
  async function copyCodes() { await navigator.clipboard.writeText(recoveryCodes.join("\n")); setMessage("Tiklash kodlari nusxalandi. Ularni xavfsiz joyda saqlang."); }

  return <AppShell title="Xavfsizlik" subtitle="Ikki bosqichli tasdiqlash va hisob himoyasi">
    <div className="mb-4"><InsightsPanel endpoint="/stats/security" /></div>
    <div className="mx-auto max-w-3xl space-y-4">
      {error && <Alert>{error}</Alert>}{message && <div className="rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800">{message}</div>}
      <section className="rounded-2xl border border-slate-200 bg-white p-5">
        <div className="flex gap-3"><ShieldCheck className="text-blue-700" /><div className="flex-1"><h2 className="font-semibold">Ikki bosqichli tasdiqlash (2FA)</h2><p className="mt-1 text-sm text-slate-600">Authenticator ilovasi orqali 30 soniyada yangilanadigan kod bilan hisobingizni himoyalang.</p>
          <div className="mt-4 flex items-center gap-2 text-sm"><span className={`h-2.5 w-2.5 rounded-full ${enabled ? "bg-emerald-500" : "bg-slate-300"}`} />{enabled ? "2FA yoqilgan" : "2FA o‘chiq"}</div>
          {!enabled && !setup && <Button className="mt-4" loading={busy} onClick={beginSetup}>2FA ni yoqish</Button>}
          {setup && <div className="mt-4 space-y-3 rounded-xl bg-slate-50 p-4"><div className="flex flex-col items-center gap-3 rounded-xl border border-slate-200 bg-white p-4"><QRCodeSVG value={setup.otpauth_url} size={220} level="M" includeMargin aria-label="Authenticator uchun Store ERP 2FA QR kodi" /><p className="text-center text-sm text-slate-600">Google Authenticator yoki Microsoft Authenticator ilovasini ochib, QR-kodni skaner qiling.</p></div><p className="text-sm">Kamera bilan skaner qilib bo‘lmasa, ilovada “Setup key” / “Kalitni qo‘lda kiritish”ni tanlang. Maxfiy kalitni hech kimga yubormang.</p><div className="break-all rounded-lg border bg-white p-3 font-mono text-sm">{setup.secret}</div><Field label="Authenticator ilovasidagi 6 xonali kod"><input value={code} onChange={e => setCode(e.target.value.replace(/\D/g, "").slice(0, 6))} inputMode="numeric" autoComplete="one-time-code" maxLength={6} placeholder="123456" className={inputCls} /></Field><Button loading={busy} disabled={code.length !== 6} onClick={confirmSetup}>Kodni tekshirish va 2FA ni yoqish</Button></div>}
          {enabled && <div className="mt-4 space-y-3"><Field label="Hisob paroli"><input type="password" value={password} onChange={e => setPassword(e.target.value)} autoComplete="current-password" className={inputCls} /></Field><Field label="Authenticator kodi"><input value={disableCode} onChange={e => setDisableCode(e.target.value)} inputMode="numeric" className={inputCls} /></Field><Button variant="secondary" loading={busy} onClick={disable}>2FA ni o‘chirish</Button></div>}
        </div></div>
      </section>
      {recoveryCodes.length > 0 && <section className="rounded-2xl border border-amber-200 bg-amber-50 p-5"><div className="flex items-center justify-between gap-3"><h2 className="font-semibold text-amber-950"><KeyRound className="mr-2 inline" size={18} />Tiklash kodlari</h2><Button variant="secondary" onClick={copyCodes}><Copy size={16} className="mr-2" />Nusxalash</Button></div><p className="my-3 text-sm text-amber-900">Har bir kod faqat bir marta ishlatiladi. Kodlarni xavfsiz joyga saqlang. Bu oynadan chiqib ketsangiz, kodlar qayta ko‘rsatilmaydi.</p><div className="grid grid-cols-2 gap-2 sm:grid-cols-3">{recoveryCodes.map(x => <code key={x} className="rounded border border-amber-200 bg-white p-2 text-center">{x}</code>)}</div></section>}
      <section className="rounded-2xl border border-slate-200 bg-white p-5"><div className="flex gap-3"><LockKeyhole className="text-blue-700" /><div><h2 className="font-semibold">Qo‘shimcha himoya</h2><p className="mt-1 text-sm text-slate-600">Login urinishlari cheklanadi, faol API sessiyalarini boshqarish esa “Sessiyalar” sahifasida mavjud. Serverga kirish va maxfiy kalitlar uchun `.env` faylini repozitoriyga yuklamang.</p></div></div></section>
    </div>
  </AppShell>;
}
