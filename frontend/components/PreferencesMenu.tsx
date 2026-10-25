"use client";
import { useEffect, useRef, useState } from "react";
import { Monitor, Moon, Rows3, Rows4, Settings2, Sun } from "lucide-react";
import { Density, Theme, useTheme } from "../app/lib/theme";
const seg = (active: boolean) => `flex flex-1 items-center justify-center gap-1.5 rounded-lg border px-2.5 py-2 text-xs font-medium transition ${active ? "border-blue-600 bg-blue-600 text-white" : "border-slate-200 bg-white text-slate-700 hover:bg-slate-50"}`;
/** Faqat mavzu va jadval zichligi. Til almashtirish funksiyasi olib tashlangan; interfeys o‘zbekcha. */
export default function PreferencesMenu({ align = "right" }: { align?: "right" | "left" }) {
  const { theme, setTheme, density, setDensity } = useTheme();
  const [open, setOpen] = useState(false); const ref = useRef<HTMLDivElement>(null);
  useEffect(() => { if (!open) return; const down = (e: MouseEvent) => { if (!ref.current?.contains(e.target as Node)) setOpen(false); }; const key = (e: KeyboardEvent) => e.key === "Escape" && setOpen(false); document.addEventListener("mousedown", down); window.addEventListener("keydown", key); return () => { document.removeEventListener("mousedown", down); window.removeEventListener("keydown", key); }; }, [open]);
  const themes: { value: Theme; icon: typeof Sun; label: string }[] = [{value:"light",icon:Sun,label:"Yorug‘"},{value:"dark",icon:Moon,label:"Qorong‘i"},{value:"system",icon:Monitor,label:"Tizim"}];
  const densities: { value: Density; icon: typeof Rows3; label: string }[] = [{value:"comfortable",icon:Rows3,label:"Oddiy"},{value:"compact",icon:Rows4,label:"Ixcham"}];
  return <div ref={ref} className="relative"><button onClick={() => setOpen(v=>!v)} aria-label="Ko‘rinish sozlamalari" aria-expanded={open} className="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-2.5 py-2 text-sm text-slate-700 hover:bg-slate-50"><Settings2 size={16}/></button>{open && <div className={`absolute z-50 mt-2 w-72 space-y-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-xl ${align === "right" ? "right-0" : "left-0"}`}><div className="text-sm font-semibold text-slate-900">Ko‘rinish sozlamalari</div><div><div className="mb-1.5 text-xs font-medium text-slate-500">Mavzu</div><div className="flex gap-1.5">{themes.map(({value,icon:Icon,label})=><button key={value} onClick={()=>setTheme(value)} className={seg(theme===value)}><Icon size={14}/>{label}</button>)}</div></div><div><div className="mb-1.5 text-xs font-medium text-slate-500">Jadval zichligi</div><div className="flex gap-1.5">{densities.map(({value,icon:Icon,label})=><button key={value} onClick={()=>setDensity(value)} className={seg(density===value)}><Icon size={14}/>{label}</button>)}</div></div></div>}</div>;
}
