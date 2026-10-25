"use client";
import { FormEvent, useCallback, useEffect, useMemo, useState } from "react";
import { CalendarPlus, ChevronLeft, ChevronRight } from "lucide-react";
import AppShell from "../../components/AppShell";
import { Alert, Button, Field, Modal, Spinner, inputCls } from "../../components/ui";
import { api, errorMessage, ApiError, Paginated } from "../lib/api";
import { SCHEDULE_TYPES, ScheduleRow, addDays, mondayOf, todayTashkent } from "../lib/cash";
import { useAuth } from "../lib/auth";

type Emp = { id: number; position: string; status: string; user?: { id: number; name: string } | null };
type CellForm = { employeeId: number; employeeName: string; date: string; existing: ScheduleRow | null; type: string; start: string; end: string; note: string };
type BulkForm = { employeeId: string; from: string; to: string; pattern: "all" | "alternate"; type: string; start: string; end: string };

const DAY_NAMES = ["Du", "Se", "Chor", "Pay", "Ju", "Sha", "Yak"];

export default function WorkSchedulesPage() {
    const { can } = useAuth();
    const canEdit = can("employees.update");
    const [weekStart, setWeekStart] = useState(() => mondayOf(todayTashkent()));
    const [employees, setEmployees] = useState<Emp[]>([]);
    const [rows, setRows] = useState<ScheduleRow[]>([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState("");
    const [notice, setNotice] = useState("");
    const [cell, setCell] = useState<CellForm | null>(null);
    const [bulk, setBulk] = useState<BulkForm | null>(null);
    const [saving, setSaving] = useState(false);
    const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});

    const days = useMemo(() => Array.from({ length: 7 }, (_, i) => addDays(weekStart, i)), [weekStart]);
    const today = todayTashkent();

    const load = useCallback(async () => {
        setLoading(true);
        setError("");
        try {
            const [emps, sched] = await Promise.all([
                api<Paginated<Emp>>("/employees", { query: { status: "active", per_page: 100 } }),
                api<ScheduleRow[]>("/work-schedules", { query: { date_from: weekStart, date_to: addDays(weekStart, 6) } }),
            ]);
            setEmployees(emps.data);
            setRows(sched);
        } catch (e) {
            setError(errorMessage(e));
        } finally {
            setLoading(false);
        }
    }, [weekStart]);

    useEffect(() => { load(); }, [load]);

    const byKey = useMemo(() => {
        const m = new Map<string, ScheduleRow>();
        rows.forEach((r) => m.set(`${r.employee_id}|${r.work_date}`, r));
        return m;
    }, [rows]);

    function openCell(emp: Emp, date: string) {
        if (!canEdit) return;
        const existing = byKey.get(`${emp.id}|${date}`) ?? null;
        setFieldErrors({});
        setCell({
            employeeId: emp.id, employeeName: emp.user?.name ?? `Xodim #${emp.id}`, date, existing,
            type: existing?.type ?? "work", start: existing?.start_time?.slice(0, 5) ?? "09:00", end: existing?.end_time?.slice(0, 5) ?? "18:00", note: existing?.note ?? "",
        });
    }

    async function saveCell(e: FormEvent) {
        e.preventDefault();
        if (!cell) return;
        setSaving(true);
        setFieldErrors({});
        setError("");
        try {
            await api("/work-schedules", {
                method: "POST",
                body: { employee_id: cell.employeeId, work_date: cell.date, type: cell.type, start_time: cell.type === "work" ? cell.start : null, end_time: cell.type === "work" ? cell.end : null, note: cell.note || null },
            });
            setNotice("Jadval saqlandi.");
            setCell(null);
            await load();
        } catch (err) {
            if (err instanceof ApiError && err.status === 422) setFieldErrors(err.errors);
            setError(errorMessage(err));
        } finally {
            setSaving(false);
        }
    }

    async function clearCell() {
        if (!cell?.existing) return;
        setSaving(true);
        setError("");
        try {
            await api(`/work-schedules/${cell.existing.id}`, { method: "DELETE" });
            setNotice("Kun jadvaldan olib tashlandi.");
            setCell(null);
            await load();
        } catch (err) {
            setError(errorMessage(err));
        } finally {
            setSaving(false);
        }
    }

    async function saveBulk(e: FormEvent) {
        e.preventDefault();
        if (!bulk) return;
        const dates: string[] = [];
        for (let d = bulk.from, i = 0; d <= bulk.to && i < 62; d = addDays(d, 1), i++) {
            if (bulk.pattern === "all" || i % 2 === 0) dates.push(d);
        }
        if (!dates.length) { setError("Sana oralig‘i noto‘g‘ri."); return; }
        setSaving(true);
        setFieldErrors({});
        setError("");
        try {
            await api("/work-schedules", {
                method: "POST",
                body: { employee_id: Number(bulk.employeeId), dates, type: bulk.type, start_time: bulk.type === "work" ? bulk.start : null, end_time: bulk.type === "work" ? bulk.end : null },
            });
            setNotice(`${dates.length} kun belgilandi.`);
            setBulk(null);
            await load();
        } catch (err) {
            if (err instanceof ApiError && err.status === 422) setFieldErrors(err.errors);
            setError(errorMessage(err));
        } finally {
            setSaving(false);
        }
    }

    const timeFields = (type: string, start: string, end: string, set: (s: string, e: string) => void) => type === "work" && (
        <div className="grid grid-cols-2 gap-4">
            <Field label="Boshlanishi" error={fieldErrors.start_time?.[0]}><input type="time" required value={start} onChange={(e) => set(e.target.value, end)} className={inputCls} /></Field>
            <Field label="Tugashi" error={fieldErrors.end_time?.[0]}><input type="time" required value={end} onChange={(e) => set(start, e.target.value)} className={inputCls} /></Field>
        </div>
    );

    return (
        <AppShell title="Ish jadvali" subtitle="Kim qaysi kuni ishlaydi. Kassa smenasidan alohida: jadval rejani, smena esa real pul harakatini bildiradi." permission="employees.view"
            actions={canEdit && employees.length > 0 && (
                <Button onClick={() => { setFieldErrors({}); setBulk({ employeeId: String(employees[0].id), from: weekStart, to: addDays(weekStart, 13), pattern: "alternate", type: "work", start: "09:00", end: "18:00" }); }}>
                    <CalendarPlus size={16} />Ommaviy belgilash
                </Button>
            )}>
            <div className="space-y-4">
                {notice && <Alert kind="success">{notice}</Alert>}
                {error && <Alert>{error}</Alert>}
                <div className="flex items-center gap-3">
                    <Button variant="secondary" aria-label="Oldingi hafta" onClick={() => setWeekStart(addDays(weekStart, -7))}><ChevronLeft size={16} /></Button>
                    <div className="text-sm font-medium">{weekStart} — {addDays(weekStart, 6)}</div>
                    <Button variant="secondary" aria-label="Keyingi hafta" onClick={() => setWeekStart(addDays(weekStart, 7))}><ChevronRight size={16} /></Button>
                    <Button variant="secondary" onClick={() => setWeekStart(mondayOf(today))}>Bu hafta</Button>
                </div>

                <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white">
                    {loading && !employees.length ? <Spinner /> : (
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[760px] text-left text-sm">
                                <thead className="bg-slate-50 text-xs uppercase text-slate-500">
                                    <tr>
                                        <th className="px-4 py-3">Xodim</th>
                                        {days.map((d, i) => <th key={d} className={`px-2 py-3 text-center ${d === today ? "text-blue-600" : ""}`}>{DAY_NAMES[i]}<div className="font-normal normal-case">{d.slice(8)}.{d.slice(5, 7)}</div></th>)}
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {employees.map((emp) => (
                                        <tr key={emp.id}>
                                            <td className="px-4 py-2"><div className="font-medium">{emp.user?.name ?? `#${emp.id}`}</div><div className="text-xs text-slate-500">{emp.position}</div></td>
                                            {days.map((d) => {
                                                const r = byKey.get(`${emp.id}|${d}`);
                                                const t = r ? SCHEDULE_TYPES[r.type] : null;
                                                return (
                                                    <td key={d} className="px-1 py-1.5 text-center">
                                                        <button type="button" disabled={!canEdit} onClick={() => openCell(emp, d)}
                                                            className={`w-full rounded-lg px-1 py-2 text-xs ${t ? t.cls : "border border-dashed border-slate-200 text-slate-300"} ${canEdit ? "hover:ring-2 hover:ring-blue-200" : "cursor-default"}`}>
                                                            {t ? <>{t.short}{r?.type === "work" && r.start_time && <div>{r.start_time.slice(0, 5)}–{r.end_time?.slice(0, 5)}</div>}</> : "—"}
                                                        </button>
                                                    </td>
                                                );
                                            })}
                                        </tr>
                                    ))}
                                    {!employees.length && <tr><td colSpan={8} className="px-4 py-12 text-center text-slate-400">Faol xodim topilmadi. Avval “Xodimlar” bo‘limida xodim qo‘shing.</td></tr>}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>
                <div className="flex flex-wrap gap-2 text-xs">
                    {Object.entries(SCHEDULE_TYPES).map(([k, v]) => <span key={k} className={`rounded-full px-2.5 py-1 ${v.cls}`}>{v.label}</span>)}
                </div>
            </div>

            <Modal open={!!cell} title={cell ? `${cell.employeeName} — ${cell.date}` : ""} onClose={() => setCell(null)}>
                {cell && (
                    <form onSubmit={saveCell} className="space-y-4">
                        <Field label="Holat" error={fieldErrors.type?.[0]}>
                            <select value={cell.type} onChange={(e) => setCell({ ...cell, type: e.target.value })} className={inputCls}>
                                {Object.entries(SCHEDULE_TYPES).map(([k, v]) => <option key={k} value={k}>{v.label}</option>)}
                            </select>
                        </Field>
                        {timeFields(cell.type, cell.start, cell.end, (s, e2) => setCell({ ...cell, start: s, end: e2 }))}
                        <Field label="Izoh" error={fieldErrors.note?.[0]}><input value={cell.note} maxLength={255} onChange={(e) => setCell({ ...cell, note: e.target.value })} className={inputCls} /></Field>
                        <div className="flex items-center justify-between gap-2">
                            <div>{cell.existing && <Button type="button" variant="secondary" className="text-red-600" loading={saving} onClick={clearCell}>Kunni tozalash</Button>}</div>
                            <div className="flex gap-2">
                                <Button type="button" variant="secondary" onClick={() => setCell(null)}>Bekor qilish</Button>
                                <Button type="submit" loading={saving}>Saqlash</Button>
                            </div>
                        </div>
                    </form>
                )}
            </Modal>

            <Modal open={!!bulk} title="Ommaviy belgilash" onClose={() => setBulk(null)}>
                {bulk && (
                    <form onSubmit={saveBulk} className="space-y-4">
                        <p className="text-sm text-slate-600">Masalan, “kunora” — oraliqning birinchi kunidan boshlab bir kun ishlaydi, bir kun dam oladi (dam kunlari jadvalga yozilmaydi). Oraliq 62 kundan oshmasin.</p>
                        <Field label="Xodim" error={fieldErrors.employee_id?.[0]}>
                            <select value={bulk.employeeId} onChange={(e) => setBulk({ ...bulk, employeeId: e.target.value })} className={inputCls}>
                                {employees.map((x) => <option key={x.id} value={x.id}>{x.user?.name ?? `#${x.id}`}</option>)}
                            </select>
                        </Field>
                        <div className="grid grid-cols-2 gap-4">
                            <Field label="Sanadan"><input type="date" required value={bulk.from} onChange={(e) => setBulk({ ...bulk, from: e.target.value })} className={inputCls} /></Field>
                            <Field label="Sanagacha"><input type="date" required value={bulk.to} min={bulk.from} onChange={(e) => setBulk({ ...bulk, to: e.target.value })} className={inputCls} /></Field>
                        </div>
                        <div className="grid grid-cols-2 gap-4">
                            <Field label="Qaysi kunlar">
                                <select value={bulk.pattern} onChange={(e) => setBulk({ ...bulk, pattern: e.target.value as "all" | "alternate" })} className={inputCls}>
                                    <option value="alternate">Kunora</option><option value="all">Har kuni</option>
                                </select>
                            </Field>
                            <Field label="Holat" error={fieldErrors.type?.[0]}>
                                <select value={bulk.type} onChange={(e) => setBulk({ ...bulk, type: e.target.value })} className={inputCls}>
                                    {Object.entries(SCHEDULE_TYPES).map(([k, v]) => <option key={k} value={k}>{v.label}</option>)}
                                </select>
                            </Field>
                        </div>
                        {timeFields(bulk.type, bulk.start, bulk.end, (s, e2) => setBulk({ ...bulk, start: s, end: e2 }))}
                        <div className="flex justify-end gap-2">
                            <Button type="button" variant="secondary" onClick={() => setBulk(null)}>Bekor qilish</Button>
                            <Button type="submit" loading={saving}>Belgilash</Button>
                        </div>
                    </form>
                )}
            </Modal>
        </AppShell>
    );
}
