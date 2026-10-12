import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import type { PropsBersamaAplikasi, TenantAktif } from '@/Tipe/Aplikasi';

import TataLetakAplikasi, {
    CariMenuProdukAktif,
    CariSubMenuAktif,
    CekMenuAktif,
    SaringMenuTerlihat,
} from './TataLetakAplikasi';

let propsHalaman: PropsBersamaAplikasi;
let urlHalaman = '/kelola';

const tiruanRouter = vi.hoisted(() => ({ post: vi.fn(), visit: vi.fn() }));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ href, children, ...sisa }: { href: string; children: ReactNode }) => (
        <a href={href} {...sisa}>
            {children}
        </a>
    ),
    router: tiruanRouter,
    usePage: () => ({ props: propsHalaman, url: urlHalaman }),
}));

function BuatProps(tenant: Partial<TenantAktif> | null, izin: string[], pemilik = false): PropsBersamaAplikasi {
    return {
        NamaAplikasi: 'Kasir',
        Kilat: null,
        Pengguna: { Uuid: '01J', Nama: 'Rina Wulandari', Email: 'rina@kopinusantara.id', EmailTerverifikasi: true },
        TenantAktif:
            tenant === null
                ? null
                : {
                      Nama: 'Kopi Nusantara',
                      StatusLangganan: 'Aktif',
                      PeriodeSelesai: null,
                      BatasTenggangPada: null,
                      ...tenant,
                  },
        PengumumanLegal: [],
        Akses: tenant === null ? null : { Pemilik: pemilik, Izin: izin },
        errors: {},
    };
}

describe('TataLetakAplikasi: menu berbasis izin & banner langganan (F-00, §19.1)', () => {
    beforeEach(() => {
        propsHalaman = BuatProps({}, []);
        urlHalaman = '/kelola';
    });

    afterEach(() => {
        cleanup();
        tiruanRouter.post.mockReset();
        tiruanRouter.visit.mockReset();
        // Status ciut bilah samping disimpan SidebarProvider di cookie; jangan bocor ke test berikutnya.
        document.cookie = 'sidebar_state=; path=/; max-age=0';
    });

    it('Pemilik melihat menu Pengaturan dan Bantuan; Langganan kini rumahnya di Pengaturan (D-27)', () => {
        propsHalaman = BuatProps({}, [], true);
        render(<TataLetakAplikasi judul="Beranda">isi</TataLetakAplikasi>);

        expect(screen.getByRole('link', { name: 'Pengaturan' }).getAttribute('href')).toBe('/kelola/pengaturan');
        expect(screen.getByRole('link', { name: 'Bantuan' }).getAttribute('href')).toBe('/kelola/bantuan');
        // D-27: Langganan, Outlet, Pengguna, Perangkat, dan Log audit keluar dari menu samping.
        expect(screen.queryByRole('link', { name: 'Langganan' })).toBeNull();
        expect(screen.queryByRole('link', { name: 'Outlet' })).toBeNull();
        expect(screen.queryByRole('link', { name: 'Peran & izin' })).toBeNull();
    });

    it('Kasir tanpa izin tidak melihat Bantuan, tetapi Pengaturan tetap ada', () => {
        propsHalaman = BuatProps({}, ['produk.lihat', 'penjualan.buat']);
        render(<TataLetakAplikasi judul="Beranda">isi</TataLetakAplikasi>);

        expect(screen.queryByRole('link', { name: 'Bantuan' })).toBeNull();
        // Isi halaman Pengaturan disaring per butir, jadi menunya sendiri terbuka untuk semua anggota.
        expect(screen.getByRole('link', { name: 'Pengaturan' })).toBeTruthy();
    });

    it('Keamanan akun ada di menu akun kanan atas, bukan di menu samping (D-27)', () => {
        propsHalaman = BuatProps({}, [], true);
        render(<TataLetakAplikasi judul="Beranda">isi</TataLetakAplikasi>);

        const utama = screen.getByRole('navigation', { name: 'Menu utama' });
        expect(within(utama).queryByRole('link', { name: 'Keamanan akun' })).toBeNull();

        // Radix membuka dropdown lewat keyboard/pointer, bukan click biasa (sama seperti test menu akun di bawah).
        fireEvent.keyDown(screen.getByRole('button', { name: /Menu akun/ }), { key: 'Enter' });
        const menu = screen.getByRole('menu');
        expect(within(menu).getByRole('menuitem', { name: 'Keamanan akun' }).getAttribute('href')).toBe(
            '/kelola/keamanan',
        );
    });

    it('tanpa tenant aktif, menu tenant disembunyikan', () => {
        propsHalaman = BuatProps(null, []);
        render(<TataLetakAplikasi judul="Keamanan akun">isi</TataLetakAplikasi>);

        expect(screen.queryByRole('navigation', { name: 'Menu utama' })).toBeNull();
    });

    it('Tertunggak: banner menyebut batas masa tenggang dan tautan bayar untuk pemegang langganan.kelola', () => {
        propsHalaman = BuatProps(
            {
                StatusLangganan: 'Tertunggak',
                PeriodeSelesai: '2026-09-19T17:00:00Z',
                BatasTenggangPada: '2026-09-26T17:00:00Z',
            },
            [],
            true,
        );
        render(<TataLetakAplikasi judul="Beranda">isi</TataLetakAplikasi>);

        expect(screen.getByText('Tagihan langganan belum dibayar')).toBeTruthy();
        expect(screen.getByText(/sampai 27 Sep 2026/)).toBeTruthy();
        expect(screen.getByRole('link', { name: 'Bayar tagihan di menu Langganan' })).toBeTruthy();
    });

    it('Ditangguhkan: anggota tanpa langganan.kelola diminta menghubungi pemilik usaha', () => {
        propsHalaman = BuatProps({ StatusLangganan: 'Ditangguhkan' }, ['outlet.lihat']);
        render(<TataLetakAplikasi judul="Beranda">isi</TataLetakAplikasi>);

        expect(screen.getByText('Langganan ditangguhkan')).toBeTruthy();
        expect(screen.getByText('Hubungi pemilik usaha untuk membayar tagihan.')).toBeTruthy();
        expect(screen.queryByRole('link', { name: 'Bayar tagihan di menu Langganan' })).toBeNull();
    });

    it('F-03: grup menu Produk tampil sebagai sub-menu berisi kerja katalog harian', () => {
        propsHalaman = BuatProps({}, ['produk.lihat']);
        urlHalaman = '/kelola/kategori';
        render(<TataLetakAplikasi judul="Kategori">isi</TataLetakAplikasi>);

        const utama = screen.getByRole('navigation', { name: 'Menu utama' });
        const grupProduk = within(utama).getByRole('button', { name: 'Produk' });
        expect(grupProduk.getAttribute('data-active')).toBe('true');
        expect(grupProduk.getAttribute('aria-expanded')).toBe('true');
        const sub = screen.getByRole('navigation', { name: 'Menu produk' });
        // D-27: Satuan, Daftar harga, Pilihan, Kelompok pajak, dan Stasiun dapur pindah ke Pengaturan.
        expect(Array.from(sub.querySelectorAll('a')).map((a) => a.textContent)).toEqual([
            'Produk',
            'Kategori',
            'Paket sesi',
        ]);
        expect(sub.querySelector('a[aria-current="page"]')?.textContent).toBe('Kategori');
    });

    it('F-03: sub-menu memilih awalan terpanjang dan tersembunyi di luar grup Produk', () => {
        expect(CariMenuProdukAktif('/kelola/produk/01J9/harga')).toBe('/kelola/produk');
        expect(CariMenuProdukAktif('/kelola/produk?kata=kopi')).toBe('/kelola/produk');
        // D-27: Impor produk pindah ke Pengaturan, jadi jalurnya kembali ke sub-menu Produk.
        expect(CariMenuProdukAktif('/kelola/produk/impor/01J9?x=1')).toBe('/kelola/produk');
        expect(CariMenuProdukAktif('/kelola/produk-lain')).toBeNull();
        expect(CariMenuProdukAktif('/kelola/outlet')).toBeNull();

        // Awalan terpanjang diuji pada pasangan bersarang yang masih ada di menu: Promo vs Klaim promo pemasok.
        const pelanggan = SaringMenuTerlihat({ Pemilik: true, Izin: [] }).find(
            ({ menu }) => menu.label === 'Pelanggan',
        );
        expect(CariSubMenuAktif(pelanggan?.sub ?? [], '/kelola/promo/klaim-pemasok?dari=2026-01-01')).toBe(
            '/kelola/promo/klaim-pemasok',
        );
        expect(CariSubMenuAktif(pelanggan?.sub ?? [], '/kelola/promo/01J9')).toBe('/kelola/promo');

        propsHalaman = BuatProps({}, ['produk.lihat']);
        render(<TataLetakAplikasi judul="Beranda">isi</TataLetakAplikasi>);
        expect(screen.queryByRole('navigation', { name: 'Menu produk' })).toBeNull();
    });

    it('Staf Gudang (pengiriman.kelola tanpa toko-online.kelola) menjangkau halaman Pengiriman; Pemilik tidak melihat menu ganda', () => {
        const SubPenjualan = (menu: ReturnType<typeof SaringMenuTerlihat>) =>
            (menu.find(({ menu: m }) => m.label === 'Penjualan & kasir')?.sub ?? []).map((m) => m.label);

        const gudang = SaringMenuTerlihat({ Pemilik: false, Izin: ['pengiriman.kelola', 'laporan.penjualan.lihat'] });
        expect(SubPenjualan(gudang)).toContain('Pengiriman');
        expect(SubPenjualan(gudang)).not.toContain('Toko online & pengiriman');

        const pemilik = SaringMenuTerlihat({ Pemilik: true, Izin: [] });
        expect(SubPenjualan(pemilik)).toContain('Toko online & pengiriman');
        expect(SubPenjualan(pemilik)).not.toContain('Pengiriman');
    });

    it('audit #33: menu khusus sektor disaring sektor outlet; mode sederhana menyembunyikan menu lanjutan', () => {
        const akses = { Pemilik: true, Izin: [] };
        const Label = (menu: ReturnType<typeof SaringMenuTerlihat>) => menu.map(({ menu: m }) => m.label);
        const SubPenjualan = (menu: ReturnType<typeof SaringMenuTerlihat>) =>
            (menu.find(({ menu: m }) => m.label === 'Penjualan & kasir')?.sub ?? []).map((m) => m.label);

        // Sektor belum diketahui: semua tampil (perilaku lama).
        expect(Label(SaringMenuTerlihat(akses))).toContain('Grosir');
        // Kafe saja: grosir, laundry, reservasi tersembunyi.
        const kafe = SaringMenuTerlihat(akses, { sektorOutlet: ['FNB-CAF'] });
        expect(Label(kafe)).not.toContain('Grosir');
        expect(SubPenjualan(kafe)).not.toContain('Laundry');
        expect(SubPenjualan(kafe)).not.toContain('Reservasi');
        // Distributor + laundry: grosir & laundry tampil.
        const campur = SaringMenuTerlihat(akses, { sektorOutlet: ['WHS-DST', 'SVC-LDR'] });
        expect(Label(campur)).toContain('Grosir');
        expect(SubPenjualan(campur)).toContain('Laundry');
        expect(SubPenjualan(campur)).toContain('Reservasi');
        // Mode sederhana.
        const sederhana = SaringMenuTerlihat(akses, { sederhana: true });
        expect(Label(sederhana)).not.toContain('Akuntansi');
        expect(Label(sederhana)).not.toContain('Grosir');
        expect(SubPenjualan(sederhana)).not.toContain('Tutup harian');
        expect(Label(sederhana)).toContain('Produk');
    });

    it('klik label grup membuka/menutup sub-menu tanpa pindah halaman; chevron memutar saat terbuka', () => {
        propsHalaman = BuatProps({}, ['produk.lihat']);
        render(<TataLetakAplikasi judul="Beranda">isi</TataLetakAplikasi>);

        const grup = screen.getByRole('button', { name: 'Produk' });
        expect(grup.getAttribute('aria-expanded')).toBe('false');
        expect(screen.queryByRole('navigation', { name: 'Menu produk' })).toBeNull();

        fireEvent.click(grup);
        expect(grup.getAttribute('aria-expanded')).toBe('true');
        expect(grup.getAttribute('data-state')).toBe('open');
        expect(grup.querySelector('svg:last-child')?.getAttribute('class')).toContain(
            'group-data-[state=open]/grup:rotate-90',
        );
        expect(screen.getByRole('navigation', { name: 'Menu produk' })).toBeTruthy();
        expect(tiruanRouter.visit).not.toHaveBeenCalled();

        fireEvent.click(grup);
        expect(grup.getAttribute('aria-expanded')).toBe('false');
    });

    it('saat sidebar diciutkan menjadi ikon, klik grup langsung menuju sub-menu pertama yang boleh', () => {
        document.cookie = 'sidebar_state=false; path=/';
        propsHalaman = BuatProps({}, ['produk.lihat']);
        render(<TataLetakAplikasi judul="Beranda">isi</TataLetakAplikasi>);

        fireEvent.click(screen.getByRole('button', { name: 'Produk' }));
        expect(tiruanRouter.visit).toHaveBeenCalledWith('/kelola/produk');
    });

    it('F-05a: grup Persediaan untuk persediaan.lihat berisi kerja stok harian saja', () => {
        propsHalaman = BuatProps({}, ['persediaan.lihat']);
        urlHalaman = '/kelola/persediaan/kartu-stok?produk=01J9ZC5V7Q8R2T4W6Y8A0B2C4D';
        render(<TataLetakAplikasi judul="Kartu stok">isi</TataLetakAplikasi>);

        const utama = screen.getByRole('navigation', { name: 'Menu utama' });
        const induk = within(utama).getByRole('button', { name: 'Persediaan' });
        expect(induk.getAttribute('data-active')).toBe('true');
        expect(induk.getAttribute('aria-expanded')).toBe('true');
        expect(SaringMenuTerlihat(propsHalaman.Akses).find(({ menu }) => menu.label === 'Persediaan')?.menu.href).toBe(
            '/kelola/persediaan/saldo',
        );
        const sub = screen.getByRole('navigation', { name: 'Menu persediaan' });
        // D-27: stok awal, impornya, dan pengaturan persediaan pindah ke Pengaturan (kegiatan menyiapkan toko).
        expect(Array.from(sub.querySelectorAll('a')).map((a) => a.textContent)).toEqual([
            'Saldo stok',
            'Kartu stok',
            'Transfer stok',
            'Stok opname',
            'Penyesuaian stok',
            'Produksi',
            'Bahan terbuang',
        ]);
        expect(sub.querySelector('a[aria-current="page"]')?.textContent).toBe('Kartu stok');
        expect(within(utama).queryByRole('button', { name: 'Akuntansi' })).toBeNull();
    });

    it('D-27: halaman penyiapan stok tidak lagi di menu Persediaan meski izinnya lengkap', () => {
        propsHalaman = BuatProps({}, ['persediaan.lihat', 'persediaan.kelola', 'akuntansi.kelola']);
        urlHalaman = '/kelola/persediaan/saldo';
        render(<TataLetakAplikasi judul="Saldo stok">isi</TataLetakAplikasi>);

        const sub = screen.getByRole('navigation', { name: 'Menu persediaan' });
        const alamat = Array.from(sub.querySelectorAll('a')).map((a) => a.getAttribute('href'));

        expect(alamat).not.toContain('/kelola/persediaan/stok-awal');
        expect(alamat).not.toContain('/kelola/persediaan/stok-awal/impor');
        expect(alamat).not.toContain('/kelola/persediaan/pengaturan');
        expect(alamat).toHaveLength(7);
    });

    it('D-27: menu Pengaturan menyala saat membuka halaman yang rumahnya di sana', () => {
        propsHalaman = BuatProps({}, ['akuntansi.kelola']);
        urlHalaman = '/kelola/persediaan/pengaturan';
        render(<TataLetakAplikasi judul="Pengaturan persediaan">isi</TataLetakAplikasi>);

        const utama = screen.getByRole('navigation', { name: 'Menu utama' });
        // Grup Persediaan tidak lagi punya sub-menu untuk izin ini, jadi grupnya tidak tampil.
        expect(within(utama).queryByRole('button', { name: 'Persediaan' })).toBeNull();
        expect(within(utama).getByRole('link', { name: 'Pengaturan' }).getAttribute('aria-current')).toBe('page');
        expect(CekMenuAktif('/kelola/pengaturan', '/kelola/peran/01J9')).toBe(true);
        expect(CekMenuAktif('/kelola/pengaturan', '/kelola/keamanan/pin')).toBe(true);
        expect(CekMenuAktif('/kelola/pengaturan', '/kelola/produk')).toBe(false);
    });

    it('F-05a: menu Akuntansi › Jurnal hanya untuk laporan.keuangan.lihat', () => {
        propsHalaman = BuatProps({}, ['laporan.keuangan.lihat']);
        urlHalaman = '/kelola/akuntansi/jurnal/01J9ZC5V7Q8R2T4W6Y8A0B2C4D';
        render(<TataLetakAplikasi judul="Jurnal">isi</TataLetakAplikasi>);

        const utama = screen.getByRole('navigation', { name: 'Menu utama' });
        expect(within(utama).getByRole('button', { name: 'Akuntansi' }).getAttribute('data-active')).toBe('true');
        expect(SaringMenuTerlihat(propsHalaman.Akses).find(({ menu }) => menu.label === 'Akuntansi')?.menu.href).toBe(
            '/kelola/akuntansi/jurnal',
        );
        expect(within(utama).queryByRole('button', { name: 'Persediaan' })).toBeNull();
        const sub = screen.getByRole('navigation', { name: 'Menu akuntansi' });
        expect(within(sub).getByRole('link', { name: 'Jurnal' }).getAttribute('aria-current')).toBe('page');
        cleanup();

        propsHalaman = BuatProps({}, ['produk.lihat', 'persediaan.lihat']);
        urlHalaman = '/kelola';
        render(<TataLetakAplikasi judul="Beranda">isi</TataLetakAplikasi>);
        expect(screen.queryByRole('button', { name: 'Akuntansi' })).toBeNull();
        expect(screen.queryByRole('navigation', { name: 'Menu persediaan' })).toBeNull();
    });

    it('F-05a: Pemilik melihat seluruh menu; urutannya dari yang paling sering dipakai (D-27)', () => {
        // D-27: 13 entri sejak grosir (v2.78), diurutkan per frekuensi pakai. Outlet, Perangkat, Pengguna & peran,
        // Log audit, dan Langganan pindah ke Pengaturan; "Shift & kas" digabung ke "Penjualan & kasir".
        expect(SaringMenuTerlihat({ Pemilik: true, Izin: [] }).map(({ menu }) => menu.label)).toEqual([
            'Beranda',
            'Kotak tindakan',
            'Penjualan & kasir',
            // F-14a: laporan yang dibaca pemilik, termasuk laporan keuangan.
            'Laporan',
            'Persediaan',
            'Produk',
            // F-04: pembelian & hutang pemasok.
            'Pembelian',
            // F-12 §9.7: grosir (pesanan, surat jalan, faktur penjualan) — cermin Pembelian di sisi jual.
            'Grosir',
            // F-16a: data pelanggan.
            'Pelanggan',
            'Tim',
            'Akuntansi',
            'Pengaturan',
            'Bantuan',
        ]);
        // Kotak tindakan (D-23 C) dan Pengaturan (F-01) tampil untuk semua; butirnya disaring izin di halamannya.
        expect(SaringMenuTerlihat({ Pemilik: false, Izin: [] }).map(({ menu }) => menu.label)).toEqual([
            'Beranda',
            'Kotak tindakan',
            'Pengaturan',
        ]);
        expect(CekMenuAktif('/kelola/persediaan/saldo', '/kelola/persediaan/kartu-stok?produk=01J9')).toBe(true);
        expect(CekMenuAktif('/kelola/persediaan/saldo', '/kelola/produk')).toBe(false);
        expect(CekMenuAktif('/kelola/akuntansi/jurnal', '/kelola/akuntansi/jurnal/01J9')).toBe(true);
        // F-04: grup Pembelian tampil untuk pembelian.kelola; pengaturan pembelian hanya untuk pembelian.po.setujui.
        expect(
            SaringMenuTerlihat({ Pemilik: false, Izin: ['pembelian.kelola'] }).map(({ menu }) => menu.label),
        ).toEqual(['Beranda', 'Kotak tindakan', 'Pembelian', 'Pengaturan']);
        expect(CekMenuAktif('/kelola/pembelian/pesanan', '/kelola/pembelian/faktur/01J9')).toBe(true);
        // F-07b/F-06: menu "Penjualan & kasir" (termasuk shift & tutup harian) ikut izin laporan.penjualan.lihat.
        expect(
            SaringMenuTerlihat({ Pemilik: false, Izin: ['laporan.penjualan.lihat'] }).map(({ menu }) => menu.label),
        ).toEqual(['Beranda', 'Kotak tindakan', 'Penjualan & kasir', 'Laporan', 'Pengaturan']);
        expect(CekMenuAktif('/kelola/penjualan', '/kelola/penjualan/01J9')).toBe(true);
        // F-16a: menu Pelanggan ikut izin pelanggan.lihat.
        expect(SaringMenuTerlihat({ Pemilik: false, Izin: ['pelanggan.lihat'] }).map(({ menu }) => menu.label)).toEqual(
            ['Beranda', 'Kotak tindakan', 'Pelanggan', 'Pengaturan'],
        );
        // F-14a: grup "Laporan" hanya berisi laporan yang boleh dibuka; tautannya = sub-menu pertama yang boleh.
        const laporanStok = SaringMenuTerlihat({ Pemilik: false, Izin: ['persediaan.lihat'] }).find(
            ({ menu }) => menu.label === 'Laporan',
        );
        expect(laporanStok?.sub.map((m) => m.label)).toEqual(['Laporan stok']);
        expect(CekMenuAktif('/kelola/laporan/penjualan', '/kelola/laporan/pajak?dari=2026-10-01')).toBe(true);
    });

    it('Pengaturan aktif di /kelola/peran; aria-current hanya pada satu menu utama (D-27)', () => {
        propsHalaman = BuatProps({}, ['pengguna.lihat', 'outlet.lihat']);
        urlHalaman = '/kelola/peran/01J9';
        render(<TataLetakAplikasi judul="Peran">isi</TataLetakAplikasi>);

        const utama = screen.getByRole('navigation', { name: 'Menu utama' });
        const aktif = Array.from(utama.querySelectorAll('a[aria-current="page"]'));
        expect(aktif.map((a) => a.textContent)).toEqual(['Pengaturan']);
        expect(CekMenuAktif('/kelola', '/kelola/outlet')).toBe(false);
    });

    it('bilah samping bisa diciutkan lewat tombol di bilah atas; remah roti menyebut usaha dan halaman', () => {
        propsHalaman = BuatProps({}, ['outlet.lihat']);
        urlHalaman = '/kelola/outlet';
        const { container } = render(<TataLetakAplikasi judul="Outlet">isi</TataLetakAplikasi>);

        const sidebar = container.querySelector('[data-slot="sidebar"]');
        expect(sidebar?.getAttribute('data-state')).toBe('expanded');

        fireEvent.click(screen.getByRole('button', { name: 'Buka atau tutup menu samping' }));

        expect(sidebar?.getAttribute('data-state')).toBe('collapsed');
        expect(sidebar?.getAttribute('data-collapsible')).toBe('icon');

        // D-27: remah roti tidak lagi di kepala halaman, tetapi di atas judul di dalam <main>.
        expect(screen.queryByRole('navigation', { name: 'Remah roti' })).toBeNull();
        const jejak = screen.getByRole('navigation', { name: 'Jejak halaman' });
        expect(within(jejak).getByText('Kopi Nusantara')).toBeTruthy();
        expect(screen.getByRole('main').contains(jejak)).toBe(true);
        expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('Outlet');
        expect(screen.getByRole('main').textContent).toContain('isi');
    });

    it('D-27: jejak halaman menunjukkan letak & jalan kembali ke Pengaturan', () => {
        propsHalaman = BuatProps({}, ['produk.lihat']);
        urlHalaman = '/kelola/satuan';
        render(<TataLetakAplikasi judul="Satuan">isi</TataLetakAplikasi>);

        const jejak = screen.getByRole('navigation', { name: 'Jejak halaman' });
        expect(within(jejak).getByRole('link', { name: 'Pengaturan' }).getAttribute('href')).toBe('/kelola/pengaturan');
        expect(within(jejak).getByText('Katalog & harga')).toBeTruthy();
        // Halaman saat ini tidak diulang di jejak karena sudah menjadi <h1>.
        expect(within(jejak).queryByText('Satuan')).toBeNull();
        expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('Satuan');
    });

    it('D-27: halaman di dalam grup menu menampilkan nama grupnya; /kelola/peran ikut Pengaturan', () => {
        propsHalaman = BuatProps({}, ['persediaan.lihat']);
        urlHalaman = '/kelola/persediaan/opname';
        render(<TataLetakAplikasi judul="Stok opname">isi</TataLetakAplikasi>);
        expect(within(screen.getByRole('navigation', { name: 'Jejak halaman' })).getByText('Persediaan')).toBeTruthy();
        cleanup();

        propsHalaman = BuatProps({}, ['pengguna.lihat']);
        urlHalaman = '/kelola/peran/01J9';
        render(<TataLetakAplikasi judul="Peran">isi</TataLetakAplikasi>);
        const jejakPeran = screen.getByRole('navigation', { name: 'Jejak halaman' });
        expect(within(jejakPeran).getByRole('link', { name: 'Pengaturan' })).toBeTruthy();
        expect(within(jejakPeran).getByText('Akses & keamanan')).toBeTruthy();
    });

    it('kepala sidebar memakai gradasi merek dan logo putih, bukan nama pelanggan', () => {
        const { container } = render(<TataLetakAplikasi judul="Beranda">isi</TataLetakAplikasi>);

        const kepala = container.querySelector('[data-slot="sidebar-header"]');
        expect(kepala?.className).toContain('bg-linear-to-br');
        expect(kepala?.className).toContain('from-brand-gelap');
        expect(kepala?.className).toContain('to-brand');
        expect(kepala?.querySelector('img[src*="LogoHorizontalPutih.webp"]')).toBeTruthy();
        expect(kepala?.querySelector('img[src*="IkonMerekPutih.png"]')).toBeTruthy();
        expect(kepala?.textContent).not.toContain('Kopi Nusantara');
    });

    it('menu aktif di sidebar gelap merek memakai latar Brand dengan teks putih tebal', () => {
        const { container } = render(<TataLetakAplikasi judul="Beranda">isi</TataLetakAplikasi>);

        const utama = screen.getByRole('navigation', { name: 'Menu utama' });
        const aktif = within(utama).getByRole('link', { name: 'Beranda' });
        expect(aktif.getAttribute('data-active')).toBe('true');
        expect(aktif.className).toContain('data-[active=true]:bg-sidebar-primary');
        expect(aktif.className).toContain('data-[active=true]:text-sidebar-primary-foreground');
        expect(aktif.className).toContain('data-[active=true]:font-semibold');
        expect(container.querySelector('[data-slot="sidebar-inner"]')?.className).toContain('bg-sidebar');
    });

    it('menu akun (DropdownMenu) menampilkan nama & email, lalu Keluar mem-POST /keluar', () => {
        render(<TataLetakAplikasi judul="Beranda">isi</TataLetakAplikasi>);

        const pemicu = screen.getByRole('button', { name: 'Menu akun Rina Wulandari' });
        expect(screen.queryByRole('menuitem', { name: 'Keluar' })).toBeNull();

        fireEvent.keyDown(pemicu, { key: 'Enter' });

        const menu = screen.getByRole('menu');
        expect(within(menu).getByText('rina@kopinusantara.id')).toBeTruthy();

        fireEvent.click(within(menu).getByRole('menuitem', { name: 'Keluar' }));

        expect(tiruanRouter.post).toHaveBeenCalledWith('/keluar');
    });

    it('D-23: fitur di luar paket tetap tampil bergembok; klik = dialog naik paket / add-on', () => {
        propsHalaman = {
            ...BuatProps({}, [], true),
            FiturPaket: {
                NamaPaket: 'Starter',
                Terkunci: {
                    'promo.mesin': {
                        Nama: 'Mesin promo',
                        Paket: { Kode: 'PRO', Nama: 'Pro', HargaBulanan: '199000.00' },
                        Addon: null,
                    },
                    'kanal.self-order': {
                        Nama: 'Self-order QR',
                        Paket: null,
                        Addon: {
                            Kode: 'SELF_ORDER',
                            Nama: 'Self-order QR',
                            HargaBulanan: '49000.00',
                            BisaDibeli: false,
                            AlasanTidakBisa: 'Perpanjangan paket Anda belum dibayar.',
                            HargaProrata: null,
                        },
                    },
                },
            },
        };
        render(<TataLetakAplikasi judul="Beranda">isi</TataLetakAplikasi>);

        fireEvent.click(screen.getByRole('button', { name: 'Pelanggan' }));
        expect(screen.getByRole('link', { name: 'Daftar pelanggan' })).toBeTruthy();
        expect(screen.queryByRole('link', { name: 'Promo' })).toBeNull();

        fireEvent.click(screen.getByRole('button', { name: 'Promo (perlu naik paket)' }));
        const dialog = screen.getByRole('dialog');
        expect(within(dialog).getByText('Mesin promo belum termasuk paket Starter')).toBeTruthy();
        expect(within(dialog).getByText(/mulai Rp\s?199\.000\/bulan/)).toBeTruthy();
        expect(within(dialog).getByRole('link', { name: 'Lihat paket Pro' }).getAttribute('href')).toBe(
            '/kelola/langganan?paket=PRO',
        );
        fireEvent.click(within(dialog).getByRole('button', { name: 'Nanti saja' }));
        expect(screen.queryByRole('dialog')).toBeNull();
        expect(tiruanRouter.visit).not.toHaveBeenCalled();
    });

    it('D-23: anggota tanpa langganan.kelola hanya diminta menghubungi Pemilik', () => {
        propsHalaman = {
            ...BuatProps({}, ['pelanggan.lihat']),
            FiturPaket: {
                NamaPaket: 'Starter',
                Terkunci: {
                    'pelanggan.deposit': {
                        Nama: 'Deposit pelanggan',
                        Paket: { Kode: 'PRO', Nama: 'Pro', HargaBulanan: '199000.00' },
                        Addon: null,
                    },
                },
            },
        };
        render(<TataLetakAplikasi judul="Beranda">isi</TataLetakAplikasi>);

        fireEvent.click(screen.getByRole('button', { name: 'Pelanggan' }));
        fireEvent.click(screen.getByRole('button', { name: 'Isi deposit (perlu naik paket)' }));
        const dialog = screen.getByRole('dialog');
        expect(within(dialog).getByText('Minta Pemilik usaha untuk naik paket atau menambah add-on.')).toBeTruthy();
        expect(within(dialog).queryByRole('link', { name: /Lihat paket/ })).toBeNull();
    });

    it('memasang Toaster dengan label Bahasa Indonesia', () => {
        render(<TataLetakAplikasi judul="Beranda">isi</TataLetakAplikasi>);

        expect(screen.getByRole('region', { name: /^Notifikasi/ })).toBeTruthy();
    });

    it('identitas usaha di bilah atas: logo dan nama usaha, nama tersembunyi di HP', () => {
        propsHalaman = BuatProps(
            {
                Nama: 'Kopi Kenangan Senja',
                TautanLogo: 'https://payoung.test/storage/logo.png',
            },
            [],
        );
        const { container } = render(<TataLetakAplikasi judul="Beranda">isi</TataLetakAplikasi>);

        const tautanIdentitas = screen.getByRole('link', { name: /Kopi Kenangan Senja/ });
        expect(tautanIdentitas.getAttribute('href')).toBe('/kelola');

        // Teks nama usaha disembunyikan di HP (hidden sm:inline-block)
        const teksNama = within(tautanIdentitas).getByText('Kopi Kenangan Senja');
        expect(teksNama.className).toContain('hidden');
        expect(teksNama.className).toContain('sm:inline-block');

        // Logo usaha tampil di dalam avatar
        const img = container.querySelector('header img[alt="Logo Kopi Kenangan Senja"]');
        expect(img?.getAttribute('src')).toBe('https://payoung.test/storage/logo.png');
    });
});
