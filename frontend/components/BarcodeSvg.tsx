"use client";
import { useMemo } from "react";
import { encodeAuto } from "../app/lib/barcode";

const QUIET = 10; // modul: standart bo'yicha chap/o'ng bo'sh zona (EAN-13 uchun 11/7, bu yerda xavfsiz o'rtacha)

/**
 * Vektorli shtrix-kod. Kenglikni konteynerga moslaydi (preserveAspectRatio="none"),
 * balandligi `height` bilan beriladi. Kodlab bo'lmasa (masalan, kirill harf) hech narsa chizmaydi.
 */
export default function BarcodeSvg({ value, height = 40, className = "" }: { value: string; height?: number | string; className?: string }) {
    const enc = useMemo(() => (value ? encodeAuto(value) : null), [value]);
    if (!enc) return null;

    // Ketma-ket "1" lar bitta to'rtburchak bo'lib birlashtiriladi (kichik SVG, aniq chekkalar)
    let d = "";
    const m = enc.modules;
    for (let i = 0; i < m.length; ) {
        if (m[i] === "1") {
            let j = i;
            while (j < m.length && m[j] === "1") j++;
            d += `M${i + QUIET} 0h${j - i}v1h-${j - i}z`;
            i = j;
        } else i++;
    }
    const total = m.length + QUIET * 2;

    return (
        <svg
            viewBox={`0 0 ${total} 1`}
            preserveAspectRatio="none"
            shapeRendering="crispEdges"
            width="100%"
            height={height}
            className={className}
            role="img"
            aria-label={`Shtrix-kod ${value}`}
        >
            <path d={d} fill="#000" />
        </svg>
    );
}
