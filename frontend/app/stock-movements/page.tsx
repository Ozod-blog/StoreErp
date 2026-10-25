"use client";
import { useCallback, useEffect, useState } from "react";
import { Download } from "lucide-react";
import AppShell from "../../components/AppShell";
import { Alert, Button, Pagination, Spinner, StatCard, inputCls, useDebounce } from "../../components/ui";
import { downloadCsv, todayStamp } from "../lib/csv";
import { api, errorMessage, Paginated } from "../lib/api";
import { formatDateTime, formatQty, formatUZS } from "../lib/format";
import { MOVEMENT_TYPES } from "../lib/documents";
import InsightsPanel from "../../components/InsightsPanel";

type Movement = {
    id: number; type: string; quantity: string; balance_after: string; unit_cost: string | null; created_at: string;
    warehouse?: { name: string } | null;
    product?: { sku: string; name: string; unit?: { short_name: string } | null } | null;
    document?: { id: number; number: string } | null;
    sale?: { id: number; number: string } | null;
    user?: { name: string } | null;
};
type Option = { id: number; name: string };

export default function StockMovementsPage() {
    const [rows, setRows] = useState<Paginated<Movement> | null>(null);
    const [page, setPage] = useState(1);
    const [search, setSearch] = useState("");
    const [warehouse, setWarehouse] = useState("");
    const [type, setType] = useState("");
    const [from, setFrom] = useState("");
    const [to, setTo] = useState("");
    const [warehouses, setWarehouses] = useState<Option[]>([]);
    const debounced = useDebounce(search);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState("");

    const load = useCallback(async () => {
        setLoading(true);
        setError("");
        try {
            setRows(await api<Paginated<Movement>>("/stock-movements", { query: { page, search: debounced, warehouse_id: warehouse, type, date_from: from, date_to: to } }));
        } catch (e) {
            setError(errorMessage(e));
        } finally {
            setLoading(false);
        }
    }, [page, debounced, warehouse, type, from, to]);

    useEffect(() => { load(); }, [load]);
    useEffect(() => { setPage(1); }, [debounced, warehouse, type, from, to]);
    useEffect(() => { api<Option[]>("/warehouses", { query: { all: 1 } }).then(setWarehouses).catch(() => setWarehouses([])); }, []);

    const inCount = (rows?.data ?? []).filter((m) => Number(m.quantity) > 0).length;
    const outCount = (rows?.data ?? []).filter((m) => Number(m.quantity) < 0).length;

    function exportCsv() {
        downloadCsv(`qoldiq-harakati-${todayStamp()}.csv`, [
            ["Vaqt", "Ombor", "SKU", "Mahsulot", "Tur", "O'zgarish", "Qoldiq", "Tannarx", "Hujjat", "Xodim"],
            ...(rows?.data ?? []).map((m) => [
                formatDateTime(m.created_at), m.warehouse?.name, m.product?.sku, m.product?.name, MOVEMENT_TYPES[m.type] ?? m.type,
                m.quantity, m.balance_after, m.unit_cost, m.document?.number ?? m.sale?.number ?? "", m.user?.name,
            ]),
        ]);
    }

    return (
        <AppShell title="Qoldiq harakati" subtitle="Har bir kirim, chiqim, ko‘chirish va tuzatish tarixi (faqat ko‘rish)" permission="warehouses.view"
            actions={<Button variant="secondary" onClick={exportCsv} disabled={!rows?.data.length}><Download size={16} />CSV</Button>}>
            <div className="space-y-4">
                {error && <Alert>{error}</Alert>}
                <InsightsPanel endpoint="/stats/products" />
                {rows && (
                    <div className="grid grid-cols-3 gap-3">
                        <StatCard title="Jami yozuv" value={String(rows.total)} hint="Filtr bo‘yicha" />
                        <StatCard title="Kirimlar" value={String(inCount)} tone="good" hint="Shu sahifada" />
                        <StatCard title="Chiqimlar" value={String(outCount)} tone={outCount ? "bad" : "default"} hint="Shu sahifada" />
                    </div>
                )}
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5">
                    <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Mahsulot nomi yoki SKU" className={inputCls} />
                    <select value={warehouse} onChange={(e) => setWarehouse(e.target.value)} className={inputCls}>
                        <option value="">Barcha omborlar</option>
                        {warehouses.map((w) => <option key={w.id} value={w.id}>{w.name}</option>)}
                    </select>
                    <select value={type} onChange={(e) => setType(e.target.value)} className={inputCls}>
                        <option value="">Barcha turlar</option>
                        {Object.entries(MOVEMENT_TYPES).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                    </select>
                    <input type="date" value={from} onChange={(e) => setFrom(e.target.value)} className={inputCls} aria-label="Sanadan" />
                    <input type="date" value={to} onChange={(e) => setTo(e.target.value)} className={inputCls} aria-label="Sanagacha" />
                </div>

                <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white">
                    {loading && !rows ? <Spinner /> : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-sm">
                                <thead className="bg-slate-50 text-xs uppercase text-slate-500">
                                    <tr>
                                        <th className="px-5 py-3">Vaqt</th><th className="px-5 py-3">Ombor</th><th className="px-5 py-3">Mahsulot</th>
                                        <th className="px-5 py-3">Tur</th><th className="px-5 py-3 text-right">O‘zgarish</th><th className="px-5 py-3 text-right">Qoldiq</th>
                                        <th className="px-5 py-3 text-right">Tannarx</th><th className="px-5 py-3">Hujjat</th><th className="px-5 py-3">Xodim</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {rows?.data.map((m) => {
                                        const delta = Number(m.quantity);
                                        return (
                                            <tr key={m.id}>
                                                <td className="px-5 py-3 whitespace-nowrap">{formatDateTime(m.created_at)}</td>
                                                <td className="px-5 py-3">{m.warehouse?.name}</td>
                                                <td className="px-5 py-3"><div className="font-medium">{m.product?.name}</div><div className="text-xs text-slate-400">{m.product?.sku}</div></td>
                                                <td className="px-5 py-3">{MOVEMENT_TYPES[m.type] ?? m.type}</td>
                                                <td className={`px-5 py-3 text-right font-semibold ${delta < 0 ? "text-red-600" : "text-emerald-600"}`}>
                                                    {delta > 0 ? "+" : ""}{formatQty(m.quantity, m.product?.unit?.short_name)}
                                                </td>
                                                <td className="px-5 py-3 text-right">{formatQty(m.balance_after, m.product?.unit?.short_name)}</td>
                                                <td className="px-5 py-3 text-right">{m.unit_cost ? formatUZS(m.unit_cost) : "—"}</td>
                                                <td className="px-5 py-3">{m.document?.number ?? m.sale?.number ?? "—"}</td>
                                                <td className="px-5 py-3 text-slate-600">{m.user?.name ?? "—"}</td>
                                            </tr>
                                        );
                                    })}
                                    {!rows?.data.length && <tr><td colSpan={9} className="px-5 py-12 text-center text-slate-400">Harakat topilmadi.</td></tr>}
                                </tbody>
                            </table>
                        </div>
                    )}
                    {rows && <Pagination page={rows.current_page} lastPage={rows.last_page} total={rows.total} onPage={setPage} />}
                </div>
            </div>
        </AppShell>
    );
}
