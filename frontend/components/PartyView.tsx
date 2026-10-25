"use client";
import { FormEvent, useCallback, useEffect, useRef, useState } from "react";
import { Plus, Pencil, Eye, Banknote, Printer, Power } from "lucide-react";
import AppShell from "./AppShell";
import { Alert, Button, ConfirmDialog, Field, Modal, Pagination, Spinner, StatusBadge, inputCls, useDebounce } from "./ui";
import { api, ApiError, errorMessage, Paginated } from "../app/lib/api";
import { formatDate, formatDateTime, formatUZS } from "../app/lib/format";
import { useAuth } from "../app/lib/auth";
import { newToken } from "../app/lib/documents";
import { METHOD_LABELS, REFERENCE_REQUIRED, centsToInput, normNumber, toCents } from "../app/lib/sales";
import InsightsPanel from "./InsightsPanel";
import {
    DOC_TYPE_LABELS, HistoryRow, PARTY, PartyConfig, PartyKind, PartyRow, PaymentRec, STATEMENT_KIND_LABELS,
    Statement, SUPPLIER_METHOD_LABELS, Summary, Totals,
} from "../app/lib/parties";

const num = (v: string | number | null | undefined) => Number(v ?? 0);

/** Qarz: musbat — qizil; manfiy — ortiqcha to‘lov (yashil). */
function DebtText({ value }: { value: string | number | null | undefined }) {
    const n = num(value);
    if (n < 0) return <span className="font-semibold text-emerald-700">{formatUZS(-n)} <span className="text-xs font-normal">(ortiqcha to‘lov)</span></span>;
    return <span className={`font-semibold ${n > 0 ? "text-red-600" : "text-slate-500"}`}>{formatUZS(n)}</span>;
}

const ESC: Record<string, string> = { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" };
const esc = (s: string) => s.replace(/[&<>"']/g, (c) => ESC[c] ?? c);

/** Hisob-kitob varaqasini alohida oynada chop etadi. */
function printStatement(title: string, st: Statement, from: string, to: string) {
    const w = window.open("", "_blank", "width=900,height=700");
    if (!w) return;
    const rows = st.rows.map((r) => `<tr><td>${esc(formatDateTime(r.date))}</td><td>${esc(STATEMENT_KIND_LABELS[r.kind] ?? r.kind)}</td><td>${esc(r.number)}</td><td>${esc(r.description)}</td><td class="r">${num(r.charge) ? esc(formatUZS(r.charge)) : ""}</td><td class="r">${num(r.credit) ? esc(formatUZS(r.credit)) : ""}</td><td class="r">${esc(formatUZS(r.balance))}</td></tr>`).join("");
    w.document.write(`<!doctype html><html><head><meta charset="utf-8"><title>${esc(title)}</title><style>body{font:13px sans-serif;padding:24px}table{border-collapse:collapse;width:100%}th,td{border:1px solid #cbd5e1;padding:5px 8px;text-align:left}.r{text-align:right}h2{margin:0 0 4px}</style></head><body>
<h2>O‘zaro hisob-kitob varaqasi</h2><div>${esc(title)}${from || to ? ` — davr: ${esc(from || "…")} / ${esc(to || "…")}` : ""}</div><br>
<table><thead><tr><th>Sana</th><th>Tur</th><th>Raqam</th><th>Izoh</th><th class="r">Qarz oshdi</th><th class="r">Qarz kamaydi</th><th class="r">Qoldiq</th></tr></thead><tbody>
<tr><td colspan="6"><b>Boshlang‘ich qoldiq</b></td><td class="r"><b>${esc(formatUZS(st.opening_balance))}</b></td></tr>${rows}
<tr><td colspan="6"><b>Yakuniy qoldiq</b></td><td class="r"><b>${esc(formatUZS(st.closing_balance))}</b></td></tr></tbody></table></body></html>`);
    w.document.close();
    w.focus();
    w.print();
}

/* ------------------------------------------------------------------ to‘lov oynasi */

function PaymentModal({ cfg, id, debt, onClose, onDone }: { cfg: PartyConfig; id: number; debt: number; onClose: () => void; onDone: (msg: string) => void }) {
    const token = useRef(newToken());
    const [amount, setAmount] = useState(centsToInput(Math.max(toCents(debt), 0)));
    const [method, setMethod] = useState("cash");
    const [reference, setReference] = useState("");
    const [note, setNote] = useState("");
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState("");
    const labels = cfg.base === "/suppliers" ? SUPPLIER_METHOD_LABELS : METHOD_LABELS;
    const needRef = cfg.base === "/customers" && REFERENCE_REQUIRED.includes(method);

    async function submit(e: FormEvent) {
        e.preventDefault();
        const cents = toCents(amount);
        if (cents <= 0) return setError("To‘lov summasi noldan katta bo‘lishi kerak.");
        if (cents > toCents(debt)) return setError(`To‘lov summasi qarzdan oshmasligi kerak (${formatUZS(debt)}).`);
        if (needRef && !reference.trim()) return setError("Chek yoki tranzaksiya raqamini kiriting.");
        setSaving(true);
        setError("");
        try {
            await api(`${cfg.base}/${id}/payments`, {
                method: "POST",
                body: { amount: normNumber(amount), method, reference: reference.trim() || null, note: note.trim() || null, client_token: token.current },
            });
            onDone("To‘lov qayd etildi.");
        } catch (err) {
            setError(errorMessage(err));
        } finally {
            setSaving(false);
        }
    }

    return (
        <Modal open title={cfg.payTitle} onClose={onClose}>
            <form onSubmit={submit} className="space-y-4">
                {error && <Alert>{error}</Alert>}
                <p className="text-sm text-slate-600">Joriy qarz: <DebtText value={debt} />. Qisman to‘lov mumkin.</p>
                <Field label="Summa (so‘m)"><input required inputMode="decimal" value={amount} onChange={(e) => setAmount(e.target.value)} className={inputCls} /></Field>
                <Field label="To‘lov usuli">
                    <select value={method} onChange={(e) => setMethod(e.target.value)} className={inputCls}>
                        {cfg.methods.map((m) => <option key={m} value={m}>{labels[m] ?? m}</option>)}
                    </select>
                </Field>
                {method !== "cash" && (
                    <Field label={needRef ? "Chek / tranzaksiya raqami" : "Izoh raqami (ixtiyoriy)"}>
                        <input value={reference} onChange={(e) => setReference(e.target.value)} className={inputCls} />
                    </Field>
                )}
                {cfg.base === "/customers" && method !== "cash" && method !== "card" && (
                    <p className="text-xs text-amber-700">Click/Payme/o‘tkazma avtomatik tekshirilmaydi: to‘lov kassir kiritgan raqam asosida qayd etiladi.</p>
                )}
                {cfg.base === "/suppliers" && method === "bank" && (
                    <p className="text-xs text-amber-700">Bank to‘lovi hozircha faqat qarzni kamaytiradi; bank hisobiga yozilishi 8-bosqichda qo‘shiladi.</p>
                )}
                {method === "cash" && <p className="text-xs text-slate-500">Naqd to‘lov ochiq kassa smenangiz orqali kassa daftariga yoziladi.</p>}
                <Field label="Izoh"><input value={note} onChange={(e) => setNote(e.target.value)} className={inputCls} /></Field>
                <div className="flex justify-end gap-2">
                    <Button type="button" variant="secondary" onClick={onClose}>Bekor qilish</Button>
                    <Button type="submit" loading={saving}>Qayd etish</Button>
                </div>
            </form>
        </Modal>
    );
}

/* ------------------------------------------------------------------ kartochka */

type Tab = "statement" | "history" | "payments";

function PartyDetail({ cfg, id, onClose, onChanged }: { cfg: PartyConfig; id: number; onClose: () => void; onChanged: () => void }) {
    const { can } = useAuth();
    const [tab, setTab] = useState<Tab>("statement");
    const [info, setInfo] = useState<(PartyRow & { summary: Summary }) | null>(null);
    const [from, setFrom] = useState("");
    const [to, setTo] = useState("");
    const [statement, setStatement] = useState<Statement | null>(null);
    const [history, setHistory] = useState<Paginated<HistoryRow> | null>(null);
    const [hPage, setHPage] = useState(1);
    const [payments, setPayments] = useState<Paginated<PaymentRec> | null>(null);
    const [pPage, setPPage] = useState(1);
    const [tick, setTick] = useState(0);
    const [error, setError] = useState("");
    const [notice, setNotice] = useState("");
    const [payOpen, setPayOpen] = useState(false);
    const [cancelFor, setCancelFor] = useState<PaymentRec | null>(null);
    const [reason, setReason] = useState("");
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        api<PartyRow & { summary: Summary }>(`${cfg.base}/${id}`).then(setInfo).catch((e) => setError(errorMessage(e)));
    }, [cfg.base, id, tick]);

    useEffect(() => {
        if (tab !== "statement") return;
        api<Statement>(`${cfg.base}/${id}/statement`, { query: { from, to } }).then(setStatement).catch((e) => setError(errorMessage(e)));
    }, [cfg.base, id, tab, from, to, tick]);

    useEffect(() => {
        if (tab !== "history") return;
        api<Paginated<HistoryRow>>(`${cfg.base}/${id}/${cfg.historyPath}`, { query: { page: hPage } }).then(setHistory).catch((e) => setError(errorMessage(e)));
    }, [cfg.base, cfg.historyPath, id, tab, hPage, tick]);

    useEffect(() => {
        if (tab !== "payments") return;
        api<Paginated<PaymentRec>>(`${cfg.base}/${id}/payments`, { query: { page: pPage } }).then(setPayments).catch((e) => setError(errorMessage(e)));
    }, [cfg.base, id, tab, pPage, tick]);

    const debt = num(info?.summary?.debt);
    const methodLabels = cfg.base === "/suppliers" ? SUPPLIER_METHOD_LABELS : METHOD_LABELS;

    async function cancelPayment() {
        if (!cancelFor) return;
        if (reason.trim().length < 3) return setError("Bekor qilish sababini kiriting (kamida 3 belgi).");
        setBusy(true);
        setError("");
        try {
            const path = cfg.base === "/customers" ? "customer-payments" : "supplier-payments";
            await api(`/${path}/${cancelFor.id}/cancel`, { method: "POST", body: { reason: reason.trim() } });
            setNotice("To‘lov bekor qilindi.");
            setCancelFor(null);
            setReason("");
            setTick((t) => t + 1);
            onChanged();
        } catch (err) {
            setError(errorMessage(err));
        } finally {
            setBusy(false);
        }
    }

    return (
        <Modal open wide title={info ? `${info.name}${info.phone ? ` · ${info.phone}` : ""}` : "Yuklanmoqda..."} onClose={onClose}>
            <div className="space-y-4">
                {notice && <Alert kind="success">{notice}</Alert>}
                {error && <Alert>{error}</Alert>}
                {!info ? <Spinner /> : (
                    <>
                        <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
                            {cfg.cards.map(([label, key]) => (
                                <div key={key} className="rounded-xl border border-slate-200 p-3">
                                    <div className="text-xs text-slate-500">{label}</div>
                                    <div className="mt-1 text-base">{key === "debt" ? <DebtText value={info.summary[key]} /> : <b>{formatUZS(info.summary[key])}</b>}</div>
                                </div>
                            ))}
                        </div>
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <div className="flex gap-1 rounded-lg bg-slate-100 p-1 text-sm">
                                {([["statement", "Hisob-kitob"], ["history", cfg.historyTitle], ["payments", "Qarz to‘lovlari"]] as [Tab, string][]).map(([k, label]) => (
                                    <button key={k} onClick={() => setTab(k)} className={`rounded-md px-3 py-1.5 ${tab === k ? "bg-white font-semibold shadow-sm" : "text-slate-600"}`}>{label}</button>
                                ))}
                            </div>
                            {can(cfg.permPay) && (
                                <Button onClick={() => setPayOpen(true)} disabled={debt <= 0}><Banknote size={16} />{cfg.payButton}</Button>
                            )}
                        </div>

                        {tab === "statement" && (
                            <div className="space-y-3">
                                <div className="flex flex-wrap items-end gap-3">
                                    <Field label="Dan"><input type="date" value={from} onChange={(e) => setFrom(e.target.value)} className={inputCls} /></Field>
                                    <Field label="Gacha"><input type="date" value={to} onChange={(e) => setTo(e.target.value)} className={inputCls} /></Field>
                                    <Button variant="secondary" disabled={!statement} onClick={() => statement && printStatement(info.name, statement, from, to)}><Printer size={15} />Chop etish</Button>
                                </div>
                                {!statement ? <Spinner /> : (
                                    <div className="overflow-x-auto rounded-xl border">
                                        <table className="w-full text-left text-sm">
                                            <thead className="bg-slate-50 text-xs uppercase text-slate-500">
                                                <tr><th className="px-3 py-2">Sana</th><th className="px-3 py-2">Tur</th><th className="px-3 py-2">Raqam</th><th className="px-3 py-2">Izoh</th><th className="px-3 py-2 text-right">Qarz oshdi</th><th className="px-3 py-2 text-right">Qarz kamaydi</th><th className="px-3 py-2 text-right">Qoldiq</th></tr>
                                            </thead>
                                            <tbody className="divide-y">
                                                <tr className="bg-slate-50/60"><td className="px-3 py-2 font-medium" colSpan={6}>Boshlang‘ich qoldiq</td><td className="px-3 py-2 text-right font-medium">{formatUZS(statement.opening_balance)}</td></tr>
                                                {statement.rows.map((r, i) => (
                                                    <tr key={`${r.kind}-${r.number}-${i}`}>
                                                        <td className="px-3 py-2 whitespace-nowrap">{formatDateTime(r.date)}</td>
                                                        <td className="px-3 py-2">{STATEMENT_KIND_LABELS[r.kind] ?? r.kind}</td>
                                                        <td className="px-3 py-2">{r.number}</td>
                                                        <td className="px-3 py-2 text-slate-600">{r.description}</td>
                                                        <td className="px-3 py-2 text-right text-red-600">{num(r.charge) ? formatUZS(r.charge) : ""}</td>
                                                        <td className="px-3 py-2 text-right text-emerald-700">{num(r.credit) ? formatUZS(r.credit) : ""}</td>
                                                        <td className="px-3 py-2 text-right font-medium">{formatUZS(r.balance)}</td>
                                                    </tr>
                                                ))}
                                                {!statement.rows.length && <tr><td colSpan={7} className="px-3 py-8 text-center text-slate-400">Bu davrda qarzga ta’sir qiluvchi operatsiya yo‘q.</td></tr>}
                                                <tr className="bg-slate-50/60"><td className="px-3 py-2 font-semibold" colSpan={6}>Yakuniy qoldiq</td><td className="px-3 py-2 text-right"><DebtText value={statement.closing_balance} /></td></tr>
                                            </tbody>
                                        </table>
                                    </div>
                                )}
                            </div>
                        )}

                        {tab === "history" && (
                            <div className="overflow-hidden rounded-xl border">
                                {!history ? <Spinner /> : (
                                    <>
                                        <div className="overflow-x-auto">
                                            <table className="w-full text-left text-sm">
                                                <thead className="bg-slate-50 text-xs uppercase text-slate-500">
                                                    <tr><th className="px-3 py-2">Sana</th><th className="px-3 py-2">Raqam</th><th className="px-3 py-2">Tur</th><th className="px-3 py-2">Holat</th><th className="px-3 py-2 text-right">Jami</th><th className="px-3 py-2 text-right">To‘langan</th><th className="px-3 py-2 text-right">Qarz</th></tr>
                                                </thead>
                                                <tbody className="divide-y">
                                                    {history.data.map((h) => {
                                                        const owed = h.debt_amount !== undefined ? num(h.debt_amount) : h.type === "purchase" ? num(h.total) - num(h.paid_amount) : num(h.total);
                                                        return (
                                                            <tr key={h.id} className={h.status === "cancelled" ? "text-slate-400 line-through" : ""}>
                                                                <td className="px-3 py-2 whitespace-nowrap">{h.sold_at ? formatDateTime(h.sold_at) : formatDate(h.doc_date)}</td>
                                                                <td className="px-3 py-2">{h.number}</td>
                                                                <td className="px-3 py-2">{DOC_TYPE_LABELS[h.type] ?? h.type}</td>
                                                                <td className="px-3 py-2"><StatusBadge status={h.status} /></td>
                                                                <td className="px-3 py-2 text-right">{formatUZS(h.total)}</td>
                                                                <td className="px-3 py-2 text-right">{formatUZS(h.paid_amount)}</td>
                                                                <td className="px-3 py-2 text-right">{h.status === "approved" && owed > 0 ? formatUZS(owed) : "—"}</td>
                                                            </tr>
                                                        );
                                                    })}
                                                    {!history.data.length && <tr><td colSpan={7} className="px-3 py-8 text-center text-slate-400">Hujjat topilmadi.</td></tr>}
                                                </tbody>
                                            </table>
                                        </div>
                                        <Pagination page={history.current_page} lastPage={history.last_page} total={history.total} onPage={setHPage} />
                                    </>
                                )}
                            </div>
                        )}

                        {tab === "payments" && (
                            <div className="overflow-hidden rounded-xl border">
                                {!payments ? <Spinner /> : (
                                    <>
                                        <div className="overflow-x-auto">
                                            <table className="w-full text-left text-sm">
                                                <thead className="bg-slate-50 text-xs uppercase text-slate-500">
                                                    <tr><th className="px-3 py-2">Sana</th><th className="px-3 py-2">Raqam</th><th className="px-3 py-2">Usul</th><th className="px-3 py-2 text-right">Summa</th><th className="px-3 py-2">Holat</th><th className="px-3 py-2">Kim qabul qildi</th><th className="px-3 py-2 text-right">Amal</th></tr>
                                                </thead>
                                                <tbody className="divide-y">
                                                    {payments.data.map((p) => (
                                                        <tr key={p.id} className={p.status === "cancelled" ? "text-slate-400" : ""}>
                                                            <td className="px-3 py-2 whitespace-nowrap">{formatDateTime(p.paid_at)}</td>
                                                            <td className="px-3 py-2">{p.number}</td>
                                                            <td className="px-3 py-2">
                                                                {methodLabels[p.method] ?? p.method}
                                                                {p.reference && <div className="text-xs text-slate-400">{p.reference}</div>}
                                                                {p.is_online_verified === false && p.method !== "cash" && p.method !== "card" && cfg.base === "/customers" && <div className="text-xs text-amber-600">avtomatik tekshirilmagan</div>}
                                                            </td>
                                                            <td className="px-3 py-2 text-right font-medium">{formatUZS(p.amount)}</td>
                                                            <td className="px-3 py-2"><StatusBadge status={p.status} />{p.cancel_reason && <div className="text-xs text-slate-400">{p.cancel_reason}</div>}</td>
                                                            <td className="px-3 py-2">{p.creator?.name ?? "—"}</td>
                                                            <td className="px-3 py-2 text-right">
                                                                {p.status === "approved" && can(cfg.permCancelPay) && (
                                                                    <Button variant="secondary" className="!px-3 !py-1.5 text-red-600" onClick={() => { setError(""); setReason(""); setCancelFor(p); }}>Bekor qilish</Button>
                                                                )}
                                                            </td>
                                                        </tr>
                                                    ))}
                                                    {!payments.data.length && <tr><td colSpan={7} className="px-3 py-8 text-center text-slate-400">Qarz to‘lovlari yo‘q.</td></tr>}
                                                </tbody>
                                            </table>
                                        </div>
                                        <Pagination page={payments.current_page} lastPage={payments.last_page} total={payments.total} onPage={setPPage} />
                                    </>
                                )}
                            </div>
                        )}
                    </>
                )}
            </div>

            {payOpen && (
                <PaymentModal cfg={cfg} id={id} debt={debt} onClose={() => setPayOpen(false)}
                    onDone={(m) => { setPayOpen(false); setNotice(m); setTick((t) => t + 1); onChanged(); }} />
            )}

            <Modal open={!!cancelFor} title="To‘lovni bekor qilish" onClose={() => setCancelFor(null)}>
                <div className="space-y-4">
                    {error && <Alert>{error}</Alert>}
                    <p className="text-sm text-slate-600">
                        {cancelFor?.number} ({formatUZS(cancelFor?.amount)}) bekor qilinadi, qarz qayta tiklanadi.
                        {cancelFor?.method === "cash" && " Naqd to‘lov bo‘lgani uchun kassa daftariga teskari yozuv kiritiladi (ochiq smenangiz kerak)."}
                    </p>
                    <Field label="Bekor qilish sababi"><input value={reason} onChange={(e) => setReason(e.target.value)} className={inputCls} /></Field>
                    <div className="flex justify-end gap-2">
                        <Button variant="secondary" onClick={() => setCancelFor(null)}>Yopish</Button>
                        <Button variant="danger" loading={busy} onClick={cancelPayment}>Bekor qilish</Button>
                    </div>
                </div>
            </Modal>
        </Modal>
    );
}

/* ------------------------------------------------------------------ ro‘yxat sahifasi */

type FormState = { id?: number; name: string; phone: string; address: string; contact_info: string; note: string; is_active: boolean };
const EMPTY: FormState = { name: "", phone: "", address: "", contact_info: "", note: "", is_active: true };

const INSIGHT_PARTY_KINDS: string[] = ["customer", "supplier"];

export default function PartyView({ kind }: { kind: PartyKind }) {
    const cfg = PARTY[kind];
    const { can } = useAuth();
    const [rows, setRows] = useState<Paginated<PartyRow> | null>(null);
    const [totals, setTotals] = useState<Totals | null>(null);
    const [page, setPage] = useState(1);
    const [search, setSearch] = useState("");
    const [status, setStatus] = useState("");
    const [onlyDebt, setOnlyDebt] = useState(false);
    const [sort, setSort] = useState("name");
    const debounced = useDebounce(search);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState("");
    const [notice, setNotice] = useState("");
    const [form, setForm] = useState<FormState | null>(null);
    const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});
    const [saving, setSaving] = useState(false);
    const [toDeactivate, setToDeactivate] = useState<PartyRow | null>(null);
    const [detailId, setDetailId] = useState<number | null>(null);

    const load = useCallback(async () => {
        setLoading(true);
        setError("");
        try {
            const [list, t] = await Promise.all([
                api<Paginated<PartyRow>>(cfg.base, { query: { page, search: debounced, status, has_debt: onlyDebt ? 1 : "", sort: sort === "debt" ? "debt" : "" } }),
                api<Totals>(`${cfg.base}/summary`),
            ]);
            setRows(list);
            setTotals(t);
        } catch (e) {
            setError(errorMessage(e));
        } finally {
            setLoading(false);
        }
    }, [cfg.base, page, debounced, status, onlyDebt, sort]);

    useEffect(() => { load(); }, [load]);
    useEffect(() => { setPage(1); }, [debounced, status, onlyDebt, sort]);

    async function save(e: FormEvent) {
        e.preventDefault();
        if (!form) return;
        setSaving(true);
        setFieldErrors({});
        setError("");
        const body: Record<string, unknown> = {
            name: form.name, phone: form.phone || null, address: form.address || null, note: form.note || null, is_active: form.is_active,
        };
        if (kind === "supplier") body.contact_info = form.contact_info || null;
        try {
            await api(form.id ? `${cfg.base}/${form.id}` : cfg.base, { method: form.id ? "PATCH" : "POST", body });
            setNotice(form.id ? `${cfg.single} yangilandi.` : `${cfg.single} qo‘shildi.`);
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
        setError("");
        try {
            await api(`${cfg.base}/${toDeactivate.id}`, { method: "DELETE" });
            setNotice(`${cfg.single} faol emas holatga o‘tkazildi.`);
            await load();
        } catch (err) {
            setError(errorMessage(err));
        } finally {
            setToDeactivate(null);
            setSaving(false);
        }
    }

    const set = (k: keyof FormState) => (e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement>) => setForm((f) => f && { ...f, [k]: e.target.value });

    return (
        <AppShell title={cfg.title} subtitle={cfg.subtitle} permission={cfg.permView}
            actions={can(cfg.permCreate) && <Button onClick={() => { setFieldErrors({}); setForm(EMPTY); }}><Plus size={16} />{cfg.single} qo‘shish</Button>}>
            <div className="space-y-4">
                {notice && <Alert kind="success">{notice}</Alert>}
                {error && !detailId && <Alert>{error}</Alert>}
                {INSIGHT_PARTY_KINDS.includes(kind) && <InsightsPanel endpoint={`/stats/parties?kind=${kind}`} />}

                {totals && (
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <div className="rounded-2xl border border-slate-200 bg-white p-4"><div className="text-xs text-slate-500">{cfg.debtLabel} (jami)</div><div className="mt-1 text-xl font-semibold text-red-600">{formatUZS(totals.total_debt)}</div></div>
                        <div className="rounded-2xl border border-slate-200 bg-white p-4"><div className="text-xs text-slate-500">Qarzdorlar soni</div><div className="mt-1 text-xl font-semibold">{totals.debtors_count}</div></div>
                        <div className="rounded-2xl border border-slate-200 bg-white p-4"><div className="text-xs text-slate-500">Ortiqcha to‘lovlar</div><div className="mt-1 text-xl font-semibold text-emerald-700">{formatUZS(totals.total_credit)}</div></div>
                    </div>
                )}

                <div className="flex flex-wrap items-center gap-3">
                    <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Nomi yoki telefon" className={`${inputCls} max-w-xs`} />
                    <select value={status} onChange={(e) => setStatus(e.target.value)} className={`${inputCls} max-w-[170px]`}>
                        <option value="">Barcha holatlar</option>
                        <option value="active">Faol</option>
                        <option value="inactive">Faol emas</option>
                    </select>
                    <select value={sort} onChange={(e) => setSort(e.target.value)} className={`${inputCls} max-w-[190px]`}>
                        <option value="name">Nomi (A–Z)</option>
                        <option value="debt">Qarz bo‘yicha (katta avval)</option>
                    </select>
                    <label className="flex items-center gap-2 text-sm text-slate-700"><input type="checkbox" checked={onlyDebt} onChange={(e) => setOnlyDebt(e.target.checked)} />Faqat qarzdorlar</label>
                </div>

                <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white">
                    {loading && !rows ? <Spinner /> : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-sm">
                                <thead className="bg-slate-50 text-xs uppercase text-slate-500">
                                    <tr><th className="px-5 py-3">Nomi</th><th className="px-5 py-3">Telefon</th><th className="px-5 py-3">Manzil</th><th className="px-5 py-3 text-right">Qarz</th><th className="px-5 py-3">Holat</th><th className="px-5 py-3 text-right">Amallar</th></tr>
                                </thead>
                                <tbody className="divide-y">
                                    {rows?.data.map((x) => (
                                        <tr key={x.id}>
                                            <td className="px-5 py-3 font-medium">{x.name}</td>
                                            <td className="px-5 py-3">{x.phone || "—"}</td>
                                            <td className="px-5 py-3">{x.address || "—"}</td>
                                            <td className="px-5 py-3 text-right"><DebtText value={x.debt} /></td>
                                            <td className="px-5 py-3"><StatusBadge status={x.is_active ? "active" : "inactive"} /></td>
                                            <td className="px-5 py-3 text-right">
                                                <div className="flex justify-end gap-2">
                                                    <Button variant="secondary" className="!px-3 !py-1.5" onClick={() => { setError(""); setDetailId(x.id); }}><Eye size={14} />Ko‘rish</Button>
                                                    {can(cfg.permUpdate) && <Button variant="secondary" className="!px-3 !py-1.5" onClick={() => { setFieldErrors({}); setForm({ id: x.id, name: x.name, phone: x.phone ?? "", address: x.address ?? "", contact_info: x.contact_info ?? "", note: x.note ?? "", is_active: x.is_active }); }}><Pencil size={14} />Tahrirlash</Button>}
                                                    {can(cfg.permDelete) && x.is_active && <Button variant="secondary" className="!px-3 !py-1.5 text-red-600" aria-label="Faolsizlantirish" onClick={() => setToDeactivate(x)}><Power size={14} /></Button>}
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                    {!rows?.data.length && <tr><td colSpan={6} className="px-5 py-12 text-center text-slate-400">{cfg.single} topilmadi.</td></tr>}
                                </tbody>
                            </table>
                        </div>
                    )}
                    {rows && <Pagination page={rows.current_page} lastPage={rows.last_page} total={rows.total} onPage={setPage} />}
                </div>
            </div>

            <Modal open={!!form} title={form?.id ? `${cfg.single}ni tahrirlash` : `Yangi ${cfg.single.toLowerCase()}`} onClose={() => setForm(null)}>
                {form && (
                    <form onSubmit={save} className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div className="sm:col-span-2"><Field label="Ism yoki kompaniya nomi" error={fieldErrors.name?.[0]}><input required value={form.name} onChange={set("name")} className={inputCls} /></Field></div>
                        <Field label="Telefon" error={fieldErrors.phone?.[0]}><input value={form.phone} onChange={set("phone")} className={inputCls} /></Field>
                        <Field label="Manzil" error={fieldErrors.address?.[0]}><input value={form.address} onChange={set("address")} className={inputCls} /></Field>
                        {kind === "supplier" && <div className="sm:col-span-2"><Field label="Aloqa ma’lumotlari" error={fieldErrors.contact_info?.[0]}><input value={form.contact_info} onChange={set("contact_info")} className={inputCls} placeholder="Aloqa shaxsi, email..." /></Field></div>}
                        <div className="sm:col-span-2"><Field label="Izoh" error={fieldErrors.note?.[0]}><textarea rows={2} value={form.note} onChange={set("note")} className={inputCls} /></Field></div>
                        <label className="flex items-center gap-2 text-sm sm:col-span-2"><input type="checkbox" checked={form.is_active} onChange={(e) => setForm({ ...form, is_active: e.target.checked })} />Faol</label>
                        <div className="flex justify-end gap-2 sm:col-span-2">
                            <Button type="button" variant="secondary" onClick={() => setForm(null)}>Bekor qilish</Button>
                            <Button type="submit" loading={saving}>Saqlash</Button>
                        </div>
                    </form>
                )}
            </Modal>

            {detailId !== null && <PartyDetail cfg={cfg} id={detailId} onClose={() => setDetailId(null)} onChanged={load} />}

            <ConfirmDialog open={!!toDeactivate} title={`${cfg.single}ni faolsizlantirish`} loading={saving} confirmLabel="Faolsizlantirish"
                message={`“${toDeactivate?.name ?? ""}” faol emas holatga o‘tkaziladi (tarix saqlanadi). Qarzi yoki ortiqcha to‘lovi bo‘lsa, amal rad etiladi.`}
                onConfirm={deactivate} onCancel={() => setToDeactivate(null)} />
        </AppShell>
    );
}
