// Shtrix-kod kodlagichini mustaqil mos yozuv (reportlab) qiymatlariga qarshi tekshiradi.
// Ishga tushirish (Node 22.6+):  node --experimental-strip-types scripts/check-barcode.mts
// Nima uchun: G jadvali (R ning teskarisi) va Code 128 ning 2-belgisi (222221) bir marta xato yozilgan edi;
// bu tekshiruv shunday xatolar qaytmasligini ta'minlaydi.
import { encodeEan13, encodeCode128B, isValidEan13, ean13Check } from "../app/lib/barcode.ts";

const EAN: Record<string, string> = {
    "4006381333931": "10100011010100111010111101111010001001011001101010100001010000101000010111010010000101100110101",
    "5901234123457": "10100010110100111011001100100110111101001110101010110011011011001000010101110010011101000100101",
    "2000000000428": "10100011010001101010011101001110001101010011101010111001011100101110010101110011011001001000101",
    "9780201379624": "10101110110001001010011100100110100111001100101010100001010001001110100101000011011001011100101"
};
const C128: Record<string, string> = {
    "SUT-1": "110100100001101110100011011101110110111000101001101110010011100110110100010001100011101011",
    "Hello World 123": "11010010000110001010001011001000011001010000110010100001000111101011011001100111010001101000111101010010011110110010100001000010011011011001100100111001101100111001011001011100101001111001100011101011",
    "A1": "110100100001010001100010011100110100001001101100011101011",
    "~ !\"#abc": "110100100001000101111011011001100110011011001100110011010010011000100101100001001000011010000101100100001011001100011101011"
};

let bad = 0;
for (const [code, expected] of Object.entries(EAN)) {
    const ok = encodeEan13(code) === expected;
    if (!ok) { bad++; console.error("EAN-13 mos emas:", code); }
}
for (const [value, expected] of Object.entries(C128)) {
    const ok = encodeCode128B(value) === expected;
    if (!ok) { bad++; console.error("Code 128 mos emas:", value); }
}
if (!isValidEan13("4006381333931") || isValidEan13("4006381333932") || ean13Check("400638133393") !== 1) { bad++; console.error("EAN-13 nazorat raqami xato"); }
if (bad) { console.error(bad + " ta xato"); process.exit(1); }
console.log("barcode: hammasi mos (" + Object.keys(EAN).length + " EAN-13, " + Object.keys(C128).length + " Code 128)");
