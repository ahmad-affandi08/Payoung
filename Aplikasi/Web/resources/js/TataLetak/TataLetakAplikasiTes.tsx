import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import type { PropsBersamaAplikasi, TenantAktif } from '@/Tipe/Aplikasi';

import TataLetakAplikasi, {
    BagiKelompokSub,
    CariMenuProdukAktif,
    CariSubMenuAktif,
    CekMenuAktif,
    SaringMenuTerlihat,
    type ItemMenu,
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

    it('D-204: Kotak tindakan berlencana merah berisi jumlah yang perlu ditindak, tanpa butir tidak ada lencana. Hanya di menu itu', () => {
        propsHalaman = { ...BuatProps({}, [], true), TindakanPerluDicek: 7 };
        const { rerender: RenderUlang } = render(<TataLetakAplikasi judul="Beranda">isi</TataLetakAplikasi>);

        const utama = screen.getByRole('navigation', { name: 'Menu utama' });
        const tautan = within(utama).getByRole('link', { name: /Kotak tindakan/ });
        expect(tautan.textContent).toContain('7 perlu ditindak');
        expect(utama.querySelector('[data-slot="sidebar-menu-badge"]')?.textContent).toBe('7');
        expect(utama.querySelectorAll('[data-slot="sidebar-menu-badge"]').length).toBe(1);
        expect(within(utama).getByRole('link', { name: 'Beranda' })).toBeTruthy();

        propsHalaman = { ...BuatProps({}, [], true), TindakanPerluDicek: 120 };
        RenderUlang(<TataLetakAplikasi judul="Beranda">isi</TataLetakAplikasi>);
        expect(utama.querySelector('[data-slot="sidebar-menu-badge"]')?.textContent).toBe('99+');

        propsHalaman = { ...BuatProps({}, [], true), TindakanPerluDicek: 0 };
        RenderUlang(<TataLetakAplikasi judul="Beranda">isi</TataLetakAplikasi>);
        expect(utama.querySelector('[data-slot="sidebar-menu-badge"]')).toBeNull();
        expect(within(utama).getByRole('link', { name: 'Kotak tindakan' })).toBeTruthy();
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

    it('menu akun menautkan ke halaman Absen hanya bagi akun yang wajib absen (D-180)', () => {
        propsHalaman = { ...BuatProps({}, [], true), AbsenSaya: true };
        const { unmount: Lepas } = render(<TataLetakAplikasi judul="Beranda">isi</TataLetakAplikasi>);
        fireEvent.keyDown(screen.getByRole('button', { name: /Menu akun/ }), { key: 'Enter' });
        expect(within(screen.getByRole('menu')).getByRole('menuitem', { name: 'Absen' }).getAttribute('href')).toBe(
            '/kelola/absen',
        );
        Lepas();

        propsHalaman = { ...BuatProps({}, [], true), AbsenSaya: false };
        render(<TataLetakAplikasi judul="Beranda">isi</TataLetakAplikasi>);
        fireEvent.keyDown(screen.getByRole('button', { name: /Menu akun/ }), { key: 'Enter' });
        expect(within(screen.getByRole('menu')).queryByRole('menuitem', { name: 'Absen' })).toBeNull();
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

    it('PGL-16: sesi akses dukungan tidak menampilkan banner milik Owner, hanya bilah petugas', () => {
        propsHalaman = {
            ...BuatProps({ StatusLangganan: 'Tertunggak', PeriodeSelesai: '2026-09-19T17:00:00Z' }, [], true),
            Pengguna: {
                Uuid: '01J',
                Nama: 'Rina Wulandari',
                Email: 'rina@kopinusantara.id',
                EmailTerverifikasi: false,
            },
            PengingatDuaFaktor: true,
            PengumumanLegal: [{ Label: 'Syarat', Versi: 2, BerlakuMulai: '2026-11-01', Tautan: '/syarat' }],
            AksesDukungan: {
                NamaPetugas: 'Silvia Suryatmi',
                Cakupan: 'BacaSaja',
                LabelCakupan: 'Baca saja',
                Darurat: false,
                DataPribadiTerbuka: false,
                BerakhirPada: '2026-10-12T07:00:00Z',
            },
        };
        render(<TataLetakAplikasi judul="Beranda">isi</TataLetakAplikasi>);

        expect(screen.getByText(/Anda mengakses akun Kopi Nusantara sebagai Dukungan\./)).toBeTruthy();
        expect(screen.queryByText('Verifikasi email Anda')).toBeNull();
        expect(screen.queryByText('Aktifkan verifikasi dua langkah')).toBeNull();
        expect(screen.queryByText('Perubahan dokumen legal')).toBeNull();
        expect(screen.queryByText('Tagihan langganan belum dibayar')).toBeNull();
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
        // D-253: Kelompok pajak dan Stasiun dapur pindah dari Pengaturan ke subgrup "Atur produk".
        // Impor produk hanya untuk pemegang produk.kelola, jadi tidak tampil bagi produk.lihat saja.
        expect(Array.from(sub.querySelectorAll('a')).map((a) => a.textContent)).toEqual([
            'Produk',
            'Daftar harga',
            'Paket sesi',
            'Kategori',
            'Satuan',
            'Pilihan (modifier)',
            'Kelompok pajak',
            'Stasiun dapur',
        ]);
        expect(sub.querySelector('a[aria-current="page"]')?.textContent).toBe('Kategori');
    });

    it('F-03: sub-menu memilih awalan terpanjang dan tersembunyi di luar grup Produk', () => {
        expect(CariMenuProdukAktif('/kelola/produk/01J9/harga')).toBe('/kelola/produk');
        expect(CariMenuProdukAktif('/kelola/produk?kata=kopi')).toBe('/kelola/produk');
        // v6.49: Impor produk punya butir sendiri di sub-menu Produk, dan awalan terpanjang yang menang.
        expect(CariMenuProdukAktif('/kelola/produk/impor/01J9?x=1')).toBe('/kelola/produk/impor');
        expect(CariMenuProdukAktif('/kelola/satuan')).toBe('/kelola/satuan');
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

    it('D-167: usaha Perusahaan tidak melihat menu kasir (Promo, Grosir untuk jasa/kontraktor), usaha campuran tetap', () => {
        const akses = { Pemilik: true, Izin: [] };
        const Label = (menu: ReturnType<typeof SaringMenuTerlihat>) => menu.map(({ menu: m }) => m.label);
        const SubPenjualan = (menu: ReturnType<typeof SaringMenuTerlihat>) =>
            (menu.find(({ menu: m }) => m.label === 'Penjualan & kasir')?.sub ?? []).map((m) => m.label);

        const jasa = SaringMenuTerlihat(akses, { sektorOutlet: ['BIZ-JSA'] });
        expect(Label(jasa)).not.toContain('Grosir');
        expect(SubPenjualan(jasa)).not.toContain('Perangkat kasir');
        // Manufaktur menjual B2B lewat Grosir, jadi menu itu tetap ada.
        expect(Label(SaringMenuTerlihat(akses, { sektorOutlet: ['BIZ-MFG'] }))).toContain('Grosir');
        // Konsultan yang juga punya toko tetap melihat menu kasir.
        const campur = SaringMenuTerlihat(akses, { sektorOutlet: ['BIZ-JSA', 'RTL-GEN'] });
        expect(SubPenjualan(campur)).toContain('Perangkat kasir');
    });

    it('audit #33: menu khusus sektor difilter sektor outlet. Mode sederhana menyembunyikan menu lanjutan', () => {
        const akses = { Pemilik: true, Izin: [] };
        const Label = (menu: ReturnType<typeof SaringMenuTerlihat>) => menu.map(({ menu: m }) => m.label);
        const SubPenjualan = (menu: ReturnType<typeof SaringMenuTerlihat>) =>
            (menu.find(({ menu: m }) => m.label === 'Penjualan & kasir')?.sub ?? []).map((m) => m.label);

        // Sektor belum diketahui: semua tampil (perilaku lama).
        expect(Label(SaringMenuTerlihat(akses))).toContain('Grosir');
        // Produksi (juga dipakai toko retail/sembako untuk pecah kemasan): tidak untuk jasa murni.
        const SubPersediaan = (menu: ReturnType<typeof SaringMenuTerlihat>) =>
            (menu.find(({ menu: m }) => m.label === 'Persediaan')?.sub ?? []).map((m) => m.label);
        expect(SubPersediaan(SaringMenuTerlihat(akses, { sektorOutlet: ['RTL-FRS'] }))).toContain('Produksi');
        expect(SubPersediaan(SaringMenuTerlihat(akses, { sektorOutlet: ['RTL-GEN'] }))).toContain('Produksi');
        expect(SubPersediaan(SaringMenuTerlihat(akses, { sektorOutlet: ['SVC-SLN'] }))).not.toContain('Produksi');
        expect(SubPersediaan(SaringMenuTerlihat(akses, { sektorOutlet: ['RTL-PHR'] }))).not.toContain('Produksi');
        // Kafe saja: grosir, laundry, reservasi tersembunyi.
        const kafe = SaringMenuTerlihat(akses, { sektorOutlet: ['FNB-CAF'] });
        expect(Label(kafe)).not.toContain('Grosir');
        expect(SubPenjualan(kafe)).not.toContain('Laundry');
        expect(SubPenjualan(kafe)).not.toContain('Reservasi');
        // D-145: usaha FnB murni melihat Reservasi meja (halaman lain); salon tetap Reservasi; FnB + salon tetap Reservasi.
        expect(SubPenjualan(kafe)).toContain('Reservasi meja');
        const HrefReservasi = (menu: ReturnType<typeof SaringMenuTerlihat>) =>
            menu
                .find(({ menu: m }) => m.label === 'Penjualan & kasir')
                ?.sub.find((m) => m.label.startsWith('Reservasi'))?.href;
        expect(HrefReservasi(kafe)).toBe('/kelola/reservasi-meja');
        expect(HrefReservasi(SaringMenuTerlihat(akses, { sektorOutlet: ['SVC-SLN'] }))).toBe('/kelola/reservasi');
        expect(HrefReservasi(SaringMenuTerlihat(akses, { sektorOutlet: ['FNB-CAF', 'SVC-SLN'] }))).toBe(
            '/kelola/reservasi',
        );
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

    it('klik label grup membuka/menutup sub-menu tanpa pindah halaman. Chevron memutar saat terbuka', () => {
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
        // D-252: lima pekerjaan mengubah stok dikelompokkan di subgrup "Ubah stok" yang tertutup sampai dibuka.
        const subgrup = within(sub).getByRole('button', { name: 'Ubah stok' });
        expect(subgrup.getAttribute('aria-expanded')).toBe('false');
        expect(Array.from(sub.querySelectorAll('a')).map((a) => a.textContent)).toEqual(['Saldo stok', 'Kartu stok']);
        fireEvent.click(subgrup);
        expect(subgrup.getAttribute('aria-expanded')).toBe('true');
        // D-27: stok awal, impornya, dan pengaturan persediaan pindah ke Pengaturan (kegiatan menyiapkan toko).
        expect(Array.from(sub.querySelectorAll('a')).map((a) => a.textContent)).toEqual([
            'Saldo stok',
            'Kartu stok',
            'Penyesuaian stok',
            'Transfer stok',
            // D-252: halaman yang dulu hanya terjangkau lewat tombol di Transfer stok.
            'Permintaan stok',
            'Stok opname',
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
        fireEvent.click(within(sub).getByRole('button', { name: 'Ubah stok' }));
        const alamat = Array.from(sub.querySelectorAll('a')).map((a) => a.getAttribute('href'));

        expect(alamat).not.toContain('/kelola/persediaan/stok-awal');
        expect(alamat).not.toContain('/kelola/persediaan/stok-awal/impor');
        expect(alamat).not.toContain('/kelola/persediaan/pengaturan');
        expect(alamat).toHaveLength(8);
    });

    it('D-27: menu Pengaturan menyala saat membuka halaman yang rumahnya di sana', () => {
        propsHalaman = BuatProps({}, ['akuntansi.kelola']);
        urlHalaman = '/kelola/pengaturan/profil-usaha';
        render(<TataLetakAplikasi judul="Profil usaha">isi</TataLetakAplikasi>);

        const utama = screen.getByRole('navigation', { name: 'Menu utama' });
        // D-253: Pengaturan persediaan kini di grup Persediaan (izin akuntansi.kelola), tetapi grupnya tidak menyala.
        expect(within(utama).getByRole('button', { name: 'Persediaan' }).getAttribute('data-active')).not.toBe('true');
        expect(within(utama).getByRole('link', { name: 'Pengaturan' }).getAttribute('aria-current')).toBe('page');
        expect(CekMenuAktif('/kelola/pengaturan', '/kelola/outlet/01J9')).toBe(true);
        // D-253: pengaturan modul pindah ke menu modulnya, jadi Pengaturan tidak lagi menyala untuk halaman itu.
        expect(CekMenuAktif('/kelola/pengaturan', '/kelola/peran/01J9')).toBe(false);
        expect(CekMenuAktif('/kelola/pengaturan', '/kelola/kasir/struk')).toBe(false);
        // Keamanan akun pindah ke menu akun (v6.49), jadi Pengaturan tidak lagi menyala untuknya.
        expect(CekMenuAktif('/kelola/pengaturan', '/kelola/keamanan/pin')).toBe(false);
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

    it('F-05a: Pemilik melihat seluruh menu. Urutannya dari yang paling sering dipakai (D-27)', () => {
        // D-27: 13 entri sejak grosir (v2.78), diurutkan per frekuensi pakai. Outlet, Perangkat, Pengguna & peran,
        // Log audit, dan Langganan pindah ke Pengaturan; "Shift & kas" digabung ke "Penjualan & kasir".
        expect(SaringMenuTerlihat({ Pemilik: true, Izin: [] }).map(({ menu }) => menu.label)).toEqual([
            'Beranda',
            'Kotak tindakan',
            'Penjualan & kasir',
            'Persediaan',
            'Produk',
            // F-04: pembelian & hutang pemasok.
            'Pembelian',
            // F-12 §9.7: grosir (pesanan, surat jalan, faktur penjualan) — cermin Pembelian di sisi jual.
            'Grosir',
            // F-16a: data pelanggan.
            'Pelanggan',
            // F-14a: laporan yang dibaca pemilik; kerja berkala, jadi di bawah menu operasional harian (D-27).
            'Laporan',
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
        // F-04: grup Pembelian tampil untuk pembelian.kelola (beserta Laporan pembelian, §10.10); pengaturan pembelian hanya untuk pembelian.po.setujui.
        expect(
            SaringMenuTerlihat({ Pemilik: false, Izin: ['pembelian.kelola'] }).map(({ menu }) => menu.label),
        ).toEqual(['Beranda', 'Kotak tindakan', 'Pembelian', 'Laporan', 'Pengaturan']);
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

    it('Pengaturan aktif di /kelola/outlet. Aria-current hanya pada satu menu utama (D-27)', () => {
        propsHalaman = BuatProps({}, ['pengguna.lihat', 'outlet.lihat']);
        urlHalaman = '/kelola/outlet/01J9';
        render(<TataLetakAplikasi judul="Outlet">isi</TataLetakAplikasi>);

        const utama = screen.getByRole('navigation', { name: 'Menu utama' });
        const aktif = Array.from(utama.querySelectorAll('a[aria-current="page"]'));
        expect(aktif.map((a) => a.textContent)).toEqual(['Pengaturan']);
        // D-253: Peran & izin pindah ke grup Tim, yang menyala dan Pengaturan tidak.
        cleanup();
        urlHalaman = '/kelola/peran/01J9';
        render(<TataLetakAplikasi judul="Peran">isi</TataLetakAplikasi>);
        const utamaPeran = screen.getByRole('navigation', { name: 'Menu utama' });
        // Tim hanya punya satu halaman terlihat untuk izin ini, jadi tampil sebagai satu tautan bernama halamannya.
        expect(Array.from(utamaPeran.querySelectorAll('a[aria-current="page"]')).map((a) => a.textContent)).toEqual([
            'Peran & izin',
        ]);
        expect(CekMenuAktif('/kelola', '/kelola/outlet')).toBe(false);
    });

    it('bilah samping bisa diciutkan lewat tombol di bilah atas. Remah roti menyebut usaha dan halaman', () => {
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
        propsHalaman = BuatProps({}, ['outlet.lihat']);
        urlHalaman = '/kelola/outlet';
        render(<TataLetakAplikasi judul="Outlet">isi</TataLetakAplikasi>);

        const jejak = screen.getByRole('navigation', { name: 'Jejak halaman' });
        expect(within(jejak).getByRole('link', { name: 'Pengaturan' }).getAttribute('href')).toBe('/kelola/pengaturan');
        expect(within(jejak).getByText('Usaha')).toBeTruthy();
        // Halaman saat ini tidak diulang di jejak karena sudah menjadi <h1>.
        expect(within(jejak).queryByText('Outlet & gudang')).toBeNull();
        expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('Outlet');
        cleanup();

        // D-253: Kelompok pajak ada di grup menu Produk, jadi jejaknya nama grup, bukan Pengaturan.
        propsHalaman = BuatProps({}, ['produk.lihat']);
        urlHalaman = '/kelola/kelompok-pajak';
        render(<TataLetakAplikasi judul="Kelompok pajak">isi</TataLetakAplikasi>);
        const jejakPajak = screen.getByRole('navigation', { name: 'Jejak halaman' });
        expect(within(jejakPajak).getByText('Produk')).toBeTruthy();
        expect(within(jejakPajak).queryByRole('link', { name: 'Pengaturan' })).toBeNull();
    });

    it('D-27: halaman di dalam grup menu menampilkan nama grupnya; /kelola/peran ikut grup Tim (D-253)', () => {
        propsHalaman = BuatProps({}, ['persediaan.lihat']);
        urlHalaman = '/kelola/persediaan/opname';
        render(<TataLetakAplikasi judul="Stok opname">isi</TataLetakAplikasi>);
        expect(within(screen.getByRole('navigation', { name: 'Jejak halaman' })).getByText('Persediaan')).toBeTruthy();
        cleanup();

        propsHalaman = BuatProps({}, ['pengguna.lihat']);
        urlHalaman = '/kelola/peran/01J9';
        render(<TataLetakAplikasi judul="Peran">isi</TataLetakAplikasi>);
        const jejakPeran = screen.getByRole('navigation', { name: 'Jejak halaman' });
        expect(within(jejakPeran).getByText('Tim')).toBeTruthy();
        expect(within(jejakPeran).queryByRole('link', { name: 'Pengaturan' })).toBeNull();
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

    it('D-23: fitur di luar paket tetap tampil bergembok. Klik = dialog naik paket / add-on', () => {
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
        fireEvent.click(screen.getByRole('button', { name: 'Promo & loyalti' }));
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

    it('D-111: menu grosir dan laporan lanjutan bergembok bila paket belum memuat fiturnya', () => {
        const penawaran = {
            Paket: { Kode: 'BISNIS', Nama: 'Bisnis', HargaBulanan: '349000.00' },
            Addon: null,
        };
        propsHalaman = {
            ...BuatProps({}, [], true),
            FiturPaket: {
                NamaPaket: 'Pro',
                Terkunci: {
                    'piutang.grosir': { Nama: 'Piutang & grosir', ...penawaran },
                    'laporan.lengkap': { Nama: 'Laporan lengkap', ...penawaran },
                },
            },
        };
        render(<TataLetakAplikasi judul="Beranda">isi</TataLetakAplikasi>);

        fireEvent.click(screen.getByRole('button', { name: 'Grosir' }));
        expect(screen.queryByRole('link', { name: 'Pesanan grosir' })).toBeNull();
        fireEvent.click(screen.getByRole('button', { name: 'Pesanan grosir (perlu naik paket)' }));
        expect(within(screen.getByRole('dialog')).getByText('Piutang & grosir belum termasuk paket Pro')).toBeTruthy();
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
        // D-253: Deposit & gift card butir langsung di Pelanggan, tidak di dalam subgrup.
        fireEvent.click(screen.getByRole('button', { name: 'Deposit & gift card (perlu naik paket)' }));
        const dialog = screen.getByRole('dialog');
        expect(within(dialog).getByText('Minta Pemilik usaha untuk naik paket atau menambah add-on.')).toBeTruthy();
        expect(within(dialog).queryByRole('link', { name: /Lihat paket/ })).toBeNull();
    });

    it('D-252: subgrup tertutup sampai dibuka, terbuka sendiri bila berisi halaman aktif, dan tetap bisa dilipat', () => {
        propsHalaman = BuatProps({}, ['laporan.keuangan.lihat', 'laporan.penjualan.lihat']);
        urlHalaman = '/kelola/akuntansi/laporan/arus-kas';
        render(<TataLetakAplikasi judul="Arus kas">isi</TataLetakAplikasi>);

        const sub = screen.getByRole('navigation', { name: 'Menu laporan' });
        const subgrup = within(sub).getByRole('button', { name: 'Laporan keuangan' });
        // Halaman aktif ada di dalamnya, jadi sudah terbuka dan hanya subgrup itu yang menyala.
        expect(subgrup.getAttribute('aria-expanded')).toBe('true');
        expect(within(sub).getByRole('link', { name: 'Arus kas' }).getAttribute('aria-current')).toBe('page');
        expect(within(sub).getByRole('link', { name: 'Laporan penjualan' })).toBeTruthy();
        fireEvent.click(subgrup);
        expect(subgrup.getAttribute('aria-expanded')).toBe('false');
        expect(within(sub).queryByRole('link', { name: 'Arus kas' })).toBeNull();
    });

    it('D-252: subgrup yang hanya menyisakan satu butir terlihat tampil rata tanpa judul', () => {
        // Hanya laporan.penjualan.lihat: Laporan keuangan butuh izin lain, jadi subgrupnya kosong dan tidak muncul.
        propsHalaman = BuatProps({}, ['laporan.penjualan.lihat']);
        urlHalaman = '/kelola/laporan/penjualan';
        render(<TataLetakAplikasi judul="Laporan penjualan">isi</TataLetakAplikasi>);

        const sub = screen.getByRole('navigation', { name: 'Menu laporan' });
        expect(within(sub).queryByRole('button', { name: 'Laporan keuangan' })).toBeNull();
        expect(within(sub).getByRole('link', { name: 'Laporan penjualan' })).toBeTruthy();
    });

    it('D-252: halaman giro dan rekonsiliasi punya tempat di subgrup Kas, bank & giro', () => {
        propsHalaman = BuatProps({}, ['laporan.keuangan.lihat']);
        urlHalaman = '/kelola/akuntansi/giro';
        render(<TataLetakAplikasi judul="Giro & cek mundur">isi</TataLetakAplikasi>);

        const sub = screen.getByRole('navigation', { name: 'Menu akuntansi' });
        expect(within(sub).getByRole('button', { name: 'Kas, bank & giro' }).getAttribute('aria-expanded')).toBe(
            'true',
        );
        expect(within(sub).getByRole('link', { name: 'Giro & cek mundur' }).getAttribute('aria-current')).toBe('page');
        expect(within(sub).getByRole('link', { name: 'Rekonsiliasi bank' }).getAttribute('href')).toBe(
            '/kelola/akuntansi/rekonsiliasi',
        );
        expect(within(sub).getByRole('link', { name: 'QRIS tanpa penjualan' })).toBeTruthy();
        // Catatan keuangan tidak berisi halaman aktif, jadi tertutup.
        expect(within(sub).getByRole('button', { name: 'Catatan keuangan' }).getAttribute('aria-expanded')).toBe(
            'false',
        );
    });

    it('D-252: grup Pembelian punya subgrup Beli barang dan Hutang & retur, Pemasok tetap rata', () => {
        propsHalaman = BuatProps({}, ['pembelian.kelola']);
        urlHalaman = '/kelola/pembelian/nota-debit';
        render(<TataLetakAplikasi judul="Nota debit">isi</TataLetakAplikasi>);

        const sub = screen.getByRole('navigation', { name: 'Menu pembelian' });
        expect(within(sub).getByRole('button', { name: 'Hutang & retur' }).getAttribute('aria-expanded')).toBe('true');
        expect(within(sub).getByRole('link', { name: 'Nota debit' }).getAttribute('aria-current')).toBe('page');
        expect(within(sub).getByRole('button', { name: 'Beli barang' }).getAttribute('aria-expanded')).toBe('false');
        expect(within(sub).getByRole('link', { name: 'Pemasok' })).toBeTruthy();
        fireEvent.click(within(sub).getByRole('button', { name: 'Beli barang' }));
        expect(within(sub).getByRole('link', { name: 'Permintaan pembelian' })).toBeTruthy();
    });

    it('D-252: BagiKelompokSub menggabungkan butir berurutan sekelompok dan meratakan subgrup satu butir', () => {
        const BuatButir = (label: string, kelompok?: string): ItemMenu => ({
            label,
            href: `/kelola/${label}`,
            izin: null,
            ...(kelompok === undefined ? {} : { kelompok }),
        });

        expect(
            BagiKelompokSub([
                BuatButir('a'),
                BuatButir('b', 'G1'),
                BuatButir('c', 'G1'),
                BuatButir('d'),
                BuatButir('e', 'G2'),
            ]).map((bagian) => [bagian.kelompok, bagian.item.map((i) => i.label)]),
        ).toEqual([
            [null, ['a']],
            ['G1', ['b', 'c']],
            [null, ['d']],
            [null, ['e']],
        ]);
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
