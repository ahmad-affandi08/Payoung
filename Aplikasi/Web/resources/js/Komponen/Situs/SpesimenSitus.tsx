import type { ReactNode } from 'react';

import { cn } from '@/Komponen/Ui/utils';
import type { NamaSpesimen } from '@/Tipe/Situs';
import gambarKasirJual from '@/Aset/Situs/KasirJual.webp';
import gambarKasirBayar from '@/Aset/Situs/KasirBayar.webp';
import gambarKasirBerhasil from '@/Aset/Situs/KasirBerhasil.webp';
import gambarKasirShift from '@/Aset/Situs/KasirShift.webp';
import gambarKasirRiwayat from '@/Aset/Situs/KasirRiwayat.webp';
import gambarKasirKas from '@/Aset/Situs/KasirKas.webp';
import gambarKasirSektorKafe from '@/Aset/Situs/KasirSektorKafe.webp';
import gambarKasirSektorToko from '@/Aset/Situs/KasirSektorToko.webp';
import gambarKasirSektorSalon from '@/Aset/Situs/KasirSektorSalon.webp';
import gambarKasirSektorLaundry from '@/Aset/Situs/KasirSektorLaundry.webp';
import gambarKasirSektorApotek from '@/Aset/Situs/KasirSektorApotek.webp';
import gambarKasirSektorBengkel from '@/Aset/Situs/KasirSektorBengkel.webp';
import gambarKasirHpJual from '@/Aset/Situs/KasirHpJual.webp';
import gambarPemilikBeranda from '@/Aset/Situs/PemilikBeranda.webp';
import gambarPemilikLaporan from '@/Aset/Situs/PemilikLaporan.webp';
import gambarPemilikPersetujuan from '@/Aset/Situs/PemilikPersetujuan.webp';
import gambarPemilikKaryawan from '@/Aset/Situs/PemilikKaryawan.webp';
import gambarPemilikInsight from '@/Aset/Situs/PemilikInsight.webp';

/**
 * Spesimen keluaran Payoung untuk situs pemasaran (D-25, D-39): tangkapan layar asli aplikasi Kasir & Pemilik
 * (berkas di `resources/js/Aset/Situs`, ikut build Vite supaya selalu terkirim bersama aset lain), serta struk dan jurnal yang benar-benar
 * dihasilkan produk. Dipakai sebagai visual hero dan blok gambar-teks bila konsol belum mengunggah gambar.
 *
 * Kenapa bukan ilustrasi:
 * - §17.6.4 melarang ilustrasi dekoratif, dan ini bukan hiasan melainkan **contoh keluaran produk**;
 * - gambar unggahan konsol tidak bisa dipakai isi bawaan (`GambarSitus` dirujuk lewat Uuid), maka tangkapan layar
 *   aplikasi disimpan sebagai berkas statis dan dipilih lewat nama spesimen;
 * - struk & jurnal murni tipografi; tangkapan layar dibingkai tepi gelap tanpa bayangan maupun gradien (§17.6.11).
 *
 * Angkanya contoh tetap (bukan klaim tentang usaha siapa pun) dan memakai font Mono + angka tabular seperti
 * struk sungguhan. Pembaca layar menerima satu kalimat lewat `role="img"`, bukan deretan angka.
 */

type PropsSpesimen = { className?: string | undefined };

export type { NamaSpesimen };

const BARIS_STRUK = [
    { Nama: 'Kopi susu gula aren', Qty: '2', Harga: '36.000' },
    { Nama: 'Croissant butter', Qty: '1', Harga: '28.000' },
    { Nama: 'Air mineral 600ml', Qty: '1', Harga: '6.000' },
] as const;

/**
 * Struk termal: kop outlet, baris penjualan, PPN, total, dan penanda bahwa transaksi ini dibuat saat offline
 * lalu menunggu terkirim — pembeda utama Payoung, ditandai dengan `Aksen` berteks `TeksUtama` (6,4:1).
 */
export function SpesimenStruk({ className }: PropsSpesimen) {
    return (
        <div
            role="img"
            aria-label="Contoh struk Payoung: tiga baris penjualan, PPN, total Rp 77.000, dan penanda transaksi dibuat saat offline yang menunggu terkirim."
            className={cn(
                'text-isi w-full max-w-sm rounded-panel border border-garis bg-permukaan p-6 font-mono text-teks-utama tabular-nums',
                className,
            )}
        >
            <div className="flex flex-col items-center gap-0.5 border-b border-dashed border-garis pb-4 text-center">
                <p className="font-semibold">KOPI SENJA</p>
                <p className="text-keterangan text-teks-sekunder">Jl. Cendana 12, Yogyakarta</p>
                <p className="text-keterangan text-teks-sekunder">PJL/20260927/KSR1/0043</p>
            </div>
            <ul className="flex flex-col gap-2 border-b border-dashed border-garis py-4">
                {BARIS_STRUK.map((b) => (
                    <li key={b.Nama} className="flex items-start justify-between gap-3">
                        <span className="flex-1">
                            {b.Qty}× {b.Nama}
                        </span>
                        <span className="text-right">{b.Harga}</span>
                    </li>
                ))}
            </ul>
            <dl className="flex flex-col gap-1 border-b border-dashed border-garis py-4">
                <div className="flex justify-between gap-3">
                    <dt className="text-teks-sekunder">Subtotal</dt>
                    <dd>70.000</dd>
                </div>
                <div className="flex justify-between gap-3">
                    <dt className="text-teks-sekunder">PPN 11%</dt>
                    <dd>7.000</dd>
                </div>
                <div className="flex justify-between gap-3 font-semibold">
                    <dt>TOTAL</dt>
                    <dd>Rp 77.000</dd>
                </div>
            </dl>
            <div className="flex flex-col gap-3 pt-4">
                <p className="flex justify-between gap-3 text-keterangan text-teks-sekunder">
                    <span>Tunai</span>
                    <span>100.000</span>
                </p>
                <p className="flex justify-between gap-3 text-keterangan text-teks-sekunder">
                    <span>Kembali</span>
                    <span>23.000</span>
                </p>
                {/* Penanda offline: warna tidak menanggung makna sendiri, teksnya yang menjelaskan. */}
                <p className="text-keterangan rounded-kontrol bg-aksen px-3 py-2 text-center font-semibold text-teks-utama">
                    Dibuat offline | menunggu terkirim
                </p>
            </div>
        </div>
    );
}

const BARIS_JURNAL = [
    { Akun: 'Kas', Kode: '1-1100', Debit: '77.000', Kredit: null },
    { Akun: 'Pendapatan Penjualan', Kode: '4-1000', Debit: null, Kredit: '70.000' },
    { Akun: 'PPN Keluaran', Kode: '2-1300', Debit: null, Kredit: '7.000' },
] as const;

/**
 * Jurnal otomatis dari struk di atas: memperlihatkan invariant Σ debit = Σ kredit, yang memang diuji di
 * test invariant keuangan. Ini pembeda yang sulit ditunjukkan lewat tangkapan layar.
 */
export function SpesimenJurnal({ className }: PropsSpesimen) {
    return (
        <div
            role="img"
            aria-label="Contoh jurnal otomatis Payoung dari satu penjualan: Kas debit 77.000, Pendapatan Penjualan kredit 70.000, PPN Keluaran kredit 7.000, dengan total debit sama dengan total kredit."
            className={cn(
                'text-isi w-full rounded-panel border border-garis bg-permukaan p-6 text-teks-utama',
                className,
            )}
        >
            <p className="text-label mb-4 font-semibold text-teks-sekunder">Jurnal otomatis | PJL/20260927/KSR1/0043</p>
            <ul className="flex flex-col">
                {BARIS_JURNAL.map((b) => (
                    <li key={b.Kode} className="flex items-baseline gap-3 border-b border-garis py-3">
                        <span className="flex-1">
                            <span className="font-mono text-keterangan text-teks-sekunder">{b.Kode}</span> {b.Akun}
                        </span>
                        <span className="w-24 text-right font-mono tabular-nums">{b.Debit ?? '—'}</span>
                        <span className="w-24 text-right font-mono tabular-nums">{b.Kredit ?? '—'}</span>
                    </li>
                ))}
                <li className="flex items-baseline gap-3 py-3 font-semibold">
                    <span className="flex-1">Seimbang</span>
                    <span className="w-24 text-right font-mono tabular-nums">77.000</span>
                    <span className="w-24 text-right font-mono tabular-nums">77.000</span>
                </li>
            </ul>
        </div>
    );
}

/**
 * Tangkapan layar asli aplikasi (D-39), dihasilkan `Aplikasi/{Kasir,Pemilik}/AlatSitus/FotoPlayStore_test.dart` lalu
 * diubah ke WebP oleh `Spesifikasi/Merek/BuatFotoSitus.py`. Tablet 1600 × 1000, ponsel 540 × 1080: lebar & tinggi
 * dipasang di `<img>` supaya halaman tidak bergeser saat gambar tiba.
 */
type Foto = { src: string; lebar: number; tinggi: number; alt: string };

const TABLET = { lebar: 1600, tinggi: 1000 } as const;
const PONSEL = { lebar: 540, tinggi: 1080 } as const;

export const FOTO = {
    KasirJual: {
        src: gambarKasirJual,
        ...TABLET,
        alt: 'Aplikasi Kasir Payoung di tablet: katalog menu kafe, keranjang berisi Es Kopi Susu Aren, Croissant Cokelat, dan Matcha Latte, total Rp 95.700 dengan PBJT 10%.',
    },
    KasirBayar: {
        src: gambarKasirBayar,
        ...TABLET,
        alt: 'Layar Bayar aplikasi Kasir Payoung: total Rp 95.700, pilihan Tunai, QRIS, EDC, transfer, dan e-wallet, tombol Uang pas, papan angka, dan Bagi tagihan.',
    },
    KasirBerhasil: {
        src: gambarKasirBerhasil,
        ...TABLET,
        alt: 'Layar pembayaran berhasil di aplikasi Kasir Payoung dengan nomor transaksi, tombol Kirim struk, dan Transaksi baru.',
    },
    KasirShift: {
        src: gambarKasirShift,
        ...TABLET,
        alt: 'Layar Shift aplikasi Kasir Payoung: penjualan shift Rp 551.100, rincian per metode bayar, produk terlaris, dan daftar periksa sebelum tutup shift.',
    },
    KasirRiwayat: {
        src: gambarKasirRiwayat,
        ...TABLET,
        alt: 'Riwayat transaksi hari ini di aplikasi Kasir Payoung dengan rincian transaksi, cetak ulang struk, kirim struk, retur atau tukar, dan batalkan transaksi.',
    },
    KasirKas: {
        src: gambarKasirKas,
        ...TABLET,
        alt: 'Layar Kas aplikasi Kasir Payoung: perkiraan kas di laci Rp 746.600, asal uang di laci, serta kas masuk dan keluar.',
    },
    KasirSektorKafe: {
        src: gambarKasirSektorKafe,
        ...TABLET,
        alt: 'Aplikasi Kasir Payoung untuk kafe: katalog menu kopi, non-kopi, dan makanan dengan foto, keranjang Es Kopi Susu Aren, Croissant Cokelat, dan Matcha Latte, total Rp 95.700 dengan PBJT 10%.',
    },
    KasirSektorToko: {
        src: gambarKasirSektorToko,
        ...TABLET,
        alt: 'Aplikasi Kasir Payoung untuk toko dan retail: katalog sembako, minuman, dan kebutuhan harian dengan foto produk, keranjang beras, minyak goreng, telur, dan mi instan, total Rp 142.000.',
    },
    KasirSektorSalon: {
        src: gambarKasirSektorSalon,
        ...TABLET,
        alt: 'Aplikasi Kasir Payoung untuk salon dan jasa: daftar layanan rambut, kuku, dan perawatan dengan gambar, keranjang potong rambut, creambath, dan manicure, total Rp 200.000.',
    },
    KasirSektorLaundry: {
        src: gambarKasirSektorLaundry,
        ...TABLET,
        alt: 'Aplikasi Kasir Payoung untuk laundry: layanan kiloan, satuan, sepatu, dan boneka dengan gambar, keranjang cuci setrika, bedcover, kemeja, dan cuci sepatu, total Rp 96.000.',
    },
    KasirSektorApotek: {
        src: gambarKasirSektorApotek,
        ...TABLET,
        alt: 'Aplikasi Kasir Payoung untuk apotek: katalog obat bebas, vitamin, alat kesehatan, dan perawatan dengan gambar, keranjang paracetamol, vitamin C, dan masker medis, total Rp 96.000.',
    },
    KasirSektorBengkel: {
        src: gambarKasirSektorBengkel,
        ...TABLET,
        alt: 'Aplikasi Kasir Payoung untuk bengkel: jasa servis, oli, dan sparepart dengan gambar, keranjang servis rutin, oli mesin, busi, dan filter oli, total Rp 184.000.',
    },
    KasirHp: {
        src: gambarKasirHpJual,
        ...PONSEL,
        alt: 'Aplikasi Kasir Payoung di ponsel Android: katalog menu, keranjang dua baris Rp 47.300, dan tombol Bayar.',
    },
    PemilikBeranda: {
        src: gambarPemilikBeranda,
        ...PONSEL,
        alt: 'Aplikasi Pemilik Payoung: omzet hari ini Rp 8.475.000, naik 14% dari kemarin, perlu tindakan stok susu hampir habis, dan omzet per outlet.',
    },
    PemilikLaporan: {
        src: gambarPemilikLaporan,
        ...PONSEL,
        alt: 'Laporan di aplikasi Pemilik Payoung: penjualan per produk dengan pilihan kategori, kasir, dan jam.',
    },
    PemilikPersetujuan: {
        src: gambarPemilikPersetujuan,
        ...PONSEL,
        alt: 'Persetujuan jarak jauh di aplikasi Pemilik Payoung: kas keluar di atas batas dan diskon manual 20%, dengan tombol Tolak dan Setujui.',
    },
    PemilikKaryawan: {
        src: gambarPemilikKaryawan,
        ...PONSEL,
        alt: 'Pantau karyawan di aplikasi Pemilik Payoung: kehadiran hari ini, yang belum absen masuk, serta komisi dan target bulan ini.',
    },
    PemilikInsight: {
        src: gambarPemilikInsight,
        ...PONSEL,
        alt: 'Insight mingguan di aplikasi Pemilik Payoung: penjualan bersih minggu lalu, produk terlaris, yang naik dan turun paling banyak, dan saran restock.',
    },
} as const satisfies Record<string, Foto>;

/**
 * `penuhTinggi`: dipakai di dalam panggung bergaris besar (`[container-type:size]`, mis. carousel hero) supaya tinggi
 * ponsel mengikuti tinggi panggung (`100cqh`), bukan lebar kolom; tanpa itu ponsel bisa lebih tinggi daripada panggung.
 */
type PropsFoto = PropsSpesimen & { prioritas?: boolean; penuhTinggi?: boolean };

const KELAS_PONSEL_PENUH = 'h-[100cqh] w-auto max-w-none [&>img]:h-full [&>img]:w-auto';

/** Bingkai tablet: tepi gelap tipis membulat, tanpa bayangan dekoratif (§17.6.11). */
function BingkaiTablet({ foto, className, prioritas = false }: PropsFoto & { foto: Foto }) {
    return (
        <div
            className={cn('w-full rounded-[1.25rem] border border-teks-utama bg-teks-utama p-1.5 sm:p-2.5', className)}
        >
            <img
                src={foto.src}
                width={foto.lebar}
                height={foto.tinggi}
                loading={prioritas ? 'eager' : 'lazy'}
                fetchPriority={prioritas ? 'high' : 'auto'}
                decoding="async"
                alt={foto.alt}
                className="block h-auto w-full rounded-[0.75rem] bg-permukaan"
            />
        </div>
    );
}

/** Bingkai HP: tepi gelap tipis membulat, tanpa bayangan dekoratif (§17.6.11). */
function BingkaiHp({ foto, className, prioritas = false }: PropsFoto & { foto: Foto }) {
    return (
        <div className={cn('rounded-[1.75rem] border border-teks-utama bg-teks-utama p-1.5', className)}>
            <img
                src={foto.src}
                width={foto.lebar}
                height={foto.tinggi}
                loading={prioritas ? 'eager' : 'lazy'}
                decoding="async"
                alt={foto.alt}
                className="block h-auto w-full rounded-[1.375rem] bg-permukaan"
            />
        </div>
    );
}

/**
 * Layar Jual aplikasi Kasir di bingkai tablet, dengan aplikasi Pemilik di HP menumpuk di sudut kiri bawah: satu
 * gambar yang langsung menjelaskan "kasir di toko, pemilik dari mana saja". Gambar asli, bukan mockup karangan.
 */
export function SpesimenKasir({ className, prioritas = false }: PropsFoto) {
    return (
        <div className={cn('relative w-full max-w-2xl pb-10 pl-6 sm:pb-14 sm:pl-10', className)}>
            <BingkaiTablet foto={FOTO.KasirJual} prioritas={prioritas} />
            <BingkaiHp
                foto={FOTO.PemilikBeranda}
                prioritas={prioritas}
                className="absolute bottom-0 left-0 w-[26%] min-w-24"
            />
        </div>
    );
}

/** Aplikasi Pemilik saja, untuk blok gambar-teks tentang memantau usaha dari HP. */
export function SpesimenPemilik({ className, prioritas = false, penuhTinggi = false }: PropsFoto) {
    return (
        <BingkaiHp
            foto={FOTO.PemilikBeranda}
            prioritas={prioritas}
            className={cn(penuhTinggi ? KELAS_PONSEL_PENUH : 'mx-auto w-full max-w-64', className)}
        />
    );
}

/** Dua layar aplikasi Pemilik berdampingan (Beranda & Laporan), sedikit bertumpuk. */
export function SpesimenPemilikDuo({ className, prioritas = false, penuhTinggi = false }: PropsFoto) {
    const kelasPonsel = penuhTinggi ? KELAS_PONSEL_PENUH : 'w-[44%]';

    return (
        <div
            className={cn(
                'mx-auto flex items-center justify-center',
                penuhTinggi ? 'h-full gap-4' : 'w-full max-w-xl gap-[4%]',
                className,
            )}
        >
            <BingkaiHp foto={FOTO.PemilikBeranda} prioritas={prioritas} className={kelasPonsel} />
            <BingkaiHp foto={FOTO.PemilikLaporan} prioritas={prioritas} className={kelasPonsel} />
        </div>
    );
}

function Tablet(foto: Foto) {
    return function SpesimenTablet({ className, prioritas = false }: PropsFoto) {
        return <BingkaiTablet foto={foto} prioritas={prioritas} className={cn('max-w-2xl', className)} />;
    };
}

function Ponsel(foto: Foto) {
    return function SpesimenPonsel({ className, prioritas = false, penuhTinggi = false }: PropsFoto) {
        return (
            <BingkaiHp
                foto={foto}
                prioritas={prioritas}
                className={cn(penuhTinggi ? KELAS_PONSEL_PENUH : 'mx-auto w-full max-w-64', className)}
            />
        );
    };
}

export const SPESIMEN: Record<NamaSpesimen, (props: PropsFoto) => ReactNode> = {
    Kasir: SpesimenKasir,
    KasirBayar: Tablet(FOTO.KasirBayar),
    KasirBerhasil: Tablet(FOTO.KasirBerhasil),
    KasirShift: Tablet(FOTO.KasirShift),
    KasirRiwayat: Tablet(FOTO.KasirRiwayat),
    KasirKas: Tablet(FOTO.KasirKas),
    KasirSektorKafe: Tablet(FOTO.KasirSektorKafe),
    KasirSektorToko: Tablet(FOTO.KasirSektorToko),
    KasirSektorSalon: Tablet(FOTO.KasirSektorSalon),
    KasirSektorLaundry: Tablet(FOTO.KasirSektorLaundry),
    KasirSektorApotek: Tablet(FOTO.KasirSektorApotek),
    KasirSektorBengkel: Tablet(FOTO.KasirSektorBengkel),
    KasirHp: Ponsel(FOTO.KasirHp),
    Pemilik: SpesimenPemilik,
    PemilikDuo: SpesimenPemilikDuo,
    PemilikLaporan: Ponsel(FOTO.PemilikLaporan),
    PemilikPersetujuan: Ponsel(FOTO.PemilikPersetujuan),
    PemilikKaryawan: Ponsel(FOTO.PemilikKaryawan),
    PemilikInsight: Ponsel(FOTO.PemilikInsight),
    Struk: SpesimenStruk,
    Jurnal: SpesimenJurnal,
};
