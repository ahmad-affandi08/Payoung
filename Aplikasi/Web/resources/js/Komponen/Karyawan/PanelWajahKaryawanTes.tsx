import { cleanup, fireEvent, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import PanelAbsenHp from '@/Komponen/Karyawan/PanelAbsenHp';
import { AturHalamanUji, RenderUji, tiruanRouter } from '@/Komponen/Katalog/TiruanInertia';
import LokasiAbsensiOutlet from '@/Komponen/Kelola/LokasiAbsensiOutlet';
import type { AbsenHpKaryawan, BarisKaryawan } from '@/Tipe/Karyawan';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

/*
 * F-18 bagian 4 (D-37, D-180, D-198) back-office: panel absen karyawan (tautan pribadi, foto wajah, tolak beralasan,
 * atur ulang) dan lokasi absensi outlet (titik + radius, hapus titik).
 */
const karyawan: BarisKaryawan = {
    Uuid: '01J9KRY0000000000000000001',
    Nama: 'Rina Wulandari',
    Jabatan: 'Barista',
    LevelStaf: null,
    SiklusGaji: 'Bulanan',
    DasarGaji: 'Tetap',
    GajiPokok: null,
    TarifLemburPerJam: null,
    PotonganTerlambatPerMenit: null,
    PotonganTidakMasukPerHari: null,
    UuidPengguna: null,
    NamaPengguna: null,
    UuidOutlet: null,
    NamaOutlet: null,
    Status: 'Aktif',
    LabelStatus: 'Aktif',
    IkutAbsensi: true,
    StatusWajah: 'Menunggu',
};

function TiruFetch(isi: AbsenHpKaryawan) {
    vi.stubGlobal(
        'fetch',
        vi.fn(() =>
            Promise.resolve(
                new Response(JSON.stringify(isi), { status: 200, headers: { 'Content-Type': 'application/json' } }),
            ),
        ),
    );
}

beforeEach(() => AturHalamanUji({}, '/kelola/karyawan'));
afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

describe('Panel wajah karyawan', () => {
    it('menampilkan 3 foto wajah menunggu; setujui mengirim Setujui=true; tolak wajib beralasan', async () => {
        TiruFetch({
            Tautan: null,
            TautanDibuatPada: null,
            Wajah: {
                Status: 'Menunggu',
                Label: 'Menunggu persetujuan',
                JumlahFoto: 3,
                AlasanTolak: null,
                DibuatPada: null,
                DitinjauPada: null,
            },
        });
        RenderUji(<PanelAbsenHp karyawan={karyawan} saatTutup={() => undefined} />);

        await waitFor(() => expect(screen.getAllByRole('img')).toHaveLength(3));
        // Tiruan router tidak memanggil onFinish (tombol tetap "memproses"), jadi tolak diuji lebih dulu, setujui di
        // render berikutnya.
        fireEvent.click(screen.getByRole('button', { name: 'Tolak' }));
        const tolak = screen.getByRole('button', { name: 'Tolak wajah' }) as HTMLButtonElement;
        expect(tolak.disabled).toBe(true);
        fireEvent.change(screen.getByLabelText(/Alasan penolakan/), { target: { value: 'Foto gelap' } });
        fireEvent.click(tolak);
        expect(tiruanRouter.post).toHaveBeenLastCalledWith(
            `/kelola/karyawan/${karyawan.Uuid}/wajah/tinjau`,
            { Setujui: false, Alasan: 'Foto gelap' },
            expect.anything(),
        );
        cleanup();

        RenderUji(<PanelAbsenHp karyawan={karyawan} saatTutup={() => undefined} />);
        fireEvent.click(await screen.findByRole('button', { name: 'Setujui wajah' }));
        expect(tiruanRouter.post).toHaveBeenLastCalledWith(
            `/kelola/karyawan/${karyawan.Uuid}/wajah/tinjau`,
            { Setujui: true },
            expect.anything(),
        );
    });

    it('wajah disetujui bisa diatur ulang, lewat konfirmasi dulu', async () => {
        TiruFetch({
            Tautan: null,
            TautanDibuatPada: null,
            Wajah: {
                Status: 'Disetujui',
                Label: 'Disetujui',
                JumlahFoto: 3,
                AlasanTolak: null,
                DibuatPada: null,
                DitinjauPada: null,
            },
        });
        RenderUji(<PanelAbsenHp karyawan={{ ...karyawan, StatusWajah: 'Disetujui' }} saatTutup={() => undefined} />);

        // Aksi merusak lewat konfirmasi dulu: klik pertama hanya membuka dialog.
        fireEvent.click(await screen.findByRole('button', { name: 'Atur ulang wajah' }));
        expect(tiruanRouter.delete).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: 'Hapus wajah' }));
        expect(tiruanRouter.delete).toHaveBeenCalledWith(`/kelola/karyawan/${karyawan.Uuid}/wajah`, expect.anything());
    });
});

describe('Tautan absen pribadi (D-198)', () => {
    const wajahKosong = null;

    it('belum ada tautan: tombol Buat tautan absen mengirim POST ke karyawan itu', async () => {
        TiruFetch({ Tautan: null, TautanDibuatPada: null, Wajah: wajahKosong });
        RenderUji(<PanelAbsenHp karyawan={karyawan} saatTutup={() => undefined} />);

        expect(await screen.findByText(/Belum ada tautan absen/)).not.toBeNull();
        fireEvent.click(screen.getByRole('button', { name: 'Buat tautan absen' }));
        expect(tiruanRouter.post).toHaveBeenLastCalledWith(
            `/kelola/karyawan/${karyawan.Uuid}/tautan-absen`,
            {},
            expect.anything(),
        );
    });

    it('karyawan nonaktif tidak bisa diberi tautan', async () => {
        TiruFetch({ Tautan: null, TautanDibuatPada: null, Wajah: wajahKosong });
        RenderUji(<PanelAbsenHp karyawan={{ ...karyawan, Status: 'Nonaktif' }} saatTutup={() => undefined} />);

        const tombol = (await screen.findByRole('button', { name: 'Buat tautan absen' })) as HTMLButtonElement;
        expect(tombol.disabled).toBe(true);
    });

    it('sudah ada tautan: tampil, bisa dikirim lewat WhatsApp; buat ulang dan cabut lewat konfirmasi dulu', async () => {
        const alamat = 'https://payoung.id/kedai-senja/absen/aBcDeFgHiJkLmNoPqRsTuVwXyZ0123456789AbCd';
        TiruFetch({ Tautan: alamat, TautanDibuatPada: '2026-10-25T03:00:00+00:00', Wajah: wajahKosong });
        RenderUji(<PanelAbsenHp karyawan={karyawan} saatTutup={() => undefined} />);

        expect(await screen.findByText(alamat)).not.toBeNull();
        const wa = screen.getByRole('link', { name: 'Kirim lewat WhatsApp' }).getAttribute('href') ?? '';
        expect(wa.startsWith('https://wa.me/?text=')).toBe(true);
        expect(decodeURIComponent(wa)).toContain(alamat);

        fireEvent.click(screen.getByRole('button', { name: 'Cabut tautan' }));
        expect(tiruanRouter.delete).not.toHaveBeenCalled();
        fireEvent.click(screen.getAllByRole('button', { name: 'Cabut tautan' }).at(-1) as HTMLElement);
        expect(tiruanRouter.delete).toHaveBeenCalledWith(
            `/kelola/karyawan/${karyawan.Uuid}/tautan-absen`,
            expect.anything(),
        );
    });
});

describe('Lokasi absensi outlet', () => {
    it('menyimpan titik & radius, dan menghapus titik', () => {
        RenderUji(
            <LokasiAbsensiOutlet
                alamatOutlet="/kelola/outlet/O-1"
                data={{ Lintang: '-7.5560000', Bujur: '110.8310000', RadiusMeter: 100 }}
                bolehKelola
            />,
        );

        expect(screen.getByRole('link', { name: 'Lihat di peta' })).not.toBeNull();
        fireEvent.change(screen.getByLabelText(/Radius/), { target: { value: '150' } });
        fireEvent.click(screen.getByRole('button', { name: 'Simpan lokasi absensi' }));
        expect(tiruanRouter.post).toHaveBeenCalledWith(
            '/kelola/outlet/O-1/lokasi-absensi',
            { Lintang: '-7.5560000', Bujur: '110.8310000', RadiusAbsensiMeter: 150 },
            expect.anything(),
        );

        cleanup();

        RenderUji(
            <LokasiAbsensiOutlet
                alamatOutlet="/kelola/outlet/O-1"
                data={{ Lintang: '-7.5560000', Bujur: '110.8310000', RadiusMeter: 100 }}
                bolehKelola
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Hapus titik lokasi' }));
        expect(tiruanRouter.post).toHaveBeenLastCalledWith(
            '/kelola/outlet/O-1/lokasi-absensi',
            { Lintang: null, Bujur: null, RadiusAbsensiMeter: 100 },
            expect.anything(),
        );
    });

    it('tanpa titik & tanpa izin: hanya keterangan, tanpa formulir', () => {
        RenderUji(
            <LokasiAbsensiOutlet
                alamatOutlet="/kelola/outlet/O-1"
                data={{ Lintang: null, Bujur: null, RadiusMeter: 100 }}
                bolehKelola={false}
            />,
        );

        expect(screen.getByText(/Belum ada titik lokasi/)).not.toBeNull();
        expect(screen.queryByRole('button')).toBeNull();
    });
});
