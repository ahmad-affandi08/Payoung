import {
    AmbilTandaDesimal,
    BandingkanDesimal,
    CekDesimalValid,
    JumlahkanDesimal,
    KalikanDesimal,
    KurangiDesimal,
    SkalaUang,
} from '@/Pustaka/HitungDesimal';

/*
 * Pratinjau angka di formulir invoice (F-21). **Hanya subtotal baris dan jumlahnya**: pajak, PPh, dan potongan uang muka
 * bergantung pada tarif bertanggal berlaku dan pembulatan per dokumen yang hanya dimiliki server (aturan emas #12),
 * jadi formulir tidak menebaknya. Angka resmi muncul di halaman invoice setelah draf disimpan.
 */

type BarisHitung = { Jumlah: string; Harga: string; Diskon: string };

/** Jumlah × harga − diskon, dibulatkan 2 desimal; null bila jumlah/harga belum valid atau diskon melebihi nilai baris. */
export function HitungSubtotalBaris(baris: BarisHitung): string | null {
    if (!CekDesimalValid(baris.Jumlah) || !CekDesimalValid(baris.Harga)) {
        return null;
    }

    const bruto = KalikanDesimal(baris.Jumlah, baris.Harga, SkalaUang);
    const diskon = baris.Diskon !== '' && CekDesimalValid(baris.Diskon) ? baris.Diskon : '0';

    if (BandingkanDesimal(diskon, bruto) > 0 || AmbilTandaDesimal(bruto) < 0) {
        return null;
    }

    return KurangiDesimal(bruto, diskon);
}

/** Jumlah subtotal baris yang valid; baris yang belum lengkap dilewati. */
export function HitungSubtotalInvoice(baris: readonly BarisHitung[]): string {
    return JumlahkanDesimal([
        '0.00',
        ...baris.map((b) => HitungSubtotalBaris(b)).filter((n): n is string => n !== null),
    ]);
}
