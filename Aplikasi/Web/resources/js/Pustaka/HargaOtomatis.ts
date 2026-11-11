/** Teks bersama untuk fitur harga otomatis dan "Harga dari harga beli" (D-266). */

/** Label pembulatan sama dengan dialog harga massal supaya pemilik toko tidak menemukan dua cara menyebut hal yang sama. */
export function LabelPembulatan(nilai: number): string {
    return nilai === 0 ? 'Tanpa pembulatan' : `Dibulatkan ke Rp ${nilai.toLocaleString('id-ID')} terdekat`;
}

/** Persen tanpa ekor nol untuk tampilan: "20.0000" menjadi "20", "7.5000" menjadi "7,5". */
export function RapikanPersen(nilai: string): string {
    const bersih = nilai.includes('.') ? nilai.replace(/\.?0+$/, '') : nilai;

    return bersih.replace('.', ',');
}

/** Persen tanpa ekor nol untuk isian (titik desimal, dikirim ke server apa adanya): "20.0000" menjadi "20". */
export function PersenIsian(nilai: string): string {
    return nilai.includes('.') ? nilai.replace(/\.?0+$/, '') : nilai;
}

/** Label pembulatan pendek untuk pilihan di formulir: "Tidak dibulatkan" atau "Rp 500 terdekat". */
export function LabelPembulatanPendek(nilai: number): string {
    return nilai === 0 ? 'Tidak dibulatkan' : `Rp ${nilai.toLocaleString('id-ID')} terdekat`;
}

/** Persen bertanda untuk perubahan harga: "+9,1%" atau "-12,5%". */
export function FormatPerubahanPersen(nilai: string): string {
    const angka = RapikanPersen(nilai);

    return nilai.startsWith('-') ? `${angka}%` : `+${angka}%`;
}

/** Persen isian ("20", "7.5") menjadi bilangan bulat seperseratus persen (750 untuk 7,5%), null bila bukan angka sah. */
function PersenSeperseratus(nilai: string): number | null {
    const cocok = /^(\d{1,4})(?:[.,](\d{1,2}))?$/.exec(nilai.trim());

    if (cocok === null) {
        return null;
    }

    return Number(cocok[1]) * 100 + Number((cocok[2] ?? '').padEnd(2, '0'));
}

/** Apakah isian persen sama nilainya dengan salah satu pilihan cepat ("20" dan "20.00" sama-sama 20). */
export function SamaDenganPersen(nilai: string, pilihan: number): boolean {
    return PersenSeperseratus(nilai) === pilihan * 100;
}

/**
 * Contoh hitungan untuk layar: modal Rp 10.000 ditambah untung, lalu dibulatkan. Bilangan bulat murni (modal 10.000
 * dikali (1 + persen/100) sama dengan 10.000 ditambah persen × 100), tanpa desimal. Dibulatkan ke kelipatan terdekat
 * dengan setengah naik, sama dengan server. Null bila untung belum berupa angka sah.
 */
export function HitungContohHarga(persen: string, pembulatan: number): { Modal: string; Harga: string } | null {
    const seperseratus = PersenSeperseratus(persen);

    if (seperseratus === null) {
        return null;
    }

    const mentah = 10000 + seperseratus;
    const harga = pembulatan > 0 ? Math.round(mentah / pembulatan) * pembulatan : mentah;

    return { Modal: '10000', Harga: String(harga) };
}
