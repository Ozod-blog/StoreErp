"use client";
import { KeyboardEvent, useCallback, useEffect, useMemo, useRef, useState } from "react";
import Link from "next/link";
import { Minus, Plus, Search, Trash2, UserPlus, X } from "lucide-react";
import AppShell from "../../components/AppShell";
import { ReceiptModal } from "../../components/Receipt";
import { Alert, Button, Field, Modal, Spinner, inputCls, useDebounce } from "../../components/ui";
import { ApiError, api, errorMessage } from "../lib/api";
import { newToken } from "../lib/documents";
import { beep, useScanner } from "../lib/scanner";
import { useToast } from "../../components/ToastProvider";
import { formatQty, formatUZS } from "../lib/format";
import {
    METHOD_LABELS, PAY_METHODS, PayMethod, PosContext, PosProduct, REFERENCE_REQUIRED, SaleRow, centsToInput, normNumber, toCents,
} from "../lib/sales";
import InsightsPanel from "../../components/InsightsPanel";

type CartLine = { product: PosProduct; qty: string; price: string; priceEdited: boolean; discount: string };
type PayState = Record<PayMethod, { amount: string; ref: string }>;
type Customer = { id: number; name: string; phone: string | null };
type PriceQuote = { subtotal: string; discount: string; total: string; loyalty_discount: string; lines: { product_id: number; gross: string; discount: string; net: string }[] };

const EMPTY_PAY: PayState = {
    cash: { amount: "", ref: "" }, card: { amount: "", ref: "" }, click: { amount: "", ref: "" }, payme: { amount: "", ref: "" }, transfer: { amount: "", ref: "" },
};

export default function PosPage() {
    const toast = useToast();
    const [ctx, setCtx] = useState<PosContext | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState("");
    const [warehouseId, setWarehouseId] = useState("");

    const [search, setSearch] = useState("");
    const debounced = useDebounce(search, 250);
    const [results, setResults] = useState<PosProduct[]>([]);
    const [searching, setSearching] = useState(false);
    const searchRef = useRef<HTMLInputElement>(null);

    const [cart, setCart] = useState<CartLine[]>([]);
    const [docDiscount, setDocDiscount] = useState("");
    const [pricingType, setPricingType] = useState<"retail" | "wholesale">("retail");
    const [loyaltyCardCode, setLoyaltyCardCode] = useState("");
    const [loyaltyPointsRedeem, setLoyaltyPointsRedeem] = useState("");
    const [serverQuote, setServerQuote] = useState<PriceQuote | null>(null);
    const [quoteLoading, setQuoteLoading] = useState(false);
    const [quoteError, setQuoteError] = useState("");
    const [customer, setCustomer] = useState<Customer | null>(null);
    const [note, setNote] = useState("");
    const [pay, setPay] = useState<PayState>(EMPTY_PAY);
    const [cashGiven, setCashGiven] = useState("");

    const [saving, setSaving] = useState(false);
    const [saleError, setSaleError] = useState("");
    const [done, setDone] = useState<{ sale: SaleRow; change: number } | null>(null);
    const token = useRef(newToken());
    const [scanMsg, setScanMsg] = useState<{ kind: "ok" | "err"; text: string } | null>(null);
    const scanTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
    const addToCartRef = useRef<(p: PosProduct) => void>(() => undefined);

    const loadContext = useCallback(async () => {
        setLoading(true);
        setError("");
        try {
            const c = await api<PosContext>("/pos/context");
            setCtx(c);
            setWarehouseId((prev) => prev || (c.default_warehouse_id ? String(c.default_warehouse_id) : ""));
        } catch (e) {
            setError(errorMessage(e));
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => { loadContext(); }, [loadContext]);

    const fetchProducts = useCallback(async (q: string): Promise<PosProduct[]> => {
        if (!warehouseId) return [];
        return api<PosProduct[]>("/pos/products", { query: { warehouse_id: warehouseId, search: q } });
    }, [warehouseId]);

    useEffect(() => {
        if (!warehouseId || !ctx?.shift) return;
        let cancelled = false;
        setSearching(true);
        fetchProducts(debounced)
            .then((r) => { if (!cancelled) setResults(r); })
            .catch((e) => { if (!cancelled) setError(errorMessage(e)); })
            .finally(() => { if (!cancelled) setSearching(false); });
        return () => { cancelled = true; };
    }, [debounced, warehouseId, ctx?.shift, fetchProducts]);

    function addToCart(p: PosProduct) {
        setSaleError("");
        setCart((prev) => {
            const i = prev.findIndex((l) => l.product.id === p.id);
            if (i >= 0) {
                const next = [...prev];
                const q = (parseFloat(normNumber(next[i].qty)) || 0) + 1;
                next[i] = { ...next[i], qty: String(Math.round(q * 1000) / 1000) };
                return next;
            }
            return [...prev, { product: p, qty: "1", price: pricingType === "wholesale" && p.wholesale_price ? p.wholesale_price : p.sale_price, priceEdited: false, discount: "" }];
        });
    }

    /** Qisqa xabar (skanerlash natijasi): muvaffaqiyat yoki xato, 2.5 soniyadan so'ng yo'qoladi. */
    const flash = useCallback((kind: "ok" | "err", text: string) => {
        setScanMsg({ kind, text });
        if (scanTimer.current) clearTimeout(scanTimer.current);
        scanTimer.current = setTimeout(() => setScanMsg(null), 2500);
        beep(kind === "ok");
    }, []);

    /**
     * Skaner kodini ANIQ moslik bo'yicha savatga qo'shadi (/pos/scan). Topilmasa false qaytaradi:
     * chaqiruvchi qidiruvga qaytishi mumkin (qo'lda terilgan nom/SKU bo'lagi bo'lishi mumkin).
     */
    const addByCode = useCallback(async (code: string): Promise<boolean> => {
        if (!warehouseId) return false;
        try {
            const p = await api<PosProduct>("/pos/scan", { query: { warehouse_id: warehouseId, code } });
            addToCartRef.current(p);
            flash("ok", `Qo‘shildi: ${p.name}`);
            return true;
        } catch (err) {
            if (err instanceof ApiError && err.status === 404) return false;
            flash("err", errorMessage(err));
            return true; // xato ko'rsatildi, qo'shimcha "topilmadi" kerak emas
        }
    }, [warehouseId, flash]);

    useEffect(() => { addToCartRef.current = addToCart; });

    // Fokus matn maydonida bo'lmasa ham skaner kodi qabul qilinadi (maydon ichida — o'sha maydonning Enter ishlovchisi).
    // Chek oynasi ochiq bo'lsa yoki smena/ombor yo'q bo'lsa — e'tiborsiz.
    useScanner((code) => {
        void addByCode(code).then((found) => { if (!found) flash("err", `Kod topilmadi: ${code}`); });
    }, !!ctx?.shift && !!warehouseId && !done);

    async function onSearchKey(e: KeyboardEvent<HTMLInputElement>) {
        if (e.key !== "Enter") return;
        e.preventDefault();
        const q = search.trim();
        if (!q) return;
        try {
            // Shtrix-kod skaneri kod + Enter yuboradi: kutmasdan darhol aniq qidiramiz.
            if (await addByCode(q)) {
                setSearch("");
                return;
            }
            const list = await fetchProducts(q);
            if (list.length === 1) {
                addToCart(list[0]);
                setSearch("");
            } else {
                setResults(list);
                if (!list.length) flash("err", `Kod topilmadi: ${q}`);
            }
        } catch (err) {
            setSaleError(errorMessage(err));
        }
    }

    function updateLine(id: number, patch: Partial<CartLine>) {
        setCart((prev) => prev.map((l) => (l.product.id === id ? { ...l, ...patch } : l)));
    }

    function stepQty(l: CartLine, delta: number) {
        const q = Math.max(0, Math.round(((parseFloat(normNumber(l.qty)) || 0) + delta) * 1000) / 1000);
        updateLine(l.product.id, { qty: String(q) });
    }

    // ---- hisob-kitob (faqat ko‘rsatish uchun; yakuniy hisobni server qiladi)
    const localCalc = useMemo(() => {
        const lines = cart.map((l) => {
            const qty = parseFloat(normNumber(l.qty)) || 0;
            const gross = Math.round(qty * toCents(l.price));
            const disc = Math.min(Math.max(toCents(l.discount), 0), gross);
            return { gross, disc, net: gross - disc, qty };
        });
        const subtotal = lines.reduce((s, l) => s + l.gross, 0);
        const lineDisc = lines.reduce((s, l) => s + l.disc, 0);
        const docDisc = Math.min(Math.max(toCents(docDiscount), 0), subtotal - lineDisc);
        const total = subtotal - lineDisc - docDisc;
        const paid = PAY_METHODS.reduce((s, m) => s + Math.max(toCents(pay[m].amount), 0), 0);
        const given = toCents(cashGiven);
        const cashAmount = toCents(pay.cash.amount);
        return { lines, subtotal, discount: lineDisc + docDisc, total, paid, debt: total - paid, change: given > cashAmount && cashAmount > 0 ? given - cashAmount : 0 };
    }, [cart, docDiscount, pay, cashGiven]);

    useEffect(() => {
        if (!cart.length) { setServerQuote(null); setQuoteError(""); setQuoteLoading(false); return; }
        setServerQuote(null); setQuoteLoading(true); setQuoteError("");
        let cancelled = false;
        const timer = setTimeout(async () => {
            const body = {
                warehouse_id: warehouseId ? Number(warehouseId) : undefined,
                customer_id: customer?.id,
                pricing_type: pricingType,
                loyalty_card_code: loyaltyCardCode.trim() || undefined,
                loyalty_points_redeem: loyaltyPointsRedeem.trim() ? Number(loyaltyPointsRedeem) : undefined,
                discount: docDiscount.trim() ? normNumber(docDiscount) : undefined,
                items: cart.map(l => ({ product_id: l.product.id, quantity: normNumber(l.qty), ...(l.priceEdited && toCents(l.price) !== toCents(pricingType === "wholesale" && l.product.wholesale_price ? l.product.wholesale_price : l.product.sale_price) ? { price: normNumber(l.price) } : {}), ...(l.discount.trim() && toCents(l.discount) > 0 ? { discount: normNumber(l.discount) } : {}) })),
            };
            try { const result = await api<PriceQuote>("/sales/quote", { method: "POST", body }); if (!cancelled) setServerQuote(result); }
            catch (e) { if (!cancelled) { setServerQuote(null); setQuoteError(errorMessage(e)); } }
            finally { if (!cancelled) setQuoteLoading(false); }
        }, 250);
        return () => { cancelled = true; clearTimeout(timer); };
    }, [cart, customer?.id, pricingType, loyaltyCardCode, loyaltyPointsRedeem, docDiscount]);

    const calc = useMemo(() => {
        if (!serverQuote) return localCalc;
        const subtotal = toCents(serverQuote.subtotal);
        const discount = toCents(serverQuote.discount);
        const total = toCents(serverQuote.total);
        const lines = localCalc.lines.map((line, i) => {
            const quoted = serverQuote.lines[i];
            return quoted ? { ...line, gross: toCents(quoted.gross), disc: toCents(quoted.discount), net: toCents(quoted.net) } : line;
        });
        const paid = localCalc.paid;
        return { ...localCalc, lines, subtotal, discount, total, paid, debt: total - paid };
    }, [serverQuote, localCalc]);

    const canDiscount = ctx?.can.discount ?? false;
    const canDebt = ctx?.can.debt ?? false;

    const problems = useMemo(() => {
        const list: string[] = [];
        if (!cart.length) return list;
        if (quoteError) list.push(quoteError);
        if (quoteLoading) list.push("Narx va aksiyalar qayta hisoblanmoqda...");
        if (cart.some((l) => !(parseFloat(normNumber(l.qty)) > 0))) list.push("Barcha mahsulotlar uchun miqdorni kiriting.");
        if (calc.total <= 0) list.push("Savdo summasi noldan katta bo‘lishi kerak.");
        if (calc.paid > calc.total) list.push("To‘lov summasi jami summadan oshib ketdi.");
        if (calc.debt > 0 && calc.paid <= calc.total) {
            if (!canDebt) list.push("Qarzga sotish ruxsatingiz yo‘q — to‘lovni to‘liq kiriting.");
            else if (!customer) list.push("Qarzga sotish uchun mijozni tanlang.");
        }
        for (const m of PAY_METHODS) {
            if (toCents(pay[m].amount) > 0 && REFERENCE_REQUIRED.includes(m) && !pay[m].ref.trim()) list.push(`${METHOD_LABELS[m]}: chek / tranzaksiya raqamini kiriting.`);
        }
        return list;
    }, [cart, calc, canDebt, customer, pay, quoteError, quoteLoading]);

    function fillRemaining(m: PayMethod) {
        const others = PAY_METHODS.filter((x) => x !== m).reduce((s, x) => s + Math.max(toCents(pay[x].amount), 0), 0);
        const rest = Math.max(calc.total - others, 0);
        setPay((p) => ({ ...p, [m]: { ...p[m], amount: rest > 0 ? centsToInput(rest) : "" } }));
    }

    function resetSale() {
        setCart([]);
        setDocDiscount("");
        setPricingType("retail");
        setLoyaltyCardCode("");
        setLoyaltyPointsRedeem("");
        setServerQuote(null); setQuoteError("");
        setCustomer(null);
        setNote("");
        setPay(EMPTY_PAY);
        setCashGiven("");
        setSaleError("");
        token.current = newToken();
        setTimeout(() => searchRef.current?.focus(), 50);
    }

    async function checkout() {
        if (!cart.length || problems.length || quoteLoading || quoteError) return;
        setSaving(true);
        setSaleError("");
        try {
            const body = {
                client_token: token.current,
                warehouse_id: warehouseId ? Number(warehouseId) : undefined,
                customer_id: customer?.id,
                pricing_type: pricingType,
                loyalty_card_code: loyaltyCardCode.trim() || undefined,
                loyalty_points_redeem: loyaltyPointsRedeem.trim() ? Number(loyaltyPointsRedeem) : undefined,
                discount: docDiscount.trim() ? normNumber(docDiscount) : undefined,
                note: note.trim() || undefined,
                items: cart.map((l) => ({
                    product_id: l.product.id,
                    quantity: normNumber(l.qty),
                    ...(l.priceEdited && toCents(l.price) !== toCents(pricingType === "wholesale" && l.product.wholesale_price ? l.product.wholesale_price : l.product.sale_price) ? { price: normNumber(l.price) } : {}),
                    ...(l.discount.trim() && toCents(l.discount) > 0 ? { discount: normNumber(l.discount) } : {}),
                })),
                payments: PAY_METHODS.filter((m) => toCents(pay[m].amount) > 0).map((m) => ({
                    method: m,
                    amount: normNumber(pay[m].amount),
                    reference: pay[m].ref.trim() || undefined,
                })),
            };
            const sale = await api<SaleRow>("/sales", { method: "POST", body });
            setDone({ sale, change: calc.change / 100 });
            toast(`Savdo ${sale.number} saqlandi. Jami: ${formatUZS(sale.total)}`, "success", "Savdo yakunlandi");
            resetSale();
            fetchProducts(debounced).then(setResults).catch(() => undefined);
        } catch (e) {
            setSaleError(errorMessage(e));
            toast(errorMessage(e), "error", "Savdo bajarilmadi");
        } finally {
            setSaving(false);
        }
    }

    const noShift = !loading && ctx && !ctx.shift;

    return (
        <AppShell title="Savdo (POS)" subtitle="Mahsulotni qidiring yoki shtrix-kodni skanerlang, to‘lovni kiriting va sotuvni yakunlang" permission="sales.create">
            {loading && <Spinner />}
            {error && <div className="mb-4"><Alert>{error}</Alert></div>}
            <div className="mb-4"><InsightsPanel endpoint="/stats/pos" defaultOpen={false} /></div>

            {noShift && (
                <div className="rounded-2xl border border-amber-200 bg-amber-50 p-6 text-sm text-amber-800">
                    Savdo qilish uchun avval kassa smenasini oching.{" "}
                    <Link href="/shifts" className="font-semibold text-blue-700 underline">Kassa smenalari →</Link>
                </div>
            )}

            {ctx?.shift && (
                <div className="grid gap-5 md:grid-cols-5">
                    {/* Chap: qidiruv va savat */}
                    <div className="space-y-5 md:col-span-3">
                        <section className="rounded-2xl border border-slate-200 bg-white p-4">
                            <div className="flex flex-wrap items-center gap-3">
                                <div className="relative min-w-[220px] flex-1">
                                    <Search size={16} className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
                                    <input
                                        ref={searchRef}
                                        autoFocus
                                        value={search}
                                        onChange={(e) => setSearch(e.target.value)}
                                        onKeyDown={onSearchKey}
                                        placeholder="Nomi, SKU yoki shtrix-kod (Enter)"
                                        className={`${inputCls} pl-9`}
                                        aria-label="Mahsulot qidirish"
                                    />
                                </div>
                                {ctx.warehouses.length > 1 && (
                                    <select
                                        value={warehouseId}
                                        onChange={(e) => setWarehouseId(e.target.value)}
                                        disabled={cart.length > 0}
                                        className={`${inputCls} !w-auto`}
                                        aria-label="Ombor"
                                        title={cart.length ? "Ombor savat bo‘sh bo‘lganda o‘zgartiriladi" : "Ombor"}
                                    >
                                        <option value="">Omborni tanlang</option>
                                        {ctx.warehouses.map((w) => <option key={w.id} value={w.id}>{w.name}</option>)}
                                    </select>
                                )}
                            </div>
                            {scanMsg && (
                                <div role="status" aria-live="polite" className={`mt-2 rounded-lg px-3 py-1.5 text-sm font-medium ${scanMsg.kind === "ok" ? "bg-emerald-50 text-emerald-700" : "bg-red-50 text-red-700"}`}>{scanMsg.text}</div>
                            )}
                            <div className="mt-1 text-xs text-slate-500">Kassa: {ctx.shift.cashbox.name} ({ctx.shift.cashbox.code})</div>

                            {!warehouseId ? (
                                <p className="mt-4 text-sm text-slate-500">Savdo qilinadigan omborni tanlang.</p>
                            ) : (
                                <div className="mt-3 max-h-72 overflow-y-auto rounded-lg border">
                                    {searching && !results.length ? <Spinner label="Qidirilmoqda..." /> : (
                                        <ul className="divide-y">
                                            {results.map((p) => {
                                                const stock = Number(p.stock);
                                                return (
                                                    <li key={p.id}>
                                                        <button
                                                            type="button"
                                                            onClick={() => addToCart(p)}
                                                            className="flex w-full items-center justify-between gap-3 px-3 py-2.5 text-left text-sm hover:bg-blue-50"
                                                        >
                                                            <span className="min-w-0">
                                                                <span className="block truncate font-medium text-slate-900">{p.name}</span>
                                                                <span className="block text-xs text-slate-400">{p.sku}{p.barcode ? ` · ${p.barcode}` : ""}</span>
                                                            </span>
                                                            <span className="shrink-0 text-right">
                                                                <span className="block font-semibold">{formatUZS(p.sale_price)}</span>
                                                                <span className={`block text-xs ${stock <= 0 ? "text-red-600" : "text-slate-500"}`}>Qoldiq: {formatQty(p.stock, p.unit)}</span>
                                                            </span>
                                                        </button>
                                                    </li>
                                                );
                                            })}
                                            {!results.length && <li className="px-3 py-8 text-center text-sm text-slate-400">Mahsulot topilmadi.</li>}
                                        </ul>
                                    )}
                                </div>
                            )}
                        </section>

                        <section className="overflow-hidden rounded-2xl border border-slate-200 bg-white">
                            <div className="flex items-center justify-between border-b px-4 py-3">
                                <h2 className="font-semibold text-slate-900">Savat ({cart.length})</h2>
                                {cart.length > 0 && <button onClick={resetSale} className="text-sm text-red-600 hover:underline">Savatni tozalash</button>}
                            </div>
                            <div className="overflow-x-auto">
                                <table className="w-full text-left text-sm">
                                    <thead className="bg-slate-50 text-xs uppercase text-slate-500">
                                        <tr>
                                            <th className="px-4 py-2">Mahsulot</th><th className="px-4 py-2">Miqdor</th><th className="px-4 py-2">Narx</th>
                                            {canDiscount && <th className="px-4 py-2">Chegirma</th>}
                                            <th className="px-4 py-2 text-right">Summa</th><th className="px-2 py-2" />
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y">
                                        {cart.map((l, i) => {
                                            const stock = Number(l.product.stock);
                                            const over = (parseFloat(normNumber(l.qty)) || 0) > stock;
                                            return (
                                                <tr key={l.product.id} className="align-top">
                                                    <td className="px-4 py-2">
                                                        <div className="font-medium">{l.product.name}</div>
                                                        <div className={`text-xs ${over ? "font-semibold text-red-600" : "text-slate-400"}`}>
                                                            {over ? `Qoldiq yetarli emas: ${formatQty(l.product.stock, l.product.unit)}` : `Qoldiq: ${formatQty(l.product.stock, l.product.unit)}`}
                                                        </div>
                                                    </td>
                                                    <td className="px-4 py-2">
                                                        <div className="flex items-center gap-1">
                                                            <button type="button" onClick={() => stepQty(l, -1)} aria-label="Kamaytirish" className="rounded-md border p-1.5 hover:bg-slate-50"><Minus size={14} /></button>
                                                            <input
                                                                value={l.qty}
                                                                onChange={(e) => updateLine(l.product.id, { qty: e.target.value })}
                                                                inputMode={l.product.allow_fraction ? "decimal" : "numeric"}
                                                                className={`${inputCls} !w-20 text-center`}
                                                                aria-label="Miqdor"
                                                            />
                                                            <button type="button" onClick={() => stepQty(l, 1)} aria-label="Ko‘paytirish" className="rounded-md border p-1.5 hover:bg-slate-50"><Plus size={14} /></button>
                                                            <span className="ml-1 text-xs text-slate-500">{l.product.unit}</span>
                                                        </div>
                                                    </td>
                                                    <td className="px-4 py-2">
                                                        {canDiscount ? (
                                                            <input value={l.price} onChange={(e) => updateLine(l.product.id, { price: e.target.value, priceEdited: true })} inputMode="decimal" className={`${inputCls} !w-28`} aria-label="Narx" />
                                                        ) : formatUZS(l.price)}
                                                    </td>
                                                    {canDiscount && (
                                                        <td className="px-4 py-2">
                                                            <input value={l.discount} onChange={(e) => updateLine(l.product.id, { discount: e.target.value })} inputMode="decimal" placeholder="0" className={`${inputCls} !w-24`} aria-label="Chegirma" />
                                                        </td>
                                                    )}
                                                    <td className="px-4 py-2 text-right font-semibold">{formatUZS(calc.lines[i].net / 100)}</td>
                                                    <td className="px-2 py-2">
                                                        <button type="button" onClick={() => setCart((prev) => prev.filter((x) => x.product.id !== l.product.id))} aria-label="O‘chirish" className="rounded-md p-1.5 text-slate-400 hover:bg-red-50 hover:text-red-600"><Trash2 size={16} /></button>
                                                    </td>
                                                </tr>
                                            );
                                        })}
                                        {!cart.length && <tr><td colSpan={canDiscount ? 6 : 5} className="px-4 py-12 text-center text-slate-400">Savat bo‘sh. Mahsulotni qidirib qo‘shing.</td></tr>}
                                    </tbody>
                                </table>
                            </div>
                        </section>
                    </div>

                    {/* O‘ng: to‘lov */}
                    <aside className="space-y-4 md:col-span-2">
                        <section className="rounded-2xl border border-slate-200 bg-white p-5">
                            <div className="mb-4 grid grid-cols-2 gap-2 rounded-xl bg-slate-100 p-1">
                                <button type="button" onClick={() => { setPricingType("retail"); setCart(prev => prev.map(l => l.priceEdited ? l : ({ ...l, price: l.product.sale_price }))); }} className={`rounded-lg px-3 py-2 text-sm font-semibold ${pricingType === "retail" ? "bg-white shadow-sm text-blue-700" : "text-slate-600"}`}>Chakana narx</button>
                                <button type="button" onClick={() => { setPricingType("wholesale"); setCart(prev => prev.map(l => l.priceEdited ? l : ({ ...l, price: l.product.wholesale_price || l.product.sale_price }))); }} className={`rounded-lg px-3 py-2 text-sm font-semibold ${pricingType === "wholesale" ? "bg-white shadow-sm text-blue-700" : "text-slate-600"}`}>Ulgurji narx</button>
                            </div>
                            <div className="mb-4 grid gap-3 sm:grid-cols-2">
                                <Field label="Mijoz kartasi kodi"><input value={loyaltyCardCode} onChange={e => setLoyaltyCardCode(e.target.value)} className={inputCls} placeholder="LOY-..." /></Field>
                                <Field label="Ishlatiladigan ballar"><input value={loyaltyPointsRedeem} onChange={e => setLoyaltyPointsRedeem(e.target.value.replace(/[^0-9]/g, ""))} inputMode="numeric" className={inputCls} placeholder="0" /></Field>
                            </div>
                            <dl className="space-y-1.5 text-sm">
                                <div className="flex justify-between"><dt className="text-slate-500">Jami (chegirmasiz)</dt><dd>{formatUZS(calc.subtotal / 100)}</dd></div>
                                <div className="flex justify-between"><dt className="text-slate-500">Chegirma</dt><dd>{calc.discount > 0 ? `−${formatUZS(calc.discount / 100)}` : "—"}</dd></div>
                                <div className="flex items-baseline justify-between border-t pt-2"><dt className="font-semibold text-slate-900">To‘lash uchun</dt><dd className="text-2xl font-bold text-blue-700">{quoteLoading ? "Hisoblanmoqda…" : formatUZS(calc.total / 100)}</dd></div>
                            </dl>
                            {canDiscount && cart.length > 0 && (
                                <div className="mt-3">
                                    <Field label="Umumiy chegirma (so‘m)">
                                        <input value={docDiscount} onChange={(e) => setDocDiscount(e.target.value)} inputMode="decimal" placeholder="0" className={inputCls} />
                                    </Field>
                                </div>
                            )}
                        </section>

                        <section className="rounded-2xl border border-slate-200 bg-white p-5">
                            <h2 className="mb-3 font-semibold text-slate-900">To‘lov</h2>
                            <div className="space-y-3">
                                {PAY_METHODS.map((m) => {
                                    const active = toCents(pay[m].amount) > 0;
                                    return (
                                        <div key={m}>
                                            <div className="flex items-center gap-2">
                                                <span className="w-32 shrink-0 text-sm text-slate-700">{METHOD_LABELS[m]}</span>
                                                <input
                                                    value={pay[m].amount}
                                                    onChange={(e) => setPay((p) => ({ ...p, [m]: { ...p[m], amount: e.target.value } }))}
                                                    inputMode="decimal"
                                                    placeholder="0"
                                                    className={inputCls}
                                                    aria-label={`${METHOD_LABELS[m]} summasi`}
                                                />
                                                <button type="button" onClick={() => fillRemaining(m)} disabled={!cart.length} className="shrink-0 rounded-lg border px-2.5 py-2 text-xs font-medium text-blue-700 hover:bg-blue-50 disabled:opacity-40">To‘liq</button>
                                            </div>
                                            {active && REFERENCE_REQUIRED.includes(m) && (
                                                <div className="mt-1.5 pl-[8.5rem]">
                                                    <input
                                                        value={pay[m].ref}
                                                        onChange={(e) => setPay((p) => ({ ...p, [m]: { ...p[m], ref: e.target.value } }))}
                                                        placeholder="Chek / tranzaksiya raqami"
                                                        className={inputCls}
                                                        aria-label={`${METHOD_LABELS[m]} chek raqami`}
                                                    />
                                                    {(m === "click" || m === "payme") && (
                                                        <p className="mt-1 text-xs text-amber-700">Bu to‘lov tizim tomonidan avtomatik tekshirilmaydi. Pul tushganini o‘zingiz tasdiqlaysiz.</p>
                                                    )}
                                                </div>
                                            )}
                                            {m === "cash" && active && (
                                                <div className="mt-1.5 flex items-center gap-2 pl-[8.5rem]">
                                                    <input value={cashGiven} onChange={(e) => setCashGiven(e.target.value)} inputMode="decimal" placeholder="Mijoz bergan naqd" className={inputCls} aria-label="Mijoz bergan naqd pul" />
                                                    {calc.change > 0 && <span className="shrink-0 text-sm font-semibold text-emerald-700">Qaytim: {formatUZS(calc.change / 100)}</span>}
                                                </div>
                                            )}
                                        </div>
                                    );
                                })}
                            </div>

                            <dl className="mt-4 space-y-1 border-t pt-3 text-sm">
                                <div className="flex justify-between"><dt className="text-slate-500">To‘langan</dt><dd className="font-medium">{formatUZS(calc.paid / 100)}</dd></div>
                                {calc.debt > 0 && <div className="flex justify-between text-red-600"><dt>Qarzga</dt><dd className="font-semibold">{formatUZS(calc.debt / 100)}</dd></div>}
                                {calc.debt < 0 && <div className="flex justify-between text-red-600"><dt>Ortiqcha kiritilgan</dt><dd className="font-semibold">{formatUZS(-calc.debt / 100)}</dd></div>}
                            </dl>
                        </section>

                        <section className="rounded-2xl border border-slate-200 bg-white p-5">
                            <CustomerPicker customer={customer} onChange={setCustomer} canCreate={ctx.can.customers_create} required={calc.debt > 0} />
                            <div className="mt-3">
                                <Field label="Izoh (ixtiyoriy)">
                                    <input value={note} onChange={(e) => setNote(e.target.value)} maxLength={500} className={inputCls} />
                                </Field>
                            </div>
                        </section>

                        {problems.length > 0 && <Alert>{problems.map((p) => <div key={p}>{p}</div>)}</Alert>}
                        {saleError && <Alert>{saleError}</Alert>}
                        <Button className="w-full !py-3 text-base" loading={saving || quoteLoading} disabled={!cart.length || problems.length > 0 || quoteLoading || !!quoteError} onClick={checkout}>
                            Sotishni yakunlash
                        </Button>
                    </aside>
                </div>
            )}

            <ReceiptModal
                open={!!done}
                sale={done?.sale ?? null}
                info={ctx?.receipt ?? null}
                change={done?.change}
                onClose={() => { setDone(null); setTimeout(() => searchRef.current?.focus(), 50); }}
            />
        </AppShell>
    );
}

/** Mijozni qidirib tanlash va tezkor yangi mijoz qo‘shish. */
function CustomerPicker({ customer, onChange, canCreate, required }: {
    customer: Customer | null; onChange: (c: Customer | null) => void; canCreate: boolean; required: boolean;
}) {
    const [q, setQ] = useState("");
    const dq = useDebounce(q, 300);
    const [list, setList] = useState<Customer[]>([]);
    const [open, setOpen] = useState(false);
    const [creating, setCreating] = useState(false);
    const [form, setForm] = useState({ name: "", phone: "" });
    const [saving, setSaving] = useState(false);
    const [err, setErr] = useState("");

    useEffect(() => {
        if (!open) return;
        api<Customer[]>("/customers", { query: { all: 1, search: dq } }).then(setList).catch(() => setList([]));
    }, [dq, open]);

    async function create() {
        setSaving(true);
        setErr("");
        try {
            const c = await api<Customer>("/customers", { method: "POST", body: { name: form.name, phone: form.phone || undefined } });
            onChange(c);
            setCreating(false);
            setForm({ name: "", phone: "" });
        } catch (e) {
            setErr(errorMessage(e));
        } finally {
            setSaving(false);
        }
    }

    return (
        <div>
            <div className="mb-1.5 flex items-center justify-between text-sm font-medium text-slate-700">
                <span>Mijoz {required && <span className="text-red-600">(qarz uchun majburiy)</span>}</span>
                {canCreate && <button type="button" onClick={() => setCreating(true)} className="inline-flex items-center gap-1 text-xs font-medium text-blue-700 hover:underline"><UserPlus size={13} /> Yangi mijoz</button>}
            </div>

            {customer ? (
                <div className="flex items-center justify-between rounded-lg border bg-slate-50 px-3 py-2 text-sm">
                    <span><span className="font-medium">{customer.name}</span>{customer.phone ? <span className="text-slate-500"> · {customer.phone}</span> : null}</span>
                    <button type="button" onClick={() => onChange(null)} aria-label="Mijozni olib tashlash" className="text-slate-400 hover:text-red-600"><X size={16} /></button>
                </div>
            ) : (
                <div className="relative">
                    <input value={q} onChange={(e) => { setQ(e.target.value); setOpen(true); }} onFocus={() => setOpen(true)} placeholder="Ism yoki telefon bo‘yicha qidirish" className={inputCls} aria-label="Mijoz qidirish" />
                    {open && (
                        <div className="absolute z-20 mt-1 max-h-56 w-full overflow-y-auto rounded-lg border bg-white shadow-lg">
                            {list.map((c) => (
                                <button key={c.id} type="button" onClick={() => { onChange(c); setOpen(false); setQ(""); }} className="block w-full px-3 py-2 text-left text-sm hover:bg-blue-50">
                                    <span className="font-medium">{c.name}</span>{c.phone ? <span className="text-slate-500"> · {c.phone}</span> : null}
                                </button>
                            ))}
                            {!list.length && <div className="px-3 py-3 text-sm text-slate-400">Mijoz topilmadi.</div>}
                            <button type="button" onClick={() => setOpen(false)} className="block w-full border-t px-3 py-2 text-left text-xs text-slate-500 hover:bg-slate-50">Yopish</button>
                        </div>
                    )}
                </div>
            )}

            <Modal open={creating} title="Yangi mijoz" onClose={() => setCreating(false)}>
                <div className="space-y-4">
                    {err && <Alert>{err}</Alert>}
                    <Field label="Ism yoki kompaniya nomi *"><input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} className={inputCls} /></Field>
                    <Field label="Telefon"><input value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} className={inputCls} /></Field>
                    <div className="flex justify-end gap-2">
                        <Button variant="secondary" onClick={() => setCreating(false)}>Bekor qilish</Button>
                        <Button loading={saving} disabled={!form.name.trim()} onClick={create}>Saqlash</Button>
                    </div>
                </div>
            </Modal>
        </div>
    );
}
