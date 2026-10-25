"use client";
import { createContext, ReactNode, useCallback, useContext, useEffect, useMemo, useState } from "react";

export type Theme = "light" | "dark" | "system";
export type Density = "comfortable" | "compact";
const THEME_KEY = "store_erp_theme";
const DENSITY_KEY = "store_erp_density";

type ThemeValue = {
  theme: Theme; resolved: "light" | "dark"; density: Density;
  setTheme: (t: Theme) => void; setDensity: (d: Density) => void;
};
const ThemeContext = createContext<ThemeValue | null>(null);

function resolve(theme: Theme): "light" | "dark" {
  if (theme !== "system") return theme;
  return typeof window !== "undefined" && window.matchMedia("(prefers-color-scheme: dark)").matches ? "dark" : "light";
}

export function ThemeProvider({ children }: { children: ReactNode }) {
  const [theme, setThemeState] = useState<Theme>("system");
  const [density, setDensityState] = useState<Density>("comfortable");
  const [resolved, setResolved] = useState<"light" | "dark">("light");

  useEffect(() => {
    const t = (localStorage.getItem(THEME_KEY) as Theme | null) ?? "system";
    const d = (localStorage.getItem(DENSITY_KEY) as Density | null) ?? "comfortable";
    setThemeState(t === "light" || t === "dark" ? t : "system");
    setDensityState(d === "compact" ? "compact" : "comfortable");
  }, []);

  useEffect(() => {
    const apply = () => {
      const r = resolve(theme);
      setResolved(r);
      document.documentElement.classList.toggle("dark", r === "dark");
    };
    apply();
    if (theme !== "system") return;
    const mq = window.matchMedia("(prefers-color-scheme: dark)");
    mq.addEventListener("change", apply);
    return () => mq.removeEventListener("change", apply);
  }, [theme]);

  useEffect(() => { document.documentElement.dataset.density = density; }, [density]);

  const setTheme = useCallback((t: Theme) => { localStorage.setItem(THEME_KEY, t); setThemeState(t); }, []);
  const setDensity = useCallback((d: Density) => { localStorage.setItem(DENSITY_KEY, d); setDensityState(d); }, []);
  const value = useMemo(() => ({ theme, resolved, density, setTheme, setDensity }), [theme, resolved, density, setTheme, setDensity]);
  return <ThemeContext.Provider value={value}>{children}</ThemeContext.Provider>;
}

export function useTheme(): ThemeValue {
  const ctx = useContext(ThemeContext);
  if (!ctx) throw new Error("useTheme faqat ThemeProvider ichida ishlatiladi");
  return ctx;
}

/** Sahifa chizilishidan oldin tema/zichlikni qo‘llaydi (oq-qora miltillashning oldini oladi). */
export const THEME_INIT_SCRIPT = `(function(){try{var t=localStorage.getItem("${THEME_KEY}")||"system";var d=localStorage.getItem("${DENSITY_KEY}")||"comfortable";var dark=t==="dark"||(t==="system"&&window.matchMedia("(prefers-color-scheme: dark)").matches);if(dark)document.documentElement.classList.add("dark");document.documentElement.dataset.density=d;}catch(e){}})();`;
