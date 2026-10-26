import { cleanup, fireEvent, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanDaftarKampanye from '@/Halaman/Kelola/Pelanggan/Kampanye/Daftar';
import HalamanDetailKampanye, { UbahKeIsoUtc } from '@/Halaman/Kelola/Pelanggan/Kampanye/Detail';
import HalamanFormKampanye from '@/Halaman/Kelola/Pelanggan/Kampanye/Form';
import HalamanBerhentiLangganan from '@/Halaman/Publik/BerhentiLangganan';
import { AturHalamanUji, RenderUji, tiruanRouter } from '@/Komponen/Katalog/TiruanInertia';
import { BuatHasilTabel } from '@/Komponen/Persediaan/DataUjiPersediaan';
import type { BarisKampanye, PropsFormKampanye } from '@/Tipe/Kampanye';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

const Kampanye: BarisKampanye = {
    Uuid: '01J9KMP0000000000000000001',
    Nama: 'Promo kopi gula aren Oktober',
    Kanal: 'Email',
    LabelKanal: 'Email',
    Status: 'Selesai',
    LabelStatus: 'Selesai',
    DijadwalkanPada: null,
    MulaiPada: '2026-10-07T05:00:00Z',
    SelesaiPada: '2026-10-07T05:02:00Z',
    JumlahPenerima: 2,
    JumlahTerkirim: 1,
    JumlahGagal: 1,
    JumlahDilewati: 0,
    DibuatPada: '2026-10-07T04:00:00Z',
};

const PropsForm: PropsFormKampanye = {
    Kampanye: null,
    // D-33: hanya WhatsApp yang ditawarkan server.
    OpsiKanal: [{ Nilai: 'Whatsapp', Label: 'WhatsApp' }],
    OpsiRfm: [
        { Nilai: 'Juara', Label: 'Juara', Keterangan: 'Belanja ≤ 30 hari lalu dan sering.' },
        { Nilai: 'Baru', Label: 'Baru', Keterangan: 'Baru sekali belanja.' },
    ],
    OpsiTier: [],
    OpsiTag: [{ Nilai: 'Kantor', Label: 'Kantor' }],
    KanalAktif: { Whatsapp: false, Email: false },
    MaksIsi: 1000,
};

describe('CRM-07 kampanye pesan', () => {
    beforeEach(() => AturHalamanUji({}, '/kelola/pelanggan/kampanye'));
    afterEach(() => {
        cleanup();
        vi.unstubAllGlobals();
    });

    it('daftar: status berteks, terkirim & gagal, tombol buat kampanye', () => {
        RenderUji(
            <HalamanDaftarKampanye
                Kampanye={BuatHasilTabel([Kampanye])}
                OpsiStatus={[{ Nilai: 'Selesai', Label: 'Selesai' }]}
                OpsiKanal={[{ Nilai: 'Email', Label: 'Email' }]}
            />,
        );

        expect(screen.getByRole('link', { name: 'Buat kampanye' }).getAttribute('href')).toBe(
            '/kelola/pelanggan/kampanye/buat',
        );
        expect(screen.getAllByText('Promo kopi gula aren Oktober').length).toBeGreaterThan(0);
        expect(screen.getAllByText('1 (gagal 1)').length).toBeGreaterThan(0);
        expect(screen.getByText(/menyetujui kabar promosi/)).toBeTruthy();
    });

    it('formulir: hanya WhatsApp (D-33), kanal belum aktif diberi tanda, pratinjau penerima dari server per segmen', async () => {
        const Ambil = vi.fn().mockResolvedValue({
            ok: true,
            json: () =>
                Promise.resolve({
                    JumlahPenerima: 2,
                    TanpaKontak: 1,
                    PerSegmen: { Juara: 0, Baru: 1 },
                    MaksPenerima: 5000,
                }),
        });
        vi.stubGlobal('fetch', Ambil);

        RenderUji(<HalamanFormKampanye {...PropsForm} />);

        expect(screen.getByRole('combobox', { name: /Kanal/ }).textContent).toContain('WhatsApp (belum aktif)');
        expect(screen.getByText(/Pengiriman WhatsApp belum aktif/)).toBeTruthy();
        // Buat kampanye berupa wizard: penerima ada di langkah kedua.
        fireEvent.change(screen.getByLabelText('Nama kampanye'), { target: { value: 'Promo akhir tahun' } });
        fireEvent.click(screen.getByRole('button', { name: 'Lanjut' }));
        await waitFor(() => expect(screen.getByRole('status').textContent).toContain('2 pelanggan'));
        expect(screen.getByRole('status').textContent).toContain(
            '1 lainnya cocok tetapi tidak punya nomor WhatsApp yang sah',
        );
        expect(screen.getByLabelText('Baru (1)')).toBeTruthy();
        expect(screen.queryByLabelText('Judul email')).toBeNull();
        const [alamat, opsi] = Ambil.mock.calls[0] as [string, { body: string }];
        expect(alamat).toBe('/kelola/pelanggan/kampanye/pratinjau');
        expect(JSON.parse(opsi.body)).toEqual({
            Kanal: 'Whatsapp',
            Segmen: { Rfm: [], UuidTier: [], Tag: [], UlangTahunBulanIni: false },
        });
    });

    it('rincian: ringkasan, contoh pesan, tanpa aksi kirim setelah selesai. Jadwal diubah ke UTC', () => {
        RenderUji(
            <HalamanDetailKampanye
                Kampanye={{
                    ...Kampanye,
                    Judul: 'Diskon 20%',
                    Isi: 'Halo {nama}',
                    Contoh: 'Halo Budi\n\n— Kopi Senja\nBerhenti menerima pesan promosi: https://contoh.id/b',
                    Segmen: ['Segmen: Baru'],
                }}
                Penerima={BuatHasilTabel([
                    {
                        Kunci: '1',
                        UuidPelanggan: '01J9PLG0000000000000000001',
                        NamaPelanggan: 'Ani Lestari',
                        Status: 'Gagal',
                        LabelStatus: 'Gagal',
                        PesanGalat: 'Email gagal dikirim: kotak penuh',
                        TerkirimPada: null,
                    },
                ])}
                OpsiStatusPenerima={[{ Nilai: 'Gagal', Label: 'Gagal' }]}
                Aturan={{ JamMulai: 8, JamSelesai: 21, UkuranGiliran: 25, JedaDetik: 60 }}
            />,
        );

        expect(screen.getByText(/Berhenti menerima pesan promosi/)).toBeTruthy();
        expect(screen.getByText('Segmen: Baru')).toBeTruthy();
        expect(screen.getAllByText('Ani Lestari').length).toBeGreaterThan(0);
        expect(screen.getAllByText('Email gagal dikirim: kotak penuh').length).toBeGreaterThan(0);
        expect(screen.queryByRole('button', { name: 'Kirim sekarang' })).toBeNull();
        expect(UbahKeIsoUtc('')).toBeNull();
        expect(UbahKeIsoUtc('2026-10-08T09:00')).toMatch(/^2026-10-0\dT\d{2}:00:00\.000Z$/);
    });

    it('halaman publik berhenti berlangganan: konfirmasi, sudah berhenti, tautan rusak', () => {
        RenderUji(
            <HalamanBerhentiLangganan
                Ditemukan
                NamaToko="Kopi Senja"
                SudahBerhenti={false}
                AlamatKirim="https://contoh.id/berhenti-langganan/x?signature=1"
            />,
        );
        expect(screen.getByRole('button', { name: 'Berhenti berlangganan' })).toBeTruthy();
        cleanup();

        RenderUji(<HalamanBerhentiLangganan Ditemukan NamaToko="Kopi Senja" SudahBerhenti AlamatKirim={null} />);
        expect(screen.getByText(/Kopi Senja tidak akan mengirim pesan promosi lagi/)).toBeTruthy();
        cleanup();

        RenderUji(
            <HalamanBerhentiLangganan Ditemukan={false} NamaToko={null} SudahBerhenti={false} AlamatKirim={null} />,
        );
        expect(screen.getByText('Tautan tidak berlaku')).toBeTruthy();
    });
});

describe('Wizard buat kampanye (CRM-07)', () => {
    beforeEach(() => {
        AturHalamanUji({}, '/kelola/pelanggan/kampanye/buat');
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue({
                ok: true,
                json: () =>
                    Promise.resolve({ JumlahPenerima: 3, TanpaKontak: 0, PerSegmen: { Baru: 3 }, MaksPenerima: 5000 }),
            }),
        );
    });
    afterEach(() => {
        cleanup();
        vi.unstubAllGlobals();
    });

    const Lanjut = () => fireEvent.click(screen.getByRole('button', { name: 'Lanjut' }));

    it('empat langkah. Nama kosong menahan Lanjut dan isi yang baru sapaan ditolak', () => {
        RenderUji(<HalamanFormKampanye {...PropsForm} />);

        expect(screen.getByText('Langkah 1 dari 4')).toBeTruthy();
        Lanjut();
        expect(screen.getByText('Isi nama kampanye dulu.')).toBeTruthy();
        expect(screen.getByText('Langkah 1 dari 4')).toBeTruthy();

        fireEvent.change(screen.getByLabelText('Nama kampanye'), { target: { value: 'Promo akhir tahun' } });
        Lanjut();
        Lanjut();
        expect(screen.getByText('Langkah 3 dari 4')).toBeTruthy();
        Lanjut();
        expect(screen.getByText('Lengkapi pesannya. Baru ada sapaan, belum ada isi untuk pelanggan.')).toBeTruthy();
        expect(screen.getByText('Langkah 3 dari 4')).toBeTruthy();
    });

    it('Tinjau memuat contoh pesan dan jumlah penerima; Simpan mengirim draf sebagai POST', async () => {
        RenderUji(<HalamanFormKampanye {...PropsForm} />);
        fireEvent.change(screen.getByLabelText('Nama kampanye'), { target: { value: 'Promo akhir tahun' } });
        Lanjut();
        fireEvent.click(screen.getByLabelText(/^Baru/));
        Lanjut();
        fireEvent.change(screen.getByRole('textbox', { name: /Isi pesan/ }), {
            target: { value: 'Halo {nama}, diskon 20% kopi sampai Minggu.' },
        });
        Lanjut();

        expect(screen.getByText('Langkah 4 dari 4')).toBeTruthy();
        expect(screen.getByText('Halo Budi, diskon 20% kopi sampai Minggu.')).toBeTruthy();
        await waitFor(() => expect(screen.getByText('3 pelanggan')).toBeTruthy());

        fireEvent.click(screen.getByRole('button', { name: 'Simpan draf kampanye' }));
        expect(tiruanRouter.post).toHaveBeenCalledWith(
            '/kelola/pelanggan/kampanye',
            {
                Nama: 'Promo akhir tahun',
                Kanal: 'Whatsapp',
                Judul: null,
                Isi: 'Halo {nama}, diskon 20% kopi sampai Minggu.',
                Segmen: { Rfm: ['Baru'], UuidTier: [], Tag: [], UlangTahunBulanIni: false },
            },
            expect.anything(),
        );
    });

    it('ubah draf tetap satu halaman tanpa penunjuk langkah', () => {
        RenderUji(
            <HalamanFormKampanye
                {...PropsForm}
                Kampanye={{
                    Uuid: '01J9KMP0000000000000000001',
                    Nama: 'Promo kopi',
                    Kanal: 'Whatsapp',
                    Judul: null,
                    Isi: 'Halo {nama}, ada diskon kopi.',
                    Segmen: {},
                }}
            />,
        );

        expect(screen.queryByText(/Langkah \d dari/)).toBeNull();
        expect(screen.getByRole('button', { name: 'Simpan draf' })).toBeTruthy();
    });
});
