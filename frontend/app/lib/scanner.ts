"use client";
import { useEffect, useRef } from "react";

/**
 * Klaviatura-emulyatsiya qiluvchi (HID) shtrix-kod skanerini ushlaydi.
 * Skaner belgilarni juda tez (odatda <30 ms oraliqda) yuboradi va Enter bilan tugatadi,
 * odam esa sekinroq yozadi — shu farq bo'yicha ajratiladi.
 *
 * Faqat fokus matn maydonida BO'LMAGANDA ishlaydi: maydon ichida skanerlash o'sha maydonning
 * o'z Enter ishlovchisi (masalan, POS qidiruvi) orqali bajariladi, bu yerda ikkilanib qolmaslik uchun.
 */
export const SCAN_MAX_GAP_MS = 60; // belgilar orasidagi eng katta oraliq
export const SCAN_MIN_LENGTH = 4;

export function useScanner(onScan: (code: string) => void, enabled = true) {
    const cb = useRef(onScan);
    useEffect(() => { cb.current = onScan; });

    useEffect(() => {
        if (!enabled) return;
        let buf = "";
        let last = 0;

        function isTyping(t: EventTarget | null): boolean {
            const el = t as HTMLElement | null;
            if (!el || !el.tagName) return false;
            const tag = el.tagName;
            return tag === "INPUT" || tag === "TEXTAREA" || tag === "SELECT" || el.isContentEditable;
        }

        function onKey(e: globalThis.KeyboardEvent) {
            if (e.ctrlKey || e.metaKey || e.altKey || isTyping(e.target)) { buf = ""; return; }
            const now = e.timeStamp || performance.now();
            if (e.key === "Enter") {
                const code = buf;
                buf = "";
                if (code.length >= SCAN_MIN_LENGTH) {
                    e.preventDefault();
                    cb.current(code);
                }
                return;
            }
            if (e.key.length !== 1) return; // Shift, F-tugmalar va h.k.
            if (now - last > SCAN_MAX_GAP_MS) buf = ""; // oraliq katta — bu odam yozishi, yangidan boshlaymiz
            buf += e.key;
            last = now;
        }

        window.addEventListener("keydown", onKey);
        return () => window.removeEventListener("keydown", onKey);
    }, [enabled]);
}

/** Qisqa signal: muvaffaqiyat — baland, xato — past va uzunroq. Audio bloklangan bo'lsa jim o'tadi. */
export function beep(ok: boolean) {
    try {
        const Ctx = window.AudioContext ?? (window as unknown as { webkitAudioContext?: typeof AudioContext }).webkitAudioContext;
        if (!Ctx) return;
        const ctx = new Ctx();
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();
        osc.frequency.value = ok ? 1100 : 300;
        gain.gain.value = 0.05;
        osc.connect(gain);
        gain.connect(ctx.destination);
        osc.start();
        osc.stop(ctx.currentTime + (ok ? 0.08 : 0.25));
        osc.onended = () => { void ctx.close(); };
    } catch {
        /* ovoz ixtiyoriy */
    }
}
