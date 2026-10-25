import { AmbilTandaDesimal } from '@/Pustaka/HitungDesimal';
import type { RingkasanAntiFraud } from '@/Tipe/Laporan';

/** Kode widget Ringkasan cepat di Beranda (D-203), urut tampil. */
export type KodeWidget = 'metode' | 'pesanan' | 'kategori' | 'antifraud' | 'terlaris' | 'komisi' | 'kasir' | 'stok';

export const DAFTAR_WIDGET: readonly { Kode: KodeWidget; Judul: string }[] = [
    { Kode: 'metode', Judul: 'Metode pembayaran' },
    { Kode: 'pesanan', Judul: 'Jenis pesanan' },
    { Kode: 'kategori', Judul: 'Penjualan per kategori' },
    { Kode: 'antifraud', Judul: 'Kontrol anti-fraud' },
    { Kode: 'terlaris', Judul: 'Produk terlaris' },
    { Kode: 'komisi', Judul: 'Komisi staf' },
    { Kode: 'kasir', Judul: 'Penjualan per kasir' },
    { Kode: 'stok', Judul: 'Stok kritis' },
];

const KUNCI_PENYIMPANAN = 'payoung.beranda.widget-tersembunyi';

/**
 * Widget yang disembunyikan pengguna di peramban ini. Hanya kemudahan per perangkat: bila penyimpanan tidak bisa
 * dibaca (jendela pribadi, data situs diblokir) semua widget tampil.
 */
export function BacaWidgetTersembunyi(): KodeWidget[] {
    try {
        const mentah = window.localStorage.getItem(KUNCI_PENYIMPANAN);
        const nilai: unknown = mentah === null ? [] : JSON.parse(mentah);

        return Array.isArray(nilai) ? DAFTAR_WIDGET.map((w) => w.Kode).filter((kode) => nilai.includes(kode)) : [];
    } catch {
        return [];
    }
}

export function SimpanWidgetTersembunyi(tersembunyi: readonly KodeWidget[]): void {
    try {
        window.localStorage.setItem(KUNCI_PENYIMPANAN, JSON.stringify(tersembunyi));
    } catch {
        // Penyimpanan tak tersedia: pilihan hanya berlaku selama halaman terbuka.
    }
}

/** String desimal server ("1234.50", "-3.0000") ke bilangan bulat berskala 4 (BigInt; tanpa float). Tak sah = 0. */
function KeSkala(nilai: string): bigint {
    const cocok = /^(-?)(\d+)(?:\.(\d+))?$/.exec(nilai.trim());

    if (cocok === null) {
        return 0n;
    }

    const mutlak = BigInt(`${cocok[2] ?? '0'}${(cocok[3] ?? '').padEnd(4, '0').slice(0, 4)}`);

    return cocok[1] === '-' ? -mutlak : mutlak;
}

/** Lebar batang 0–100 untuk `nilai` terhadap `terbesar`; nilai nol atau negatif memberi 0, selain itu minimal 2 supaya terlihat. */
export function HitungLebarBatang(nilai: string, terbesar: string): number {
    const n = KeSkala(nilai);
    const maks = KeSkala(terbesar);

    if (maks <= 0n || n <= 0n) {
        return 0;
    }

    return Math.min(100, Math.max(2, Number((n * 100n + maks / 2n) / maks)));
}

/** Persen bulat `nilai` dari `total` (0 bila total nol atau negatif). */
export function HitungPersenBagian(nilai: string, total: string): number {
    const t = KeSkala(total);

    return t > 0n ? Number((KeSkala(nilai) * 100n + t / 2n) / t) : 0;
}

/** Kata status anti-fraud untuk pembaca; warna hanya pelengkap. */
export function LabelTingkatAntiFraud(tingkat: RingkasanAntiFraud['Tingkat']): string {
    return tingkat === 'Tinggi' ? 'Perlu diperiksa' : tingkat === 'Sedang' ? 'Waspada' : 'Aman';
}

/** Status stok menurut saldo: minus lebih parah daripada sekadar di bawah minimum. */
export function JenisStatusStok(saldo: string): 'bahaya' | 'peringatan' {
    return AmbilTandaDesimal(saldo) < 0 ? 'bahaya' : 'peringatan';
}
