"use client";
import { FormEvent, useCallback, useEffect, useRef, useState } from "react";
import { Alert, Button, Field, Modal, Pagination, Spinner, inputCls } from "./ui";
import { api, errorMessage, ApiError, Paginated } from "../app/lib/api";
import { CATEGORY_LABELS, CashTx, CashboxRow } from "../app/lib/cash";
import { newToken } from "../app/lib/documents";
import { formatDateTime, formatUZS } from "../app/lib/format";

/** Kassaga qo‘lda kirim/chiqim. client_token qayta yuborilganda ikkinchi yozuv yaratilmasligini kafolatlaydi. */
export function CashOperationModal({ open, cashboxes, defaultCashboxId, defaultDirection = "in", onClose, onDone }: {
    open: boolean; cashboxes: CashboxRow[]; defaultCashboxId?: number; defaultDirection?: "in" | "out"; onClose: () => void; onDone: (msg: string) => void;
}) {
    const [cashboxId, setCashboxId] = useState("");
    const [direction, setDirection] = useState<"in" | "out">("in");
    const [amount, setAmount] = useState("");
    const [note, setNote] = useState("");
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState("");
    const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});
    const token = useRef(newToken());

    useEffect(() => {
        if (!open) return;
        setCashboxId(defaultCashboxId ? String(defaultCashboxId) : cashboxes[0] ? String(cashboxes[0].id) : "");
        setDirection(defaultDirection);
        setAmount("");
        setNote("");
        setError("");
        setFieldErrors({});
        token.current = newToken();
    }, [open, defaultCashboxId, defaultDirection, cashboxes]);

    async function submit(e: FormEvent) {
        e.preventDefault();
        setSaving(true);
        setError("");
        setFieldErrors({});
        try {
            await api("/cash-transactions", { method: "POST", body: { cashbox_id: Number(cashboxId), direction, amount, note, client_token: token.current } });
            onDone(direction === "in" ? "Kassaga kirim qayd etildi." : "Kassadan chiqim qayd etildi.");
        } catch (err) {
            if (err instanceof ApiError && err.status === 422) setFieldErrors(err.errors);
            setError(errorMessage(err));
        } finally {
            setSaving(false);
        }
    }

    return (
        <Modal open={open} title="Kassa operatsiyasi" onClose={onClose}>
            <form onSubmit={submit} className="space-y-4">
                {error && <Alert>{error}</Alert>}
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <Field label="Kassa" error={fieldErrors.cashbox_id?.[0]}>
                        <select required value={cashboxId} onChange={(e) => setCashboxId(e.target.value)} className={inputCls}>
                            {cashboxes.map((c) => <option key={c.id} value={c.id}>{c.name} ({c.code})</option>)}
                        </select>
                    </Field>
                    <Field label="Operatsiya turi">
                        <select value={direction} onChange={(e) => setDirection(e.target.value as "in" | "out")} className={inputCls}>
                            <option value="in">Kirim (kassaga pul kirdi)</option>
                            <option value="out">Chiqim (kassadan pul chiqdi)</option>
                        </select>
                    </Field>
                </div>
                <Field label="Summa (so‘m)" error={fieldErrors.amount?.[0]}>
                    <input required inputMode="decimal" value={amount} onChange={(e) => setAmount(e.target.value.replace(",", "."))} className={inputCls} placeholder="0.00" />
                </Field>
                <Field label="Izoh (sabab)" error={fieldErrors.note?.[0]}>
                    <input required minLength={3} maxLength={255} value={note} onChange={(e) => setNote(e.target.value)} className={inputCls} placeholder="Masalan: suv xarid qilindi" />
                </Field>
                <div className="flex justify-end gap-2">
                    <Button type="button" variant="secondary" onClick={onClose}>Bekor qilish</Button>
                    <Button type="submit" loading={saving}>Saqlash</Button>
                </div>
            </form>
        </Modal>
    );
}

/** Kassa daftari (tarix) jadvali. */
export function TransactionsTable({ query }: { query: Record<string, string | number | undefined> }) {
    const [rows, setRows] = useState<Paginated<CashTx> | null>(null);
    const [page, setPage] = useState(1);
    const [error, setError] = useState("");
    const [loading, setLoading] = useState(true);
    const key = JSON.stringify(query);

    const load = useCallback(async () => {
        setLoading(true);
        setError("");
        try {
            setRows(await api<Paginated<CashTx>>("/cash-transactions", { query: { ...JSON.parse(key), page } }));
        } catch (e) {
            setError(errorMessage(e));
        } finally {
            setLoading(false);
        }
    }, [key, page]);

    useEffect(() => { setPage(1); }, [key]);
    useEffect(() => { load(); }, [load]);

    if (loading && !rows) return <Spinner />;
    return (
        <div className="space-y-3">
            {error && <Alert>{error}</Alert>}
            <div className="overflow-x-auto rounded-lg border">
                <table className="w-full text-left text-sm">
                    <thead className="bg-slate-50 text-xs uppercase text-slate-500">
                        <tr><th className="px-3 py-2">Sana</th><th className="px-3 py-2">Tur</th><th className="px-3 py-2">Izoh</th><th className="px-3 py-2">Xodim</th><th className="px-3 py-2 text-right">Kirim</th><th className="px-3 py-2 text-right">Chiqim</th><th className="px-3 py-2 text-right">Qoldiq</th></tr>
                    </thead>
                    <tbody className="divide-y">
                        {rows?.data.map((t) => (
                            <tr key={t.id}>
                                <td className="whitespace-nowrap px-3 py-2">{formatDateTime(t.occurred_at)}</td>
                                <td className="px-3 py-2">{CATEGORY_LABELS[t.category] ?? t.category}</td>
                                <td className="px-3 py-2">{t.note || "—"}</td>
                                <td className="px-3 py-2">{t.user?.name ?? "—"}</td>
                                <td className="whitespace-nowrap px-3 py-2 text-right text-emerald-700">{t.direction === "in" ? formatUZS(t.amount) : ""}</td>
                                <td className="whitespace-nowrap px-3 py-2 text-right text-red-600">{t.direction === "out" ? formatUZS(t.amount) : ""}</td>
                                <td className="whitespace-nowrap px-3 py-2 text-right font-medium">{formatUZS(t.balance_after)}</td>
                            </tr>
                        ))}
                        {!rows?.data.length && <tr><td colSpan={7} className="px-3 py-10 text-center text-slate-400">Operatsiyalar topilmadi.</td></tr>}
                    </tbody>
                </table>
            </div>
            {rows && <Pagination page={rows.current_page} lastPage={rows.last_page} total={rows.total} onPage={setPage} />}
        </div>
    );
}
