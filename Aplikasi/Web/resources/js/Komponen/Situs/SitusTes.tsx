import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import TataLetakSitus from '@/TataLetak/TataLetakSitus';
import type { BagianSitus, DataSitus } from '@/Tipe/Situs';

import RenderBagian, { HitungIrama, WarnaiIrama } from './Bagian/RenderBagian';
import { CekTautanHalamanSitus } from './TautanSitus';
import TeksKaya from './TeksKaya';

let situs: DataSitus;

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ href, children, ...sisa }: { href: string; children: ReactNode }) => (
        <a href={href} data-inertia="ya" {...sisa}>
            {children}
        </a>
    ),
    usePage: () => ({ props: { Situs: situs }, url: '/harga' }),
}));

function BuatSitus(tambahan: Partial<DataSitus> = {}): DataSitus {
    return {
        NamaSitus: 'Payoung',
        Slogan: 'Smart Choice Your Business Partner',
        Logo: null,
        Menu: [
            { Label: 'Fitur', Tautan: '/fitur' },
            { Label: 'Harga', Tautan: '/harga' },
        ],
        MenuKaki: [
            { Judul: 'Perusahaan', Tautan: [{ Label: 'Kebijakan privasi', Tautan: '/legal/kebijakan-privasi' }] },
        ],
        TeksKaki: 'Kasir untuk usaha Indonesia.',
        Kontak: {
            WhatsApp: '0812-3456-7890',
            TautanWhatsApp: 'https://wa.me/6281234567890',
            Email: 'halo@payoung.id',
            Telepon: null,
            Alamat: null,
            JamLayanan: null,
        },
        MediaSosial: { Instagram: 'https://instagram.com/payoung' },
        Pengumuman: { Teks: 'Diskon 17 Agustus', Tautan: '/harga' },
        TautanUnduh: {},
        TombolDaftar: { Label: 'Coba gratis', Tautan: 'https://dashboard.payoung.id/daftar' },
        TombolMasuk: { Label: 'Masuk', Tautan: 'https://dashboard.payoung.id/masuk' },
        WhatsAppMelayang: true,
        Tahun: 2026,
        ...tambahan,
    };
}

afterEach(cleanup);

describe('Situs pemasaran D-21: utilitas', () => {
    it('tautan halaman situs lewat Inertia; jalur sistem, domain lain, dan pintasan eksternal tidak', () => {
        expect(CekTautanHalamanSitus('/fitur')).toBe(true);
        expect(CekTautanHalamanSitus('/solusi/kafe-resto#harga')).toBe(true);
        expect(CekTautanHalamanSitus('/legal/kebijakan-privasi')).toBe(false);
        expect(CekTautanHalamanSitus('/masuk')).toBe(false);
        expect(CekTautanHalamanSitus('/peta-situs')).toBe(false);
        expect(CekTautanHalamanSitus('//evil.test')).toBe(false);
        expect(CekTautanHalamanSitus('https://wa.me/62812')).toBe(false);
    });

    it('irama latar: hero terang diikuti Latar + garis, blok gelap memutus, dan selang-seling lanjut (D-25)', () => {
        const jenis = ['Hero', 'Keunggulan', 'Faq', 'Cta', 'TeksBebas'] as const;

        expect(HitungIrama(jenis.map((j) => ({ Jenis: j, Latar: null }) as BagianSitus))).toEqual([
            // Hero terang: bagian sesudahnya tidak boleh putih lagi, dan diberi garis pemisah.
            { latar: 'permukaan', garisAtas: false },
            { latar: 'latar', garisAtas: true },
            { latar: 'permukaan', garisAtas: true },
            // Cta selalu berlatar merek dan memutus irama: sesudahnya mulai putih tanpa garis.
            { latar: 'merek', garisAtas: false },
            { latar: 'permukaan', garisAtas: false },
        ]);
    });

    it('WarnaiIrama: latar krem menjadi peach, jeda gelap menjadi teal pekat, hero tidak diubah (D-93)', () => {
        const bagian = [
            { Jenis: 'Hero', Latar: 'Terang' },
            ...Array.from({ length: 5 }, () => ({ Jenis: 'Keunggulan' })),
        ] as unknown as BagianSitus[];

        expect(WarnaiIrama(bagian, HitungIrama(bagian))).toEqual([
            'permukaan',
            'aksen',
            'permukaan',
            'aksen',
            'permukaan',
            'brand',
        ]);
    });

    it('hero gelap membuat bagian sesudahnya mulai putih tanpa garis (D-25)', () => {
        const bagian = [{ Jenis: 'Hero', Latar: 'Navy' }, { Jenis: 'Keunggulan' }] as unknown as BagianSitus[];

        expect(HitungIrama(bagian)).toEqual([
            { latar: 'navy', garisAtas: false },
            { latar: 'permukaan', garisAtas: false },
        ]);
    });

    it('lima bagian terang berturut-turut disela satu bagian gelap (D-25)', () => {
        const bagian = Array.from({ length: 6 }, () => ({ Jenis: 'Keunggulan' })) as unknown as BagianSitus[];

        expect(HitungIrama(bagian).map((i) => i.latar)).toEqual([
            'permukaan',
            'latar',
            'permukaan',
            'latar',
            'navy',
            'permukaan',
        ]);
    });

    it('blok Harga dan TeksBebas tidak pernah dijadikan jeda gelap (D-25)', () => {
        const bagian = [
            ...Array.from({ length: 4 }, () => ({ Jenis: 'Keunggulan' })),
            { Jenis: 'Harga' },
        ] as unknown as BagianSitus[];

        expect(HitungIrama(bagian)[4]).toEqual({ latar: 'permukaan', garisAtas: true });
    });

    it('TeksKaya merender teks tanpa HTML: skrip tetap teks, tautan javascript: bukan tautan', () => {
        render(
            <TeksKaya
                teks={
                    '## Judul\n\n<script>alert(1)</script> **tebal** [aman](/harga) [jahat](javascript:alert(1))\n\n- satu\n- dua'
                }
            />,
        );

        expect(screen.getByRole('heading', { name: 'Judul' })).toBeTruthy();
        expect(document.querySelector('script')).toBeNull();
        expect(screen.getByText(/<script>alert\(1\)<\/script>/)).toBeTruthy();
        expect(screen.getByRole('link', { name: 'aman' }).getAttribute('href')).toBe('/harga');
        expect(screen.queryByRole('link', { name: 'jahat' })).toBeNull();
        expect(screen.getAllByRole('listitem')).toHaveLength(2);
    });

    it('TeksKaya membaca judul dan daftar yang ditulis tanpa baris kosong di sekitarnya (halaman Tentang kami)', () => {
        render(<TeksKaya teks={'Pembuka.\n## Tiga hal\n- **Satu** dulu\n- Dua\nPenutup.'} />);

        expect(screen.getByRole('heading', { name: 'Tiga hal' }).tagName).toBe('H3');
        expect(screen.getAllByRole('listitem')).toHaveLength(2);
        expect(screen.getByText('Pembuka.')).toBeTruthy();
        expect(screen.getByText('Penutup.')).toBeTruthy();
        expect(screen.queryByText(/## /)).toBeNull();
    });

    it('TeksKaya merender judul bertingkat & daftar bernomor yang dipakai dokumen legal (D-28)', () => {
        // `## ` tetap h3 seperti sebelum D-28, supaya artikel blog yang sudah terbit tidak berubah tampilannya.
        render(
            <TeksKaya
                teks={'# Kebijakan Privasi\n\n## 1. Tentang Payoung\n\n### 1.1 Ruang lingkup\n\n3. tiga\n4. empat'}
            />,
        );

        expect(screen.getByRole('heading', { name: 'Kebijakan Privasi', level: 2 })).toBeTruthy();
        expect(screen.getByRole('heading', { name: '1. Tentang Payoung', level: 3 })).toBeTruthy();
        expect(screen.getByRole('heading', { name: '1.1 Ruang lingkup', level: 4 })).toBeTruthy();

        const bernomor = screen.getByRole('list');
        expect(bernomor.tagName).toBe('OL');
        // Nomor awal dipakai apa adanya, jadi pasal yang dikutip sebagian tetap bernomor benar.
        expect(bernomor.getAttribute('start')).toBe('3');
        expect(screen.getAllByRole('listitem').map((b) => b.textContent)).toEqual(['tiga', 'empat']);
    });
});

describe('Situs pemasaran D-21: tata letak & blok', () => {
    it('kepala berisi menu (aktif ditandai), tombol masuk/daftar ke domain tenant, pengumuman, dan WhatsApp melayang', () => {
        situs = BuatSitus();
        render(<TataLetakSitus judul="Harga">isi</TataLetakSitus>);

        const [menu] = screen.getAllByRole('navigation', { name: 'Menu utama' });
        if (!menu) {
            throw new Error('Menu utama tidak ada');
        }
        expect(within(menu).getByRole('link', { name: 'Harga' }).className).toContain('text-brand');
        expect(screen.getByRole('link', { name: 'Coba gratis' }).getAttribute('href')).toBe(
            'https://dashboard.payoung.id/daftar',
        );
        expect(screen.getByRole('link', { name: 'Diskon 17 Agustus' })).toBeTruthy();
        expect(screen.getByRole('link', { name: 'Chat WhatsApp' }).getAttribute('target')).toBe('_blank');
        expect(screen.getByRole('link', { name: 'Instagram' }).getAttribute('rel')).toBe('noopener noreferrer');
        expect(screen.getByText(/© 2026 Payoung/)).toBeTruthy();
    });

    it('menu Solusi jadi dropdown sektor per kelompok: buka dengan klik, tutup dengan Esc, tautan sektor ada (D-91)', () => {
        situs = BuatSitus({
            Menu: [
                { Label: 'Fitur', Tautan: '/fitur' },
                {
                    Label: 'Solusi',
                    Tautan: '@solusi',
                    Solusi: [
                        {
                            Judul: 'Kafe & Resto',
                            Tautan: '/solusi/kafe-resto',
                            Ikon: 'Coffee',
                            Sektor: [
                                {
                                    Nama: 'Kafe & kedai kopi',
                                    Ringkas: 'Mode cepat & meja',
                                    Ikon: 'Coffee',
                                    Tautan: '/solusi/kafe-kedai-kopi',
                                },
                            ],
                        },
                        {
                            Judul: 'Toko & Retail',
                            Tautan: '/solusi/toko-retail',
                            Ikon: 'Store',
                            Sektor: [
                                {
                                    Nama: 'Toko sayur, buah & sembako',
                                    Ringkas: 'Timbang per kg',
                                    Ikon: 'ShoppingBasket',
                                    Tautan: '/solusi/toko-sayur-sembako',
                                },
                            ],
                        },
                    ],
                },
                { Label: 'Harga', Tautan: '/harga' },
            ],
        });
        render(<TataLetakSitus judul="Harga">isi</TataLetakSitus>);

        const [pemicu] = screen.getAllByRole('button', { name: 'Solusi' });
        if (!pemicu) {
            throw new Error('Tombol Solusi tidak ada');
        }
        expect(pemicu.getAttribute('aria-expanded')).toBe('false');

        fireEvent.click(pemicu);
        expect(pemicu.getAttribute('aria-expanded')).toBe('true');
        expect(screen.getAllByRole('link', { name: /Toko sayur, buah & sembako/ })[0]?.getAttribute('href')).toBe(
            '/solusi/toko-sayur-sembako',
        );
        expect(screen.getAllByRole('link', { name: 'Kafe & Resto' })[0]?.getAttribute('href')).toBe(
            '/solusi/kafe-resto',
        );

        fireEvent.keyDown(document, { key: 'Escape' });
        expect(pemicu.getAttribute('aria-expanded')).toBe('false');
    });

    it('banner cookie & tombol WhatsApp satu tumpukan, jadi banner tidak menutupi tombolnya (D-28)', () => {
        // Sebelumnya banner `fixed bottom-0 z-50` dan tombol `fixed bottom-4 z-30` memakai area yang sama, jadi
        // tombolnya tertutup sampai pengunjung memilih. Sekarang keduanya berada di satu wadah tepi bawah.
        situs = BuatSitus({ Analitik: { IdGoogleAnalytics: 'G-UJI1234', IdMetaPixel: null } });
        render(<TataLetakSitus judul="Harga">isi</TataLetakSitus>);

        const banner = screen.getByRole('region', { name: 'Persetujuan cookie' });
        const tombol = screen.getByRole('link', { name: 'Chat WhatsApp' });
        const tumpukan = banner.parentElement;

        expect(tumpukan?.className).toContain('fixed');
        expect(tumpukan?.contains(tombol)).toBe(true);
        // Tidak ada elemen `fixed` kedua di tepi bawah yang bisa saling menimpa.
        expect(tombol.className).not.toContain('fixed');
        expect(banner.className).not.toContain('fixed');
    });

    it('pratinjau menampilkan penanda draf; tanpa nomor WhatsApp tidak ada tombol melayang', () => {
        situs = BuatSitus({ WhatsAppMelayang: false, Pengumuman: null });
        render(
            <TataLetakSitus judul="Uji" pratinjau>
                isi
            </TataLetakSitus>,
        );

        expect(screen.getByRole('status').textContent).toContain('Pratinjau draf');
        expect(screen.queryByRole('link', { name: 'Chat WhatsApp' })).toBeNull();
    });

    it('Hero pertama memakai h1; FAQ bisa dibuka; harga bulanan/tahunan, gratis, dan harga negosiasi', () => {
        situs = BuatSitus();
        render(
            <RenderBagian
                bagian={[
                    {
                        Jenis: 'Hero',
                        Label: null,
                        Judul: 'Kasir yang tetap jalan',
                        Subjudul: null,
                        TombolUtama: { Label: 'Coba gratis', Tautan: '/daftar' },
                        TombolKedua: null,
                        Latar: null,
                        Spesimen: null,
                        Gambar: null,
                        Catatan: null,
                    },
                    {
                        Jenis: 'Harga',
                        Label: null,
                        Judul: 'Harga',
                        Subjudul: null,
                        TampilkanTahunan: true,
                        PaketDisorot: 'PRO',
                        TeksTombol: null,
                        CatatanKaki: 'Harga belum termasuk PPN.',
                        TautanDaftar: '/daftar',
                        Paket: [
                            {
                                Kode: 'GRATIS',
                                Nama: 'Gratis',
                                Keterangan: null,
                                HargaNegosiasi: false,
                                MasaTrialHari: 0,
                                HargaBulanan: '0.00',
                                HargaTahunan: '0.00',
                                HematTahunan: null,
                                Promo: null,
                                Batas: ['1 outlet'],
                                Fitur: [],
                            },
                            {
                                Kode: 'PRO',
                                Nama: 'Pro',
                                Keterangan: null,
                                HargaNegosiasi: false,
                                MasaTrialHari: 14,
                                HargaBulanan: '199000.00',
                                HargaTahunan: '1990000.00',
                                HematTahunan: '398000.00',
                                Promo: null,
                                Batas: [],
                                Fitur: ['Promo'],
                            },
                            {
                                Kode: 'ENTERPRISE',
                                Nama: 'Enterprise',
                                Keterangan: null,
                                HargaNegosiasi: true,
                                MasaTrialHari: 0,
                                HargaBulanan: null,
                                HargaTahunan: null,
                                HematTahunan: null,
                                Promo: null,
                                Batas: [],
                                Fitur: [],
                            },
                        ],
                    },
                    {
                        Jenis: 'Faq',
                        Label: null,
                        Judul: 'Tanya jawab',
                        Subjudul: null,
                        Item: [{ Pertanyaan: 'Bisa offline?', Jawaban: 'Bisa, transaksi disimpan di perangkat.' }],
                    },
                ]}
            />,
        );

        expect(screen.getByRole('heading', { level: 1, name: 'Kasir yang tetap jalan' })).toBeTruthy();
        expect(screen.getByText('Rp 199.000')).toBeTruthy();
        expect(screen.getAllByText('Gratis').length).toBeGreaterThan(0);
        expect(screen.getByText('Disarankan')).toBeTruthy();
        expect(screen.getByRole('link', { name: 'Hubungi kami' }).getAttribute('href')).toBe(
            'https://wa.me/6281234567890',
        );

        fireEvent.click(screen.getByRole('radio', { name: 'Tahunan' }));
        expect(screen.getByText('Rp 1.990.000')).toBeTruthy();
        expect(screen.getByText('Hemat Rp 398.000 per tahun')).toBeTruthy();

        expect(screen.getByText('Bisa offline?').closest('details')).toBeTruthy();
    });
    it('harga dengan diskon peluncuran: harga normal dicoret, persen diskon, tanggal akhir, dan kunci harga (D-86)', () => {
        situs = BuatSitus();
        const BuatPaket = (kunci: boolean) =>
            ({
                Jenis: 'Harga',
                Label: null,
                Judul: 'Harga',
                Subjudul: null,
                TampilkanTahunan: true,
                PaketDisorot: 'PRO',
                TeksTombol: null,
                CatatanKaki: null,
                TautanDaftar: '/daftar',
                Paket: [
                    {
                        Kode: 'PRO',
                        Nama: 'Pro',
                        Keterangan: null,
                        HargaNegosiasi: false,
                        MasaTrialHari: 14,
                        HargaBulanan: '149000.00',
                        HargaTahunan: '1430400.00',
                        HematTahunan: '357600.00',
                        Promo: {
                            HargaBulananNormal: '249000.00',
                            HargaTahunanNormal: '2390400.00',
                            BerlakuSampai: '2027-01-31',
                            PersenDiskon: 40,
                            HargaTerkunci: kunci,
                        },
                        Batas: [],
                        Fitur: [],
                    },
                ],
            }) as BagianSitus;

        render(<RenderBagian bagian={[BuatPaket(true)]} />);

        expect(screen.getByText('Diskon peluncuran 40%')).toBeTruthy();
        expect(screen.getByText('Rp 249.000').className).toContain('line-through');
        expect(screen.getByText('Rp 149.000')).toBeTruthy();
        expect(screen.getByText(/Promo sampai 31 Jan 2027\./).textContent).toContain('harga ini terkunci');

        fireEvent.click(screen.getByRole('radio', { name: 'Tahunan' }));
        expect(screen.getByText('Rp 2.390.400').className).toContain('line-through');
        expect(screen.getByText('Rp 1.430.400')).toBeTruthy();

        cleanup();
        render(<RenderBagian bagian={[BuatPaket(false)]} />);
        // Tanpa kunci harga, janji "terkunci" tidak boleh muncul.
        expect(screen.getByText(/Promo sampai 31 Jan 2027\./).textContent).not.toContain('terkunci');
    });
    it('blok Keunggulan: ikon dalam kotak berwarna bergilir nada (D-93), dan tiga tata letak punya bentuk berbeda (D-25)', () => {
        situs = BuatSitus();
        const item = [
            { Ikon: 'WifiOff', Judul: 'Tetap jalan offline', Teks: 'Transaksi tersimpan di perangkat.' },
            { Ikon: 'Boxes', Judul: 'Stok otomatis', Teks: 'Stok berkurang setiap penjualan.' },
        ];
        const Blok = (tataLetak: 'Grid' | 'Daftar' | 'Sorot'): BagianSitus =>
            ({
                Jenis: 'Keunggulan',
                Label: null,
                Judul: 'Kemampuan',
                Subjudul: null,
                Kolom: '3',
                TataLetak: tataLetak,
                Item: item,
            }) as BagianSitus;

        const { container } = render(<RenderBagian bagian={[Blok('Grid')]} />);

        // D-93 membalik D-25: ikon kembali berkotak, tetapi tiap butir bernada berbeda (apricot, teal, ...) supaya deretan
        // kartu tidak seragam abu-abu. Nada dibaca dari urutan butir.
        expect(container.querySelector('span.bg-aksen')).toBeTruthy();
        expect(container.querySelector('span.bg-brand')).toBeTruthy();
        expect(screen.getByRole('heading', { level: 3, name: /Tetap jalan offline/ })).toBeTruthy();
        // Grid: tiap item adalah kartu berbingkai.
        expect(container.querySelectorAll('li.rounded-3xl')).toHaveLength(2);

        cleanup();
        const daftar = render(<RenderBagian bagian={[Blok('Daftar')]} />).container;

        // Daftar: tanpa bingkai kartu, item dipisah garis atas 1px.
        expect(daftar.querySelectorAll('li.rounded-3xl')).toHaveLength(0);
        expect(daftar.querySelectorAll('li.border-t')).toHaveLength(2);

        cleanup();
        const sorot = render(<RenderBagian bagian={[Blok('Sorot')]} />).container;

        // Sorot: item pertama naik satu tingkat (judul h3 berukuran judul) dan melebar penuh.
        const pertama = sorot.querySelectorAll('li')[0];
        expect(pertama?.className).toContain('lg:col-span-2');
        expect(sorot.querySelector('h3')?.className).toContain('text-judul');
    });

    it('hero gelap memakai judul sorotan-besar, label Aksen berteks Navy, dan tombol kedua bergaris (D-25)', () => {
        situs = BuatSitus();
        const { container } = render(
            <RenderBagian
                bagian={[
                    {
                        Jenis: 'Hero',
                        Label: 'Aplikasi kasir',
                        Judul: 'Kasir yang tetap jalan',
                        Subjudul: null,
                        TombolUtama: { Label: 'Coba gratis', Tautan: '/daftar' },
                        TombolKedua: { Label: 'Lihat fitur', Tautan: '/fitur' },
                        Gambar: null,
                        Catatan: null,
                        Latar: 'Navy',
                    } as BagianSitus,
                ]}
            />,
        );

        const judul = screen.getByRole('heading', { level: 1 });
        expect(judul.className).toContain('sm:text-sorotan-besar');
        expect(judul.className).toContain('text-permukaan');

        // Kuning hanya sah dengan teks Navy (6,4:1); teks putih di atasnya 2,1:1 dan dilarang.
        const label = screen.getByText('Aplikasi kasir');
        expect(label.className).toContain('bg-aksen');
        expect(label.className).toContain('text-teks-utama');
        expect(label.className).not.toContain('text-permukaan');

        // Di latar gelap tombol kedua bergaris terang, bukan isian penuh.
        expect(screen.getByRole('link', { name: 'Lihat fitur' }).className).toContain('border-brand-gelap-teks');
        expect(container.querySelector('section')?.className).toContain('bg-teks-utama');
    });
    it('hero tanpa gambar memakai spesimen struk, bukan ilustrasi hiasan (D-25)', () => {
        situs = BuatSitus();
        const { container } = render(
            <RenderBagian
                bagian={[
                    {
                        Jenis: 'Hero',
                        Label: null,
                        Judul: 'Kasir tetap mencatat',
                        Subjudul: null,
                        TombolUtama: null,
                        TombolKedua: null,
                        Gambar: null,
                        Catatan: null,
                        Latar: 'Navy',
                        Spesimen: 'Struk',
                    } as BagianSitus,
                ]}
            />,
        );

        // Pembaca layar menerima satu kalimat, bukan deretan angka struk.
        const spesimen = screen.getByRole('img');
        expect(spesimen.getAttribute('aria-label')).toContain('Contoh struk Payoung');
        // Struk memakai font Mono dan angka tabular seperti struk sungguhan (token §17.5).
        expect(spesimen.className).toContain('font-mono');
        expect(spesimen.className).toContain('tabular-nums');
        expect(screen.getByText('Rp 77.000')).toBeTruthy();

        // Penanda offline: kuning hanya sah berteks Navy, dan maknanya dibawa teks bukan warna.
        const penanda = screen.getByText(/Dibuat offline/);
        expect(penanda.className).toContain('bg-aksen');
        expect(penanda.className).toContain('text-teks-utama');
        expect(penanda.className).not.toContain('text-permukaan');

        // Bukan gambar unggahan: tidak ada <img> yang perlu tersedia per pemasangan.
        expect(container.querySelector('img')).toBeNull();
    });

    it('spesimen jurnal memperlihatkan debit sama dengan kredit (D-25)', () => {
        situs = BuatSitus();
        render(
            <RenderBagian
                bagian={[
                    {
                        Jenis: 'GambarTeks',
                        Label: null,
                        Judul: 'Pembukuan',
                        Subjudul: null,
                        Teks: null,
                        Poin: [],
                        Gambar: null,
                        PosisiGambar: 'Kanan',
                        Spesimen: 'Jurnal',
                        Tombol: null,
                    } as BagianSitus,
                ]}
            />,
        );

        const jurnal = screen.getByRole('img');
        expect(jurnal.getAttribute('aria-label')).toContain('total debit sama dengan total kredit');
        // Invariant Σ debit = Σ kredit terlihat langsung di baris penutup: dua kolom bernilai sama.
        const penutup = screen.getByText('Seimbang').closest('li');
        const angka = [...(penutup?.querySelectorAll('span.tabular-nums') ?? [])].map((e) => e.textContent);
        expect(angka).toEqual(['77.000', '77.000']);
    });
});
