import { Head, Link, router, usePage } from '@inertiajs/react';
import { ChevronRightIcon, LockIcon } from 'lucide-react';
import { useState, type MouseEvent, type ReactNode } from 'react';

import JudulHalaman from '@/Komponen/Umpan/JudulHalaman';
import IkonNavigasi, { type NamaIkonNavigasi } from '@/Komponen/Navigasi/IkonNavigasi';
import Tombol from '@/Komponen/Formulir/Tombol';
import { Button } from '@/Komponen/Ui/button';
import DialogNaikPaket from '@/Komponen/Langganan/DialogNaikPaket';
import { CekButirSesuaiEdisi, daftarPengaturan, type GrupPengaturan } from '@/Pustaka/DaftarPengaturan';
import { CekSesuaiSektor } from '@/Pustaka/Sektor';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/Komponen/Ui/collapsible';
import {
    Sidebar,
    SidebarContent,
    SidebarGroup,
    SidebarGroupContent,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarMenuSub,
    SidebarMenuSubButton,
    SidebarMenuSubItem,
    SidebarProvider,
    SidebarRail,
    useSidebar,
} from '@/Komponen/Ui/sidebar';
import { cn } from '@/Komponen/Ui/utils';
import BannerPengumuman from '@/Komponen/Umpan/BannerPengumuman';
import DialogHasil from '@/Komponen/Umpan/DialogHasil';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatTanggal, FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import type { FiturPaket, PropsBersamaAplikasi, TenantAktif } from '@/Tipe/Aplikasi';
import { IzinTenant, PunyaIzinTenant, type KunciIzinTenant } from '@/Tipe/Organisasi';

import {
    BacaSidebarTerbuka,
    type ButirJejak,
    JejakHalaman,
    kelasChevronGrupSidebar,
    kelasTombolGrupSidebar,
    kelasTombolMenuSidebar,
    kelasTombolSubMenuSidebar,
    KepalaTataLetak,
    MenuAkun,
    PemberitahuanMelayang,
} from './BagianTataLetak';
import KepalaSidebarMerek from './KepalaSidebarMerek';
import PencarianCepat, { type HalamanPencarian, type SumberPencarian } from './PencarianCepat';

type PropsTataLetak = {
    judul: string;
    /**
     * Langkah tambahan di akhir jejak halaman, untuk halaman rincian yang butuh jalan kembali ke daftarnya
     * (misal `[{ label: 'Semua outlet', href: '/kelola/outlet' }]`).
     *
     * Halaman **tidak boleh** merender remah rotinya sendiri: jejak dari tata letak sudah memakai
     * `aria-label="Jejak halaman"`, jadi remah roti kedua menghasilkan dua landmark bernama sama dan dua jejak
     * bertumpuk. Dijaga `JejakHalamanTes`.
     */
    jejak?: ButirJejak[];
    children: ReactNode;
};

/**
 * `fitur` = kunci fitur paket (D-23): di luar paket tetap tampil dengan gembok; klik = dialog naik paket/add-on.
 * `pemisah` = garis pemisah di atas item ini (D-27: memisahkan kerja harian dari pengaturan & bantuan).
 */
type ItemMenu = {
    label: string;
    href: string;
    izin: KunciIzinTenant | null;
    /** D-101: izin alternatif — cukup salah satu dari `izin` atau `atau` (menu Tim terbuka bagi pemegang lihat karyawan atau lihat pengguna). */
    atau?: KunciIzinTenant;
    /**
     * Bagi pemegang `atau` yang tidak punya `izin`, item ini berubah menjadi tautan ke halaman lain (label baru, tanpa
     * gembok paket): "Toko online & pengiriman" menjadi "Pengiriman" untuk Staf Gudang yang hanya berizin `pengiriman.kelola`.
     */
    gantiBilaHanyaAtau?: { label: string; href: string };
    ikon?: NamaIkonNavigasi;
    fitur?: string;
    pemisah?: boolean;
    /** Audit #33: awalan kode sektor outlet yang memakai menu ini (mis. `WHS`, `SVC-LDR`); tanpa = semua sektor. */
    sektor?: string[];
    /** Audit #33: disembunyikan di "Mode sederhana" (tetap bisa dibuka lewat Ctrl+K). */
    lanjutan?: boolean;
};

/** Menu utama bersub-menu: tampil bila ada sub-menu yang boleh dibuka; tautannya = sub-menu pertama yang boleh. */
type GrupMenu = ItemMenu & { labelSub: string; sub: ItemMenu[] };

// F-03: grup menu "Produk". Tampil sebagai sub-menu saat salah satu halamannya dibuka.
// D-27: master yang diatur sekali (Satuan, Pilihan, Kelompok pajak, Daftar harga, Stasiun dapur) dan alat impor
// massal pindah ke Pengaturan. Impor tetap punya tombol "Impor dari Excel" di halaman Produk, sama seperti
// Impor stok awal di halaman Stok awal.
const menuProduk: ItemMenu[] = [
    { label: 'Produk', href: '/kelola/produk', izin: IzinTenant.ProdukLihat },
    { label: 'Kategori', href: '/kelola/kategori', izin: IzinTenant.ProdukLihat },
    // F-16d bagian 2: paket sesi (produk Jasa yang dijual sebagai N sesi).
    {
        label: 'Paket sesi',
        href: '/kelola/paket-sesi',
        izin: IzinTenant.ProdukLihat,
        fitur: 'pelanggan.paket-sesi',
        sektor: ['SVC'],
    },
];

// F-05a: grup menu "Persediaan" (DesainF05a E). D-27: stok awal, impornya, dan pengaturan persediaan pindah ke
// Pengaturan karena dipakai saat menyiapkan toko, bukan saat bekerja harian.
const menuPersediaan: ItemMenu[] = [
    { label: 'Saldo stok', href: '/kelola/persediaan/saldo', izin: IzinTenant.PersediaanLihat },
    { label: 'Kartu stok', href: '/kelola/persediaan/kartu-stok', izin: IzinTenant.PersediaanLihat },
    // F-05b: transfer, stok opname, penyesuaian (lihat: persediaan.lihat; tindakan dijaga di rute).
    {
        label: 'Transfer stok',
        href: '/kelola/persediaan/transfer',
        izin: IzinTenant.PersediaanLihat,
        fitur: 'stok.transfer',
        lanjutan: true,
    },
    {
        label: 'Stok opname',
        href: '/kelola/persediaan/opname',
        izin: IzinTenant.PersediaanLihat,
        fitur: 'stok.opname',
        lanjutan: true,
    },
    { label: 'Penyesuaian stok', href: '/kelola/persediaan/penyesuaian', izin: IzinTenant.PersediaanLihat },
    // F-05e: order produksi (resep → bahan keluar, hasil masuk).
    {
        label: 'Produksi',
        href: '/kelola/persediaan/produksi',
        izin: IzinTenant.PersediaanLihat,
        sektor: ['FNB', 'RTL-BLD', 'WHS'],
        lanjutan: true,
    },
    // F-05f: bahan terbuang (waste) & food cost.
    {
        label: 'Bahan terbuang',
        href: '/kelola/persediaan/bahan-terbuang',
        izin: IzinTenant.PersediaanLihat,
        // D-88: toko sayur/buah, kelontong, pet shop, florist, dan apotek juga membuang barang (layu, busuk, kedaluwarsa).
        sektor: ['FNB', 'RTL-BLD', 'RTL-GEN', 'RTL-FRS', 'RTL-PET', 'RTL-FLR', 'RTL-PHR'],
        lanjutan: true,
    },
];

// F-04 fase 1: grup menu "Pembelian" (pembelian.kelola); pengaturan pembelian butuh pembelian.po.setujui.
// Audit kemudahan pakai #14: belanja stok (beli tunai sekali simpan) paling sering dipakai toko kecil, jadi entri
// pertama; riwayat pembayaran hutang dibuka dari halaman Hutang pemasok supaya grup tetap ≤ 7 sub-menu.
const menuPembelian: ItemMenu[] = [
    { label: 'Belanja stok', href: '/kelola/pembelian/belanja-stok', izin: IzinTenant.PembelianKelola },
    {
        label: 'Pesanan pembelian',
        href: '/kelola/pembelian/pesanan',
        izin: IzinTenant.PembelianKelola,
        fitur: 'pembelian.po',
        lanjutan: true,
    },
    { label: 'Penerimaan barang', href: '/kelola/pembelian/penerimaan', izin: IzinTenant.PembelianKelola },
    { label: 'Faktur pembelian', href: '/kelola/pembelian/faktur', izin: IzinTenant.PembelianKelola, lanjutan: true },
    { label: 'Hutang pemasok', href: '/kelola/pembelian/hutang', izin: IzinTenant.PembelianKelola },
    { label: 'Retur pembelian', href: '/kelola/pembelian/retur', izin: IzinTenant.PembelianKelola, lanjutan: true },
    { label: 'Pemasok', href: '/kelola/pembelian/pemasok', izin: IzinTenant.PembelianKelola },
];

// Grosir (F-12, §9.7, D-32): satu alur jual-kirim-tagih beserta returnya, cermin grup "Pembelian" di sisi beli.
const menuGrosir: ItemMenu[] = [
    { label: 'Pesanan grosir', href: '/kelola/grosir/pesanan', izin: IzinTenant.GrosirKelola, fitur: 'piutang.grosir' },
    {
        label: 'Surat jalan',
        href: '/kelola/grosir/surat-jalan',
        izin: IzinTenant.GrosirKelola,
        fitur: 'piutang.grosir',
    },
    {
        label: 'Faktur penjualan',
        href: '/kelola/grosir/faktur',
        izin: IzinTenant.GrosirKelola,
        fitur: 'piutang.grosir',
    },
    { label: 'Retur grosir', href: '/kelola/grosir/retur', izin: IzinTenant.GrosirKelola, fitur: 'piutang.grosir' },
    // Modul Salesman bagian 1: kunjungan & pesanan dari aplikasi salesman.
    {
        label: 'Kunjungan salesman',
        href: '/kelola/grosir/kunjungan',
        izin: IzinTenant.GrosirKelola,
        fitur: 'piutang.grosir',
        lanjutan: true,
    },
    // Modul Salesman bagian 3: kendaraan kanvas & rekap harian (muat, terjual, bongkar, setoran).
    {
        label: 'Kanvas',
        href: '/kelola/grosir/kanvas',
        izin: IzinTenant.GrosirKelola,
        fitur: 'piutang.grosir',
        lanjutan: true,
    },
];

// F-06: grup menu "Shift & kas" (pemantauan back-office; layar kasir ada di aplikasi Flutter): shift (laporan.penjualan.lihat), kategori kas (akuntansi.kelola), pengaturan (outlet.kelola).
// F-16a/F-16b: data pelanggan, tier, pengaturan loyalti.
const menuPelanggan: ItemMenu[] = [
    { label: 'Daftar pelanggan', href: '/kelola/pelanggan', izin: IzinTenant.PelangganLihat },
    { label: 'Promo', href: '/kelola/promo', izin: IzinTenant.PelangganLihat, fitur: 'promo.mesin' },
    // F-16c bagian 4b: klaim promo yang ditanggung pemasok.
    {
        label: 'Klaim promo pemasok',
        href: '/kelola/promo/klaim-pemasok',
        izin: IzinTenant.PelangganLihat,
        fitur: 'promo.mesin',
    },
    // F-12: piutang pelanggan (penjualan tempo) & pelunasan.
    { label: 'Piutang pelanggan', href: '/kelola/piutang', izin: IzinTenant.PelangganLihat },
    { label: 'Pelunasan piutang', href: '/kelola/piutang/pelunasan', izin: IzinTenant.PelangganLihat },
    // F-16d bagian 1: isi deposit pelanggan dari kasir.
    {
        label: 'Isi deposit',
        href: '/kelola/pelanggan/isi-deposit',
        izin: IzinTenant.PelangganLihat,
        fitur: 'pelanggan.deposit',
    },
    // F-16d bagian 2: saldo paket sesi pelanggan.
    {
        label: 'Saldo paket sesi',
        href: '/kelola/pelanggan/saldo-sesi',
        izin: IzinTenant.PelangganLihat,
        fitur: 'pelanggan.paket-sesi',
        sektor: ['SVC'],
    },
];

// D-101: grup "Tim" — satu daftar untuk akun masuk (pengguna) dan data karyawan; jadwal, absensi, komisi, gaji (karyawan.lihat).
const menuKaryawan: ItemMenu[] = [
    { label: 'Daftar tim', href: '/kelola/tim', izin: IzinTenant.KaryawanLihat, atau: IzinTenant.PenggunaLihat },
    { label: 'Jadwal kerja', href: '/kelola/karyawan/jadwal', izin: IzinTenant.KaryawanLihat },
    { label: 'Absensi', href: '/kelola/karyawan/absensi', izin: IzinTenant.KaryawanLihat },
    // F-18 bagian 2: komisi (aturannya pindah ke Pengaturan, D-27).
    {
        label: 'Laporan komisi',
        href: '/kelola/karyawan/komisi/laporan',
        izin: IzinTenant.KaryawanLihat,
        fitur: 'karyawan.komisi',
    },
    // F-18 bagian 3: kasbon, target penjualan.
    { label: 'Kasbon', href: '/kelola/karyawan/kasbon', izin: IzinTenant.KaryawanLihat, lanjutan: true },
    { label: 'Target penjualan', href: '/kelola/karyawan/target', izin: IzinTenant.KaryawanLihat, lanjutan: true },
    // F-18 bagian 3: rekap gaji bulanan (memuat gaji).
    { label: 'Rekap gaji', href: '/kelola/karyawan/gaji', izin: IzinTenant.KaryawanKelola },
];

/*
 * F-07b/F-09/F-06/F-15: grup menu "Penjualan & kasir" — semua yang terjadi di kasir hari itu, baca saja dari
 * back-office (layar kasirnya ada di aplikasi Flutter).
 *
 * D-27: grup "Shift & kas" digabung ke sini. Setelah pengaturan kasir/struk/gerbang & kategori kas pindah ke
 * Pengaturan, grup itu hanya menyisakan dua halaman, dan keduanya menjawab pertanyaan yang sama dengan daftar
 * penjualan: apa yang terjadi di kasir.
 */
const menuPenjualan: ItemMenu[] = [
    { label: 'Daftar penjualan', href: '/kelola/penjualan', izin: IzinTenant.LaporanPenjualanLihat },
    {
        label: 'Toko online & pengiriman',
        href: '/kelola/toko-online',
        izin: IzinTenant.TokoOnlineKelola,
        // Staf Gudang berizin `pengiriman.kelola` tanpa `toko-online.kelola` tetap menjangkau halaman Pengiriman.
        atau: IzinTenant.PengirimanKelola,
        gantiBilaHanyaAtau: { label: 'Pengiriman', href: '/kelola/pengiriman' },
        fitur: 'kanal.toko-online',
    },
    { label: 'Shift kasir', href: '/kelola/kasir/shift', izin: IzinTenant.LaporanPenjualanLihat },
    // F-15: tutup harian (End of Day) per outlet.
    {
        label: 'Tutup harian',
        href: '/kelola/kasir/tutup-harian',
        izin: IzinTenant.LaporanPenjualanLihat,
        lanjutan: true,
    },
    // F-12 bagian 2: pre-order & uang muka.
    { label: 'Pre-order', href: '/kelola/pre-order', izin: IzinTenant.LaporanPenjualanLihat },
    // F-07 mode service: reservasi layanan jasa per staf.
    { label: 'Reservasi', href: '/kelola/reservasi', izin: IzinTenant.ReservasiKelola, sektor: ['SVC'] },
    { label: 'Laundry', href: '/kelola/laundry', izin: IzinTenant.LaundryKelola, sektor: ['SVC-LDR'] },
];

// F-05a: grup menu "Akuntansi"; jurnal (baca saja) memakai laporan.keuangan.lihat (DesainF05a H-13).
// F-13a: bagan akun, pemetaan akun, kas & bank, dan laporan keuangan (lihat laporan.keuangan.lihat, ubah di halaman
// butuh akuntansi.kelola).
const menuAkuntansi: ItemMenu[] = [
    {
        label: 'Jurnal',
        href: '/kelola/akuntansi/jurnal',
        izin: IzinTenant.LaporanKeuanganLihat,
        fitur: 'akuntansi.penuh',
    },
    { label: 'Kas & bank', href: '/kelola/akuntansi/kas-bank', izin: IzinTenant.LaporanKeuanganLihat },
    // F-08 BR-08.4: pencairan dana non-tunai (J-08.1). Rumahnya di Akuntansi, bukan Penjualan, karena yang dikerjakan
    // di sini pembukuan uang masuk rekening & beban biaya pembayaran — pekerjaan yang sama dengan Kas & bank.
    { label: 'Pencairan dana', href: '/kelola/akuntansi/pencairan', izin: IzinTenant.LaporanKeuanganLihat },
    {
        label: 'Buku besar',
        href: '/kelola/akuntansi/laporan/buku-besar',
        izin: IzinTenant.LaporanKeuanganLihat,
        fitur: 'akuntansi.penuh',
    },
    {
        label: 'Neraca saldo',
        href: '/kelola/akuntansi/laporan/neraca-saldo',
        izin: IzinTenant.LaporanKeuanganLihat,
        fitur: 'akuntansi.penuh',
    },
    // FIN-10 (v3.38): aset tetap & penyusutan otomatis bulanan (kerja berkala, jadi menu, bukan Pengaturan).
    {
        label: 'Aset tetap',
        href: '/kelola/akuntansi/aset-tetap',
        izin: IzinTenant.LaporanKeuanganLihat,
        fitur: 'akuntansi.penuh',
    },
    {
        label: 'Tutup buku',
        href: '/kelola/akuntansi/tutup-buku',
        izin: IzinTenant.LaporanKeuanganLihat,
        fitur: 'akuntansi.penuh',
    },
];

/*
 * F-14a: grup menu "Laporan" — yang dibaca pemilik.
 *
 * D-27: laporan keuangan (Laba rugi, Neraca, Arus kas) pindah ke sini dari grup Akuntansi. Pemilik mencarinya
 * sebagai laporan, bukan sebagai pekerjaan pembukuan; Akuntansi kini berisi pekerjaan pembukuannya sendiri
 * (jurnal, kas & bank, buku besar, neraca saldo, tutup buku). Bagan & pemetaan akun pindah ke Pengaturan.
 */
const menuLaporan: ItemMenu[] = [
    { label: 'Laporan penjualan', href: '/kelola/laporan/penjualan', izin: IzinTenant.LaporanPenjualanLihat },
    {
        label: 'Laporan pajak',
        href: '/kelola/laporan/pajak',
        izin: IzinTenant.LaporanKeuanganLihat,
        fitur: 'laporan.lengkap',
    },
    { label: 'Laporan stok', href: '/kelola/laporan/stok', izin: IzinTenant.PersediaanLihat, fitur: 'laporan.lengkap' },
    // Apotek (§9.5): obat wajib resep & data pendukung SIPNAP.
    {
        label: 'Laporan apotek',
        href: '/kelola/laporan/apotek',
        izin: IzinTenant.LaporanPenjualanLihat,
        fitur: 'laporan.lengkap',
        sektor: ['RTL-PHR'],
    },
    {
        label: 'Laba rugi',
        href: '/kelola/akuntansi/laporan/laba-rugi',
        izin: IzinTenant.LaporanKeuanganLihat,
        fitur: 'laporan.lengkap',
    },
    {
        label: 'Neraca',
        href: '/kelola/akuntansi/laporan/neraca',
        izin: IzinTenant.LaporanKeuanganLihat,
        fitur: 'akuntansi.penuh',
    },
    {
        label: 'Arus kas',
        href: '/kelola/akuntansi/laporan/arus-kas',
        izin: IzinTenant.LaporanKeuanganLihat,
        fitur: 'akuntansi.penuh',
    },
];

/** Item sub-menu yang aktif untuk URL ini: awalan terpanjang menang (/kelola/produk/impor vs /kelola/produk). */
export function CariSubMenuAktif(daftar: ItemMenu[], url: string): string | null {
    const jalur = url.split('?')[0] ?? url;
    const cocok = daftar
        .filter((menu) => jalur === menu.href || jalur.startsWith(`${menu.href}/`))
        .sort((a, b) => b.href.length - a.href.length);

    return cocok[0]?.href ?? null;
}

/** Item sub-menu Produk yang aktif untuk URL ini. */
export function CariMenuProdukAktif(url: string): string | null {
    return CariSubMenuAktif(menuProduk, url);
}

// Menu back-office tenant berbasis izin (hanya UX; server tetap memeriksa izin lewat WajibIzinTenant).
/*
 * Menu back-office tenant berbasis izin (hanya UX; server tetap memeriksa izin lewat WajibIzinTenant).
 *
 * D-27 anggaran & peta navigasi: maksimal 12 entri di level ini dan 7 sub-menu per grup, diurutkan dari yang
 * paling sering dipakai ke yang paling jarang — bukan urutan modul kode. Halaman yang diatur sekali lalu tidak
 * disentuh lagi (master, pengaturan modul, outlet, pengguna, perangkat, log audit, langganan) tidak ada di sini;
 * rumahnya di `/kelola/pengaturan` (`Pustaka/DaftarPengaturan`), dan semuanya tetap bisa dicari lewat Ctrl+K.
 * Dijaga `AnggaranNavigasiTes`.
 */
export const daftarMenu: (ItemMenu | GrupMenu)[] = [
    { label: 'Beranda', href: '/kelola', izin: null, ikon: 'Beranda' },
    // D-23 C: semua yang perlu ditindaklanjuti (butir disaring izin di server).
    { label: 'Kotak tindakan', href: '/kelola/tindakan', izin: null, ikon: 'KotakMasuk' },
    {
        label: 'Penjualan & kasir',
        href: '/kelola/penjualan',
        izin: null,
        ikon: 'Struk',
        labelSub: 'Menu penjualan & kasir',
        sub: menuPenjualan,
    },
    // F-14a: laporan yang dibaca pemilik, termasuk laporan keuangan.
    {
        label: 'Laporan',
        href: '/kelola/laporan/penjualan',
        izin: null,
        ikon: 'Laporan',
        labelSub: 'Menu laporan',
        sub: menuLaporan,
    },
    {
        label: 'Persediaan',
        href: '/kelola/persediaan/saldo',
        izin: null,
        ikon: 'Gudang',
        labelSub: 'Menu persediaan',
        sub: menuPersediaan,
    },
    {
        label: 'Produk',
        href: '/kelola/produk',
        izin: IzinTenant.ProdukLihat,
        ikon: 'Produk',
        labelSub: 'Menu produk',
        sub: menuProduk,
    },
    // F-04 fase 1: pembelian & hutang pemasok.
    {
        label: 'Pembelian',
        href: '/kelola/pembelian/pesanan',
        izin: null,
        ikon: 'Pembelian',
        labelSub: 'Menu pembelian',
        sub: menuPembelian,
    },
    // Grosir: penjualan besar ke pengecer, bersebelahan dengan Pembelian karena bentuk dokumennya cermin.
    {
        label: 'Grosir',
        href: '/kelola/grosir/pesanan',
        izin: IzinTenant.GrosirKelola,
        sektor: ['WHS', 'RTL-BLD'],
        lanjutan: true,
        ikon: 'Pengiriman',
        labelSub: 'Menu grosir',
        sub: menuGrosir,
    },
    {
        label: 'Pelanggan',
        href: '/kelola/pelanggan',
        izin: null,
        ikon: 'Pelanggan',
        labelSub: 'Menu pelanggan',
        sub: menuPelanggan,
    },
    {
        label: 'Tim',
        href: '/kelola/tim',
        izin: null,
        ikon: 'Karyawan',
        labelSub: 'Menu tim',
        sub: menuKaryawan,
    },
    {
        label: 'Akuntansi',
        href: '/kelola/akuntansi/jurnal',
        izin: null,
        lanjutan: true,
        ikon: 'Akuntansi',
        labelSub: 'Menu akuntansi',
        sub: menuAkuntansi,
    },
    // Pemisah: di bawah sini bukan kerja harian lagi.
    { label: 'Pengaturan', href: '/kelola/pengaturan', izin: null, ikon: 'Pengaturan', pemisah: true },
    { label: 'Bantuan', href: '/kelola/bantuan', izin: IzinTenant.BantuanTiketLihat, ikon: 'Dukungan' },
];

/**
 * Sumber data pencarian cepat. Aktif hanya bila halaman daftarnya (`alamat`) ada di menu yang boleh dilihat, jadi
 * mengikuti izin yang sama dengan sidebar; server tetap memeriksa izin & tenant pada endpoint JSON TabelData.
 */
const sumberPencarian: SumberPencarian[] = [
    {
        id: 'produk',
        label: 'Produk',
        alamat: '/kelola/produk',
        ikon: 'Produk',
        AmbilHasil: (b) => ({
            judul: String(b.Nama),
            keterangan: typeof b.Sku === 'string' ? b.Sku : null,
            href: `/kelola/produk/${String(b.Uuid)}`,
        }),
    },
    {
        id: 'pelanggan',
        label: 'Pelanggan',
        alamat: '/kelola/pelanggan',
        ikon: 'Pelanggan',
        AmbilHasil: (b) => ({
            judul: String(b.Nama),
            keterangan: typeof b.NoHp === 'string' ? b.NoHp : null,
            href: `/kelola/pelanggan/${String(b.Uuid)}`,
        }),
    },
    {
        id: 'pemasok',
        label: 'Pemasok',
        alamat: '/kelola/pembelian/pemasok',
        ikon: 'Pembelian',
        // Pemasok tidak punya halaman detail: buka daftarnya dengan pencarian nama ini.
        AmbilHasil: (b) => ({
            judul: String(b.Nama),
            keterangan: typeof b.Kode === 'string' ? b.Kode : null,
            href: `/kelola/pembelian/pemasok?${new URLSearchParams({ cari: String(b.Nama) }).toString()}`,
        }),
    },
    {
        id: 'nomor-seri',
        label: 'Nomor seri / IMEI',
        alamat: '/kelola/persediaan/kartu-stok/nomor-seri',
        ikon: 'Gudang',
        // Hasil membuka riwayat unitnya langsung (`?unit=`), bukan daftar pencarian.
        AmbilHasil: (b) => ({
            judul: String(b.Nomor),
            keterangan:
                [b.NamaProduk, b.LabelStatus].filter((x): x is string => typeof x === 'string').join(' | ') || null,
            href: `/kelola/persediaan/kartu-stok/nomor-seri?${new URLSearchParams({ cari: String(b.Nomor), unit: String(b.Uuid) }).toString()}`,
        }),
    },
    {
        id: 'penjualan',
        label: 'Penjualan',
        alamat: '/kelola/penjualan',
        ikon: 'Struk',
        AmbilHasil: (b) => ({
            judul: String(b.Nomor),
            keterangan: typeof b.NamaOutlet === 'string' ? b.NamaOutlet : null,
            href: `/kelola/penjualan/${String(b.Uuid)}`,
        }),
    },
];

/**
 * Halaman yang berumah sebagai tab di halaman lain (D-27: satu rumah menu, grup sudah di batas sub-menu). Ia tidak
 * punya entri menu, tetapi Ctrl+K harus tetap menemukannya; hanya muncul bila halaman induknya terlihat (izin sama).
 */
const halamanTurunan: (HalamanPencarian & { induk: string; izin?: KunciIzinTenant; sektor?: string[] })[] = [
    {
        induk: '/kelola/persediaan/kartu-stok',
        label: 'Riwayat nomor seri / IMEI',
        href: '/kelola/persediaan/kartu-stok/nomor-seri',
        grup: 'Persediaan',
        ikon: 'Gudang',
        sektor: ['RTL-ELC', 'SVC-WRK', 'WHS'],
    },
    // Bengkel (§9.10): grup "Penjualan & kasir" sudah 7 sub-menu (D-27); perintah kerja & kendaraan dibuka dari tombol
    // di Daftar penjualan, detail pelanggan, Kotak Tindakan, dan Ctrl+K. Tetap disaring izin `bengkel.kelola`.
    {
        induk: '/kelola/penjualan',
        label: 'Perintah kerja bengkel',
        href: '/kelola/bengkel/perintah-kerja',
        grup: 'Penjualan & kasir',
        ikon: 'Struk',
        izin: IzinTenant.BengkelKelola,
        sektor: ['SVC-WRK'],
    },
    {
        induk: '/kelola/penjualan',
        label: 'Kendaraan pelanggan',
        href: '/kelola/bengkel/kendaraan',
        grup: 'Penjualan & kasir',
        ikon: 'Struk',
        izin: IzinTenant.BengkelKelola,
        sektor: ['SVC-WRK'],
    },
];

/**
 * Halaman & sumber data pencarian cepat untuk menu yang boleh dilihat (grup menu jadi keterangan halaman).
 *
 * D-27: halaman yang pindah dari menu samping ke Pengaturan (master, pengaturan modul, outlet, pengguna, perangkat,
 * log audit, langganan) tetap ikut di sini, jadi Ctrl+K tetap menemukan semuanya walau menu sampingnya diringkas.
 */
export function SusunPencarian(
    menuTerlihat: MenuTerlihat[],
    akses: PropsBersamaAplikasi['Akses'],
    edisi?: PropsBersamaAplikasi['Edisi'],
    sektorOutlet: string[] = [],
): {
    halaman: HalamanPencarian[];
    sumber: SumberPencarian[];
} {
    const menu = menuTerlihat.flatMap(({ menu: induk, sub }): HalamanPencarian[] =>
        sub.length === 0
            ? [{ label: induk.label, href: induk.href, grup: null, ikon: induk.ikon }]
            : sub.map((item) => ({ label: item.label, href: item.href, grup: induk.label, ikon: induk.ikon })),
    );
    const pengaturan = daftarPengaturan.flatMap(({ judul, butir }): HalamanPencarian[] =>
        butir
            .filter(
                (item) =>
                    CekButirSesuaiEdisi(item, edisi) &&
                    CekSesuaiSektor(item, sektorOutlet) &&
                    (item.izin === null ||
                        PunyaIzinTenant(akses, item.izin) ||
                        (item.atau !== undefined && PunyaIzinTenant(akses, item.atau))),
            )
            .map((item) => ({
                label: item.label,
                href: item.href,
                grup: `Pengaturan › ${judul}`,
                ikon: 'Pengaturan',
            })),
    );
    // Halaman yang rumahnya tab di halaman lain (bukan entri menu sendiri) ikut Ctrl+K selama induknya terlihat.
    const turunan = halamanTurunan.flatMap(({ induk, izin, sektor, ...halamanTurunanItem }): HalamanPencarian[] =>
        menu.some((ada) => ada.href === induk) &&
        (izin === undefined || PunyaIzinTenant(akses, izin)) &&
        CekSesuaiSektor({ sektor }, sektorOutlet)
            ? [halamanTurunanItem]
            : [],
    );
    // Menu samping menang bila alamatnya sama, supaya satu halaman tidak muncul dua kali di hasil pencarian.
    const halaman = [...menu, ...turunan, ...pengaturan.filter((item) => !menu.some((ada) => ada.href === item.href))];
    const alamatTerlihat = new Set(halaman.map((h) => h.href));

    return { halaman, sumber: sumberPencarian.filter((s) => alamatTerlihat.has(s.alamat)) };
}

function CekGrupMenu(menu: ItemMenu | GrupMenu): menu is GrupMenu {
    return 'sub' in menu;
}

/** Halaman yang rumahnya di Pengaturan (D-27), untuk menyalakan menu Pengaturan saat salah satunya dibuka. */
const butirPengaturan: ItemMenu[] = daftarPengaturan.flatMap(({ butir }) => butir);

/** Grup Pengaturan yang memuat jalur ini. */
function CariGrupPengaturan(jalur: string): GrupPengaturan | undefined {
    return daftarPengaturan.find(({ butir }) =>
        butir.some((item) => jalur === item.href || jalur.startsWith(`${item.href}/`)),
    );
}

/**
 * Jejak halaman di atas judul (D-27): nama usaha, lalu induk halaman ini.
 *
 * Untuk halaman yang rumahnya di Pengaturan, induknya adalah tautan **Pengaturan** beserta nama grupnya — tanpa itu
 * halaman yang keluar dari menu samping tidak punya petunjuk letak maupun jalan kembali. Halaman saat ini tidak
 * diulang karena sudah menjadi `<h1>`.
 */
export function SusunJejak(url: string, namaInduk: string, menuTerlihat: MenuTerlihat[]): ButirJejak[] {
    const jalur = url.split('?')[0] ?? url;
    const awal: ButirJejak[] = [{ label: namaInduk }];
    const grupPengaturan = CariGrupPengaturan(jalur);

    if (grupPengaturan !== undefined) {
        return [...awal, { label: 'Pengaturan', href: '/kelola/pengaturan' }, { label: grupPengaturan.judul }];
    }

    const grupMenu = menuTerlihat.find(
        ({ labelSub, sub }) => labelSub !== null && CariSubMenuAktif(sub, jalur) !== null,
    );

    return grupMenu === undefined ? awal : [...awal, { label: grupMenu.menu.label }];
}

/**
 * Menu utama yang aktif untuk URL ini (grup untuk seluruh sub-menunya).
 *
 * D-27: Pengaturan menyala untuk seluruh halaman yang rumahnya di sana — termasuk `/kelola/peran` (di bawah
 * Pengguna & peran) dan `/kelola/keamanan/pin` — supaya menu samping tetap menunjukkan posisi pengguna setelah
 * halaman-halaman itu keluar dari level utama.
 */
export function CekMenuAktif(href: string, url: string): boolean {
    if (href === '/kelola') {
        return url === '/kelola';
    }

    const grup = daftarMenu.find((menu): menu is GrupMenu => CekGrupMenu(menu) && menu.href === href);

    if (grup) {
        return CariSubMenuAktif(grup.sub, url) !== null;
    }

    if (href === '/kelola/pengaturan') {
        return (
            url.startsWith(href) || url.startsWith('/kelola/peran') || CariSubMenuAktif(butirPengaturan, url) !== null
        );
    }

    return url.startsWith(href);
}

type MenuTerlihat = { menu: ItemMenu; labelSub: string | null; sub: ItemMenu[] };

export { CekSesuaiSektor };

/**
 * Menu utama yang boleh dilihat pemegang akses ini beserta sub-menunya; grup tanpa sub-menu boleh disembunyikan.
 * Audit #33: `sektorOutlet` menyaring menu khusus sektor; `sederhana` menyembunyikan menu `lanjutan`.
 */
function PilihTampilanItem(akses: PropsBersamaAplikasi['Akses']) {
    return (menu: ItemMenu): ItemMenu => {
        if (menu.gantiBilaHanyaAtau === undefined || menu.izin === null || PunyaIzinTenant(akses, menu.izin)) {
            return menu;
        }

        const tanpaFitur: ItemMenu = { ...menu, ...menu.gantiBilaHanyaAtau };
        delete tanpaFitur.fitur;

        return tanpaFitur;
    };
}

export function SaringMenuTerlihat(
    akses: PropsBersamaAplikasi['Akses'],
    opsi: { sektorOutlet?: string[]; sederhana?: boolean } = {},
): MenuTerlihat[] {
    const CekBoleh = (menu: ItemMenu) =>
        (menu.izin === null ||
            PunyaIzinTenant(akses, menu.izin) ||
            (menu.atau !== undefined && PunyaIzinTenant(akses, menu.atau))) &&
        CekSesuaiSektor(menu, opsi.sektorOutlet ?? []) &&
        !(opsi.sederhana === true && menu.lanjutan === true);

    return daftarMenu.flatMap((menu): MenuTerlihat[] => {
        if (!CekBoleh(menu)) {
            return [];
        }

        if (!CekGrupMenu(menu)) {
            return [{ menu, labelSub: null, sub: [] }];
        }

        const sub = menu.sub.filter(CekBoleh).map(PilihTampilanItem(akses));
        const pertama = sub[0];

        return pertama === undefined ? [] : [{ menu: { ...menu, href: pertama.href }, labelSub: menu.labelSub, sub }];
    });
}

/**
 * Satu menu utama sidebar. Grup bersub-menu adalah tombol Collapsible: klik label membuka/menutup sub-menu dengan
 * animasi tinggi dan chevron memutar 90°. Saat sidebar diciutkan menjadi ikon (sub-menu tak terlihat), klik langsung
 * menuju sub-menu pertama. Grup halaman aktif terbuka sejak awal (tanpa animasi saat dimuat).
 */
function ItemMenuSidebar({
    menu,
    labelSub,
    sub,
    url,
    terkunci,
    saatTerkunci,
}: MenuTerlihat & { url: string; terkunci: FiturPaket['Terkunci']; saatTerkunci: (kunci: string) => void }) {
    const { state, isMobile } = useSidebar();
    const subAktif = labelSub === null ? null : CariSubMenuAktif(sub, url);
    const aktif = labelSub === null ? CekMenuAktif(menu.href, url) : subAktif !== null;
    const [terbuka, AturTerbuka] = useState(subAktif !== null);
    const ikon = menu.ikon ? <IkonNavigasi nama={menu.ikon} /> : null;

    if (labelSub === null) {
        return (
            // D-27: `pemisah` memberi garis di atas item, memisahkan kerja harian dari pengaturan & bantuan.
            <SidebarMenuItem className={menu.pemisah === true ? 'mt-2 border-t border-sidebar-border pt-2' : undefined}>
                <SidebarMenuButton asChild isActive={aktif} tooltip={menu.label} className={kelasTombolMenuSidebar}>
                    <Link href={menu.href} aria-current={aktif ? 'page' : undefined}>
                        {ikon}
                        <span>{menu.label}</span>
                    </Link>
                </SidebarMenuButton>
            </SidebarMenuItem>
        );
    }

    const TanganiKlikGrup = (peristiwa: MouseEvent<HTMLButtonElement>) => {
        if (state === 'collapsed' && !isMobile) {
            peristiwa.preventDefault();
            router.visit(menu.href);
        }
    };

    return (
        <Collapsible asChild open={terbuka} onOpenChange={AturTerbuka}>
            <SidebarMenuItem>
                <CollapsibleTrigger asChild onClick={TanganiKlikGrup}>
                    <SidebarMenuButton
                        isActive={aktif}
                        tooltip={menu.label}
                        className={cn(kelasTombolMenuSidebar, kelasTombolGrupSidebar)}
                    >
                        {ikon}
                        <span>{menu.label}</span>
                        <ChevronRightIcon aria-hidden="true" className={kelasChevronGrupSidebar} />
                    </SidebarMenuButton>
                </CollapsibleTrigger>
                <CollapsibleContent className="overflow-hidden data-[state=closed]:animate-collapsible-up data-[state=open]:animate-collapsible-down">
                    <nav aria-label={labelSub}>
                        <SidebarMenuSub className="mt-1 mb-1">
                            {sub.map((item) => {
                                const kunci = item.fitur !== undefined && item.fitur in terkunci ? item.fitur : null;

                                return (
                                    <SidebarMenuSubItem key={item.href}>
                                        <SidebarMenuSubButton
                                            asChild
                                            isActive={subAktif === item.href}
                                            className={kelasTombolSubMenuSidebar}
                                        >
                                            {kunci === null ? (
                                                <Link
                                                    href={item.href}
                                                    aria-current={subAktif === item.href ? 'page' : undefined}
                                                >
                                                    {item.label}
                                                </Link>
                                            ) : (
                                                <button
                                                    type="button"
                                                    aria-haspopup="dialog"
                                                    aria-label={`${item.label} (perlu naik paket)`}
                                                    onClick={() => saatTerkunci(kunci)}
                                                >
                                                    <span className="min-w-0 flex-1 truncate text-left">
                                                        {item.label}
                                                    </span>
                                                    <LockIcon aria-hidden="true" className="size-3.5 shrink-0" />
                                                </button>
                                            )}
                                        </SidebarMenuSubButton>
                                    </SidebarMenuSubItem>
                                );
                            })}
                        </SidebarMenuSub>
                    </nav>
                </CollapsibleContent>
            </SidebarMenuItem>
        </Collapsible>
    );
}

/** F-00: banner selama langganan Tertunggak (masa tenggang) atau Ditangguhkan (hanya lihat, export, bayar). */
function BannerLangganan({ tenant, bolehBayar }: { tenant: TenantAktif; bolehBayar: boolean }) {
    const ajakan = bolehBayar ? (
        <Link href="/kelola/langganan" className="font-semibold text-brand underline">
            Bayar tagihan di menu Langganan
        </Link>
    ) : (
        <span>Hubungi pemilik usaha untuk membayar tagihan.</span>
    );

    if (tenant.StatusLangganan === 'Tertunggak') {
        return (
            <Pemberitahuan jenis="peringatan" judul="Tagihan langganan belum dibayar">
                <p>
                    Periode langganan berakhir {FormatTanggalWaktu(tenant.PeriodeSelesai)}. Semua fitur tetap berjalan
                    {tenant.BatasTenggangPada ? ` sampai ${FormatTanggalWaktu(tenant.BatasTenggangPada)}` : ''}; setelah
                    itu langganan ditangguhkan dan data tidak bisa diubah.
                </p>
                <p className="mt-1">{ajakan}</p>
            </Pemberitahuan>
        );
    }

    if (tenant.StatusLangganan === 'Ditangguhkan') {
        return (
            <Pemberitahuan jenis="bahaya" judul="Langganan ditangguhkan">
                <p>
                    Anda masih bisa masuk, melihat data dan laporan, serta mengekspor data. Menambah atau mengubah data
                    dan berjualan di POS terkunci sampai tagihan dibayar.
                </p>
                <p className="mt-1">{ajakan}</p>
            </Pemberitahuan>
        );
    }

    return null;
}

const KunciModeSederhana = 'Navigasi.ModeSederhana';

/** Audit #33: pilihan "Mode sederhana" disimpan per peramban; bawaan mati. */
function BacaModeSederhana(): boolean {
    try {
        return window.localStorage.getItem(KunciModeSederhana) === '1';
    } catch {
        return false;
    }
}

/**
 * Tata letak back-office tenant (/kelola): bilah menu samping shadcn/ui berbasis izin (bisa diciutkan menjadi ikon,
 * menjadi Sheet di layar sempit), bilah atas dengan remah roti & menu akun, lalu banner status dan isi halaman.
 * Menu modul ditambahkan per flow (F-01 dst.).
 */
export default function TataLetakAplikasi({ judul, jejak = [], children }: PropsTataLetak) {
    const { props, url } = usePage<PropsBersamaAplikasi>();
    const tenantAktif = props.TenantAktif;
    const sektorOutlet = props.SektorOutlet ?? [];
    const [sederhana, AturSederhana] = useState(BacaModeSederhana);
    const menuTerlihat = SaringMenuTerlihat(props.Akses, { sektorOutlet, sederhana });
    // Ctrl+K tetap menemukan menu lanjutan walau mode sederhana aktif.
    const pencarian = SusunPencarian(
        SaringMenuTerlihat(props.Akses, { sektorOutlet }),
        props.Akses,
        props.Edisi,
        sektorOutlet,
    );
    const UbahSederhana = (aktif: boolean) => {
        AturSederhana(aktif);
        try {
            window.localStorage.setItem(KunciModeSederhana, aktif ? '1' : '0');
        } catch {
            // Penyimpanan peramban tidak tersedia: pilihan hanya berlaku di halaman ini.
        }
    };
    const namaInduk = tenantAktif?.Nama ?? props.NamaAplikasi;
    const [mengirim, AturMengirim] = useState(false);
    // D-23: fitur di luar paket (gembok di menu) dan dialog penawarannya.
    const terkunci = props.FiturPaket?.Terkunci ?? {};
    const [kunciPenawaran, AturKunciPenawaran] = useState<string | null>(null);
    const penawaran = kunciPenawaran === null ? undefined : terkunci[kunciPenawaran];
    const KirimUlangVerifikasi = () =>
        router.post(
            '/verifikasi-email/kirim-ulang',
            {},
            { preserveScroll: true, onStart: () => AturMengirim(true), onFinish: () => AturMengirim(false) },
        );

    return (
        <SidebarProvider defaultOpen={BacaSidebarTerbuka()}>
            <Head title={judul} />
            {kunciPenawaran !== null && penawaran ? (
                <DialogNaikPaket
                    kunci={kunciPenawaran}
                    penawaran={penawaran}
                    namaPaket={props.FiturPaket?.NamaPaket ?? null}
                    bolehKelola={PunyaIzinTenant(props.Akses, IzinTenant.LanggananKelola)}
                    saatTutup={() => AturKunciPenawaran(null)}
                />
            ) : null}
            <Sidebar collapsible="icon" className="border-sidebar-border">
                <KepalaSidebarMerek nama={props.NamaAplikasi} />
                <SidebarContent>
                    {tenantAktif && props.Akses ? (
                        <nav aria-label="Menu utama">
                            <SidebarGroup className="px-3 py-3">
                                <SidebarGroupContent>
                                    <SidebarMenu>
                                        {menuTerlihat.map((terlihat) => (
                                            <ItemMenuSidebar
                                                key={terlihat.menu.label}
                                                {...terlihat}
                                                url={url}
                                                terkunci={terkunci}
                                                saatTerkunci={AturKunciPenawaran}
                                            />
                                        ))}
                                    </SidebarMenu>
                                </SidebarGroupContent>
                            </SidebarGroup>
                        </nav>
                    ) : null}
                    {tenantAktif && props.Akses ? (
                        <div className="mt-auto px-4 pb-3 group-data-[collapsible=icon]:hidden">
                            <label className="flex cursor-pointer items-center gap-2 text-keterangan text-sidebar-foreground">
                                <input
                                    type="checkbox"
                                    checked={sederhana}
                                    onChange={(peristiwa) => UbahSederhana(peristiwa.target.checked)}
                                    className="size-4 accent-brand"
                                />
                                Mode sederhana (sembunyikan menu lanjutan)
                            </label>
                        </div>
                    ) : null}
                </SidebarContent>
                {/* D-27: Keamanan akun pindah ke menu akun di kanan atas, tempat orang mencarinya. */}
                {/* Rel hanya pintasan tetikus (tabIndex -1); tombol di bilah atas adalah kontrol yang diumumkan. */}
                <SidebarRail aria-hidden="true" aria-label={undefined} title="Buka atau tutup menu samping" />
            </Sidebar>
            <div data-slot="sidebar-inset" className="relative flex w-full min-w-0 flex-1 flex-col bg-latar">
                {/* D-27: remah roti pindah dari kepala ke atas judul halaman sebagai `JejakHalaman`. */}
                <KepalaTataLetak
                    induk={namaInduk}
                    judul={judul}
                    remah={false}
                    identitasUsaha={
                        tenantAktif
                            ? {
                                  nama: tenantAktif.Nama,
                                  tautanLogo: tenantAktif.TautanLogo,
                              }
                            : null
                    }
                >
                    <PencarianCepat halaman={pencarian.halaman} sumber={pencarian.sumber} />
                    <MenuAkun
                        nama={props.Pengguna?.Nama}
                        email={props.Pengguna?.Email}
                        tenant={tenantAktif}
                        bolehKelolaLangganan={PunyaIzinTenant(props.Akses, IzinTenant.LanggananKelola)}
                    />
                </KepalaTataLetak>
                <main className="mx-auto flex w-full max-w-6xl flex-col gap-4 px-4 py-6">
                    <JejakHalaman jejak={[...SusunJejak(url, namaInduk, menuTerlihat), ...jejak]} />
                    <JudulHalaman>{judul}</JudulHalaman>
                    {props.Pengguna && !props.Pengguna.EmailTerverifikasi ? (
                        <Pemberitahuan jenis="peringatan" judul="Verifikasi email Anda">
                            <p>
                                Kami mengirim tautan verifikasi ke {props.Pengguna.Email}. Buka tautan itu untuk
                                mengamankan akun. Emailnya salah ketik? Ganti di Keamanan akun.
                            </p>
                            <div className="mt-2 flex flex-wrap gap-2">
                                <Tombol varian="sekunder" memproses={mengirim} onClick={KirimUlangVerifikasi}>
                                    Kirim ulang tautan
                                </Tombol>
                                <Button
                                    asChild
                                    variant="outline"
                                    className="h-8 pointer-coarse:h-11 border-garis-input text-label font-semibold"
                                >
                                    <Link href="/kelola/keamanan#email">Ganti email</Link>
                                </Button>
                            </div>
                        </Pemberitahuan>
                    ) : null}
                    {/* P-10 PGL-19: pengumuman & jadwal pemeliharaan dari pengelola platform. */}
                    {props.PengumumanPlatform && props.PengumumanPlatform.length > 0 ? (
                        <BannerPengumuman pengumuman={props.PengumumanPlatform} />
                    ) : null}
                    {/* D-38: verifikasi dua langkah paket Bisnis ditunda selama trial, cukup pengingat. */}
                    {props.PengingatDuaFaktor ? (
                        <Pemberitahuan jenis="info" judul="Aktifkan verifikasi dua langkah">
                            Paket Bisnis mewajibkan verifikasi dua langkah untuk Pemilik, Admin, dan Akuntan. Selama
                            masa coba Anda masih bisa memakai semua menu; setelahnya menu dibuka setelah verifikasi
                            aktif.{' '}
                            <Link href="/kelola/keamanan" className="font-semibold text-brand underline">
                                Aktifkan sekarang
                            </Link>
                        </Pemberitahuan>
                    ) : null}
                    {/* BR-P06.5: pengumuman versi materiil dokumen legal selama masa pengumuman. */}
                    {props.PengumumanLegal.length > 0 ? (
                        <Pemberitahuan jenis="info" judul="Perubahan dokumen legal">
                            <ul className="flex flex-col gap-1">
                                {props.PengumumanLegal.map((pengumuman) => (
                                    <li key={`${pengumuman.Label}-${pengumuman.Versi}`}>
                                        {pengumuman.Label} versi {pengumuman.Versi} berlaku mulai{' '}
                                        {FormatTanggal(pengumuman.BerlakuMulai)}.{' '}
                                        <a href={pengumuman.Tautan} className="font-semibold text-brand underline">
                                            Baca perubahannya
                                        </a>
                                    </li>
                                ))}
                            </ul>
                            <p className="mt-1">Anda akan diminta menyetujuinya setelah tanggal berlaku.</p>
                        </Pemberitahuan>
                    ) : null}
                    {tenantAktif ? (
                        <BannerLangganan
                            tenant={tenantAktif}
                            bolehBayar={PunyaIzinTenant(props.Akses, IzinTenant.LanggananKelola)}
                        />
                    ) : null}
                    {children}
                </main>
            </div>
            <DialogHasil berhasil={props.Kilat} gagal={props.errors.Umum} penanda={props} />
            <PemberitahuanMelayang />
        </SidebarProvider>
    );
}
