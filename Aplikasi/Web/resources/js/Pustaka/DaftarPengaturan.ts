import { IzinTenant, type KunciIzinTenant } from '@/Tipe/Organisasi';

/**
 * Isi halaman Pengaturan (`/kelola/pengaturan`) — pengaturan **usaha secara keseluruhan** (profil, jenis usaha, outlet,
 * data, akses dukungan) dan **integrasi** (API, webhook, WhatsApp). Pengaturan yang melekat pada satu modul (kasir,
 * struk, stok, pembelian, loyalti, komisi, akun, dan sebagainya) tinggal di menu modulnya sebagai subgrup (D-253),
 * bukan di sini.
 *
 * D-27: halaman di sini tidak boleh ikut muncul di menu samping (dijaga `AnggaranNavigasiTes`), supaya satu halaman
 * hanya punya satu rumah. Semuanya tetap bisa dicari lewat Ctrl+K karena `SusunPencarian` ikut membaca daftar ini.
 *
 * `izin` null = semua anggota boleh. `fitur` = kunci fitur paket (D-23): di luar paket tetap tampil dengan gembok,
 * kliknya membuka dialog naik paket, sama seperti di menu samping.
 */
export type ButirPengaturan = {
    label: string;
    keterangan: string;
    href: string;
    izin: KunciIzinTenant | null;
    /** Izin alternatif: butir tampil bila pengguna punya `izin` ATAU `atau` (mis. laporan penjualan atau keuangan). */
    atau?: KunciIzinTenant;
    fitur?: string;
    /** D-35: butir yang rutenya hanya ada di satu edisi (misal integrasi server di edisi Lisensi). */
    edisi?: 'Saas' | 'Lisensi';
    /** D-48: awalan kode sektor outlet yang memakai butir ini (mis. `FNB`); tanpa = semua sektor. */
    sektor?: string[];
    /** D-167, D-183: disembunyikan bila SEMUA sektor usaha ada di daftar ini (mis. `BIZ`: usaha tanpa kasir). */
    kecualiSektor?: string[];
};

export type GrupPengaturan = { judul: string; butir: ButirPengaturan[] };

/**
 * Halaman milik akun pengguna yang rumahnya menu akun (avatar di kanan atas), bukan Pengaturan dan bukan menu samping
 * (D-27, penyesuaian v6.49). Tidak ditampilkan di halaman Pengaturan; hanya ikut pencarian cepat (Ctrl+K).
 */
export const butirAkun: ButirPengaturan[] = [
    {
        label: 'Langganan & tagihan',
        keterangan: 'Paket yang aktif, batas pemakaian, tagihan, dan bukti pembayaran.',
        href: '/kelola/langganan',
        izin: IzinTenant.LanggananKelola,
        // D-35: edisi Lisensi tidak berlangganan, jadi rutenya tidak ada di sana.
        edisi: 'Saas',
    },
    {
        label: 'Keamanan akun saya',
        keterangan: 'Kata sandi, verifikasi dua langkah, dan PIN kasir milik Anda sendiri.',
        href: '/kelola/keamanan',
        izin: null,
    },
];

/**
 * Pintasan "Paling sering dipakai" di atas halaman Pengaturan (butir yang paling sering dibuka pemilik setelah
 * usaha berjalan). Hanya pintasan: rumah butirnya tetap grup masing-masing, jadi tidak melanggar "satu rumah".
 */
export const pintasanPengaturan: string[] = ['/kelola/pengaturan/profil-usaha', '/kelola/outlet'];

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
                label: 'Pembaruan template usaha',
                keterangan:
                    'Lihat dan terapkan versi baru template jenis usaha: akun, kategori, satuan, dan fitur yang ditambahkan.',
                href: '/kelola/pengaturan/pembaruan-template',
                izin: IzinTenant.OutletKelola,
            },
            {
                label: 'Ganti jenis usaha',
                keterangan:
                    'Salah memilih jenis usaha saat mendaftar? Ganti di sini. Data bawaan lama yang belum dipakai dibersihkan, transaksi tidak berubah.',
                href: '/kelola/pengaturan/ganti-sektor',
                izin: IzinTenant.DataKelola,
            },
            {
                label: 'Outlet & gudang',
                keterangan: 'Cabang, gudang, area & meja, zona waktu, jam tutup buku, dan profil pajak per outlet.',
                href: '/kelola/outlet',
                izin: IzinTenant.OutletLihat,
            },
            {
                label: 'Data saya',
                keterangan: 'Unduh salinan lengkap data usaha Anda (UU PDP) dan, nanti, tutup akun usaha.',
                href: '/kelola/pengaturan/data-saya',
                izin: IzinTenant.DataKelola,
            },
            {
                label: 'Akses dukungan',
                keterangan:
                    'Izinkan tim Payoung membantu langsung di akun Anda untuk waktu terbatas. Anda bisa mencabutnya kapan saja dan melihat jejaknya.',
                href: '/kelola/pengaturan/akses-dukungan',
                izin: IzinTenant.DukunganAksesKelola,
                // PGL-16: tidak ada di edisi Lisensi (tanpa konsol Payoung).
                edisi: 'Saas',
            },
        ],
    },
    {
        judul: 'Integrasi & API',
        butir: [
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
                label: 'WhatsApp & kuota pesan',
                keterangan:
                    'Pantau kuota pesan WhatsApp bulan ini dan pasang nomor WhatsApp sendiri agar pesan tidak memakai kuota.',
                href: '/kelola/pengaturan/whatsapp',
                izin: IzinTenant.IntegrasiApiKelola,
                fitur: 'integrasi.whatsapp',
                // D-108: edisi Lisensi memakai halaman integrasi server di bawah.
                edisi: 'Saas',
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
