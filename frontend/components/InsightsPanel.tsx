"use client";
import { ReactNode, useCallback, useEffect, useState } from "react";
import {
  Area, AreaChart, Bar, BarChart, CartesianGrid, Cell, Legend, Pie, PieChart, ResponsiveContainer, Tooltip, XAxis, YAxis,
} from "recharts";
import {
  Activity, Banknote, BarChart3, Boxes, ChevronDown, CircleAlert, Clock, FileText, Package, Receipt, Tag,
  TrendingUp, Truck, Users, Wallet, ShoppingCart,
} from "lucide-react";
import { api } from "../app/lib/api";

/**
 * Sahifa tepasidagi umumiy "Statistika" paneli.
 * Backend (/stats/...) { title, period, kpis[], charts[] } qaytaradi — panel uni o‘zi chizadi.
 * Ishlatish:  <InsightsPanel endpoint="/stats/sales" />
 */

type Fmt = "money" | "int" | "pct" | "text";
type Kpi = { title: string; value: string | number; format: Fmt; note?: string | null; tone?: string; icon?: string };
type Row = { label: string; value?: number;[k: string]: string | number | undefined };
type Column = { key: string; label: string; format?: Fmt };
type ChartSpec = {
  type: "area" | "bars" | "donut" | "hbar" | "table"; title: string; hint?: string; format?: Fmt; wide?: boolean; color?: string;
  data: Record<string, string | number>[]; series?: { key: string; name: string; color: string }[]; columns?: Column[];
};
type Spec = { title: string; period: string; kpis: Kpi[]; charts: ChartSpec[] };

const nf = (n: number) => Math.round(n).toLocaleString("ru-RU").replace(/\u00a0/g, " ");
const compact = (v: number) => {
  const a = Math.abs(v);
  if (a >= 1e9) return `${(v / 1e9).toFixed(1)} mlrd`;
  if (a >= 1e6) return `${(v / 1e6).toFixed(1)} mln`;
  if (a >= 1e3) return `${(v / 1e3).toFixed(0)} ming`;
  return String(Math.round(v));
};
const fmt = (v: unknown, f: Fmt = "int") => {
  const n = Number(v ?? 0);
  if (f === "text") return String(v ?? "—");
  if (f === "money") return `${nf(n)} so‘m`;
  if (f === "pct") return `${n.toFixed(1)}%`;
  return nf(n);
};
const axisFmt = (f: Fmt = "int") => (v: number) => (f === "money" ? compact(v) : f === "pct" ? `${v}%` : String(v));

const COLORS = ["#2563eb", "#10b981", "#f59e0b", "#8b5cf6", "#06b6d4", "#f43f5e", "#64748b"];
const AXIS = "#94a3b8";
const GRID = "rgba(148,163,184,0.25)";
const panel = "rounded-2xl border border-slate-200/80 bg-white shadow-sm";
const tints: Record<string, string> = {
  blue: "bg-blue-50 text-blue-700", emerald: "bg-emerald-50 text-emerald-700", amber: "bg-amber-50 text-amber-700", violet: "bg-violet-50 text-violet-700",
  cyan: "bg-cyan-50 text-cyan-700", indigo: "bg-indigo-50 text-indigo-700", rose: "bg-rose-50 text-rose-700", orange: "bg-orange-50 text-orange-700", slate: "bg-slate-100 text-slate-600",
};
const ICONS: Record<string, typeof Activity> = {
  activity: Activity, shopping: ShoppingCart, trend: TrendingUp, wallet: Wallet, alert: CircleAlert, package: Package, users: Users, truck: Truck,
  clock: Clock, file: FileText, banknote: Banknote, boxes: Boxes, receipt: Receipt, tag: Tag,
};

type TipItem = { name?: string; value?: number | string; color?: string };
function Tip({ active, payload, label, f }: { active?: boolean; payload?: TipItem[]; label?: string | number; f: Fmt }) {
  if (!active || !payload?.length) return null;
  return (
    <div className="rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs shadow-lg">
      {label !== undefined && label !== "" && <div className="mb-1 font-semibold text-slate-800">{label}</div>}
      {payload.map((p, i) => (
        <div key={i} className="flex items-center gap-2 text-slate-600">
          <span className="h-2 w-2 rounded-full" style={{ background: p.color }} /><span>{p.name}:</span>
          <span className="font-semibold text-slate-800">{fmt(p.value, f)}</span>
        </div>
      ))}
    </div>
  );
}

const Empty = ({ text = "Hozircha ma‘lumot yo‘q" }: { text?: string }) => (
  <div className="flex h-40 items-center justify-center rounded-xl bg-slate-50 text-sm text-slate-400">{text}</div>
);

function KpiCard({ k }: { k: Kpi }) {
  const Icon = ICONS[k.icon ?? "activity"] ?? Activity;
  return (
    <div className="rounded-2xl border border-slate-200/80 bg-white p-4 shadow-sm">
      <div className="flex items-start justify-between gap-2">
        <div className="min-w-0 text-xs font-medium text-slate-500">{k.title}</div>
        <span className={`flex h-8 w-8 shrink-0 items-center justify-center rounded-xl ${tints[k.tone ?? "blue"] ?? tints.blue}`}><Icon size={16} /></span>
      </div>
      <div className="mt-2 break-words text-lg font-bold tracking-tight text-slate-950 md:text-xl">{fmt(k.value, k.format)}</div>
      {k.note && <div className="mt-0.5 text-xs text-slate-500">{k.note}</div>}
    </div>
  );
}

function ChartBody({ c }: { c: ChartSpec }) {
  const f = c.format ?? "int";
  const color = c.color ?? COLORS[0];
  const data = c.data as Record<string, string | number>[];
  const empty = !data.length || (c.type !== "table" && data.every((r) => Object.entries(r).every(([k, v]) => k === "label" || !Number(v))));
  if (empty) return <Empty />;

  if (c.type === "area") {
    const id = `ga${color.slice(1)}`;
    return (
      <div className="h-56 w-full"><ResponsiveContainer>
        <AreaChart data={data} margin={{ top: 8, right: 8, left: -8, bottom: 0 }}>
          <defs><linearGradient id={id} x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stopColor={color} stopOpacity={0.35} /><stop offset="100%" stopColor={color} stopOpacity={0.02} /></linearGradient></defs>
          <CartesianGrid stroke={GRID} vertical={false} />
          <XAxis dataKey="label" tick={{ fontSize: 11, fill: AXIS }} tickLine={false} axisLine={false} interval="preserveStartEnd" minTickGap={18} />
          <YAxis tick={{ fontSize: 11, fill: AXIS }} tickLine={false} axisLine={false} tickFormatter={axisFmt(f)} width={f === "money" ? 52 : 40} allowDecimals={false} />
          <Tooltip content={<Tip f={f} />} />
          <Area type="monotone" dataKey="value" name={c.title} stroke={color} strokeWidth={2.5} fill={`url(#${id})`} />
        </AreaChart>
      </ResponsiveContainer></div>
    );
  }
  if (c.type === "bars") {
    const series = c.series ?? [{ key: "value", name: c.title, color }];
    return (
      <div className="h-56 w-full"><ResponsiveContainer>
        <BarChart data={data} margin={{ top: 8, right: 8, left: -8, bottom: 0 }}>
          <CartesianGrid stroke={GRID} vertical={false} />
          <XAxis dataKey="label" tick={{ fontSize: 11, fill: AXIS }} tickLine={false} axisLine={false} interval="preserveStartEnd" minTickGap={14} />
          <YAxis tick={{ fontSize: 11, fill: AXIS }} tickLine={false} axisLine={false} tickFormatter={axisFmt(f)} width={f === "money" ? 52 : 40} allowDecimals={false} />
          <Tooltip content={<Tip f={f} />} cursor={{ fill: "rgba(148,163,184,0.12)" }} />
          {series.length > 1 && <Legend iconType="circle" iconSize={8} formatter={(v: string) => <span className="text-xs text-slate-600">{v}</span>} />}
          {series.map((s) => <Bar key={s.key} dataKey={s.key} name={s.name} fill={s.color} radius={[4, 4, 0, 0]} maxBarSize={28} />)}
        </BarChart>
      </ResponsiveContainer></div>
    );
  }
  if (c.type === "donut") {
    const rows = data.filter((r) => Number(r.value) > 0);
    return (
      <div className="h-56 w-full"><ResponsiveContainer>
        <PieChart>
          <Pie data={rows} dataKey="value" nameKey="label" innerRadius={50} outerRadius={80} paddingAngle={2} stroke="none">
            {rows.map((_, i) => <Cell key={i} fill={COLORS[i % COLORS.length]} />)}
          </Pie>
          <Tooltip content={<Tip f={f} />} />
          <Legend iconType="circle" iconSize={8} formatter={(v: string) => <span className="text-xs text-slate-600">{v}</span>} />
        </PieChart>
      </ResponsiveContainer></div>
    );
  }
  if (c.type === "hbar") {
    const rows = data.map((r) => ({ ...r, label: String(r.label).length > 18 ? `${String(r.label).slice(0, 17)}…` : r.label }));
    return (
      <div style={{ height: Math.max(180, rows.length * 38) }} className="w-full"><ResponsiveContainer>
        <BarChart data={rows} layout="vertical" margin={{ top: 0, right: 12, left: 4, bottom: 0 }}>
          <CartesianGrid stroke={GRID} horizontal={false} />
          <XAxis type="number" tick={{ fontSize: 11, fill: AXIS }} tickLine={false} axisLine={false} tickFormatter={axisFmt(f)} allowDecimals={false} />
          <YAxis type="category" dataKey="label" width={104} tick={{ fontSize: 11, fill: "#475569" }} tickLine={false} axisLine={false} />
          <Tooltip content={<Tip f={f} />} cursor={{ fill: "rgba(148,163,184,0.12)" }} />
          <Bar dataKey="value" name={c.title} fill={color} radius={[0, 6, 6, 0]} barSize={16} />
        </BarChart>
      </ResponsiveContainer></div>
    );
  }
  // table
  const cols = c.columns ?? [];
  return (
    <div className="overflow-x-auto">
      <table className="w-full min-w-[320px] text-left text-sm">
        <thead className="text-xs text-slate-500"><tr>{cols.map((col, i) => <th key={col.key} className={`px-2 py-2 font-medium ${i > 0 ? "text-right" : ""}`}>{col.label}</th>)}</tr></thead>
        <tbody className="divide-y divide-slate-100">
          {data.map((r, ri) => (
            <tr key={ri}>{cols.map((col, i) => (
              <td key={col.key} className={`px-2 py-2 ${i === 0 ? "font-medium text-slate-800" : "text-right text-slate-600"}`}>
                {i === 0 && <span className="mr-2 inline-flex h-5 w-5 items-center justify-center rounded-full bg-slate-100 text-[10px] font-semibold text-slate-500">{ri + 1}</span>}
                {col.format ? fmt(r[col.key], col.format) : String(r[col.key] ?? "—")}
              </td>
            ))}</tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function ChartCard({ c }: { c: ChartSpec }) {
  return (
    <div className={`${panel} p-4 ${c.wide ? "lg:col-span-2" : ""}`}>
      <div className="mb-3"><h4 className="font-semibold text-slate-900">{c.title}</h4>{c.hint && <p className="text-xs text-slate-500">{c.hint}</p>}</div>
      <ChartBody c={c} />
    </div>
  );
}

export default function InsightsPanel({ endpoint, extra, defaultOpen = true }: { endpoint: string; extra?: ReactNode; defaultOpen?: boolean }) {
  const [spec, setSpec] = useState<Spec | null>(null);
  const [failed, setFailed] = useState(false);
  const [open, setOpen] = useState(defaultOpen);
  const storeKey = `insights:${endpoint}`;

  useEffect(() => {
    try { const v = localStorage.getItem(storeKey); if (v === "closed") setOpen(false); else if (v === "open") setOpen(true); } catch { /* ixtiyoriy */ }
  }, [storeKey]);

  const load = useCallback(async () => {
    try { setSpec(await api<Spec>(endpoint)); setFailed(false); } catch { setFailed(true); }
  }, [endpoint]);
  useEffect(() => {
    load();
    const onFocus = () => load();
    window.addEventListener("focus", onFocus);
    return () => window.removeEventListener("focus", onFocus);
  }, [load]);

  if (failed && !spec) return null; // ruxsat yo‘q yoki xato: sahifa statistikasiz ishlayveradi
  const toggle = () => setOpen((o) => { const n = !o; try { localStorage.setItem(storeKey, n ? "open" : "closed"); } catch { /* ixtiyoriy */ } return n; });

  return (
    <section className="space-y-3">
      <button type="button" onClick={toggle} aria-expanded={open} className="flex w-full items-center justify-between rounded-xl bg-white px-4 py-2.5 text-left shadow-sm ring-1 ring-slate-200/80 hover:bg-slate-50">
        <span className="flex items-center gap-2 text-sm font-semibold text-slate-800">
          <BarChart3 size={16} className="text-blue-600" />{spec?.title ?? "Statistika"}
          {spec && <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-500">{spec.period}</span>}
        </span>
        <ChevronDown size={16} className={`text-slate-400 transition-transform ${open ? "rotate-180" : ""}`} />
      </button>
      {open && (
        !spec ? (
          <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">{[0, 1, 2, 3].map((i) => <div key={i} className="h-24 animate-pulse rounded-2xl bg-slate-100" />)}</div>
        ) : (
          <div className="space-y-4">
            <div className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">{spec.kpis.map((k, i) => <KpiCard key={i} k={k} />)}</div>
            <div className="grid gap-4 lg:grid-cols-3">{spec.charts.map((c, i) => <ChartCard key={i} c={c} />)}</div>
            {extra}
          </div>
        )
      )}
    </section>
  );
}
