import { IzinTenant, type KunciIzinTenant } from '@/Tipe/Organisasi';

/**
 * Isi halaman Pengaturan (`/kelola/pengaturan`) — rumah semua halaman yang **diatur sekali lalu jarang disentuh**.
 *
 * D-27: halaman di sini tidak boleh ikut muncul di menu samping (dijaga `AnggaranNavigasiTes`), supaya satu halaman
 * hanya punya satu rumah dan menu harian tetap pendek. Semuanya tetap bisa dicari lewat Ctrl+K karena
 * `SusunPencarian` ikut membaca daftar ini.
 *
 * `izin` null = semua anggota boleh. `fitur` = kunci fitur paket (D-23): di luar paket tetap tampil dengan gembok,
 * kliknya membuka dialog naik paket, sama seperti di menu samping.
 */
export type ButirPengaturan = {
    label: string;
    keterangan: string;
    href: string;
    izin: KunciIzinTenant | null;
    fitur?: string;
    /** D-35: butir yang rutenya hanya ada di satu edisi (misal integrasi server di edisi Lisensi). */
    edisi?: 'Saas' | 'Lisensi';
    /** D-48: awalan kode sektor outlet yang memakai butir ini (mis. `FNB`); tanpa = semua sektor. */
    sektor?: string[];
};

export type GrupPengaturan = { judul: string; butir: ButirPengaturan[] };

export const daftarPengaturan: GrupPengaturan[] = [
    {
        judul: 'Usaha',
        butir: [
            {
                label: 'Profil usaha',
                keterangan: 'Nama usaha, logo, alamat, kota, NPWP, dan status PKP. Tampil di struk dan aplikasi kasir.',
                href: '/kelola/pengaturan/profil-usaha',
                izin: IzinTenant.OutletKelola,
            },
            {
                label: 'Outlet & gudang',
                keterangan: 'Cabang, gudang, area & meja, zona waktu, jam tutup buku, dan profil pajak per outlet.',
                href: '/kelola/outlet',
                izin: IzinTenant.OutletLihat,
            },
            {
                label: 'Langganan & tagihan',
                keterangan: 'Paket yang aktif, batas pemakaian, tagihan, dan bukti pembayaran.',
                href: '/kelola/langganan',
                izin: IzinTenant.LanggananKelola,
                // D-35: edisi Lisensi tidak berlangganan, jadi rutenya tidak ada di sana.
                edisi: 'Saas',
            },
        ],
    },
    {
        judul: 'Katalog & harga',
        butir: [
            {
                label: 'Satuan',
                keterangan: 'Satuan jual & beli beserta konversinya, misal dus ke pcs.',
                href: '/kelola/satuan',
                izin: IzinTenant.ProdukLihat,
            },
            {
                label: 'Pilihan (modifier)',
                keterangan: 'Kelompok pilihan seperti tingkat gula atau ukuran, beserta tambahan harganya.',
                href: '/kelola/kelompok-pilihan',
                izin: IzinTenant.ProdukLihat,
            },
            {
                label: 'Kelompok pajak',
                keterangan: 'Kelompok tarif pajak yang dipasang ke produk.',
                href: '/kelola/kelompok-pajak',
                izin: IzinTenant.ProdukLihat,
            },
            {
                label: 'Daftar harga',
                keterangan: 'Harga per tier pelanggan dan per kanal, misal harga GoFood atau GrabFood.',
                href: '/kelola/daftar-harga',
                izin: IzinTenant.ProdukLihat,
                fitur: 'harga.daftar-harga',
            },
            {
                label: 'Stasiun dapur',
                keterangan: 'Stasiun pembuatan pesanan dan tiket dapur yang dicetak untuk masing-masing.',
                href: '/kelola/stasiun-dapur',
                izin: IzinTenant.ProdukLihat,
                fitur: 'pos.kds',
                sektor: ['FNB'],
            },
            {
                label: 'Impor produk',
                keterangan: 'Unggah banyak produk sekaligus dari Excel/CSV, beserta riwayat imporannya.',
                href: '/kelola/produk/impor',
                izin: IzinTenant.ProdukKelola,
            },
        ],
    },
    {
        judul: 'Kasir & struk',
        butir: [
            {
                label: 'Pengaturan kasir',
                keterangan: 'Mode kasir, kembalian, pembulatan, diskon, dan perilaku layar jual.',
                href: '/kelola/kasir/pengaturan',
                izin: IzinTenant.OutletKelola,
            },
            {
                label: 'Pengaturan struk',
                keterangan: 'Isi struk, catatan kaki, logo, dan cetak otomatis. Satu pengaturan untuk semua outlet.',
                href: '/kelola/kasir/struk',
                izin: IzinTenant.OutletKelola,
            },
            {
                label: 'Kategori kas',
                keterangan: 'Kategori kas masuk & keluar yang bisa dipilih kasir saat shift berjalan.',
                href: '/kelola/kasir/kategori-kas',
                izin: IzinTenant.AkuntansiKelola,
            },
            {
                label: 'Metode pembayaran',
                keterangan: 'Tunai, QRIS, EDC, transfer, dan ojol yang tampil sebagai tombol di layar bayar kasir.',
                href: '/kelola/panduan-awal/metode-pembayaran',
                izin: IzinTenant.PanduanAwalKelola,
            },
            {
                label: 'Gerbang pembayaran',
                keterangan: 'Gerbang QRIS milik toko untuk pembayaran dinamis di kasir.',
                href: '/kelola/pembayaran/gerbang',
                izin: IzinTenant.PembayaranGerbangAtur,
            },
            {
                label: 'Aktivasi QRIS',
                keterangan:
                    'Daftarkan QRIS toko cukup dengan foto KTP, foto selfie, foto tempat usaha, dan nomor rekening.',
                href: '/kelola/pembayaran/aktivasi-qris',
                izin: IzinTenant.PembayaranGerbangAtur,
                // Butuh akun Partner DOKU milik Payoung, jadi rutenya tidak ada di edisi Lisensi.
                edisi: 'Saas',
            },
        ],
    },
    {
        judul: 'Stok & pembelian',
        butir: [
            {
                label: 'Pengaturan persediaan',
                keterangan: 'Metode harga pokok, stok minus, dan perilaku opname & penyesuaian.',
                href: '/kelola/persediaan/pengaturan',
                izin: IzinTenant.AkuntansiKelola,
            },
            {
                label: 'Stok awal',
                keterangan: 'Saldo stok pembuka per produk & lokasi, diposting sekali saat mulai memakai Payoung.',
                href: '/kelola/persediaan/stok-awal',
                izin: IzinTenant.PersediaanLihat,
            },
            {
                label: 'Impor stok awal',
                keterangan: 'Unggah stok awal banyak produk sekaligus dari Excel/CSV.',
                href: '/kelola/persediaan/stok-awal/impor',
                izin: IzinTenant.PersediaanKelola,
            },
            {
                label: 'Pengaturan pembelian',
                keterangan: 'Persetujuan pesanan pembelian, toleransi penerimaan, dan draf PO otomatis.',
                href: '/kelola/pembelian/pengaturan',
                izin: IzinTenant.PembelianPoSetujui,
            },
        ],
    },
    {
        judul: 'Pelanggan',
        butir: [
            {
                label: 'Tier pelanggan',
                keterangan: 'Tingkatan pelanggan dan harga khusus per tier.',
                href: '/kelola/pelanggan/tier',
                izin: IzinTenant.PelangganLihat,
                fitur: 'pelanggan.loyalti',
            },
            {
                label: 'Pengaturan loyalti',
                keterangan: 'Perolehan poin, masa berlaku, dan penukaran poin sebagai diskon.',
                href: '/kelola/pelanggan/loyalti',
                izin: IzinTenant.PelangganLihat,
                fitur: 'pelanggan.loyalti',
            },
        ],
    },
    {
        judul: 'Karyawan',
        butir: [
            {
                label: 'Aturan komisi',
                keterangan: 'Dasar perhitungan komisi per staf pelayan atau per produk.',
                href: '/kelola/karyawan/komisi',
                izin: IzinTenant.KaryawanLihat,
                fitur: 'karyawan.komisi',
            },
            {
                label: 'Aturan kehadiran',
                keterangan:
                    'Wajib berjadwal saat absen, toleransi terlambat & pulang cepat, ambang lembur, serta pengingat shift dan peringatan ke pengelola lewat WhatsApp.',
                href: '/kelola/karyawan/aturan-kehadiran',
                izin: IzinTenant.KaryawanLihat,
            },
        ],
    },
    {
        judul: 'Akuntansi',
        butir: [
            {
                label: 'Bagan akun',
                keterangan: 'Daftar akun (COA) beserta nama dan statusnya.',
                href: '/kelola/akuntansi/akun',
                izin: IzinTenant.LaporanKeuanganLihat,
                fitur: 'akuntansi.penuh',
            },
            {
                label: 'Pemetaan akun',
                keterangan: 'Akun yang dipakai jurnal otomatis untuk penjualan, stok, pajak, dan kas.',
                href: '/kelola/akuntansi/pemetaan',
                izin: IzinTenant.LaporanKeuanganLihat,
                fitur: 'akuntansi.penuh',
            },
        ],
    },
    {
        judul: 'Akses & keamanan',
        butir: [
            {
                label: 'Peran & izin',
                keterangan: 'Izin tiap peran. Anggota tim, akses masuk, dan PIN kasir ada di menu Tim.',
                href: '/kelola/peran',
                izin: IzinTenant.PenggunaLihat,
            },
            {
                label: 'Perangkat kasir',
                keterangan: 'Perangkat terdaftar, kode aktivasi, pencabutan, dan profil hardware.',
                href: '/kelola/perangkat',
                izin: IzinTenant.PerangkatLihat,
            },
            {
                label: 'Keamanan akun saya',
                keterangan: 'Kata sandi, verifikasi dua langkah, dan PIN kasir milik Anda sendiri.',
                href: '/kelola/keamanan',
                izin: null,
            },
            {
                label: 'Log audit',
                keterangan: 'Riwayat perubahan penting: siapa, apa, kapan, dari perangkat mana.',
                href: '/kelola/log-audit',
                izin: IzinTenant.AuditLihat,
            },
            {
                label: 'Token API',
                keterangan: 'Token untuk aplikasi lain membaca produk, stok, penjualan, atau pelanggan lewat API.',
                href: '/kelola/pengaturan/api',
                izin: IzinTenant.IntegrasiApiKelola,
                fitur: 'api.publik',
            },
            {
                label: 'Webhook',
                keterangan:
                    'Kirim pemberitahuan otomatis ke aplikasi lain saat penjualan selesai, di-void, atau diretur.',
                href: '/kelola/pengaturan/webhook',
                izin: IzinTenant.IntegrasiApiKelola,
                fitur: 'api.publik',
            },
            {
                label: 'Email & WhatsApp server',
                keterangan:
                    'Akun email, WhatsApp, dan penyimpanan berkas milik toko untuk server ini (edisi pasang sendiri).',
                href: '/kelola/pengaturan/integrasi-server',
                izin: IzinTenant.IntegrasiApiKelola,
                edisi: 'Lisensi',
            },
        ],
    },
];

/**
 * Butir pengaturan yang boleh dilihat dan cocok dengan kata cari, tetap terkelompok; grup tanpa hasil dibuang.
 *
 * Pencocokan mencakup nama grup, label, dan keterangannya sekaligus, jadi mengetik "pajak" juga menemukan butir
 * yang hanya menyebut pajak di keterangannya. Fungsi murni supaya bisa diuji tanpa merender halaman.
 */
/** D-35: butir tanpa `edisi` tampil di semua edisi; yang bertanda hanya di edisinya (rutenya tidak ada di edisi lain). */
export function CekButirSesuaiEdisi(butir: ButirPengaturan, edisi: 'Saas' | 'Lisensi' | undefined): boolean {
    return butir.edisi === undefined || butir.edisi === (edisi ?? 'Saas');
}

export function SaringPengaturan(kata: string, BolehLihat: (butir: ButirPengaturan) => boolean): GrupPengaturan[] {
    const cari = kata.trim().toLowerCase();

    return daftarPengaturan
        .map((grup) => ({
            ...grup,
            butir: grup.butir.filter(
                (butir) =>
                    BolehLihat(butir) &&
                    (cari === '' || `${grup.judul} ${butir.label} ${butir.keterangan}`.toLowerCase().includes(cari)),
            ),
        }))
        .filter((grup) => grup.butir.length > 0);
}
