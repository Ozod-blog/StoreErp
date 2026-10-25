"use client";
import { Area, Bar, BarChart, CartesianGrid, ComposedChart, Line, ResponsiveContainer, Tooltip, XAxis, YAxis } from "recharts";
import { useI18n } from "../app/lib/i18n";
import { formatUZS } from "../app/lib/format";

export type TrendRow = { date: string; sales: number; returns: number; net: number };

const AXIS = "#94a3b8";
const GRID = "rgba(148,163,184,0.25)";

function useCompact() {
  const { t } = useI18n();
  return (v: number) => {
    const a = Math.abs(v);
    if (a >= 1_000_000_000) return `${(v / 1_000_000_000).toFixed(1)} ${t("unit.billion")}`;
    if (a >= 1_000_000) return `${(v / 1_000_000).toFixed(1)} ${t("unit.million")}`;
    if (a >= 1000) return `${(v / 1000).toFixed(0)} ${t("unit.thousand")}`;
    return String(Math.round(v));
  };
}

type TipEntry = { name?: string; value?: number | string; color?: string };
function TooltipBox({ active, payload, label }: { active?: boolean; payload?: TipEntry[]; label?: string | number }) {
  if (!active || !payload?.length) return null;
  return (
    <div className="rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs shadow-lg">
      <div className="mb-1 font-semibold text-slate-800">{label}</div>
      {payload.map((p, i) => (
        <div key={i} className="flex items-center gap-2 text-slate-600">
          <span className="h-2 w-2 rounded-full" style={{ background: p.color }} />
          <span>{p.name}:</span><span className="font-semibold text-slate-800">{formatUZS(Number(p.value ?? 0))}</span>
        </div>
      ))}
    </div>
  );
}

/** Savdo dinamikasi: joriy davr (maydon) + qaytarishlar + ixtiyoriy oldingi davr (punktir chiziq, kun tartibi bo‘yicha). */
export function SalesTrendChart({ rows, previous }: { rows: TrendRow[]; previous?: TrendRow[] }) {
  const { t } = useI18n();
  const compact = useCompact();
  const data = rows.map((r, i) => ({
    label: r.date.slice(5),
    sales: r.sales,
    returns: r.returns,
    prev: previous ? previous[i]?.sales : undefined,
  }));
  return (
    <div className="h-64 w-full">
      <ResponsiveContainer width="100%" height="100%">
        <ComposedChart data={data} margin={{ top: 8, right: 8, left: 0, bottom: 0 }}>
          <defs>
            <linearGradient id="salesFill" x1="0" y1="0" x2="0" y2="1">
              <stop offset="0%" stopColor="#2563eb" stopOpacity={0.3} />
              <stop offset="100%" stopColor="#2563eb" stopOpacity={0.02} />
            </linearGradient>
          </defs>
          <CartesianGrid stroke={GRID} strokeDasharray="4 4" vertical={false} />
          <XAxis dataKey="label" tick={{ fill: AXIS, fontSize: 11 }} tickLine={false} axisLine={false} minTickGap={24} />
          <YAxis tick={{ fill: AXIS, fontSize: 11 }} tickLine={false} axisLine={false} width={52} tickFormatter={compact} />
          <Tooltip content={<TooltipBox />} />
          <Area type="monotone" dataKey="sales" name={t("dash.legendSales")} stroke="#2563eb" strokeWidth={2.5} fill="url(#salesFill)" dot={false} activeDot={{ r: 4 }} />
          <Line type="monotone" dataKey="returns" name={t("dash.legendReturns")} stroke="#fb7185" strokeWidth={2} dot={false} />
          {previous && <Line type="monotone" dataKey="prev" name={t("dash.legendPrev")} stroke="#94a3b8" strokeWidth={2} strokeDasharray="5 4" dot={false} connectNulls />}
        </ComposedChart>
      </ResponsiveContainer>
    </div>
  );
}

/** Eng ko‘p sotilgan mahsulotlar — gorizontal ustunlar. */
export function TopProductsChart({ items }: { items: { name: string; total: number }[] }) {
  const compact = useCompact();
  const { t } = useI18n();
  return (
    <div style={{ height: Math.max(120, items.length * 38) }} className="w-full">
      <ResponsiveContainer width="100%" height="100%">
        <BarChart data={items} layout="vertical" margin={{ top: 0, right: 12, left: 0, bottom: 0 }}>
          <CartesianGrid stroke={GRID} strokeDasharray="4 4" horizontal={false} />
          <XAxis type="number" tick={{ fill: AXIS, fontSize: 11 }} tickLine={false} axisLine={false} tickFormatter={compact} />
          <YAxis type="category" dataKey="name" width={110} tick={{ fill: AXIS, fontSize: 11 }} tickLine={false} axisLine={false}
            tickFormatter={(v: string) => (v.length > 16 ? `${v.slice(0, 15)}…` : v)} />
          <Tooltip content={<TooltipBox />} cursor={{ fill: "rgba(148,163,184,0.12)" }} />
          <Bar dataKey="total" name={t("dash.colAmount")} fill="#2563eb" radius={[0, 6, 6, 0]} barSize={18} />
        </BarChart>
      </ResponsiveContainer>
    </div>
  );
}
