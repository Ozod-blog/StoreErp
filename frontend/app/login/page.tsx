"use client";
import { FormEvent, useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { LockKeyhole, Store } from "lucide-react";
import { useAuth } from "../lib/auth";
import { api, errorMessage } from "../lib/api";
import { Alert, Button, Field, inputCls } from "../../components/ui";

export default function LoginPage() {
    const { user, loading: authLoading, login, twoFactorChallenge, verifyTwoFactor } = useAuth();
    const router = useRouter();
    const [email, setEmail] = useState("");
    const [password, setPassword] = useState("");
    const [error, setError] = useState("");
    const [loading, setLoading] = useState(false);
    const [code, setCode] = useState("");
    const [recoveryMode, setRecoveryMode] = useState(false);
    const [recoveryEmail, setRecoveryEmail] = useState("");
    const [recoveryCode, setRecoveryCode] = useState("");
    const [recoverySent, setRecoverySent] = useState(false);
    const [notice, setNotice] = useState("");
    const [passwordResetMode, setPasswordResetMode] = useState(false);
    const [passwordResetSent, setPasswordResetSent] = useState(false);
    const [passwordResetCode, setPasswordResetCode] = useState("");
    const [newPassword, setNewPassword] = useState("");
    const [newPasswordConfirmation, setNewPasswordConfirmation] = useState("");

    useEffect(() => {
        if (!authLoading && user) router.replace("/");
    }, [authLoading, user, router]);

    async function startEmailRecovery() {
        setError(""); setNotice(""); setLoading(true);
        try {
            const r = await api<{ message: string }>("/auth/two-factor/recovery/email/start", { method: "POST", body: { email: recoveryEmail.trim() || email.trim() } });
            setNotice(r.message); setRecoverySent(true);
        } catch (err) { setError(errorMessage(err)); } finally { setLoading(false); }
    }
    async function verifyEmailRecovery() {
        setError(""); setNotice(""); setLoading(true);
        try {
            const r = await api<{ message: string }>("/auth/two-factor/recovery/email/verify", { method: "POST", body: { email: recoveryEmail.trim() || email.trim(), code: recoveryCode } });
            setNotice(r.message); setRecoveryMode(false); setRecoverySent(false); setRecoveryCode("");
        } catch (err) { setError(errorMessage(err)); } finally { setLoading(false); }
    }

    async function requestPasswordReset() {
        setError(""); setNotice(""); setLoading(true);
        try {
            const r = await api<{ message: string }>("/auth/password/forgot", { method: "POST", body: { email: email.trim() } });
            setNotice(r.message); setPasswordResetSent(true);
        } catch (err) { setError(errorMessage(err)); } finally { setLoading(false); }
    }


    async function submitPasswordReset() {
        setError(""); setNotice("");
        if (newPassword !== newPasswordConfirmation) { setError("Yangi parollar bir xil emas."); return; }
        setLoading(true);
        try {
            const r = await api<{ message: string }>("/auth/password/reset", { method: "POST", body: { email: email.trim(), code: passwordResetCode, password: newPassword, password_confirmation: newPasswordConfirmation } });
            setNotice(r.message); setPasswordResetMode(false); setPasswordResetSent(false); setPasswordResetCode(""); setNewPassword(""); setNewPasswordConfirmation(""); setPassword("");
        } catch (err) { setError(errorMessage(err)); } finally { setLoading(false); }
    }


    async function submit(e: FormEvent) {
        e.preventDefault();
        setError("");
        setLoading(true);
        try {
            if (twoFactorChallenge) { await verifyTwoFactor(code.trim()); router.replace("/"); }
            else { const complete = await login(email.trim(), password); if (complete) router.replace("/"); }
        } catch (err) {
            setError(errorMessage(err));
        } finally {
            setLoading(false);
        }
    }

    return (
        <main className="flex min-h-screen items-center justify-center bg-slate-100 p-5">
            <form onSubmit={submit} className="w-full max-w-md space-y-4 rounded-2xl border border-slate-200 bg-white p-8 shadow-xl">
                <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-blue-600 text-white"><Store size={24} /></div>
                <div>
                    <h1 className="text-2xl font-bold text-slate-900">Store ERP</h1>
                    <p className="mt-1 text-sm text-slate-500">Hisobingizga kiring</p>
                </div>
                {!twoFactorChallenge && !passwordResetMode && <>
                    <Field label="Email">
                        <input type="email" required autoComplete="username" value={email} onChange={(e) => setEmail(e.target.value)} className={inputCls} />
                    </Field>
                    <Field label="Parol">
                        <div className="relative">
                            <LockKeyhole className="absolute left-3 top-2.5 text-slate-400" size={16} />
                            <input type="password" required autoComplete="current-password" value={password} onChange={(e) => setPassword(e.target.value)} className={`${inputCls} pl-9`} />
                        </div>
                    </Field>
                </>}
                {twoFactorChallenge && <Field label="Authenticator kodi yoki tiklash kodi"><input autoFocus required value={code} onChange={(e) => setCode(e.target.value)} className={inputCls} autoComplete="one-time-code" /></Field>}
                {notice && <div className="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800">{notice}</div>}
                {error && <Alert>{error}</Alert>}
                {passwordResetMode && <section className="space-y-3 rounded-xl border border-blue-200 bg-blue-50 p-4">
                    <h2 className="font-semibold text-slate-900">Email orqali parolni tiklash</h2>
                    <p className="text-sm text-slate-600">Hisobingizga bog‘langan emailga 6 xonali kod yuboramiz. Kod 10 daqiqa amal qiladi. Parol almashtirilgach, barcha eski sessiyalar bekor qilinadi.</p>
                    <Field label="Hisob emaili"><input type="email" required value={email} onChange={e => setEmail(e.target.value)} className={inputCls} autoComplete="email" /></Field>
                    {!passwordResetSent ? <>
                        <Button type="button" className="w-full" loading={loading} onClick={requestPasswordReset} disabled={!email.includes("@")}>Emailga tiklash kodini yuborish</Button>
                    </> : <>
                        <Field label="Emailga kelgan 6 xonali kod"><input inputMode="numeric" autoComplete="one-time-code" maxLength={6} value={passwordResetCode} onChange={e => setPasswordResetCode(e.target.value.replace(/\D/g, "").slice(0, 6))} className={inputCls} /></Field>
                        <Field label="Yangi parol (kamida 10 belgi)"><input type="password" autoComplete="new-password" minLength={10} required value={newPassword} onChange={e => setNewPassword(e.target.value)} className={inputCls} /></Field>
                        <Field label="Yangi parolni takrorlang"><input type="password" autoComplete="new-password" minLength={10} required value={newPasswordConfirmation} onChange={e => setNewPasswordConfirmation(e.target.value)} className={inputCls} /></Field>
                        <Button type="button" className="w-full" loading={loading} disabled={passwordResetCode.length !== 6 || newPassword.length < 10 || !newPasswordConfirmation} onClick={submitPasswordReset}>Parolni yangilash</Button>
                        <button type="button" className="w-full text-sm text-slate-600 underline" onClick={() => { setPasswordResetSent(false); setPasswordResetCode(""); setNotice(""); setError(""); }}>Yangi kod so‘rash</button>
                    </>}
                    <button type="button" className="w-full text-sm text-slate-600 underline" onClick={() => { setPasswordResetMode(false); setPasswordResetSent(false); setPasswordResetCode(""); setNewPassword(""); setNewPasswordConfirmation(""); setNotice(""); setError(""); }}>Kirishga qaytish</button>
                </section>}
                {!twoFactorChallenge && !recoveryMode && !passwordResetMode && <button type="button" className="w-full text-sm font-medium text-blue-700 underline" onClick={() => { setPasswordResetMode(true); setError(""); setNotice(""); }}>Parolni unutdingizmi?</button>}
                {twoFactorChallenge && !recoveryMode && <button type="button" className="w-full text-sm font-medium text-blue-700 underline" onClick={() => { setRecoveryMode(true); setRecoveryEmail(email); setError(""); setNotice(""); }}>Authenticator va zaxira kodlarim yo‘qolgan</button>}
                {recoveryMode && <section className="space-y-3 rounded-xl border border-amber-200 bg-amber-50 p-4">
                    <h2 className="font-semibold text-slate-900">Email orqali 2FA tiklash</h2>
                    <p className="text-sm text-slate-600">Faqat hisobingizda oldindan tasdiqlangan emailga kod yuboriladi. Tasdiqlangach, eski sessiyalar bekor qilinadi va yangi Authenticator ulashingiz kerak bo‘ladi.</p>
                    <Field label="Hisob emaili"><input type="email" required value={recoveryEmail || email} onChange={e => setRecoveryEmail(e.target.value)} className={inputCls} /></Field>
                    {!recoverySent ? <Button type="button" className="w-full" loading={loading} onClick={startEmailRecovery}>Tiklash kodini yuborish</Button> : <><Field label="Emailga kelgan 6 xonali kod"><input inputMode="numeric" maxLength={6} value={recoveryCode} onChange={e => setRecoveryCode(e.target.value.replace(/\D/g, "").slice(0, 6))} className={inputCls} /></Field><Button type="button" className="w-full" loading={loading} disabled={recoveryCode.length !== 6} onClick={verifyEmailRecovery}>Emailni tasdiqlash va 2FA tiklash</Button></>}
                    <button type="button" className="w-full text-sm text-slate-600 underline" onClick={() => { setRecoveryMode(false); setRecoverySent(false); setRecoveryCode(""); setError(""); }}>Orqaga qaytish</button>
                </section>}
                <Button type="submit" loading={loading} className="w-full" disabled={recoveryMode || passwordResetMode}>{twoFactorChallenge ? "Tasdiqlash" : "Kirish"}</Button>
            </form>
        </main>
    );
}
