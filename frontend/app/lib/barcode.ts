/**
 * Bog'liqliksiz shtrix-kod kodlovchi: EAN-13 va Code 128 (B to'plami).
 * Natija — "1" (qora) va "0" (oq) modullar qatori; SVG chizish BarcodeSvg komponentida.
 */

const L = ["0001101", "0011001", "0010011", "0111101", "0100011", "0110001", "0101111", "0111011", "0110111", "0001011"];
const R = L.map((p) => p.split("").map((c) => (c === "1" ? "0" : "1")).join(""));
const G = R.map((p) => p.split("").reverse().join("")); // G = R ning teskarisi (L ning emas)
// 1-raqamga qarab chap 6 raqamning L/G tartibi
const PARITY = ["LLLLLL", "LLGLGG", "LLGGLG", "LLGGGL", "LGLLGG", "LGGLLG", "LGGGLL", "LGLGLG", "LGLGGL", "LGGLGL"];

export function ean13Check(base12: string): number {
    let sum = 0;
    for (let i = 0; i < 12; i++) sum += Number(base12[i]) * (i % 2 === 0 ? 1 : 3);
    return (10 - (sum % 10)) % 10;
}

export function isValidEan13(code: string): boolean {
    return /^\d{13}$/.test(code) && ean13Check(code.slice(0, 12)) === Number(code[12]);
}

export function encodeEan13(code: string): string {
    if (!isValidEan13(code)) throw new Error("EAN-13 kodi noto‘g‘ri");
    const parity = PARITY[Number(code[0])];
    let out = "101";
    for (let i = 0; i < 6; i++) out += (parity[i] === "L" ? L : G)[Number(code[i + 1])];
    out += "01010";
    for (let i = 0; i < 6; i++) out += R[Number(code[i + 7])];
    return out + "101";
}

// Code 128: har simvol uchun bar/bo'shliq kengliklari (stop — 7 ta, qolganlari 6 ta)
const C128 = [
    "212222", "222122", "222221", "121223", "121322", "131222", "122213", "122312", "132212", "221213",
    "221312", "231212", "112232", "122132", "122231", "113222", "123122", "123221", "223211", "221132",
    "221231", "213212", "223112", "312131", "311222", "321122", "321221", "312212", "322112", "322211",
    "212123", "212321", "232121", "111323", "131123", "131321", "112313", "132113", "132311", "211313",
    "231113", "231311", "112133", "112331", "132131", "113123", "113321", "133121", "313121", "211331",
    "231131", "213113", "213311", "213131", "311123", "311321", "331121", "312113", "312311", "332111",
    "314111", "221411", "431111", "111224", "111422", "121124", "121421", "141122", "141221", "112214",
    "112412", "122114", "122411", "142112", "142211", "241211", "221114", "413111", "241112", "134111",
    "111242", "121142", "121241", "114212", "124112", "124211", "411212", "421112", "421211", "212141",
    "214121", "412121", "111143", "111341", "131141", "114113", "114311", "411113", "411311", "113141",
    "114131", "311141", "411131", "211412", "211214", "211232", "2331112",
];
const START_B = 104;
const STOP = 106;

export function canEncode128B(text: string): boolean {
    return text.length > 0 && /^[\x20-\x7e]+$/.test(text);
}

export function encodeCode128B(text: string): string {
    if (!canEncode128B(text)) throw new Error("Code 128 faqat ASCII belgilarni qo‘llaydi");
    const values = [START_B, ...text.split("").map((c) => c.charCodeAt(0) - 32)];
    let checksum = values[0];
    for (let i = 1; i < values.length; i++) checksum += values[i] * i;
    values.push(checksum % 103, STOP);

    let out = "";
    for (const v of values) {
        let bar = true;
        for (const w of C128[v]) {
            out += (bar ? "1" : "0").repeat(Number(w));
            bar = !bar;
        }
    }
    return out;
}

/** Qiymatga mos turini tanlaydi: to'g'ri EAN-13 → EAN-13, aks holda Code 128. Kodlab bo'lmasa null. */
export function encodeAuto(value: string): { modules: string; kind: "ean13" | "code128" } | null {
    if (isValidEan13(value)) return { modules: encodeEan13(value), kind: "ean13" };
    if (canEncode128B(value)) return { modules: encodeCode128B(value), kind: "code128" };
    return null;
}
