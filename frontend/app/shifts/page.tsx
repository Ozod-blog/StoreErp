"use client";
import { FormEvent, useCallback, useEffect, useState } from "react";
import { Play, Square, Repeat, ArrowDownToLine, ArrowUpFromLine, CheckCheck } from "lucide-react";
import AppShell from "../../components/AppShell";
import { Alert, Button, Field, Modal, Pagination, Spinner, StatusBadge, inputCls } from "../../components/ui";
import { CashOperationModal, TransactionsTable } from "../../components/CashParts";
import { api, errorMessage, ApiError, Paginated } from "../lib/api";
import { CATEGORY_LABELS, CashboxRow, SCHEDULE_TYPES, ScheduleRow, Shift, addDays, todayTashkent } from "../lib/cash";
import { formatDate, formatDateTime, formatUZS } from "../lib/format";
import { METHOD_LABELS } from "../lib/sales";
import { useAuth } from "../lib/auth";

type UserOpt = { id: number; name: string };

function num(v: string | null | undefined): number {
    const n = Number(String(v ?? "").replace(",", "."));
    return Number.isFinite(n) ? n : 0;
}

export default function ShiftsPage() {
    const { user, can } = useAuth();
    const [current, setCurrent] = useState<Shift | null>(null);
    const [currentLoading, setCurrentLoading] = useState(true);
    const [list, setList] = useState<Paginated<Shift> | null>(null);
    const [page, setPage] = useState(1);
    const [status, setStatus] = useState("");
    const [diffOnly, setDiffOnly] = useState(false);
    const [cashboxFilter, setCashboxFilter] = useState("");
    const [dateFrom, setDateFrom] = useState("");
    const [dateTo, setDateTo] = useState("");
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState("");
    const [notice, setNotice] = useState("");
    const [warning, setWarning] = useState("");
    const [cashboxes, setCashboxes] = useState<CashboxRow[]>([]);
    const [schedule, setSchedule] = useState<ScheduleRow[]>([]);

    const [openForm, setOpenForm] = useState(false);
    const [closeTarget, setCloseTarget] = useState<Shift | null>(null);
    const [handoverTarget, setHandoverTarget] = useState<Shift | null>(null);
    const [opTarget, setOpTarget] = useState<{ cashboxId: number; direction: "in" | "out" } | null>(null);
    const [detail, setDetail] = useState<Shift | null>(null);
    const [detailLoading, setDetailLoading] = useState(false);

    const loadCurrent = useCallback(async () => {
        setCurrentLoading(true);
        try {
            setCurrent((await api<{ shift: Shift | null }>("/shifts/current")).shift);
        } catch (e) {
            setError(errorMessage(e));
        } finally {
            setCurrentLoading(false);
        }
    }, []);

    const loadList = useCallback(async () => {
        setLoading(true);
        try {
            setList(await api<Paginated<Shift>>("/shifts", {
                query: { page, status, cashbox_id: cashboxFilter, date_from: dateFrom, date_to: dateTo, has_difference: diffOnly ? "1" : undefined },
            }));
        } catch (e) {
            setError(errorMessage(e));
        } finally {
            setLoading(false);
        }
    }, [page, status, cashboxFilter, dateFrom, dateTo, diffOnly]);

    useEffect(() => { loadCurrent(); }, [loadCurrent]);
    useEffect(() => { loadList(); }, [loadList]);
    useEffect(() => { setPage(1); }, [status, cashboxFilter, dateFrom, dateTo, diffOnly]);
    useEffect(() => {
        api<CashboxRow[]>("/cashboxes", { query: { all: 1 } }).then(setCashboxes).catch(() => undefined);
        const from = todayTashkent();
        api<ScheduleRow[]>("/my-schedule", { query: { date_from: from, date_to: addDays(from, 6) } }).then(setSchedule).catch(() => undefined);
    }, []);

    function refresh(msg?: string) {
        if (msg) setNotice(msg);
        loadCurrent();
        loadList();
    }

    async function openDetail(id: number) {
        setDetailLoading(true);
        setDetail({ id } as Shift);
        try {
            setDetail(await api<Shift>(`/shifts/${id}`));
        } catch (e) {
            setError(errorMessage(e));
            setDetail(null);
        } finally {
            setDetailLoading(false);
        }
    }

    const canCloseOther = can("shifts.approve");
    const cashboxName = (id: number) => cashboxes.find((c) => c.id === id)?.name ?? `Kassa #${id}`;

    return (
        <AppShell title="Kassa smenalari" subtitle="Smenani ochish, naqd pulni nazorat qilish, yopish va topshirish" permission="shifts.view">
            <div className="space-y-5">
                {notice && <Alert kind="success">{notice}</Alert>}
                {warning && <Alert>{warning}</Alert>}
                {error && <Alert>{error}</Alert>}

                <section className="rounded-2xl border border-slate-200 bg-white p-5">
                    <h2 className="font-semibold text-slate-900">Mening faol smenam</h2>
                    {currentLoading ? <Spinner /> : current ? (
                        <div className="mt-4 space-y-4">
                            <div className="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                                <div><div className="text-slate-500">Kassa</div><div className="font-medium">{current.cashbox?.name}</div></div>
                                <div><div className="text-slate-500">Ochilgan vaqt</div><div className="font-medium">{formatDateTime(current.opened_at)}</div></div>
                                <div><div className="text-slate-500">Boshlang‘ich naqd pul</div><div className="font-medium">{formatUZS(current.opening_cash)}</div></div>
                                <div><div className="text-slate-500">Kutilayotgan naqd pul</div><div className="text-lg font-bold text-blue-700">{formatUZS(current.summary?.expected_cash)}</div></div>
                            </div>
                            <div className="grid gap-3 text-sm sm:grid-cols-2">
                                <div className="rounded-lg bg-emerald-50 p-3"><div className="text-emerald-700">Smena davomidagi kirim</div><div className="font-semibold">{formatUZS(current.summary?.cash_in)}</div></div>
                                <div className="rounded-lg bg-red-50 p-3"><div className="text-red-700">Smena davomidagi chiqim</div><div className="font-semibold">{formatUZS(current.summary?.cash_out)}</div></div>
                            </div>
                            <p className="text-xs text-slate-500">Savdo tushumlari va qaytarishlar 6-bosqichda (POS) shu hisobga avtomatik qo‘shiladi.</p>
                            <div className="flex flex-wrap gap-2">
                                {can("shifts.update") && <Button variant="secondary" onClick={() => setOpTarget({ cashboxId: current.cashbox_id, direction: "in" })}><ArrowDownToLine size={16} />Kirim</Button>}
                                {can("shifts.update") && <Button variant="secondary" onClick={() => setOpTarget({ cashboxId: current.cashbox_id, direction: "out" })}><ArrowUpFromLine size={16} />Chiqim</Button>}
                                {can("shifts.update") && <Button variant="secondary" onClick={() => setHandoverTarget(current)}><Repeat size={16} />Smenani topshirish</Button>}
                                {can("shifts.update") && <Button variant="danger" onClick={() => setCloseTarget(current)}><Square size={16} />Smenani yopish</Button>}
                            </div>
                        </div>
                    ) : (
                        <div className="mt-3 flex flex-wrap items-center justify-between gap-3">
                            <p className="text-sm text-slate-500">Sizda faol smena yo‘q.</p>
                            {can("shifts.create") && <Button onClick={() => setOpenForm(true)}><Play size={16} />Smenani ochish</Button>}
                        </div>
                    )}
                </section>

                {schedule.length > 0 && (
                    <section className="rounded-2xl border border-slate-200 bg-white p-5">
                        <h2 className="font-semibold text-slate-900">Mening ish jadvalim (7 kun)</h2>
                        <div className="mt-3 flex flex-wrap gap-2 text-sm">
                            {schedule.map((s) => (
                                <span key={s.id} className={`rounded-lg px-3 py-1.5 ${SCHEDULE_TYPES[s.type]?.cls ?? "bg-slate-100"}`}>
                                    {formatDate(`${s.work_date}T00:00:00+05:00`)} — {SCHEDULE_TYPES[s.type]?.label ?? s.type}{s.type === "work" && s.start_time ? ` ${s.start_time.slice(0, 5)}–${s.end_time?.slice(0, 5)}` : ""}
                                </span>
                            ))}
                        </div>
                    </section>
                )}

                <section className="space-y-3">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <h2 className="font-semibold text-slate-900">Smenalar tarixi</h2>
                    </div>
                    <div className="flex flex-wrap gap-3">
                        <select value={status} onChange={(e) => setStatus(e.target.value)} className={`${inputCls} max-w-[170px]`}>
                            <option value="">Barcha holatlar</option><option value="open">Ochiq</option><option value="closed">Yopilgan</option>
                        </select>
                        <select value={cashboxFilter} onChange={(e) => setCashboxFilter(e.target.value)} className={`${inputCls} max-w-[200px]`}>
                            <option value="">Barcha kassalar</option>
                            {cashboxes.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                        </select>
                        <input type="date" value={dateFrom} onChange={(e) => setDateFrom(e.target.value)} className={`${inputCls} max-w-[170px]`} aria-label="Boshlanish sanasi" />
                        <input type="date" value={dateTo} onChange={(e) => setDateTo(e.target.value)} className={`${inputCls} max-w-[170px]`} aria-label="Tugash sanasi" />
                        <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={diffOnly} onChange={(e) => setDiffOnly(e.target.checked)} />Faqat farqli</label>
                    </div>
                    <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white">
                        {loading && !list ? <Spinner /> : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-left text-sm">
                                    <thead className="bg-slate-50 text-xs uppercase text-slate-500">
                                        <tr><th className="px-4 py-3">Kassa</th><th className="px-4 py-3">Xodim</th><th className="px-4 py-3">Ochildi</th><th className="px-4 py-3">Yopildi</th><th className="px-4 py-3 text-right">Kutilgan</th><th className="px-4 py-3 text-right">Haqiqiy</th><th className="px-4 py-3 text-right">Farq</th><th className="px-4 py-3">Holat</th><th className="px-4 py-3" /></tr>
                                    </thead>
                                    <tbody className="divide-y">
                                        {list?.data.map((s) => {
                                            const d = num(s.difference);
                                            return (
                                                <tr key={s.id}>
                                                    <td className="px-4 py-3">{s.cashbox?.name}</td>
                                                    <td className="px-4 py-3">{s.user?.name}</td>
                                                    <td className="whitespace-nowrap px-4 py-3">{formatDateTime(s.opened_at)}</td>
                                                    <td className="whitespace-nowrap px-4 py-3">{formatDateTime(s.closed_at)}</td>
                                                    <td className="whitespace-nowrap px-4 py-3 text-right">{s.status === "closed" ? formatUZS(s.expected_cash) : "—"}</td>
                                                    <td className="whitespace-nowrap px-4 py-3 text-right">{s.status === "closed" ? formatUZS(s.actual_cash) : "—"}</td>
                                                    <td className={`whitespace-nowrap px-4 py-3 text-right font-medium ${d < 0 ? "text-red-600" : d > 0 ? "text-amber-600" : ""}`}>{s.status === "closed" ? formatUZS(s.difference) : "—"}</td>
                                                    <td className="px-4 py-3"><StatusBadge status={s.status} />{s.reviewed_at && <span className="ml-1 text-xs text-emerald-600">✓ ko‘rib chiqilgan</span>}</td>
                                                    <td className="px-4 py-3 text-right"><Button variant="secondary" className="!px-3 !py-1.5" onClick={() => openDetail(s.id)}>Batafsil</Button></td>
                                                </tr>
                                            );
                                        })}
                                        {!list?.data.length && <tr><td colSpan={9} className="px-4 py-12 text-center text-slate-400">Smenalar topilmadi.</td></tr>}
                                    </tbody>
                                </table>
                            </div>
                        )}
                        {list && <Pagination page={list.current_page} lastPage={list.last_page} total={list.total} onPage={setPage} />}
                    </div>
                </section>
            </div>

            <OpenShiftModal open={openForm} cashboxes={cashboxes} canChooseUser={can("shifts.approve")} meId={user?.id}
                onClose={() => setOpenForm(false)}
                onDone={(w) => { setOpenForm(false); setWarning(w ?? ""); refresh("Smena ochildi."); }} />

            <CloseShiftModal target={closeTarget} onClose={() => setCloseTarget(null)}
                onDone={() => { setCloseTarget(null); setDetail(null); refresh("Smena yopildi."); }} />

            <HandoverModal target={handoverTarget} onClose={() => setHandoverTarget(null)}
                onDone={() => { setHandoverTarget(null); setDetail(null); refresh("Smena topshirildi — keyingi xodim uchun yangi smena ochildi."); }} />

            <CashOperationModal open={!!opTarget} cashboxes={cashboxes} defaultCashboxId={opTarget?.cashboxId} defaultDirection={opTarget?.direction}
                onClose={() => setOpTarget(null)} onDone={(m) => { setOpTarget(null); refresh(m); }} />

            <Modal open={!!detail} wide title={`Smena #${detail?.id ?? ""}`} onClose={() => setDetail(null)}>
                {detailLoading || !detail?.cashbox ? <Spinner /> : (
                    <ShiftDetail shift={detail} cashboxName={cashboxName(detail.cashbox_id)}
                        canReview={can("shifts.approve")}
                        canClose={can("shifts.update") && (detail.user_id === user?.id || canCloseOther)}
                        onClose={() => { setCloseTarget(detail); setDetail(null); }}
                        onReviewed={(s) => { setDetail(s); refresh("Smena ko‘rib chiqildi."); }} onError={setError} />
                )}
            </Modal>
        </AppShell>
    );
}

function OpenShiftModal({ open, cashboxes, canChooseUser, meId, onClose, onDone }: {
    open: boolean; cashboxes: CashboxRow[]; canChooseUser: boolean; meId?: number; onClose: () => void; onDone: (warning: string | null) => void;
}) {
    const [cashboxId, setCashboxId] = useState("");
    const [userId, setUserId] = useState("");
    const [users, setUsers] = useState<UserOpt[]>([]);
    const [opening, setOpening] = useState("");
    const [note, setNote] = useState("");
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState("");
    const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});

    useEffect(() => {
        if (!open) return;
        setCashboxId(cashboxes[0] ? String(cashboxes[0].id) : "");
        setUserId(meId ? String(meId) : "");
        setOpening("");
        setNote("");
        setError("");
        setFieldErrors({});
        if (canChooseUser) api<UserOpt[]>("/shifts/cashier-options").then(setUsers).catch((e) => setError(errorMessage(e)));
    }, [open, cashboxes, meId, canChooseUser]);

    const box = cashboxes.find((c) => String(c.id) === cashboxId);

    async function submit(e: FormEvent) {
        e.preventDefault();
        setSaving(true);
        setError("");
        setFieldErrors({});
        try {
            const r = await api<{ warning: string | null }>("/shifts/open", {
                method: "POST",
                body: { cashbox_id: Number(cashboxId), user_id: userId ? Number(userId) : undefined, opening_cash: opening, note: note || null },
            });
            onDone(r.warning);
        } catch (err) {
            if (err instanceof ApiError && err.status === 422) setFieldErrors(err.errors);
            setError(errorMessage(err));
        } finally {
            setSaving(false);
        }
    }

    return (
        <Modal open={open} title="Smenani ochish" onClose={onClose}>
            <form onSubmit={submit} className="space-y-4">
                {error && <Alert>{error}</Alert>}
                <Field label="Kassa" error={fieldErrors.cashbox_id?.[0]}>
                    <select required value={cashboxId} onChange={(e) => setCashboxId(e.target.value)} className={inputCls}>
                        {cashboxes.map((c) => <option key={c.id} value={c.id}>{c.name} ({c.code}){c.open_shift ? " — band" : ""}</option>)}
                    </select>
                </Field>
                {canChooseUser && (
                    <Field label="Xodim (kassir)" error={fieldErrors.user_id?.[0]}>
                        <select required value={userId} onChange={(e) => setUserId(e.target.value)} className={inputCls}>
                            {users.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
                        </select>
                    </Field>
                )}
                <Field label="Kassadagi boshlang‘ich naqd pul (sanab chiqilgan, so‘m)" error={fieldErrors.opening_cash?.[0]}>
                    <input required inputMode="decimal" value={opening} onChange={(e) => setOpening(e.target.value.replace(",", "."))} className={inputCls} placeholder="0.00" />
                </Field>
                {box?.balance !== undefined && <p className="text-xs text-slate-500">Daftar bo‘yicha kassa balansi: {formatUZS(box.balance)}. Farq bo‘lsa izoh majburiy.</p>}
                <Field label="Izoh (farq bo‘lsa sababi)" error={fieldErrors.note?.[0]}>
                    <input value={note} onChange={(e) => setNote(e.target.value)} maxLength={255} className={inputCls} />
                </Field>
                <div className="flex justify-end gap-2">
                    <Button type="button" variant="secondary" onClick={onClose}>Bekor qilish</Button>
                    <Button type="submit" loading={saving}>Smenani ochish</Button>
                </div>
            </form>
        </Modal>
    );
}

function CloseShiftModal({ target, onClose, onDone }: { target: Shift | null; onClose: () => void; onDone: () => void }) {
    const [actual, setActual] = useState("");
    const [reason, setReason] = useState("");
    const [expected, setExpected] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState("");

    useEffect(() => {
        if (!target) return;
        setActual("");
        setReason("");
        setError("");
        setExpected(target.summary?.expected_cash ?? null);
        if (!target.summary) api<Shift>(`/shifts/${target.id}`).then((s) => setExpected(s.summary?.expected_cash ?? null)).catch(() => undefined);
    }, [target]);

    const diff = expected !== null && actual !== "" ? Math.round((num(actual) - num(expected)) * 100) / 100 : null;

    async function submit(e: FormEvent) {
        e.preventDefault();
        if (!target) return;
        setSaving(true);
        setError("");
        try {
            await api(`/shifts/${target.id}/close`, { method: "POST", body: { actual_cash: actual, reason: reason || null } });
            onDone();
        } catch (err) {
            setError(errorMessage(err));
        } finally {
            setSaving(false);
        }
    }

    return (
        <Modal open={!!target} title="Smenani yopish" onClose={onClose}>
            <form onSubmit={submit} className="space-y-4">
                {error && <Alert>{error}</Alert>}
                <div className="rounded-lg bg-slate-50 p-3 text-sm">Kutilayotgan naqd pul: <b>{formatUZS(expected)}</b></div>
                <Field label="Kassadagi haqiqiy naqd pul (sanab chiqilgan, so‘m)">
                    <input required inputMode="decimal" value={actual} onChange={(e) => setActual(e.target.value.replace(",", "."))} className={inputCls} placeholder="0.00" />
                </Field>
                {diff !== null && diff !== 0 && (
                    <Alert>{diff < 0 ? `Kamomad: ${formatUZS(Math.abs(diff))}` : `Ortiqcha: ${formatUZS(diff)}`} — farq sababini kiriting.</Alert>
                )}
                <Field label="Farq sababi">
                    <input value={reason} onChange={(e) => setReason(e.target.value)} maxLength={255} required={diff !== null && diff !== 0} className={inputCls} />
                </Field>
                <p className="text-xs text-slate-500">Yopilgan smena keyin o‘zgartirilmaydi.</p>
                <div className="flex justify-end gap-2">
                    <Button type="button" variant="secondary" onClick={onClose}>Bekor qilish</Button>
                    <Button type="submit" variant="danger" loading={saving}>Smenani yopish</Button>
                </div>
            </form>
        </Modal>
    );
}

function HandoverModal({ target, onClose, onDone }: { target: Shift | null; onClose: () => void; onDone: () => void }) {
    const [users, setUsers] = useState<UserOpt[]>([]);
    const [toUser, setToUser] = useState("");
    const [actual, setActual] = useState("");
    const [reason, setReason] = useState("");
    const [expected, setExpected] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState("");

    useEffect(() => {
        if (!target) return;
        setActual("");
        setReason("");
        setToUser("");
        setError("");
        setExpected(target.summary?.expected_cash ?? null);
        api<UserOpt[]>("/shifts/handover-candidates").then(setUsers).catch((e) => setError(errorMessage(e)));
    }, [target]);

    const diff = expected !== null && actual !== "" ? Math.round((num(actual) - num(expected)) * 100) / 100 : null;

    async function submit(e: FormEvent) {
        e.preventDefault();
        if (!target) return;
        setSaving(true);
        setError("");
        try {
            await api(`/shifts/${target.id}/handover`, { method: "POST", body: { actual_cash: actual, reason: reason || null, to_user_id: Number(toUser) } });
            onDone();
        } catch (err) {
            setError(errorMessage(err));
        } finally {
            setSaving(false);
        }
    }

    return (
        <Modal open={!!target} title="Smenani topshirish" onClose={onClose}>
            <form onSubmit={submit} className="space-y-4">
                {error && <Alert>{error}</Alert>}
                <p className="text-sm text-slate-600">Joriy smena yopiladi, sanalgan summa bilan qabul qiluvchi uchun yangi smena ochiladi.</p>
                <Field label="Qabul qiluvchi xodim">
                    <select required value={toUser} onChange={(e) => setToUser(e.target.value)} className={inputCls}>
                        <option value="">Tanlang...</option>
                        {users.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
                    </select>
                </Field>
                {!users.length && <p className="text-xs text-amber-600">Qabul qila oladigan bo‘sh xodim topilmadi (smena ochish ruxsati bor va ochiq smenasiz).</p>}
                <div className="rounded-lg bg-slate-50 p-3 text-sm">Kutilayotgan naqd pul: <b>{formatUZS(expected)}</b></div>
                <Field label="Topshirilayotgan haqiqiy naqd pul (so‘m)">
                    <input required inputMode="decimal" value={actual} onChange={(e) => setActual(e.target.value.replace(",", "."))} className={inputCls} placeholder="0.00" />
                </Field>
                {diff !== null && diff !== 0 && <Alert>{diff < 0 ? `Kamomad: ${formatUZS(Math.abs(diff))}` : `Ortiqcha: ${formatUZS(diff)}`} — sababini kiriting.</Alert>}
                <Field label="Farq sababi">
                    <input value={reason} onChange={(e) => setReason(e.target.value)} maxLength={255} required={diff !== null && diff !== 0} className={inputCls} />
                </Field>
                <div className="flex justify-end gap-2">
                    <Button type="button" variant="secondary" onClick={onClose}>Bekor qilish</Button>
                    <Button type="submit" loading={saving}>Topshirish</Button>
                </div>
            </form>
        </Modal>
    );
}

function ShiftDetail({ shift, cashboxName, canReview, canClose, onClose, onReviewed, onError }: {
    shift: Shift; cashboxName: string; canReview: boolean; canClose: boolean; onClose: () => void; onReviewed: (s: Shift) => void; onError: (m: string) => void;
}) {
    const [note, setNote] = useState("");
    const [busy, setBusy] = useState(false);

    async function review() {
        setBusy(true);
        try {
            onReviewed(await api<Shift>(`/shifts/${shift.id}/review`, { method: "POST", body: { note: note || null } }));
        } catch (e) {
            onError(errorMessage(e));
        } finally {
            setBusy(false);
        }
    }

    const d = num(shift.difference);
    return (
        <div className="space-y-5">
            <dl className="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-3">
                <div><dt className="text-slate-500">Kassa</dt><dd className="font-medium">{cashboxName}</dd></div>
                <div><dt className="text-slate-500">Xodim</dt><dd className="font-medium">{shift.user?.name}</dd></div>
                <div><dt className="text-slate-500">Holat</dt><dd><StatusBadge status={shift.status} /></dd></div>
                <div><dt className="text-slate-500">Ochildi</dt><dd>{formatDateTime(shift.opened_at)}</dd></div>
                <div><dt className="text-slate-500">Yopildi</dt><dd>{formatDateTime(shift.closed_at)}{shift.closer ? ` (${shift.closer.name})` : ""}</dd></div>
                <div><dt className="text-slate-500">Boshlang‘ich naqd pul</dt><dd>{formatUZS(shift.opening_cash)}</dd></div>
                <div><dt className="text-slate-500">Kutilgan</dt><dd>{formatUZS(shift.status === "closed" ? shift.expected_cash : shift.summary?.expected_cash)}</dd></div>
                <div><dt className="text-slate-500">Haqiqiy</dt><dd>{shift.status === "closed" ? formatUZS(shift.actual_cash) : "—"}</dd></div>
                <div><dt className="text-slate-500">Farq</dt><dd className={d < 0 ? "font-semibold text-red-600" : d > 0 ? "font-semibold text-amber-600" : ""}>{shift.status === "closed" ? formatUZS(shift.difference) : "—"}</dd></div>
            </dl>
            {shift.opening_note && <p className="text-sm text-slate-600">Ochilish izohi: {shift.opening_note}</p>}
            {shift.difference_reason && <p className="text-sm text-slate-600">Farq sababi: {shift.difference_reason}</p>}
            {shift.handed_over_to && <p className="text-sm text-slate-600">Smena topshirildi: {shift.handed_over_to.name}</p>}
            {shift.handover_from_shift_id && <p className="text-sm text-slate-600">Smena #{shift.handover_from_shift_id} dan qabul qilingan.</p>}

            {shift.summary && shift.summary.by_category.length > 0 && (
                <div className="rounded-lg border">
                    <table className="w-full text-left text-sm">
                        <thead className="bg-slate-50 text-xs uppercase text-slate-500"><tr><th className="px-3 py-2">Operatsiya turi</th><th className="px-3 py-2 text-right">Soni</th><th className="px-3 py-2 text-right">Summa</th></tr></thead>
                        <tbody className="divide-y">
                            {shift.summary.by_category.map((c) => (
                                <tr key={`${c.direction}-${c.category}`}>
                                    <td className="px-3 py-2">{CATEGORY_LABELS[c.category] ?? c.category} <span className="text-xs text-slate-400">({c.direction === "in" ? "kirim" : "chiqim"})</span></td>
                                    <td className="px-3 py-2 text-right">{c.count}</td>
                                    <td className={`px-3 py-2 text-right ${c.direction === "in" ? "text-emerald-700" : "text-red-600"}`}>{formatUZS(c.total)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            {shift.sales_summary && (shift.sales_summary.sales_count > 0 || shift.sales_summary.returns_count > 0) && (
                <div className="rounded-lg border p-3 text-sm">
                    <h3 className="mb-2 font-semibold text-slate-700">Savdo (smena bo‘yicha)</h3>
                    <dl className="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                        <div><dt className="text-slate-500">Sotuvlar</dt><dd className="font-medium">{shift.sales_summary.sales_count} ta · {formatUZS(shift.sales_summary.sales_total)}</dd></div>
                        <div><dt className="text-slate-500">Qaytarishlar</dt><dd className="font-medium">{shift.sales_summary.returns_count} ta · {formatUZS(shift.sales_summary.returns_total)}</dd></div>
                        <div><dt className="text-slate-500">Qarzga sotilgan</dt><dd className="font-medium">{formatUZS(shift.sales_summary.sales_debt)}</dd></div>
                        <div><dt className="text-slate-500">Naqd bo‘lmagan sof tushum</dt><dd className="font-medium">{formatUZS(shift.sales_summary.non_cash_net)}</dd></div>
                    </dl>
                    {shift.sales_summary.by_method.length > 0 && (
                        <ul className="mt-2 space-y-0.5 text-xs text-slate-600">
                            {shift.sales_summary.by_method.map((m) => <li key={m.method}>{METHOD_LABELS[m.method] ?? m.method}: {formatUZS(m.net)}</li>)}
                        </ul>
                    )}
                </div>
            )}

            <div>
                <h3 className="mb-2 text-sm font-semibold text-slate-700">Smena operatsiyalari</h3>
                <TransactionsTable query={{ shift_id: shift.id }} />
            </div>

            {shift.status === "open" && canClose && <div className="flex justify-end"><Button variant="danger" onClick={onClose}><Square size={16} />Smenani yopish</Button></div>}

            {shift.status === "closed" && (shift.reviewed_at ? (
                <Alert kind="success">Ko‘rib chiqildi: {shift.reviewer?.name} — {formatDateTime(shift.reviewed_at)}{shift.review_note ? `. ${shift.review_note}` : ""}</Alert>
            ) : canReview && (
                <div className="flex flex-wrap items-end gap-3 border-t pt-4">
                    <div className="min-w-[220px] flex-1"><Field label="Ko‘rib chiqish izohi (ixtiyoriy)"><input value={note} onChange={(e) => setNote(e.target.value)} maxLength={255} className={inputCls} /></Field></div>
                    <Button loading={busy} onClick={review}><CheckCheck size={16} />Ko‘rib chiqildi</Button>
                </div>
            ))}
        </div>
    );
}
