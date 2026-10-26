import { cleanup, fireEvent, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import {
    AturHalamanUji,
    kirimanForm,
    RenderUji,
    TambahPropsHalamanUji,
    tiruanRouter,
} from '@/Komponen/Katalog/TiruanInertia';
import Buat from '@/Halaman/Pengelola/KampanyeProspek/Buat';
import Daftar from '@/Halaman/Pengelola/KampanyeProspek/Daftar';
import Kontak from '@/Halaman/Pengelola/KampanyeProspek/Kontak';
import Tampil from '@/Halaman/Pengelola/KampanyeProspek/Tampil';
import BerhentiProspek from '@/Halaman/Publik/BerhentiProspek';
import type { BarisKampanyeProspek, BarisKontakProspek } from '@/Tipe/KampanyeProspek';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

/*
 * D-216: halaman kampanye email prospek di konsol (daftar, buat, rincian, kontak) dan halaman berhenti berlangganan
 * publik. Yang dijaga: kampanye tak bisa dijalankan tanpa email kampanye aktif, hitungan penerima dari sektor terpilih,
 * konfirmasi sebelum jalankan atau batalkan, alasan jeda tampil, dan halaman berhenti tidak pernah menampilkan alamat email.
 */

function AturKonsol(url = '/kampanye-prospek') {
    AturHalamanUji({}, url);
    TambahPropsHalamanUji({
        NamaAplikasi: 'Payoung',
        Lingkungan: 'Produksi',
        Kilat: null,
        Pengguna: {
            Uuid: 'P1',
            Nama: 'Dewi Super',
            Email: 'dewi@contoh.id',
            KodePeran: [],
            Izin: ['komunikasi.kelola', 'tenant.lihat'],
        },
        PeringatanSuperAdmin: false,
        PeringatanIntegrasi: [],
        PeringatanOperasional: [],
    });
}

const kampanye: BarisKampanyeProspek = {
    Uuid: '01JKAMPANYE00000000000001',
    Nama: 'Perkenalan untuk kafe',
    Subjek: 'Kasir untuk kafe & kedai kopi',
    Status: 'Draf',
    LabelStatus: 'Draf',
    AlasanJeda: null,
    BatasPerHari: 40,
    Sektor: ['Kafe & kedai kopi'],
    MulaiKirimPada: null,
    SelesaiPada: null,
    JumlahPenerima: 0,
    JumlahTerkirim: 0,
    JumlahGagal: 0,
    JumlahDilewati: 0,
    DapatDijalankan: true,
    DapatDijeda: false,
    DapatDibatalkan: true,
};

const kontakAktif: BarisKontakProspek = {
    Uuid: '01JKONTAK0000000000000001',
    Email: 'kopi@contoh.id',
    Sektor: 'FNB-CAF',
    LabelSektor: 'Kafe & kedai kopi',
    Sumber: 'Data Pelaku Ekonomi Kreatif di Kota Pontianak',
    Status: 'Aktif',
    LabelStatus: 'Aktif',
    BerhentiPada: null,
    DibuatPada: '2026-10-26T03:00:00Z',
};

afterEach(cleanup);

describe('Daftar kampanye prospek', () => {
    it('menampilkan kampanye dengan hasil kirim, tautan rincian, dan peringatan bila email kampanye belum aktif', () => {
        AturKonsol();
        RenderUji(
            <Daftar
                Kampanye={[
                    {
                        ...kampanye,
                        Status: 'Berjalan',
                        LabelStatus: 'Berjalan',
                        JumlahPenerima: 200,
                        JumlahTerkirim: 80,
                        JumlahGagal: 2,
                        JumlahDilewati: 1,
                    },
                ]}
                EmailKampanyeAktif={false}
                Kontak={{ Aktif: 1234, Berhenti: 5, Total: 1239 }}
            />,
        );

        expect(screen.getByRole('link', { name: 'Perkenalan untuk kafe' }).getAttribute('href')).toBe(
            '/kampanye-prospek/01JKAMPANYE00000000000001',
        );
        expect(screen.getByText(/80 dari 200 terkirim \| 2 gagal \| 1 dilewati/)).toBeTruthy();
        expect(screen.getByText(/1\.234 kontak aktif \| 5 berhenti atau tidak aktif/)).toBeTruthy();
        expect(screen.getByText('Email kampanye belum aktif')).toBeTruthy();
        expect(screen.getByRole('link', { name: 'Buat kampanye' }).getAttribute('href')).toBe('/kampanye-prospek/buat');

        // Tab kampanye aktif di halaman ini; tab Kontak tidak.
        const nav = screen.getByRole('navigation', { name: 'Kampanye prospek' });

        expect(within(nav).getByRole('link', { name: 'Kampanye' }).getAttribute('aria-current')).toBe('page');
        expect(within(nav).getByRole('link', { name: 'Kontak' }).getAttribute('aria-current')).toBeNull();
    });

    it('tanpa peringatan dan dengan keadaan kosong bila belum ada kampanye', () => {
        AturKonsol();
        RenderUji(<Daftar Kampanye={[]} EmailKampanyeAktif Kontak={{ Aktif: 0, Berhenti: 0, Total: 0 }} />);

        expect(screen.queryByText('Email kampanye belum aktif')).toBeNull();
        expect(screen.getByText(/Belum ada kampanye/)).toBeTruthy();
    });
});

describe('Buat kampanye prospek', () => {
    const pilihan = [
        { Nilai: 'FNB-CAF', Label: 'Kafe & kedai kopi', Aktif: 120, Total: 125 },
        { Nilai: 'BIZ-JSA', Label: 'Jasa profesional & agensi', Aktif: 80, Total: 80 },
    ];

    it('menghitung penerima dari sektor terpilih dan lama selesainya menurut batas per hari', () => {
        AturKonsol('/kampanye-prospek/buat');
        RenderUji(
            <Buat
                PilihanSektor={pilihan}
                EmailKampanyeAktif
                BatasBawaan={40}
                MaksBatas={500}
                PenandaSektor="{sektor}"
            />,
        );

        expect(screen.getByRole('status').textContent).toBe('Pilih sedikitnya satu sektor.');
        expect((screen.getByRole('button', { name: 'Simpan sebagai draf' }) as HTMLButtonElement).disabled).toBe(true);

        fireEvent.click(screen.getByRole('checkbox', { name: /Kafe & kedai kopi/ }));
        fireEvent.click(screen.getByRole('checkbox', { name: /Jasa profesional & agensi/ }));

        expect(screen.getByRole('status').textContent).toContain('200 penerima aktif');
        expect(screen.getByText(/selesai dalam sekitar 5 hari kerja/)).toBeTruthy();
    });

    it('mengirim formulir ke server setelah semua bidang wajib terisi', () => {
        AturKonsol('/kampanye-prospek/buat');
        RenderUji(
            <Buat
                PilihanSektor={pilihan}
                EmailKampanyeAktif={false}
                BatasBawaan={40}
                MaksBatas={500}
                PenandaSektor="{sektor}"
            />,
        );

        // Draf boleh disimpan walau pengirim belum aktif, tetapi peringatannya tampil.
        expect(screen.getByText('Email kampanye belum aktif')).toBeTruthy();

        fireEvent.change(screen.getByLabelText(/Nama kampanye/), { target: { value: 'Perkenalan untuk kafe' } });
        fireEvent.change(screen.getByLabelText(/Subjek email/), { target: { value: 'Kasir untuk {sektor}' } });
        fireEvent.change(screen.getByLabelText(/Isi email/), {
            target: { value: 'Halo, Payoung adalah aplikasi kasir untuk usaha Anda.' },
        });
        fireEvent.click(screen.getByRole('checkbox', { name: /Kafe & kedai kopi/ }));
        fireEvent.click(screen.getByRole('button', { name: 'Simpan sebagai draf' }));

        expect(kirimanForm).toHaveLength(1);
        expect(kirimanForm[0]?.url).toBe('/kampanye-prospek');
        expect(kirimanForm[0]?.data).toMatchObject({
            Nama: 'Perkenalan untuk kafe',
            Sektor: ['FNB-CAF'],
            BatasPerHari: '40',
        });
    });
});

describe('Rincian kampanye prospek', () => {
    const penerima = [
        {
            Id: 1,
            Email: 'a@contoh.id',
            Sektor: 'Kafe & kedai kopi',
            Status: 'Gagal' as const,
            Alasan: 'Kotak surat penuh.',
            DikirimPada: null,
        },
        {
            Id: 2,
            Email: 'b@contoh.id',
            Sektor: 'Kafe & kedai kopi',
            Status: 'Terkirim' as const,
            Alasan: null,
            DikirimPada: '2026-10-26T03:00:00Z',
        },
    ];

    it('draf: jalankan lewat konfirmasi. Tombol mati selama email kampanye belum aktif', () => {
        AturKonsol();
        const { unmount: Lepas } = RenderUji(
            <Tampil Kampanye={{ ...kampanye, Isi: 'Isi pesan' }} EmailKampanyeAktif={false} Penerima={[]} />,
        );

        expect((screen.getByRole('button', { name: 'Jalankan kampanye' }) as HTMLButtonElement).disabled).toBe(true);
        expect((screen.getByRole('button', { name: 'Kirim email uji ke saya' }) as HTMLButtonElement).disabled).toBe(
            true,
        );
        Lepas();

        RenderUji(<Tampil Kampanye={{ ...kampanye, Isi: 'Isi pesan' }} EmailKampanyeAktif Penerima={[]} />);
        fireEvent.click(screen.getByRole('button', { name: 'Kirim email uji ke saya' }));

        expect(tiruanRouter.post).toHaveBeenCalledWith(
            '/kampanye-prospek/01JKAMPANYE00000000000001/uji',
            {},
            expect.anything(),
        );

        fireEvent.click(screen.getByRole('button', { name: 'Jalankan kampanye' }));
        fireEvent.click(screen.getAllByRole('button', { name: 'Jalankan kampanye' }).at(-1) as HTMLElement);

        expect(tiruanRouter.put).toHaveBeenCalledWith(
            '/kampanye-prospek/01JKAMPANYE00000000000001/status',
            { Status: 'Berjalan' },
            expect.anything(),
        );
    });

    it('dijeda: alasan tampil, bisa dilanjutkan atau dibatalkan. Hasil per penerima gagal di atas', () => {
        AturKonsol();
        RenderUji(
            <Tampil
                Kampanye={{
                    ...kampanye,
                    Status: 'Dijeda',
                    LabelStatus: 'Dijeda',
                    AlasanJeda: 'Lima email terakhir gagal berturut-turut.',
                    JumlahPenerima: 2,
                    JumlahTerkirim: 1,
                    JumlahGagal: 1,
                    Isi: 'Isi pesan',
                }}
                EmailKampanyeAktif
                Penerima={penerima}
            />,
        );

        expect(screen.getByText('Lima email terakhir gagal berturut-turut.')).toBeTruthy();
        expect(screen.getByRole('button', { name: 'Lanjutkan kampanye' })).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Jalankan kampanye' })).toBeNull();

        fireEvent.click(screen.getByRole('button', { name: 'Batalkan kampanye' }));
        fireEvent.click(screen.getAllByRole('button', { name: 'Batalkan kampanye' }).at(-1) as HTMLElement);

        expect(tiruanRouter.put).toHaveBeenCalledWith(
            '/kampanye-prospek/01JKAMPANYE00000000000001/status',
            { Status: 'Dibatalkan' },
            expect.anything(),
        );
        expect(screen.getByText('Kotak surat penuh.')).toBeTruthy();
    });

    it('selesai: tanpa tombol aksi apa pun', () => {
        AturKonsol();
        RenderUji(
            <Tampil
                Kampanye={{
                    ...kampanye,
                    Status: 'Selesai',
                    LabelStatus: 'Selesai',
                    DapatDijalankan: false,
                    DapatDibatalkan: false,
                    Isi: 'Isi pesan',
                }}
                EmailKampanyeAktif
                Penerima={[]}
            />,
        );

        expect(screen.queryByRole('button', { name: /Jalankan|Jeda|Lanjutkan|Batalkan|Kirim email uji/ })).toBeNull();
    });
});

describe('Kontak prospek', () => {
    it('menampilkan kontak dan ringkasannya. Impor menunggu berkas dan sumber', () => {
        AturKonsol('/kampanye-prospek/kontak');
        RenderUji(
            <Kontak
                Kontak={[
                    kontakAktif,
                    {
                        ...kontakAktif,
                        Uuid: '01JKONTAK0000000000000002',
                        Email: 'pergi@contoh.id',
                        Status: 'Berhenti',
                        LabelStatus: 'Berhenti berlangganan',
                    },
                ]}
                Ringkasan={{ Aktif: 1, Berhenti: 1, Total: 2 }}
                MaksBarisDaftar={2000}
                MaksBarisImpor={5000}
                PilihanSektor={[{ Nilai: 'FNB-CAF', Label: 'Kafe & kedai kopi' }]}
                PilihanStatus={[{ Nilai: 'Aktif', Label: 'Aktif' }]}
            />,
        );

        expect(screen.getByText('kopi@contoh.id')).toBeTruthy();
        expect(screen.getByText('pergi@contoh.id')).toBeTruthy();
        expect(screen.getByText(/2 kontak \| 1 aktif \| 1 berhenti atau tidak aktif/)).toBeTruthy();
        expect(screen.getByText(/kolom bernama/)).toBeTruthy();
        expect((screen.getByRole('button', { name: 'Impor kontak' }) as HTMLButtonElement).disabled).toBe(true);

        const nav = screen.getByRole('navigation', { name: 'Kampanye prospek' });

        expect(within(nav).getByRole('link', { name: 'Kontak' }).getAttribute('aria-current')).toBe('page');
    });
});

describe('Berhenti berlangganan publik', () => {
    it('konfirmasi lalu kirim. Tidak pernah menampilkan alamat email', () => {
        AturHalamanUji({}, '/berhenti-prospek/01JKONTAK0000000000000001');
        const alamat = '/berhenti-prospek/01JKONTAK0000000000000001?expires=1&signature=abc';
        const { container } = RenderUji(<BerhentiProspek Ditemukan SudahBerhenti={false} AlamatKirim={alamat} />);

        expect(screen.getByText('Berhenti menerima email dari Payoung?')).toBeTruthy();
        expect(container.textContent).not.toContain('@');

        fireEvent.click(screen.getByRole('button', { name: 'Berhenti berlangganan' }));

        expect(tiruanRouter.post).toHaveBeenCalledWith(alamat, {}, expect.anything());
    });

    it('sudah berhenti dan tautan rusak punya pesannya sendiri', () => {
        AturHalamanUji({}, '/berhenti-prospek/x');
        const { unmount: Lepas } = RenderUji(<BerhentiProspek Ditemukan SudahBerhenti AlamatKirim={null} />);

        expect(screen.getByText('Anda sudah berhenti berlangganan')).toBeTruthy();
        Lepas();

        RenderUji(<BerhentiProspek Ditemukan={false} SudahBerhenti={false} AlamatKirim={null} />);

        expect(screen.getByText('Tautan tidak berlaku')).toBeTruthy();
    });
});
