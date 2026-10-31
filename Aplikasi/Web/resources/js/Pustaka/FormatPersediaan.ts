/**
 * Format tampilan persediaan F-05a (PRD §17.6.7, DesainF05a E). Masukan selalu string desimal dari server;
 * pemisah disisipkan pada teks, tanpa number/float (CLAUDE.md #7).
 */
import { FormatRupiah } from '@/Pustaka/Format';
import { BulatkanDesimal, CekDesimalValid } from '@/Pustaka/HitungDesimal';
import type { MetodeHpp, OpsiGudang, PelacakanProduk, StatusNomorSeri, StatusStokAwal } from '@/Tipe/Persediaan';

const polaDesimal = /^(-?)(\d+)(?:\.(\d+))?$/;

function SisipkanRibuan(bulat: string): string {
    return bulat.replace(/^0+(?=\d)/, '').replace(/\B(?=(\d{3})+(?!\d))/g, '.');
}

/**
 * Kuantitas dengan satuan: ("1250.5000", "kg") → "1.250,5 kg"; ("-4.0000", "pcs") → "−4 pcs"; ("0.0000") → "0".
 * Nol di belakang koma dibuang; tanda minus memakai "−" (U+2212) agar terbaca jelas.
 */
export function FormatJumlahStok(nilai: string, simbol = ''): string {
    const cocok = polaDesimal.exec(nilai.trim());

    if (!cocok) {
        throw new Error(`Jumlah tidak valid: "${nilai}"`);
    }

    const [, tanda = '', bulat = '0', pecahan = ''] = cocok;
    const bulatBersih = SisipkanRibuan(bulat);
    const pecahanRingkas = pecahan.replace(/0+$/, '');
    const negatif = tanda === '-' && (bulatBersih !== '0' || pecahanRingkas !== '');
    const angka = `${negatif ? '−' : ''}${bulatBersih}${pecahanRingkas === '' ? '' : `,${pecahanRingkas}`}`;

    return `${angka} ${simbol}`.trim();
}

/**
 * HPP per satuan (6 desimal di server) ditampilkan sebagai Rupiah 2 desimal, dibulatkan HalfUp: "3636.545455" →
 * "Rp 3.636,55"; "1234.568000" → "Rp 1.234,57"; "1000.000000" → "Rp 1.000"; "1234.500000" → "Rp 1.234,50".
 * Presisi penuh tetap di server dan di isian HPP; hanya tampilan yang dibulatkan. Null = HPP belum diketahui → "—".
 */
export function FormatHppSatuan(nilai: string | null): string {
    if (nilai === null) {
        return '—';
    }

    if (!CekDesimalValid(nilai)) {
        throw new Error(`HPP tidak valid: "${nilai}"`);
    }

    return FormatRupiah(BulatkanDesimal(nilai, 2));
}

/** Nilai uang persediaan/mutasi (skala 2, boleh negatif): "-3703.70" → "−Rp 3.703,70". */
export function FormatNilai(nilai: string): string {
    if (!CekDesimalValid(nilai)) {
        throw new Error(`Nilai tidak valid: "${nilai}"`);
    }

    return FormatRupiah(BulatkanDesimal(nilai, 2));
}

type JenisLabel = 'sukses' | 'peringatan' | 'bahaya' | 'netral';

/** Warna label status dokumen stok awal; teks label tetap dari server (`LabelStatus`). */
export function AmbilJenisLabelStatusStokAwal(status: StatusStokAwal): JenisLabel {
    const peta: Record<StatusStokAwal, JenisLabel> = {
        Draf: 'netral',
        Memproses: 'peringatan',
        Diposting: 'sukses',
        Dibatalkan: 'bahaya',
        Dibuang: 'netral',
    };

    return peta[status];
}

/** Warna label status nomor seri; teks label tetap dari server (`LabelStatus`). Tersedia = sukses, dalam perjalanan = perlu perhatian. */
export function AmbilJenisLabelStatusNomorSeri(status: StatusNomorSeri): JenisLabel {
    const peta: Record<StatusNomorSeri, JenisLabel> = {
        Tersedia: 'sukses',
        DalamPerjalanan: 'peringatan',
        Terjual: 'netral',
        Keluar: 'netral',
    };

    return peta[status];
}

/** Label pelacakan produk untuk tabel: Batch → "Batch & kedaluwarsa", Seri → "Nomor seri", Tidak → null. */
export function AmbilLabelPelacakan(pelacakan: PelacakanProduk): string | null {
    if (pelacakan === 'Batch') {
        return 'Batch & kedaluwarsa';
    }

    return pelacakan === 'Seri' ? 'Nomor seri' : null;
}

/** Nama metode HPP untuk ringkasan (label lengkap dan keterangan tetap dari server di halaman pengaturan). */
export function AmbilLabelMetodeHpp(metode: MetodeHpp): string {
    return metode === 'Fifo' ? 'FIFO (masuk pertama, keluar pertama)' : 'Rata-rata bergerak';
}

/** Tautan kartu stok satu produk di satu lokasi (DesainF05a D: `?produk=&gudang=&dari=&sampai=`). */
export function BuatUrlKartuStok(uuidProduk: string, uuidGudang: string, dari?: string, sampai?: string): string {
    const parameter = new URLSearchParams({ produk: uuidProduk, gudang: uuidGudang });

    if (dari) {
        parameter.set('dari', dari);
    }

    if (sampai) {
        parameter.set('sampai', sampai);
    }

    return `/kelola/persediaan/kartu-stok?${parameter.toString()}`;
}

/** Label opsi lokasi stok: "Gudang Utama | Outlet Solo"; ditandai bila lokasi diarsipkan. */
export function FormatLabelGudang(gudang: Pick<OpsiGudang, 'Nama' | 'NamaOutlet' | 'Aktif'>): string {
    const nama = gudang.NamaOutlet ? `${gudang.Nama} | ${gudang.NamaOutlet}` : gudang.Nama;

    return gudang.Aktif ? nama : `${nama} (diarsipkan)`;
}
