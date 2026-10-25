"use client";
import Link from "next/link";
import { ReactNode, useCallback, useEffect, useState } from "react";
import {
  Area, AreaChart, Bar, BarChart, CartesianGrid, Cell, Legend, Pie, PieChart, ResponsiveContainer, Tooltip, XAxis, YAxis,
} from "recharts";
import {
  Activity, ArrowDownRight, ArrowRight, ArrowUpRight, Banknote, Boxes, CircleAlert, ClipboardList, Clock, CreditCard, FileText,
  Package, Receipt, RefreshCw, ShoppingCart, Truck, TrendingUp, Users, Wallet,
} from "lucide-react";
import AppShell from "./AppShell";
import { Alert, Skeleton } from "./ui";
import { useAuth } from "../app/lib/auth";
import { api, errorMessage } from "../app/lib/api";

/* ------------------------------------------------------------------ turlar */
type Totals = { count: number; total: string; debt: string };
type Day = { date: string; total?: number; count?: number; incoming?: number; outgoing?: number };
type Method = { method: string; total: number };
type TopProduct = { id: number; name: string; quantity: number; total: number };
type Payload = {
  user: { id: number; name: string; roles: { name: string; slug: string }[] };
  generated_at: string;
  personal: {
    actions: { id: number; action: string; entity: string | null; entity_id: number | null; result: string | null; at: string }[];
    sales?: {
      today: Totals; week: Totals; month: Totals & { avg_check: string }; returns_month: number; series: Day[]; by_method: Method[]; top_products: TopProduct[];
      recent: { id: number; number: string; type: string; status: string; total: string; debt_amount: string; sold_at: string; customer: string | null }[];
    };
    shift?: {
      open: { id: number; cashbox: string; opened_at: string; opening_cash: string; sales_count: number; sales_total: string } | null;
      recent: { id: number; status: string; opened_at: string; closed_at: string | null; difference: string | null; cashbox: string }[];
    };
    documents?: { month_count: number; by_type: { type: string; count: number }[]; recent: { id: number; number: string; type: string; status: string; total: string; doc_date: string }[] };
    expenses?: { month_count: number; month_total: string; pending: number };
  };
  overview: {
    sales?: { today: Totals; yesterday: Totals; week: Totals; month: Totals; series: Day[]; by_method: Method[]; top_products: TopProduct[]; by_cashier: { name: string; count: number; total: number }[] };
    stock?: {
      products: number; low_count: number; low: { id: number; name: string; sku: string; quantity: number; min_stock: number }[];
      warehouses?: number; by_warehouse?: { name: string; positions: number }[];
      movements?: { id: number; type: string; quantity: string; created_at: string; product: string; warehouse: string }[]; movement_series?: Day[];
    };
    purchases?: { month_total: string; month_count: number; drafts: number; by_supplier: { name: string; total: number }[] };
    customers?: { count: number; debt: string };
    finance?: { cash_balance?: string; bank_balance?: string; month_expenses?: string; pending_expenses?: number; expenses_by_category?: { category: string; total: number }[] };
    shifts?: { open: number; to_review: number | null };
    team?: { users: number | null; employees: number | null };
  };
};

/* ------------------------------------------------------------ yordamchilar */
const nf = (n: number) => Math.round(n).toLocaleString("ru-RU").replace(/\u00a0/g, " ");
const money = (v: string | number | null | undefined) => `${nf(Number(v ?? 0))} so‘m`;
const compact = (v: number) => {
  const a = Math.abs(v);
  if (a >= 1e9) return `${(v / 1e9).toFixed(1)} mlrd`;
  if (a >= 1e6) return `${(v / 1e6).toFixed(1)} mln`;
  if (a >= 1e3) return `${(v / 1e3).toFixed(0)} ming`;
  return String(Math.round(v));
};
const shortDate = (d: string) => `${d.slice(8, 10)}.${d.slice(5, 7)}`;
const dt = (iso?: string | null) => {
  if (!iso) return "—";
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return String(iso).slice(0, 16).replace("T", " ");
  return `${String(d.getDate()).padStart(2, "0")}.${String(d.getMonth() + 1).padStart(2, "0")} ${String(d.getHours()).padStart(2, "0")}:${String(d.getMinutes()).padStart(2, "0")}`;
};
const METHOD: Record<string, string> = { cash: "Naqd pul", card: "Bank kartasi", click: "Click", payme: "Payme", transfer: "Bank o‘tkazmasi" };
const DOC_TYPE: Record<string, string> = { purchase: "Xarid", purchase_return: "Xaridni qaytarish", transfer: "O‘tkazma", write_off: "Hisobdan chiqarish", adjustment: "Tuzatish", receipt: "Kirim" };
const MOVE_TYPE: Record<string, string> = { purchase: "Xarid", sale: "Sotuv", return: "Qaytarish", transfer_in: "O‘tkazma (kirim)", transfer_out: "O‘tkazma (chiqim)", purchase_return: "Xarid qaytarish", write_off: "Hisobdan chiqarish", adjustment: "Tuzatish", opening: "Boshlang‘ich qoldiq" };
const COLORS = ["#2563eb", "#10b981", "#f59e0b", "#8b5cf6", "#06b6d4", "#f43f5e", "#64748b"];
const AXIS = "#94a3b8";
const GRID = "rgba(148,163,184,0.25)";
const nice = (s: string) => { const t = s.replace(/[._]/g, " ").trim(); return t.charAt(0).toUpperCase() + t.slice(1); };
const label = (map: Record<string, string>, k: string) => map[k] ?? nice(k);
const pct = (cur: number, prev: number) => (prev > 0 ? ((cur - prev) / prev) * 100 : cur > 0 ? 100 : 0);

/* ----------------------------------------------------------- kichik bloklar */
const panel = "rounded-2xl border border-slate-200/80 bg-white shadow-sm";
const tints: Record<string, string> = {
  blue: "bg-blue-50 text-blue-700", emerald: "bg-emerald-50 text-emerald-700", amber: "bg-amber-50 text-amber-700", violet: "bg-violet-50 text-violet-700",
  cyan: "bg-cyan-50 text-cyan-700", indigo: "bg-indigo-50 text-indigo-700", rose: "bg-rose-50 text-rose-700", orange: "bg-orange-50 text-orange-700",
};

function Section({ title, hint, children }: { title: string; hint?: string; children: ReactNode }) {
  return (
    <section className="space-y-3">
      <div><h3 className="text-lg font-bold text-slate-900">{title}</h3>{hint && <p className="mt-0.5 text-xs text-slate-500">{hint}</p>}</div>
      {children}
    </section>
  );
}

function Kpi({ title, value, note, icon: Icon, tint = "blue", delta, invert }: {
  title: string; value: string; note?: string; icon: typeof Activity; tint?: keyof typeof tints; delta?: number; invert?: boolean;
}) {
  const good = delta === undefined ? true : invert ? delta <= 0 : delta >= 0;
  return (
    <div className={`${panel} p-4`}>
      <div className="flex items-start justify-between gap-2">
        <div className="min-w-0 text-xs font-medium text-slate-500">{title}</div>
        <span className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-xl ${tints[tint]}`}><Icon size={18} /></span>
      </div>
      <div className="mt-2 break-words text-xl font-bold tracking-tight text-slate-950">{value}</div>
      <div className="mt-1 flex flex-wrap items-center gap-2 text-xs text-slate-500">
        {delta !== undefined && (
          <span className={`inline-flex items-center gap-0.5 rounded-full px-1.5 py-0.5 font-semibold ${good ? "bg-emerald-50 text-emerald-700" : "bg-rose-50 text-rose-700"}`}>
            {delta >= 0 ? <ArrowUpRight size={12} /> : <ArrowDownRight size={12} />}{Math.abs(delta).toFixed(0)}%
          </span>
        )}
        {note && <span>{note}</span>}
      </div>
    </div>
  );
}

function ChartCard({ title, hint, children, className = "" }: { title: string; hint?: string; children: ReactNode; className?: string }) {
  return (
    <div className={`${panel} p-4 ${className}`}>
      <div className="mb-3"><h4 className="font-semibold text-slate-900">{title}</h4>{hint && <p className="text-xs text-slate-500">{hint}</p>}</div>
      {children}
    </div>
  );
}
const Empty = ({ text = "Hozircha ma‘lumot yo‘q" }: { text?: string }) => (
  <div className="flex h-40 items-center justify-center rounded-xl bg-slate-50 text-sm text-slate-400">{text}</div>
);

type TipItem = { name?: string; value?: number | string; color?: string };
function Tip({ active, payload, label: l, money: asMoney = true }: { active?: boolean; payload?: TipItem[]; label?: string | number; money?: boolean }) {
  if (!active || !payload?.length) return null;
  return (
    <div className="rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs shadow-lg">
      {l !== undefined && <div className="mb-1 font-semibold text-slate-800">{l}</div>}
      {payload.map((p, i) => (
        <div key={i} className="flex items-center gap-2 text-slate-600">
          <span className="h-2 w-2 rounded-full" style={{ background: p.color }} /><span>{p.name}:</span>
          <span className="font-semibold text-slate-800">{asMoney ? money(p.value) : nf(Number(p.value ?? 0))}</span>
        </div>
      ))}
    </div>
  );
}

function TrendArea({ rows, name = "Savdo", color = "#2563eb" }: { rows: Day[]; name?: string; color?: string }) {
  if (!rows.length || rows.every((r) => !r.total)) return <Empty text="So‘nggi 14 kunda savdo bo‘lmagan" />;
  const data = rows.map((r) => ({ d: shortDate(r.date), total: r.total ?? 0 }));
  const id = `g${color.slice(1)}`;
  return (
    <div className="h-56 w-full">
      <ResponsiveContainer>
        <AreaChart data={data} margin={{ top: 8, right: 8, left: -8, bottom: 0 }}>
          <defs><linearGradient id={id} x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stopColor={color} stopOpacity={0.35} /><stop offset="100%" stopColor={color} stopOpacity={0.02} /></linearGradient></defs>
          <CartesianGrid stroke={GRID} vertical={false} />
          <XAxis dataKey="d" tick={{ fontSize: 11, fill: AXIS }} tickLine={false} axisLine={false} />
          <YAxis tick={{ fontSize: 11, fill: AXIS }} tickLine={false} axisLine={false} tickFormatter={compact} width={52} />
          <Tooltip content={<Tip />} />
          <Area type="monotone" dataKey="total" name={name} stroke={color} strokeWidth={2.5} fill={`url(#${id})`} />
        </AreaChart>
      </ResponsiveContainer>
    </div>
  );
}

function Donut({ rows, nameOf = (s: string) => s }: { rows: { name: string; value: number }[]; nameOf?: (s: string) => string }) {
  const data = rows.filter((r) => r.value > 0);
  if (!data.length) return <Empty />;
  return (
    <div className="h-56 w-full">
      <ResponsiveContainer>
        <PieChart>
          <Pie data={data} dataKey="value" nameKey="name" innerRadius={50} outerRadius={80} paddingAngle={2} stroke="none">
            {data.map((_, i) => <Cell key={i} fill={COLORS[i % COLORS.length]} />)}
          </Pie>
          <Tooltip content={<Tip />} />
          <Legend iconType="circle" iconSize={8} formatter={(v: string) => <span className="text-xs text-slate-600">{nameOf(v)}</span>} />
        </PieChart>
      </ResponsiveContainer>
    </div>
  );
}

function HBar({ rows, money: asMoney = true, color = "#2563eb" }: { rows: { name: string; value: number }[]; money?: boolean; color?: string }) {
  if (!rows.length || rows.every((r) => !r.value)) return <Empty />;
  const data = rows.map((r) => ({ ...r, name: r.name.length > 18 ? `${r.name.slice(0, 17)}…` : r.name }));
  return (
    <div style={{ height: Math.max(180, data.length * 38) }} className="w-full">
      <ResponsiveContainer>
        <BarChart data={data} layout="vertical" margin={{ top: 0, right: 12, left: 4, bottom: 0 }}>
          <CartesianGrid stroke={GRID} horizontal={false} />
          <XAxis type="number" tick={{ fontSize: 11, fill: AXIS }} tickLine={false} axisLine={false} tickFormatter={asMoney ? compact : undefined} />
          <YAxis type="category" dataKey="name" width={104} tick={{ fontSize: 11, fill: "#475569" }} tickLine={false} axisLine={false} />
          <Tooltip content={<Tip money={asMoney} />} cursor={{ fill: "rgba(148,163,184,0.12)" }} />
          <Bar dataKey="value" name={asMoney ? "Summa" : "Soni"} fill={color} radius={[0, 6, 6, 0]} barSize={16} />
        </BarChart>
      </ResponsiveContainer>
    </div>
  );
}

function Moves({ rows }: { rows: Day[] }) {
  if (!rows.length || rows.every((r) => !r.incoming && !r.outgoing)) return <Empty text="So‘nggi 14 kunda harakat bo‘lmagan" />;
  const data = rows.map((r) => ({ d: shortDate(r.date), incoming: r.incoming ?? 0, outgoing: r.outgoing ?? 0 }));
  return (
    <div className="h-56 w-full">
      <ResponsiveContainer>
        <BarChart data={data} margin={{ top: 8, right: 8, left: -20, bottom: 0 }}>
          <CartesianGrid stroke={GRID} vertical={false} />
          <XAxis dataKey="d" tick={{ fontSize: 11, fill: AXIS }} tickLine={false} axisLine={false} />
          <YAxis tick={{ fontSize: 11, fill: AXIS }} tickLine={false} axisLine={false} allowDecimals={false} />
          <Tooltip content={<Tip money={false} />} cursor={{ fill: "rgba(148,163,184,0.12)" }} />
          <Legend iconType="circle" iconSize={8} formatter={(v: string) => <span className="text-xs text-slate-600">{v === "incoming" ? "Kirim" : "Chiqim"}</span>} />
          <Bar dataKey="incoming" name="Kirim" fill="#10b981" radius={[4, 4, 0, 0]} />
          <Bar dataKey="outgoing" name="Chiqim" fill="#f43f5e" radius={[4, 4, 0, 0]} />
        </BarChart>
      </ResponsiveContainer>
    </div>
  );
}

function Table({ head, children, empty }: { head: string[]; children: ReactNode; empty?: boolean }) {
  if (empty) return <Empty />;
  return (
    <div className="overflow-x-auto">
      <table className="w-full min-w-[420px] text-left text-sm">
        <thead className="text-xs text-slate-500"><tr>{head.map((h, i) => <th key={h} className={`px-3 py-2 font-medium ${i === head.length - 1 ? "text-right" : ""}`}>{h}</th>)}</tr></thead>
        <tbody className="divide-y divide-slate-100">{children}</tbody>
      </table>
    </div>
  );
}

const Pill = ({ tone, children }: { tone: "green" | "red" | "slate" | "amber"; children: ReactNode }) => {
  const c = { green: "bg-emerald-50 text-emerald-700", red: "bg-rose-50 text-rose-700", slate: "bg-slate-100 text-slate-600", amber: "bg-amber-50 text-amber-700" }[tone];
  return <span className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${c}`}>{children}</span>;
};
const saleTone = (s: string) => (s === "approved" ? "green" : s === "cancelled" ? "red" : "slate");
const saleText = (s: string) => ({ approved: "Tasdiqlangan", cancelled: "Bekor qilingan", draft: "Qoralama" }[s] ?? nice(s));

/* ------------------------------------------------------------ asosiy oyna */
export default function RoleDashboard() {
  const { user, can } = useAuth();
  const [data, setData] = useState<Payload | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  const load = useCallback(async () => {
    setLoading(true); setError("");
    try { setData(await api<Payload>("/dashboard/me")); } catch (e) { setError(errorMessage(e)); }
    finally { setLoading(false); }
  }, []);
  useEffect(() => { load(); }, [load]);
  useEffect(() => { const id = setInterval(load, 60000); return () => clearInterval(id); }, [load]);

  const roleNames = (data?.user.roles ?? user?.roles ?? []).map((r) => r.name).join(", ") || "Foydalanuvchi";
  const p = data?.personal, o = data?.overview;
  const today = new Date().toLocaleDateString("ru-RU", { weekday: "long", day: "2-digit", month: "long", year: "numeric" });

  const quick = [
    { href: "/pos", label: "Savdo oynasi", icon: ShoppingCart, show: can("sales.create") },
    { href: "/shifts", label: "Kassa smenasi", icon: Clock, show: can("shifts.view") },
    { href: "/sales", label: "Sotuvlar tarixi", icon: Receipt, show: can("sales.view") },
    { href: "/products", label: "Mahsulotlar", icon: Package, show: can("products.view") },
    { href: "/purchases", label: "Xaridlar", icon: Truck, show: can("purchases.view") },
    { href: "/inventory", label: "Ombor qoldiqlari", icon: Boxes, show: can("warehouses.view") },
    { href: "/expenses", label: "Xarajatlar", icon: Banknote, show: can("expenses.view") },
    { href: "/customers", label: "Mijozlar", icon: Users, show: can("customers.view") },
    { href: "/reports", label: "Hisobotlar", icon: TrendingUp, show: can("reports.view") },
  ].filter((x) => x.show);

  return (
    <AppShell title="Boshqaruv paneli" subtitle="Sizning rolingizga mos ko‘rsatkichlar va harakatlar">
      <div className="space-y-6">
        {/* Salomlashuv */}
        <div className="relative overflow-hidden rounded-3xl bg-gradient-to-br from-blue-700 via-indigo-700 to-violet-700 p-6 text-white shadow-lg">
          <div className="absolute -right-10 -top-10 h-44 w-44 rounded-full bg-white/10 blur-2xl" />
          <div className="relative flex flex-wrap items-start justify-between gap-4">
            <div>
              <div className="mb-2 inline-flex items-center gap-2 rounded-full border border-white/20 bg-white/10 px-3 py-1 text-xs text-blue-50"><Activity size={14} />{roleNames}</div>
              <h2 className="text-2xl font-bold tracking-tight md:text-3xl">Xush kelibsiz, {user?.name ?? data?.user.name ?? "foydalanuvchi"}!</h2>
              <p className="mt-1 text-sm capitalize text-blue-100">{today}</p>
              {p?.shift && (
                <div className={`mt-3 inline-flex items-center gap-2 rounded-xl px-3 py-1.5 text-xs font-medium ${p.shift.open ? "bg-emerald-400/20 text-emerald-50" : "bg-white/10 text-blue-100"}`}>
                  <span className={`h-2 w-2 rounded-full ${p.shift.open ? "bg-emerald-300" : "bg-slate-300"}`} />
                  {p.shift.open ? `Smena ochiq: ${p.shift.open.cashbox} · ${dt(p.shift.open.opened_at)} dan beri` : "Hozir ochiq smena yo‘q"}
                </div>
              )}
            </div>
            <button type="button" onClick={load} disabled={loading} className="inline-flex items-center gap-2 rounded-xl border border-white/20 bg-white/10 px-3 py-2 text-xs font-medium hover:bg-white/20 disabled:opacity-60">
              <RefreshCw size={14} className={loading ? "animate-spin" : ""} />Yangilash
            </button>
          </div>
        </div>

        {error && <Alert>{error}</Alert>}
        {loading && !data && <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">{[0, 1, 2, 3].map((i) => <Skeleton key={i} className="h-28 w-full rounded-2xl" />)}</div>}

        {quick.length > 0 && (
          <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-5">
            {quick.map(({ href, label: l, icon: Icon }) => (
              <Link key={href} href={href} className={`${panel} group flex items-center gap-3 p-3 transition hover:border-blue-300 hover:shadow-md`}>
                <span className="flex h-9 w-9 items-center justify-center rounded-xl bg-blue-50 text-blue-700"><Icon size={18} /></span>
                <span className="min-w-0 flex-1 truncate text-sm font-medium text-slate-800">{l}</span>
                <ArrowRight size={14} className="text-slate-300 transition group-hover:translate-x-0.5 group-hover:text-blue-600" />
              </Link>
            ))}
          </div>
        )}

        {data && p && (
          <Section title="Mening harakatlarim" hint="Faqat sizning nomingizdan bajarilgan ishlar">
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
              {p.sales && <>
                <Kpi title="Bugungi sotuvlarim" value={money(p.sales.today.total)} note={`${p.sales.today.count} ta chek`} icon={ShoppingCart} tint="blue" />
                <Kpi title="So‘nggi 7 kun" value={money(p.sales.week.total)} note={`${p.sales.week.count} ta chek`} icon={TrendingUp} tint="emerald" />
                <Kpi title="Shu oy" value={money(p.sales.month.total)} note={`o‘rtacha chek ${money(p.sales.month.avg_check)}`} icon={Wallet} tint="violet" />
                <Kpi title="Shu oy qarzga berilgan" value={money(p.sales.month.debt)} note={`${p.sales.returns_month} ta qaytarish`} icon={CircleAlert} tint="amber" />
              </>}
              {p.shift?.open && <Kpi title="Joriy smena savdosi" value={money(p.shift.open.sales_total)} note={`${p.shift.open.sales_count} ta chek · boshida ${money(p.shift.open.opening_cash)}`} icon={Clock} tint="cyan" />}
              {p.documents && <Kpi title="Shu oy hujjatlarim" value={nf(p.documents.month_count)} note={p.documents.by_type.map((x) => `${label(DOC_TYPE, x.type)}: ${x.count}`).join(" · ") || "hujjat yo‘q"} icon={FileText} tint="indigo" />}
              {p.expenses && <Kpi title="Shu oy xarajatlarim" value={money(p.expenses.month_total)} note={`${p.expenses.month_count} ta · kutilmoqda: ${p.expenses.pending}`} icon={Banknote} tint="orange" />}
            </div>

            {p.sales && (
              <div className="grid gap-4 lg:grid-cols-3">
                <ChartCard title="Mening savdo dinamikam" hint="Oxirgi 14 kun" className="lg:col-span-2"><TrendArea rows={p.sales.series} /></ChartCard>
                <ChartCard title="To‘lov usullari" hint="Shu oy, mening sotuvlarim"><Donut rows={p.sales.by_method.map((m) => ({ name: m.method, value: m.total }))} nameOf={(s) => label(METHOD, s)} /></ChartCard>
              </div>
            )}

            <div className="grid gap-4 lg:grid-cols-2">
              {p.sales && (
                <ChartCard title="Eng ko‘p sotgan mahsulotlarim" hint="Shu oy, summa bo‘yicha"><HBar rows={p.sales.top_products.map((x) => ({ name: x.name, value: x.total }))} color="#10b981" /></ChartCard>
              )}
              {p.sales && (
                <div className={`${panel} p-4`}>
                  <h4 className="mb-2 font-semibold text-slate-900">So‘nggi sotuvlarim</h4>
                  <Table head={["Raqam", "Mijoz", "Holat", "Summa"]} empty={!p.sales.recent.length}>
                    {p.sales.recent.map((s) => (
                      <tr key={s.id}>
                        <td className="px-3 py-2"><div className="font-medium text-slate-800">{s.number}</div><div className="text-xs text-slate-400">{dt(s.sold_at)}</div></td>
                        <td className="px-3 py-2 text-slate-600">{s.customer ?? "—"}</td>
                        <td className="px-3 py-2"><Pill tone={saleTone(s.status)}>{s.type === "return" ? "Qaytarish" : saleText(s.status)}</Pill></td>
                        <td className="px-3 py-2 text-right font-semibold text-slate-800">{money(s.total)}</td>
                      </tr>
                    ))}
                  </Table>
                </div>
              )}
              {p.documents && (
                <div className={`${panel} p-4`}>
                  <h4 className="mb-2 font-semibold text-slate-900">So‘nggi hujjatlarim</h4>
                  <Table head={["Raqam", "Turi", "Holat", "Summa"]} empty={!p.documents.recent.length}>
                    {p.documents.recent.map((d) => (
                      <tr key={d.id}>
                        <td className="px-3 py-2"><div className="font-medium text-slate-800">{d.number}</div><div className="text-xs text-slate-400">{d.doc_date}</div></td>
                        <td className="px-3 py-2 text-slate-600">{label(DOC_TYPE, d.type)}</td>
                        <td className="px-3 py-2"><Pill tone={saleTone(d.status)}>{saleText(d.status)}</Pill></td>
                        <td className="px-3 py-2 text-right font-semibold text-slate-800">{money(d.total)}</td>
                      </tr>
                    ))}
                  </Table>
                </div>
              )}
              {p.shift && (
                <div className={`${panel} p-4`}>
                  <h4 className="mb-2 font-semibold text-slate-900">Mening smenalarim</h4>
                  <Table head={["Kassa", "Ochilgan", "Holat", "Farq"]} empty={!p.shift.recent.length}>
                    {p.shift.recent.map((s) => {
                      const diff = Number(s.difference ?? 0);
                      return (
                        <tr key={s.id}>
                          <td className="px-3 py-2 font-medium text-slate-800">{s.cashbox}</td>
                          <td className="px-3 py-2 text-slate-600">{dt(s.opened_at)}</td>
                          <td className="px-3 py-2"><Pill tone={s.status === "open" ? "green" : "slate"}>{s.status === "open" ? "Ochiq" : "Yopilgan"}</Pill></td>
                          <td className={`px-3 py-2 text-right font-semibold ${diff === 0 ? "text-slate-500" : diff < 0 ? "text-rose-600" : "text-emerald-600"}`}>{s.status === "open" ? "—" : money(diff)}</td>
                        </tr>
                      );
                    })}
                  </Table>
                </div>
              )}
              <div className={`${panel} p-4`}>
                <h4 className="mb-3 flex items-center gap-2 font-semibold text-slate-900"><ClipboardList size={16} className="text-slate-400" />So‘nggi harakatlarim</h4>
                {p.actions.length ? (
                  <ol className="relative space-y-3 border-l border-slate-200 pl-4">
                    {p.actions.map((a) => (
                      <li key={a.id} className="relative">
                        <span className={`absolute -left-[21px] top-1 h-2.5 w-2.5 rounded-full ring-4 ring-white ${a.result === "failed" ? "bg-rose-500" : "bg-blue-500"}`} />
                        <div className="flex flex-wrap items-center justify-between gap-2">
                          <span className="text-sm font-medium text-slate-800">{nice(a.action)}{a.entity ? ` · ${a.entity}${a.entity_id ? ` #${a.entity_id}` : ""}` : ""}</span>
                          <span className="text-xs text-slate-400">{dt(a.at)}</span>
                        </div>
                        {a.result === "failed" && <span className="text-xs text-rose-600">Bajarilmadi</span>}
                      </li>
                    ))}
                  </ol>
                ) : <Empty text="Hali harakat qayd etilmagan" />}
              </div>
            </div>
          </Section>
        )}

        {data && o && Object.keys(o).length > 0 && (
          <Section title="Umumiy holat" hint="Do‘kon bo‘yicha jami ko‘rsatkichlar (ruxsatingizdagi bo‘limlar)">
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
              {o.sales && <>
                <Kpi title="Bugungi savdo (jami)" value={money(o.sales.today.total)} note={`${o.sales.today.count} ta chek · kecha ${money(o.sales.yesterday.total)}`} icon={ShoppingCart} tint="blue" delta={pct(Number(o.sales.today.total), Number(o.sales.yesterday.total))} />
                <Kpi title="Shu oy savdo" value={money(o.sales.month.total)} note={`${o.sales.month.count} ta chek`} icon={TrendingUp} tint="emerald" />
              </>}
              {o.stock && <>
                <Kpi title="Faol mahsulotlar" value={nf(o.stock.products)} note={o.stock.warehouses !== undefined ? `${o.stock.warehouses} ta ombor` : undefined} icon={Package} tint="indigo" />
                <Kpi title="Kam qolgan mahsulotlar" value={nf(o.stock.low_count)} note="minimal qoldiqdan past" icon={CircleAlert} tint="orange" delta={o.stock.low_count ? o.stock.low_count : undefined} invert />
              </>}
              {o.purchases && <Kpi title="Shu oy xaridlar" value={money(o.purchases.month_total)} note={`${o.purchases.month_count} ta · qoralama: ${o.purchases.drafts}`} icon={Truck} tint="cyan" />}
              {o.customers && <Kpi title="Mijozlar qarzi" value={money(o.customers.debt)} note={`${o.customers.count} ta faol mijoz`} icon={Users} tint="rose" />}
              {o.finance?.cash_balance !== undefined && <Kpi title="Kassa qoldig‘i" value={money(o.finance.cash_balance)} icon={Wallet} tint="cyan" />}
              {o.finance?.bank_balance !== undefined && <Kpi title="Bank qoldig‘i" value={money(o.finance.bank_balance)} icon={CreditCard} tint="indigo" />}
              {o.finance?.month_expenses !== undefined && <Kpi title="Shu oy xarajatlar" value={money(o.finance.month_expenses)} note={`kutilayotgan: ${o.finance.pending_expenses ?? 0}`} icon={Banknote} tint="amber" />}
              {o.shifts && <Kpi title="Ochiq smenalar" value={nf(o.shifts.open)} note={o.shifts.to_review !== null ? `tekshiruv kutmoqda: ${o.shifts.to_review}` : undefined} icon={Clock} tint="violet" />}
              {o.team && <Kpi title="Jamoa" value={nf(o.team.users ?? o.team.employees ?? 0)} note={[o.team.users !== null ? "foydalanuvchi" : "", o.team.employees !== null ? `${o.team.employees} xodim` : ""].filter(Boolean).join(" · ")} icon={Users} tint="blue" />}
            </div>

            {o.sales && (
              <div className="grid gap-4 lg:grid-cols-3">
                <ChartCard title="Do‘kon savdosi" hint="Oxirgi 14 kun, barcha kassirlar" className="lg:col-span-2"><TrendArea rows={o.sales.series} color="#10b981" name="Jami savdo" /></ChartCard>
                <ChartCard title="To‘lov usullari" hint="Shu oy, jami"><Donut rows={o.sales.by_method.map((m) => ({ name: m.method, value: m.total }))} nameOf={(s) => label(METHOD, s)} /></ChartCard>
                <ChartCard title="Top mahsulotlar" hint="Oxirgi 30 kun"><HBar rows={o.sales.top_products.map((x) => ({ name: x.name, value: x.total }))} color="#8b5cf6" /></ChartCard>
                <ChartCard title="Kassirlar reytingi" hint="Shu oy, savdo summasi"><HBar rows={o.sales.by_cashier.map((x) => ({ name: x.name, value: x.total }))} color="#06b6d4" /></ChartCard>
              </div>
            )}

            <div className="grid gap-4 lg:grid-cols-3">
              {o.stock?.movement_series && <ChartCard title="Ombor harakati" hint="Oxirgi 14 kun (kirim va chiqim soni)" className="lg:col-span-2"><Moves rows={o.stock.movement_series} /></ChartCard>}
              {o.stock?.by_warehouse && <ChartCard title="Omborlar bo‘yicha pozitsiyalar" hint="Qoldig‘i bor mahsulot turlari"><HBar rows={o.stock.by_warehouse.map((x) => ({ name: x.name, value: x.positions }))} money={false} color="#f59e0b" /></ChartCard>}
              {o.finance?.expenses_by_category && <ChartCard title="Xarajatlar turlari" hint="Shu oy, tasdiqlangan"><Donut rows={o.finance.expenses_by_category.map((x) => ({ name: x.category, value: x.total }))} /></ChartCard>}
              {o.purchases && <ChartCard title="Yetkazib beruvchilar" hint="Shu oy xarid summasi"><HBar rows={o.purchases.by_supplier.map((x) => ({ name: x.name, value: x.total }))} color="#f43f5e" /></ChartCard>}
            </div>

            <div className="grid gap-4 lg:grid-cols-2">
              {o.stock && (
                <div className={`${panel} p-4`}>
                  <div className="mb-2 flex items-center justify-between"><h4 className="font-semibold text-slate-900">Qoldig‘i kam mahsulotlar</h4>{can("warehouses.view") && <Link href="/inventory" className="text-xs font-medium text-blue-600 hover:underline">Barchasi</Link>}</div>
                  <Table head={["Mahsulot", "Minimal", "Qoldiq"]} empty={!o.stock.low.length}>
                    {o.stock.low.map((x) => (
                      <tr key={x.id}>
                        <td className="px-3 py-2"><div className="font-medium text-slate-800">{x.name}</div><div className="text-xs text-slate-400">{x.sku}</div></td>
                        <td className="px-3 py-2 text-slate-600">{nf(x.min_stock)}</td>
                        <td className="px-3 py-2 text-right"><Pill tone={x.quantity <= 0 ? "red" : "amber"}>{nf(x.quantity)}</Pill></td>
                      </tr>
                    ))}
                  </Table>
                </div>
              )}
              {o.stock?.movements && (
                <div className={`${panel} p-4`}>
                  <div className="mb-2 flex items-center justify-between"><h4 className="font-semibold text-slate-900">So‘nggi ombor harakatlari</h4><Link href="/stock-movements" className="text-xs font-medium text-blue-600 hover:underline">Barchasi</Link></div>
                  <Table head={["Mahsulot", "Turi", "Miqdor"]} empty={!o.stock.movements.length}>
                    {o.stock.movements.map((m) => {
                      const q = Number(m.quantity);
                      return (
                        <tr key={m.id}>
                          <td className="px-3 py-2"><div className="font-medium text-slate-800">{m.product}</div><div className="text-xs text-slate-400">{m.warehouse} · {dt(m.created_at)}</div></td>
                          <td className="px-3 py-2 text-slate-600">{label(MOVE_TYPE, m.type)}</td>
                          <td className={`px-3 py-2 text-right font-semibold ${q < 0 ? "text-rose-600" : "text-emerald-600"}`}>{q > 0 ? "+" : ""}{nf(q)}</td>
                        </tr>
                      );
                    })}
                  </Table>
                </div>
              )}
            </div>
          </Section>
        )}

        {data && !p?.sales && !p?.shift && !p?.documents && !p?.expenses && (!o || Object.keys(o).length === 0) && (
          <div className={`${panel} p-8 text-center text-sm text-slate-500`}>Sizning rolingiz uchun ko‘rsatiladigan bo‘limlar hali sozlanmagan. Administratorga murojaat qiling.</div>
        )}
        <div className="rounded-xl bg-slate-100/80 px-4 py-3 text-xs text-slate-500">Ma‘lumotlar har 60 soniyada avtomatik yangilanadi. Oxirgi yangilanish: {data ? dt(data.generated_at) : "—"}</div>
      </div>
    </AppShell>
  );
}
