"use client";
import { FormEvent, useCallback, useEffect, useRef, useState } from "react";
import { Ban, Download, Printer, Search, Undo2 } from "lucide-react";
import AppShell from "../../components/AppShell";
import { ReceiptModal } from "../../components/Receipt";
import { Alert, Button, Field, Modal, Pagination, Spinner, StatCard, StatusBadge, inputCls, useDebounce } from "../../components/ui";
import { api, errorMessage, Paginated } from "../lib/api";
import { newToken } from "../lib/documents";
import { formatDateTime, formatQty, formatUZS } from "../lib/format";
import { METHOD_LABELS, PAY_METHODS, REFERENCE_REQUIRED, ReturnableRow, SaleRow, normNumber } from "../lib/sales";
import { useAuth } from "../lib/auth";
import { downloadCsv, todayStamp } from "../lib/csv";
import InsightsPanel from "../../components/InsightsPanel";

export default function SalesPage() {
    const { can } = useAuth();
    const [rows, setRows] = useState<Paginated<SaleRow> | null>(null);
    const [page, setPage] = useState(1);
    const [search, setSearch] = useState("");
    const debounced = useDebounce(search);
    const [type, setType] = useState("");
    const [status, setStatus] = useState("");
    const [from, setFrom] = useState("");
    const [to, setTo] = useState("");
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState("");
    const [notice, setNotice] = useState("");

    const [detail, setDetail] = useState<SaleRow | null>(null);
    const [detailLoading, setDetailLoading] = useState(false);
    const [receipt, setReceipt] = useState<SaleRow | null>(null);
    const [returning, setReturning] = useState<SaleRow | null>(null);
    const [cancelling, setCancelling] = useState<SaleRow | null>(null);

    const load = useCallback(async () => {
        setLoading(true);
        setError("");
        try {
            setRows(await api<Paginated<SaleRow>>("/sales", { query: { page, search: debounced, type, status, date_from: from, date_to: to } }));
        } catch (e) {
            setError(errorMessage(e));
        } finally {
            setLoading(false);
        }
    }, [page, debounced, type, status, from, to]);

    useEffect(() => { load(); }, [load]);
    useEffect(() => { setPage(1); }, [debounced, type, status, from, to]);

    // Ko'rsatilgan sahifadagi tasdiqlangan hujjatlar bo'yicha yig'indi (qaytarishlar minus)
    const pageTotals = (rows?.data ?? []).filter((s) => s.status === "approved").reduce(
        (a, s) => {
            const k = s.type === "return" ? -1 : 1;
            return { total: a.total + k * Number(s.total), paid: a.paid + k * Number(s.paid_amount), debt: a.debt + k * Number(s.debt_amount) };
        },
        { total: 0, paid: 0, debt: 0 },
    );

    function exportCsv() {
        downloadCsv(`sotuvlar-${todayStamp()}.csv`, [
            ["Chek", "Turi", "Vaqt", "Mijoz", "Kassir", "Jami", "To'langan", "Qarz", "Holat"],
            ...(rows?.data ?? []).map((s) => [
                s.number, s.type === "return" ? "Qaytarish" : "Sotuv", formatDateTime(s.sold_at), s.customer?.name ?? "",
                s.creator?.name ?? "", s.total, s.paid_amount, s.debt_amount, s.status === "approved" ? "Tasdiqlangan" : "Bekor qilingan",
            ]),
        ]);
    }

    async function openDetail(id: number) {
        setDetailLoading(true);
        setError("");
        try {
            setDetail(await api<SaleRow>(`/sales/${id}`));
        } catch (e) {
            setError(errorMessage(e));
        } finally {
            setDetailLoading(false);
        }
    }

    async function refreshDetail(id: number, msg: string) {
        setNotice(msg);
        setReturning(null);
        setCancelling(null);
        await load();
        try {
            setDetail(await api<SaleRow>(`/sales/${id}`));
        } catch {
            setDetail(null);
        }
    }

    return (
        <AppShell title="Sotuv tarixi" subtitle="Sotuvlar, qaytarishlar va cheklar. Tasdiqlangan sotuv o‘chirilmaydi — faqat bekor qilinadi yoki qaytariladi." permission="sales.view"
            actions={<Button variant="secondary" onClick={exportCsv} disabled={!rows?.data.length}><Download size={16} />CSV</Button>}>
            <div className="space-y-4">
                {notice && <Alert kind="success">{notice}</Alert>}
                {error && <Alert>{error}</Alert>}
                <InsightsPanel endpoint="/stats/products" />

                {rows && rows.data.length > 0 && (
                    <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                        <StatCard title="Hujjatlar (filtr bo‘yicha)" value={String(rows.total)} />
                        <StatCard title="Sof summa" value={formatUZS(pageTotals.total)} hint="Shu sahifa, qaytarishlar ayirilgan" />
                        <StatCard title="To‘langan" value={formatUZS(pageTotals.paid)} tone="good" hint="Shu sahifa" />
                        <StatCard title="Qarz" value={formatUZS(pageTotals.debt)} tone={pageTotals.debt > 0 ? "bad" : "default"} hint="Shu sahifa" />
                    </div>
                )}
                <div className="grid gap-3 md:grid-cols-5">
                    <div className="relative md:col-span-2">
                        <Search size={16} className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
                        <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Chek raqami yoki mijoz" className={`${inputCls} pl-9`} aria-label="Qidirish" />
                    </div>
                    <select value={type} onChange={(e) => setType(e.target.value)} className={inputCls} aria-label="Turi">
                        <option value="">Barcha turlar</option><option value="sale">Sotuv</option><option value="return">Qaytarish</option>
                    </select>
                    <select value={status} onChange={(e) => setStatus(e.target.value)} className={inputCls} aria-label="Holati">
                        <option value="">Barcha holatlar</option><option value="approved">Tasdiqlangan</option><option value="cancelled">Bekor qilingan</option>
                    </select>
                    <div className="grid grid-cols-2 gap-2">
                        <input type="date" value={from} onChange={(e) => setFrom(e.target.value)} className={inputCls} aria-label="Sanadan" />
                        <input type="date" value={to} onChange={(e) => setTo(e.target.value)} className={inputCls} aria-label="Sanagacha" />
                    </div>
                </div>

                <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white">
                    {loading && !rows ? <Spinner /> : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-sm">
                                <thead className="bg-slate-50 text-xs uppercase text-slate-500">
                                    <tr>
                                        <th className="px-5 py-3">Chek</th><th className="px-5 py-3">Vaqt</th><th className="px-5 py-3">Mijoz</th><th className="px-5 py-3">Kassir</th>
                                        <th className="px-5 py-3 text-right">Jami</th><th className="px-5 py-3 text-right">To‘langan</th><th className="px-5 py-3 text-right">Qarz</th><th className="px-5 py-3">Holat</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {rows?.data.map((s) => (
                                        <tr key={s.id} onClick={() => openDetail(s.id)} className="cursor-pointer hover:bg-slate-50">
                                            <td className="px-5 py-3 font-medium">
                                                {s.number}
                                                {s.type === "return" && <span className="ml-2 rounded-full bg-violet-50 px-2 py-0.5 text-xs font-semibold text-violet-700">Qaytarish</span>}
                                            </td>
                                            <td className="whitespace-nowrap px-5 py-3">{formatDateTime(s.sold_at)}</td>
                                            <td className="px-5 py-3">{s.customer?.name ?? "—"}</td>
                                            <td className="px-5 py-3 text-slate-600">{s.creator?.name ?? "—"}</td>
                                            <td className="px-5 py-3 text-right font-semibold">{formatUZS(s.total)}</td>
                                            <td className="px-5 py-3 text-right">{formatUZS(s.paid_amount)}</td>
                                            <td className={`px-5 py-3 text-right ${Number(s.debt_amount) > 0 ? "font-semibold text-red-600" : ""}`}>{Number(s.debt_amount) > 0 ? formatUZS(s.debt_amount) : "—"}</td>
                                            <td className="px-5 py-3"><StatusBadge status={s.status} /></td>
                                        </tr>
                                    ))}
                                    {!rows?.data.length && <tr><td colSpan={8} className="px-5 py-12 text-center text-slate-400">Sotuv topilmadi.</td></tr>}
                                </tbody>
                            </table>
                        </div>
                    )}
                    {rows && <Pagination page={rows.current_page} lastPage={rows.last_page} total={rows.total} onPage={setPage} />}
                </div>
            </div>

            <Modal open={detailLoading && !detail} title="Yuklanmoqda" onClose={() => undefined}><Spinner /></Modal>

            {detail && (
                <Modal open title={`${detail.type === "return" ? "Qaytarish" : "Sotuv"} ${detail.number}`} onClose={() => setDetail(null)} wide>
                    <SaleDetail
                        sale={detail}
                        canReturn={can("sales.update")}
                        canCancel={can("sales.cancel")}
                        onReceipt={() => setReceipt(detail)}
                        onReturn={() => setReturning(detail)}
                        onCancel={() => setCancelling(detail)}
                        onOpenSale={(id) => openDetail(id)}
                    />
                </Modal>
            )}

            <ReceiptModal open={!!receipt} sale={receipt} info={receipt?.receipt_info ?? null} onClose={() => setReceipt(null)} />

            {returning && (
                <ReturnModal sale={returning} onClose={() => setReturning(null)} onDone={(msg) => refreshDetail(returning.id, msg)} />
            )}
            {cancelling && (
                <CancelModal sale={cancelling} onClose={() => setCancelling(null)} onDone={(msg) => refreshDetail(cancelling.id, msg)} />
            )}
        </AppShell>
    );
}

function SaleDetail({ sale, canReturn, canCancel, onReceipt, onReturn, onCancel, onOpenSale }: {
    sale: SaleRow; canReturn: boolean; canCancel: boolean; onReceipt: () => void; onReturn: () => void; onCancel: () => void; onOpenSale: (id: number) => void;
}) {
    const approvedReturns = (sale.returns ?? []).filter((r) => r.status === "approved");
    const isSale = sale.type === "sale";
    const payments = sale.payments ?? [];

    return (
        <div className="space-y-5">
            <dl className="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-3">
                <div><dt className="text-slate-500">Holat</dt><dd><StatusBadge status={sale.status} /></dd></div>
                <div><dt className="text-slate-500">Vaqt</dt><dd>{formatDateTime(sale.sold_at)}</dd></div>
                <div><dt className="text-slate-500">Kassir</dt><dd>{sale.creator?.name ?? "—"}</dd></div>
                <div><dt className="text-slate-500">Mijoz</dt><dd>{sale.customer?.name ?? "—"}</dd></div>
                <div><dt className="text-slate-500">Kassa / ombor</dt><dd>{sale.cashbox?.name ?? "—"} / {sale.warehouse?.name ?? "—"}</dd></div>
                {sale.parent && (
                    <div><dt className="text-slate-500">Asl sotuv</dt><dd><button className="font-medium text-blue-700 hover:underline" onClick={() => onOpenSale(sale.parent!.id)}>{sale.parent.number}</button></dd></div>
                )}
            </dl>

            {sale.status === "cancelled" && (
                <Alert>Bekor qilingan {sale.cancelled_at ? formatDateTime(sale.cancelled_at) : ""}{sale.canceller ? ` (${sale.canceller.name})` : ""}. Sabab: {sale.cancel_reason}</Alert>
            )}
            {sale.type === "return" && sale.note && <p className="text-sm text-slate-600">Qaytarish sababi: {sale.note}</p>}

            <div className="overflow-x-auto rounded-lg border">
                <table className="w-full text-left text-sm">
                    <thead className="bg-slate-50 text-xs uppercase text-slate-500">
                        <tr><th className="px-3 py-2">Mahsulot</th><th className="px-3 py-2 text-right">Miqdor</th><th className="px-3 py-2 text-right">Narx</th><th className="px-3 py-2 text-right">Chegirma</th><th className="px-3 py-2 text-right">Summa</th></tr>
                    </thead>
                    <tbody className="divide-y">
                        {(sale.items ?? []).map((i) => (
                            <tr key={i.id}>
                                <td className="px-3 py-2"><div className="font-medium">{i.product?.name}</div><div className="text-xs text-slate-400">{i.product?.sku}</div></td>
                                <td className="px-3 py-2 text-right">{formatQty(i.quantity, i.product?.unit?.short_name)}</td>
                                <td className="px-3 py-2 text-right">{formatUZS(i.price)}</td>
                                <td className="px-3 py-2 text-right">{Number(i.discount) + Number(i.discount_share) > 0 ? formatUZS(Number(i.discount) + Number(i.discount_share)) : "—"}</td>
                                <td className="px-3 py-2 text-right font-semibold">{formatUZS(i.net_total)}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            <div className="grid gap-5 md:grid-cols-2">
                <dl className="space-y-1.5 text-sm">
                    {isSale && <div className="flex justify-between"><dt className="text-slate-500">Jami (chegirmasiz)</dt><dd>{formatUZS(sale.subtotal)}</dd></div>}
                    {isSale && <div className="flex justify-between"><dt className="text-slate-500">Chegirma</dt><dd>{formatUZS(sale.discount)}</dd></div>}
                    <div className="flex justify-between border-t pt-1.5"><dt className="font-semibold">{isSale ? "Jami" : "Qaytarilgan qiymat"}</dt><dd className="font-bold">{formatUZS(sale.total)}</dd></div>
                    <div className="flex justify-between"><dt className="text-slate-500">{isSale ? "To‘langan" : "Mijozga qaytarilgan pul"}</dt><dd>{formatUZS(sale.paid_amount)}</dd></div>
                    {Number(sale.debt_amount) > 0 && <div className="flex justify-between"><dt className="text-slate-500">{isSale ? "Qarzga" : "Qarzdan ayrilgan"}</dt><dd className="font-semibold text-red-600">{formatUZS(sale.debt_amount)}</dd></div>}
                </dl>
                <div>
                    <h3 className="mb-1.5 text-sm font-semibold text-slate-700">To‘lovlar</h3>
                    {payments.length ? (
                        <ul className="space-y-1 text-sm">
                            {payments.map((p) => (
                                <li key={p.id} className="flex justify-between gap-3">
                                    <span>
                                        {METHOD_LABELS[p.method] ?? p.method}
                                        <span className="text-xs text-slate-400"> ({p.kind === "payment" ? "qabul" : p.kind === "refund" ? "qaytarildi" : "bekor qilish"})</span>
                                        {p.reference && <span className="block text-xs text-slate-500">№ {p.reference}</span>}
                                        {REFERENCE_REQUIRED.includes(p.method) && !p.is_online_verified && <span className="block text-xs text-amber-700">Avtomatik tekshirilmagan (kassir tasdiqlagan)</span>}
                                    </span>
                                    <span className={p.direction === "out" ? "text-red-600" : "text-emerald-700"}>{p.direction === "out" ? "−" : "+"}{formatUZS(p.amount)}</span>
                                </li>
                            ))}
                        </ul>
                    ) : <p className="text-sm text-slate-400">To‘lov yozuvi yo‘q{isSale && Number(sale.debt_amount) > 0 ? " (to‘liq qarzga)" : ""}.</p>}
                </div>
            </div>

            {isSale && approvedReturns.length > 0 && (
                <div>
                    <h3 className="mb-1.5 text-sm font-semibold text-slate-700">Qaytarishlar</h3>
                    <ul className="space-y-1 text-sm">
                        {approvedReturns.map((r) => (
                            <li key={r.id} className="flex justify-between"><button className="font-medium text-blue-700 hover:underline" onClick={() => onOpenSale(r.id)}>{r.number}</button><span>{formatUZS(r.total)}</span></li>
                        ))}
                    </ul>
                </div>
            )}

            <div className="flex flex-wrap justify-end gap-2 border-t pt-4">
                <Button variant="secondary" onClick={onReceipt}><Printer size={16} /> Chek</Button>
                {isSale && sale.status === "approved" && canReturn && <Button variant="secondary" onClick={onReturn}><Undo2 size={16} /> Qaytarish</Button>}
                {isSale && sale.status === "approved" && canCancel && approvedReturns.length === 0 && <Button variant="danger" onClick={onCancel}><Ban size={16} /> Bekor qilish</Button>}
            </div>
        </div>
    );
}

function ReturnModal({ sale, onClose, onDone }: { sale: SaleRow; onClose: () => void; onDone: (msg: string) => void }) {
    const [items, setItems] = useState<ReturnableRow[] | null>(null);
    const [qty, setQty] = useState<Record<number, string>>({});
    const [method, setMethod] = useState("cash");
    const [reference, setReference] = useState("");
    const [note, setNote] = useState("");
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState("");
    const token = useRef(newToken());

    useEffect(() => {
        api<{ items: ReturnableRow[] }>(`/sales/${sale.id}/returnable`).then((r) => setItems(r.items)).catch((e) => setError(errorMessage(e)));
    }, [sale.id]);

    const picked = (Object.entries(qty) as [string, string][]).filter(([, v]) => parseFloat(normNumber(v)) > 0);

    async function submit(e: FormEvent) {
        e.preventDefault();
        setSaving(true);
        setError("");
        try {
            await api(`/sales/${sale.id}/returns`, {
                method: "POST",
                body: {
                    client_token: token.current,
                    note,
                    refund_method: method,
                    reference: reference.trim() || undefined,
                    items: picked.map(([id, v]) => ({ product_id: Number(id), quantity: normNumber(v) })),
                },
            });
            onDone("Qaytarish rasmiylashtirildi.");
        } catch (err) {
            setError(errorMessage(err));
        } finally {
            setSaving(false);
        }
    }

    return (
        <Modal open title={`Qaytarish — ${sale.number}`} onClose={onClose} wide>
            <form onSubmit={submit} className="space-y-4">
                {error && <Alert>{error}</Alert>}
                {!items ? <Spinner /> : (
                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full text-left text-sm">
                            <thead className="bg-slate-50 text-xs uppercase text-slate-500">
                                <tr><th className="px-3 py-2">Mahsulot</th><th className="px-3 py-2 text-right">Sotilgan</th><th className="px-3 py-2 text-right">Qaytarilgan</th><th className="px-3 py-2 text-right">Qolgan</th><th className="px-3 py-2">Qaytariladi</th></tr>
                            </thead>
                            <tbody className="divide-y">
                                {items.map((r) => {
                                    const left = Number(r.remaining);
                                    return (
                                        <tr key={r.product_id}>
                                            <td className="px-3 py-2"><div className="font-medium">{r.name}</div><div className="text-xs text-slate-400">{r.sku}</div></td>
                                            <td className="px-3 py-2 text-right">{formatQty(r.sold, r.unit)}</td>
                                            <td className="px-3 py-2 text-right">{formatQty(r.returned, r.unit)}</td>
                                            <td className="px-3 py-2 text-right">{formatQty(r.remaining, r.unit)}</td>
                                            <td className="px-3 py-2">
                                                <div className="flex items-center gap-1">
                                                    <input
                                                        value={qty[r.product_id] ?? ""}
                                                        onChange={(e) => setQty({ ...qty, [r.product_id]: e.target.value })}
                                                        disabled={left <= 0}
                                                        inputMode={r.allow_fraction ? "decimal" : "numeric"}
                                                        placeholder="0"
                                                        className={`${inputCls} !w-24`}
                                                        aria-label={`${r.name} qaytariladigan miqdor`}
                                                    />
                                                    {left > 0 && <button type="button" onClick={() => setQty({ ...qty, [r.product_id]: r.remaining })} className="text-xs font-medium text-blue-700 hover:underline">hammasi</button>}
                                                </div>
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                )}

                <p className="text-xs text-slate-500">Avval shu sotuvning to‘lanmagan qarzi kamayadi; qolgan summa quyidagi usulda mijozga qaytariladi. Naqd qaytarish sizning ochiq smenangizdagi kassadan chiqim bo‘ladi.</p>
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label="Pul qaytarish usuli">
                        <select value={method} onChange={(e) => setMethod(e.target.value)} className={inputCls}>
                            {PAY_METHODS.map((m) => <option key={m} value={m}>{METHOD_LABELS[m]}</option>)}
                        </select>
                    </Field>
                    {REFERENCE_REQUIRED.includes(method) && (
                        <Field label="Chek / tranzaksiya raqami"><input value={reference} onChange={(e) => setReference(e.target.value)} className={inputCls} /></Field>
                    )}
                </div>
                <Field label="Qaytarish sababi *"><input value={note} onChange={(e) => setNote(e.target.value)} maxLength={500} className={inputCls} /></Field>

                <div className="flex justify-end gap-2">
                    <Button type="button" variant="secondary" onClick={onClose}>Bekor qilish</Button>
                    <Button type="submit" loading={saving} disabled={!picked.length || note.trim().length < 3}>Qaytarishni rasmiylashtirish</Button>
                </div>
            </form>
        </Modal>
    );
}

function CancelModal({ sale, onClose, onDone }: { sale: SaleRow; onClose: () => void; onDone: (msg: string) => void }) {
    const [reason, setReason] = useState("");
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState("");

    async function submit() {
        setSaving(true);
        setError("");
        try {
            await api(`/sales/${sale.id}/cancel`, { method: "POST", body: { reason } });
            onDone("Sotuv bekor qilindi: ombor qoldig‘i va to‘lovlar tiklandi.");
        } catch (e) {
            setError(errorMessage(e));
        } finally {
            setSaving(false);
        }
    }

    return (
        <Modal open title={`Sotuvni bekor qilish — ${sale.number}`} onClose={onClose}>
            <div className="space-y-4">
                {error && <Alert>{error}</Alert>}
                <p className="text-sm text-slate-600">
                    Mahsulotlar omborga qaytariladi. Naqd to‘lov sizning ochiq smenangizdagi kassadan chiqim qilinadi (smena ochiq bo‘lishi kerak). Hujjat o‘chirilmaydi, tarixda “bekor qilingan” bo‘lib qoladi.
                </p>
                <Field label="Bekor qilish sababi *"><input value={reason} onChange={(e) => setReason(e.target.value)} maxLength={500} className={inputCls} /></Field>
                <div className="flex justify-end gap-2">
                    <Button variant="secondary" onClick={onClose}>Yopish</Button>
                    <Button variant="danger" loading={saving} disabled={reason.trim().length < 3} onClick={submit}>Sotuvni bekor qilish</Button>
                </div>
            </div>
        </Modal>
    );
}
