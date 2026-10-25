"use client";
import Link from "next/link";
import { useCallback, useEffect, useMemo, useState } from "react";
import { Activity, ArrowDownRight, ArrowRight, ArrowUpRight, Banknote, Boxes, CalendarDays, CircleAlert, CreditCard, Package, RefreshCw, ShoppingCart, TrendingUp, Wallet } from "lucide-react";
import AppShell from "../components/AppShell";
import { Alert, Skeleton, inputCls } from "../components/ui";
import { Button } from "../components/ui";
import { useAuth } from "./lib/auth";
import { api, errorMessage } from "./lib/api";
import { formatQty, formatUZS } from "./lib/format";
import { useErpSync } from "./lib/use-erp-sync";
import { useI18n, MessageKey } from "./lib/i18n";
import { SalesTrendChart, TopProductsChart, TrendRow } from "../components/charts";

type Branch = { id: number; name: string };
type Summary = { period: { date_from: string; date_to: string }; kpis: Record<string, string | number> };
type DailyRow = { date: string; type: string; total: string; documents: number };
type TopProduct = { id: number; name: string; sku: string; quantity: string; total: string };
type SalesReport = { daily: DailyRow[]; top_products: TopProduct[]; summary: Record<string, string | number> };
type StockRow = { id: number; name: string; sku: string; quantity: string; min_stock: string; sale_price: string; retail_value_estimate: string; low_stock: boolean };
type InventoryReport = { summary: Record<string, string | number>; data: StockRow[] };
const dateText = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
const now = new Date();
const monthStart = dateText(new Date(now.getFullYear(), now.getMonth(), 1));
const todayText = dateText(now);
const parseDay = (d: string) => { const [y, m, day] = d.split("-").map(Number); return new Date(Date.UTC(y, m - 1, day)); };
const dayText = (d: Date) => d.toISOString().slice(0, 10);
/** Tanlangan davr bilan bir xil uzunlikdagi oldingi davr. */
function previousRange(from: string, to: string) {
  const a = parseDay(from), b = parseDay(to);
  const days = Math.max(1, Math.round((b.getTime() - a.getTime()) / 86_400_000) + 1);
  const prevTo = new Date(a.getTime() - 86_400_000);
  const prevFrom = new Date(prevTo.getTime() - (days - 1) * 86_400_000);
  return { from: dayText(prevFrom), to: dayText(prevTo) };
}
const deltaKeys = new Set(["sales_net", "gross_profit", "approved_expenses", "operating_result"]);
const costKeys = new Set(["approved_expenses"]); // o‘sishi yomon ko‘rsatkich
const moneyKeys = new Set(["sales_net", "gross_profit", "approved_expenses", "operating_result", "cash_balance", "bank_balance", "customer_debt_estimate"]);
const kpis = [
  { key: "sales_net", label: "kpi.sales_net", icon: ShoppingCart, tint: "blue", note: "kpi.sales_net.note" },
  { key: "gross_profit", label: "kpi.gross_profit", icon: TrendingUp, tint: "emerald", note: "kpi.gross_profit.note" },
  { key: "approved_expenses", label: "kpi.approved_expenses", icon: Banknote, tint: "amber", note: "kpi.approved_expenses.note" },
  { key: "operating_result", label: "kpi.operating_result", icon: Activity, tint: "violet", note: "kpi.operating_result.note" },
  { key: "cash_balance", label: "kpi.cash_balance", icon: Wallet, tint: "cyan", note: "kpi.cash_balance.note" },
  { key: "bank_balance", label: "kpi.bank_balance", icon: CreditCard, tint: "indigo", note: "kpi.bank_balance.note" },
  { key: "customer_debt_estimate", label: "kpi.customer_debt_estimate", icon: ArrowUpRight, tint: "rose", note: "kpi.customer_debt_estimate.note" },
  { key: "low_stock_products", label: "kpi.low_stock_products", icon: CircleAlert, tint: "orange", note: "kpi.low_stock_products.note" },
] as const;
const tintClasses: Record<string, string> = {
  blue: "bg-blue-50 text-blue-700", emerald: "bg-emerald-50 text-emerald-700", amber: "bg-amber-50 text-amber-700",
  violet: "bg-violet-50 text-violet-700", cyan: "bg-cyan-50 text-cyan-700", indigo: "bg-indigo-50 text-indigo-700",
  rose: "bg-rose-50 text-rose-700", orange: "bg-orange-50 text-orange-700",
};
const panel = "rounded-2xl border border-slate-200/80 bg-white shadow-sm";

export default function Home() {
  const { user, permissions, can } = useAuth();
  const { t } = useI18n();
  const [summary, setSummary] = useState<Summary | null>(null);
  const [sales, setSales] = useState<SalesReport | null>(null);
  const [inventory, setInventory] = useState<InventoryReport | null>(null);
  const [prevSummary, setPrevSummary] = useState<Summary | null>(null);
  const [prevSales, setPrevSales] = useState<SalesReport | null>(null);
  const [compare, setCompare] = useState(true);
  const [branches, setBranches] = useState<Branch[]>([]);
  const [branch, setBranch] = useState("");
  const [from, setFrom] = useState(monthStart);
  const [to, setTo] = useState(todayText);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  const load = useCallback(async () => {
    if (!can("reports.view")) { setLoading(false); return; }
    setLoading(true); setError("");
    const query = { date_from: from, date_to: to, branch_id: branch || undefined };
    try {
      const [summaryResult, salesResult, inventoryResult] = await Promise.all([
        api<Summary>("/reports/dashboard", { query }),
        api<SalesReport>("/reports/sales", { query }),
        api<InventoryReport>("/reports/inventory", { query }),
      ]);
      setSummary(summaryResult); setSales(salesResult); setInventory(inventoryResult);
      if (compare) {
        const pr = previousRange(from, to);
        const pq = { date_from: pr.from, date_to: pr.to, branch_id: branch || undefined };
        const [ps, pl] = await Promise.all([api<Summary>("/reports/dashboard", { query: pq }).catch(() => null), api<SalesReport>("/reports/sales", { query: pq }).catch(() => null)]);
        setPrevSummary(ps); setPrevSales(pl);
      } else { setPrevSummary(null); setPrevSales(null); }
    } catch (e) { setError(errorMessage(e)); setSummary(null); setSales(null); setInventory(null); setPrevSummary(null); setPrevSales(null); }
    finally { setLoading(false); }
  }, [can, from, to, branch, compare]);
  useEffect(() => { if (can("reports.view")) api<Branch[]>("/reports/branch-options").then(setBranches).catch(() => setBranches([])); }, [can]);
  useEffect(() => { void load(); }, [load]);
  // Mahsulot, sotuv, xarid, ombor yoki kassadagi o‘zgarishlardan so‘ng dashboard refreshsiz yangilanadi.
  useErpSync(() => { void load(); }, { enabled: can("reports.view"), debounceMs: 250 });

  const trend = useMemo(() => toTrend(sales), [sales]);
  const prevTrend = useMemo(() => (compare && prevSales ? toTrend(prevSales) : undefined), [compare, prevSales]);
  const prevPeriod = useMemo(() => previousRange(from, to), [from, to]);
  const lowStock = useMemo(() => (inventory?.data ?? []).filter((p) => p.low_stock).slice(0, 6), [inventory]);
  const salesTotal = (rows?: TrendRow[]) => (rows ?? []).reduce((sum, r) => sum + r.sales, 0);
  const links = [
    { href: "/pos", label: "dash.quick.pos", detail: "dash.quick.pos.d", icon: ShoppingCart, perm: "sales.create" },
    { href: "/products", label: "dash.quick.products", detail: "dash.quick.products.d", icon: Package, perm: "products.view" },
    { href: "/warehouses", label: "dash.quick.warehouses", detail: "dash.quick.warehouses.d", icon: Boxes, perm: "warehouses.view" },
    { href: "/reports", label: "dash.quick.reports", detail: "dash.quick.reports.d", icon: Activity, perm: "reports.view" },
  ].filter((item) => can(item.perm));

  return <AppShell title={t("dash.title")} subtitle={t("dash.subtitle")}>
    <div className="space-y-6">
      <section className="relative overflow-hidden rounded-3xl bg-gradient-to-r from-slate-950 via-blue-950 to-blue-800 p-6 text-white shadow-sm md:p-8">
        <div className="pointer-events-none absolute -right-8 -top-16 h-56 w-56 rounded-full border-[28px] border-white/5" />
        <div className="pointer-events-none absolute right-24 -bottom-24 h-48 w-48 rounded-full bg-cyan-400/10 blur-2xl" />
        <div className="relative flex flex-col justify-between gap-5 md:flex-row md:items-center">
          <div><div className="mb-2 inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/10 px-3 py-1 text-xs text-blue-100"><Activity size={14} /> {t("dash.badge")}</div>
            <h2 className="text-2xl font-bold tracking-tight md:text-3xl">{t("dash.welcome", { name: user?.name || t("dash.userFallback") })}</h2>
            <p className="mt-2 max-w-xl text-sm leading-6 text-blue-100">{t("dash.intro")}</p>
          </div>
          <div className="rounded-2xl border border-white/15 bg-white/10 p-4 backdrop-blur-sm md:min-w-52"><div className="flex items-center gap-2 text-xs text-blue-100"><CalendarDays size={14} /> {t("dash.period")}</div><div className="mt-2 font-semibold">{from} — {to}</div><div className="mt-1 text-xs text-blue-100">{user?.roles.map((r) => r.name).join(", ") || t("dash.roleNone")}</div></div>
        </div>
      </section>

      {can("reports.view") && <section className={`${panel} p-4`}>
        <div className="mb-3 flex items-center justify-between gap-3"><div><h3 className="font-semibold text-slate-900">{t("dash.filters")}</h3><p className="mt-1 text-xs text-slate-500">{t("dash.filtersHint")}</p></div><Button variant="secondary" onClick={load} disabled={loading}><RefreshCw size={15} className={loading ? "animate-spin" : ""} /> {t("common.refresh")}</Button></div>
        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
          <label className="text-xs font-medium text-slate-600">{t("dash.dateFrom")}<input type="date" value={from} onChange={(e) => setFrom(e.target.value)} className={`${inputCls} mt-1.5`} /></label>
          <label className="text-xs font-medium text-slate-600">{t("dash.dateTo")}<input type="date" min={from} value={to} onChange={(e) => setTo(e.target.value)} className={`${inputCls} mt-1.5`} /></label>
          {branches.length > 0 ? <label className="text-xs font-medium text-slate-600">{t("dash.branch")}<select value={branch} onChange={(e) => setBranch(e.target.value)} className={`${inputCls} mt-1.5`}><option value="">{t("dash.allBranches")}</option>{branches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}</select></label> : <div className="flex items-end text-xs text-slate-500">{t("dash.noBranches")}</div>}
          <div className="flex items-end text-xs text-slate-500"><label className="inline-flex cursor-pointer items-center gap-2 text-slate-600"><input type="checkbox" checked={compare} onChange={(e) => setCompare(e.target.checked)} className="h-4 w-4 rounded border-slate-300" />{t("dash.compare")}</label></div>
        </div>
      </section>}
      {error && <Alert>{error}</Alert>}
      {loading ? <div className={`${panel} p-5`}><DashboardSkeleton /></div> : can("reports.view") && summary ? <>
        <section>
          <div className="mb-3 flex items-end justify-between gap-3"><div><h3 className="text-lg font-bold text-slate-900">{t("dash.kpiTitle")}</h3><p className="mt-1 text-sm text-slate-500">{t("dash.kpiHint")}</p></div><Link href="/reports" className="hidden items-center gap-1 text-sm font-semibold text-blue-700 hover:text-blue-900 sm:inline-flex">{t("dash.toReports")} <ArrowRight size={15} /></Link></div>
          <div className="grid auto-rows-fr gap-3 sm:grid-cols-2 xl:grid-cols-4">{kpis.map(({ key, label, icon: Icon, tint, note }) => <div key={key} className={`${panel} p-4 transition hover:-translate-y-0.5 hover:shadow-md`}>
            <div className="flex items-start justify-between gap-3"><div className="text-sm font-medium text-slate-500">{t(label)}</div><div className={`rounded-xl p-2 ${tintClasses[tint]}`}><Icon size={18} /></div></div>
            <div className="mt-4 break-words text-xl font-bold tracking-tight text-slate-950 md:text-2xl">{moneyKeys.has(key) ? formatUZS(String(summary.kpis[key] ?? "0")) : formatQty(String(summary.kpis[key] ?? "0"))}</div><div className="mt-2 flex flex-wrap items-center gap-x-2 text-xs text-slate-400"><span>{t(note)}</span>{compare && prevSummary && deltaKeys.has(key) && <Delta now={Number(summary.kpis[key] ?? 0)} before={Number(prevSummary.kpis[key] ?? 0)} inverse={costKeys.has(key)} suffix={t("dash.vsPrev")} />}</div>
          </div>)}</div>
        </section>

        <section className="grid gap-5 xl:grid-cols-[minmax(0,1.7fr)_minmax(300px,1fr)]">
          <div className={`${panel} p-5`}><div className="mb-5 flex flex-wrap items-start justify-between gap-3"><div><h3 className="font-bold text-slate-900">{t("dash.trendTitle")}</h3><p className="mt-1 text-sm text-slate-500">{t("dash.trendHint")}</p></div><div className="flex gap-3 text-xs"><span className="inline-flex items-center gap-1.5 text-slate-600"><span className="h-2 w-2 rounded-full bg-blue-600" /> {t("dash.legendSales")}</span><span className="inline-flex items-center gap-1.5 text-slate-600"><span className="h-2 w-2 rounded-full bg-rose-400" /> {t("dash.legendReturns")}</span>{prevTrend && <span className="inline-flex items-center gap-1.5 text-slate-600"><span className="h-0.5 w-3 bg-slate-400" /> {t("dash.legendPrev")}</span>}</div></div>
            <SalesChart rows={trend} previous={prevTrend} prevPeriod={prevPeriod} total={salesTotal} />
            {trend.length > 0 && <div className="mt-5 overflow-x-auto rounded-xl border border-slate-100">
              <table className="w-full text-left text-xs">
                <thead className="bg-slate-50 text-slate-500"><tr><th className="px-3 py-2.5 font-medium">{t("dash.colDate")}</th><th className="px-3 py-2.5 text-right font-medium">{t("dash.colSales")}</th><th className="px-3 py-2.5 text-right font-medium">{t("dash.colReturns")}</th><th className="px-3 py-2.5 text-right font-medium">{t("dash.colNet")}</th></tr></thead>
                <tbody className="divide-y divide-slate-100">{trend.slice(-7).reverse().map((r) => <tr key={r.date} className="text-slate-700"><td className="px-3 py-2.5">{r.date}</td><td className="px-3 py-2.5 text-right">{formatUZS(r.sales)}</td><td className="px-3 py-2.5 text-right text-rose-600">{formatUZS(r.returns)}</td><td className="px-3 py-2.5 text-right font-semibold">{formatUZS(r.net)}</td></tr>)}</tbody>
              </table>
            </div>}
          </div>
          <div className={`${panel} p-5`}><div className="mb-4 flex items-start justify-between"><div><h3 className="font-bold text-slate-900">{t("dash.quickTitle")}</h3><p className="mt-1 text-sm text-slate-500">{t("dash.quickHint")}</p></div><div className="rounded-xl bg-blue-50 p-2 text-blue-700"><ArrowRight size={18} /></div></div>
            <div className="space-y-2">{links.map(({ href, label, detail, icon: Icon }) => <Link key={href} href={href} className="group flex items-center gap-3 rounded-xl border border-slate-100 p-3 transition hover:border-blue-200 hover:bg-blue-50/60"><span className="rounded-xl bg-slate-100 p-2.5 text-slate-700 group-hover:bg-white group-hover:text-blue-700"><Icon size={18} /></span><span className="min-w-0 flex-1"><span className="block text-sm font-semibold text-slate-800">{t(label as MessageKey)}</span><span className="mt-0.5 block text-xs text-slate-500">{t(detail as MessageKey)}</span></span><ArrowRight size={16} className="text-slate-300 group-hover:text-blue-700" /></Link>)}</div>
          </div>
        </section>

        <section className="grid gap-5 xl:grid-cols-2">
          <div className={`${panel} overflow-hidden`}><div className="flex items-center justify-between border-b border-slate-100 px-5 py-4"><div><h3 className="font-bold text-slate-900">{t("dash.topTitle")}</h3><p className="mt-1 text-xs text-slate-500">{t("dash.topHint")}</p></div><Link href="/reports" className="text-xs font-semibold text-blue-700">{t("dash.topMore")}</Link></div>
            {(sales?.top_products ?? []).length ? <div className="overflow-x-auto"><div className="px-5 pt-4"><TopProductsChart items={sales!.top_products.slice(0, 5).map((p) => ({ name: p.name, total: Number(p.total) }))} /></div><table className="w-full text-left text-sm"><thead className="bg-slate-50 text-xs text-slate-500"><tr><th className="px-5 py-3 font-medium">{t("dash.colProduct")}</th><th className="px-3 py-3 font-medium">{t("dash.colQty")}</th><th className="px-5 py-3 text-right font-medium">{t("dash.colAmount")}</th></tr></thead><tbody className="divide-y divide-slate-100">{sales!.top_products.slice(0, 5).map((p, i) => <tr key={p.id} className="hover:bg-slate-50/70"><td className="px-5 py-3"><div className="flex items-center gap-3"><span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-xs font-bold text-slate-600">{i + 1}</span><span className="min-w-0"><span className="block truncate font-medium text-slate-800">{p.name}</span><span className="text-xs text-slate-400">{p.sku || t("common.skuNone")}</span></span></div></td><td className="px-3 py-3 text-slate-600">{formatQty(p.quantity)}</td><td className="px-5 py-3 text-right font-semibold text-slate-800">{formatUZS(p.total)}</td></tr>)}</tbody></table></div> : <EmptyState text={t("dash.topEmpty")} />}
          </div>
          <div className={`${panel} overflow-hidden`}><div className="flex items-center justify-between border-b border-slate-100 px-5 py-4"><div><h3 className="font-bold text-slate-900">{t("dash.lowTitle")}</h3><p className="mt-1 text-xs text-slate-500">{t("dash.lowHint")}</p></div><Link href="/reports" className="text-xs font-semibold text-blue-700">{t("dash.lowMore")}</Link></div>
            {lowStock.length ? <div className="divide-y divide-slate-100">{lowStock.map((p) => <div key={p.id} className="flex items-center gap-3 px-5 py-3"><span className="rounded-xl bg-amber-50 p-2.5 text-amber-700"><Package size={17} /></span><span className="min-w-0 flex-1"><span className="block truncate text-sm font-semibold text-slate-800">{p.name}</span><span className="text-xs text-slate-400">{p.sku || t("common.skuNone")}</span></span><span className="text-right"><span className="block text-sm font-bold text-amber-700">{formatQty(p.quantity)}</span><span className="text-xs text-slate-400">{t("dash.min", { value: formatQty(p.min_stock) })}</span></span></div>)}</div> : <EmptyState text={t("dash.lowEmpty")} icon="stock" />}
          </div>
        </section>
        <div className="flex flex-wrap items-center justify-between gap-2 rounded-xl bg-slate-100/80 px-4 py-3 text-xs text-slate-500"><span>{t("dash.footNote")}</span><span>{t("dash.footUser", { email: user?.email ?? "", count: permissions.length })}</span></div>
      </> : <div className={`${panel} p-8 text-center`}><div className="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl bg-slate-100 text-slate-500"><Activity size={22} /></div><h3 className="mt-3 font-semibold text-slate-900">{t("dash.restrictedTitle")}</h3><p className="mt-1 text-sm text-slate-500">{t("dash.restrictedText")}</p></div>}
    </div>
  </AppShell>;
}

function toTrend(sales: SalesReport | null): TrendRow[] {
  const grouped = new Map<string, { sales: number; returns: number }>();
  for (const row of sales?.daily ?? []) {
    const item = grouped.get(row.date) ?? { sales: 0, returns: 0 };
    if (row.type === "return") item.returns += Number(row.total || 0);
    else item.sales += Number(row.total || 0);
    grouped.set(row.date, item);
  }
  return [...grouped.entries()].sort(([a], [b]) => a.localeCompare(b)).map(([date, v]) => ({ date, ...v, net: v.sales - v.returns }));
}

function Delta({ now, before, inverse, suffix }: { now: number; before: number; inverse?: boolean; suffix: string }) {
  if (!before) return null;
  const pct = ((now - before) / Math.abs(before)) * 100;
  if (!Number.isFinite(pct)) return null;
  const up = pct >= 0;
  const good = inverse ? !up : up;
  const Icon = up ? ArrowUpRight : ArrowDownRight;
  return <span className={`inline-flex items-center gap-0.5 font-semibold ${good ? "text-emerald-600" : "text-rose-600"}`} title={suffix}><Icon size={13} />{Math.abs(pct).toFixed(1)}%</span>;
}

function DashboardSkeleton() {
  return <div className={`${panel} space-y-4 p-5`}><div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">{Array.from({ length: 8 }).map((_, i) => <Skeleton key={i} className="h-24" />)}</div><Skeleton className="h-64" /></div>;
}

function SalesChart({ rows, previous, prevPeriod, total }: { rows: TrendRow[]; previous?: TrendRow[]; prevPeriod: { from: string; to: string }; total: (r?: TrendRow[]) => number }) {
  const { t } = useI18n();
  if (!rows.length || rows.every((r) => r.sales === 0 && r.returns === 0)) return <EmptyState text={t("dash.chartEmpty")} />;
  const returns = rows.reduce((sum, r) => sum + r.returns, 0);
  return <div className="w-full"><div className="mb-2 flex items-center justify-between text-xs text-slate-400"><span>{t("dash.axisAmount")}</span><span>{t("dash.points", { n: rows.length })}</span></div>
    <SalesTrendChart rows={rows} previous={previous} />
    <div className={`mt-3 grid gap-3 ${previous ? "grid-cols-3" : "grid-cols-2"}`}>
      <div className="rounded-xl bg-blue-50 p-3"><div className="text-xs text-blue-700">{t("dash.revenue")}</div><div className="mt-1 font-bold text-blue-950">{formatUZS(total(rows))}</div></div>
      <div className="rounded-xl bg-rose-50 p-3"><div className="text-xs text-rose-700">{t("dash.returnsTotal")}</div><div className="mt-1 font-bold text-rose-950">{formatUZS(returns)}</div></div>
      {previous && <div className="rounded-xl bg-slate-100 p-3"><div className="text-xs text-slate-600">{t("dash.prevRevenue")}</div><div className="mt-1 font-bold text-slate-900">{formatUZS(total(previous))}</div><div className="mt-0.5 text-[10px] text-slate-400">{prevPeriod.from} — {prevPeriod.to}</div></div>}
    </div></div>;
}
function EmptyState({ text, icon }: { text: string; icon?: "stock" }) { return <div className="flex min-h-32 flex-col items-center justify-center px-5 py-8 text-center"><span className={`rounded-xl p-3 ${icon === "stock" ? "bg-emerald-50 text-emerald-700" : "bg-slate-100 text-slate-500"}`}>{icon === "stock" ? <ArrowDownRight size={20} /> : <Activity size={20} />}</span><p className="mt-3 max-w-sm text-sm text-slate-500">{text}</p></div>; }
