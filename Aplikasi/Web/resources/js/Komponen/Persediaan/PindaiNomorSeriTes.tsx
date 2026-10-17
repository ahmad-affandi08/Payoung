import { cleanup, fireEvent, screen, waitFor } from '@testing-library/react';
import { useState } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { RenderUji } from '@/Komponen/Katalog/TiruanInertia';
import { SiapkanKameraTiruan } from '@/Pengujian/KameraTiruan';

import BidangNomorSeri from './BidangNomorSeri';
import PemilihPelacakan from './Dokumen/PemilihPelacakan';
import TombolPindaiNomorSeri from './TombolPindaiNomorSeri';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

const kamera = vi.hoisted(() => ({
    mulai: vi.fn(),
    daftar: vi.fn(),
}));

vi.mock('@/Pustaka/PemindaiKamera', async (impor) => ({
    ...(await impor<typeof import('@/Pustaka/PemindaiKamera')>()),
    MulaiPemindaian: kamera.mulai,
    DaftarKamera: kamera.daftar,
}));

/** Jam tiruan: pindaian ulang nomor yang sama baru dihitung lagi setelah jeda 2 detik. */
function PasangJam() {
    let sekarang = 5_000_000;

    vi.spyOn(Date, 'now').mockImplementation(() => sekarang);

    return () => {
        sekarang += 3000;
    };
}

async function BukaKamera(namaTombol: string, tiruan: ReturnType<typeof SiapkanKameraTiruan>) {
    fireEvent.click(screen.getByRole('button', { name: namaTombol }));
    await waitFor(() => expect(tiruan.opsiTerakhir()).not.toBeNull());
    await waitFor(() => expect(screen.queryByText('Membuka kamera…')).toBeNull());
}

function WadahTambah({ awal = [] as string[], maksimal }: { awal?: string[]; maksimal?: number }) {
    const [daftar, AturDaftar] = useState(awal);

    return (
        <>
            <TombolPindaiNomorSeri
                label="Pindai nomor seri Ponsel X"
                daftar={daftar}
                saatBerubah={AturDaftar}
                {...(maksimal === undefined ? {} : { maksimal })}
            />
            <output aria-label="isi">{daftar.join('|')}</output>
        </>
    );
}

function WadahCentang({ tersedia, awal = [] as string[] }: { tersedia: string[]; awal?: string[] }) {
    const [daftar, AturDaftar] = useState(awal);

    return (
        <>
            <TombolPindaiNomorSeri
                label="Pindai nomor seri retur Ponsel X"
                daftar={daftar}
                tersedia={tersedia}
                saatBerubah={AturDaftar}
            />
            <output aria-label="isi">{daftar.join('|')}</output>
        </>
    );
}

describe('TombolPindaiNomorSeri (F-05, BR nomor seri unik)', () => {
    beforeEach(() => {
        kamera.mulai.mockReset();
        kamera.daftar.mockReset();
    });
    afterEach(() => {
        cleanup();
        vi.restoreAllMocks();
    });

    it('mode tambah: tiap nomor masuk ke daftar, kamera tetap terbuka, nomor ganda (beda huruf besar) ditolak dengan pesan', async () => {
        const tiruan = SiapkanKameraTiruan(kamera);
        const Maju = PasangJam();

        RenderUji(<WadahTambah awal={['', 'IMEI-0001']} />);
        await BukaKamera('Pindai nomor seri Ponsel X', tiruan);

        tiruan.terbaca('IMEI-0002');
        await waitFor(() => expect(screen.getByLabelText('isi').textContent).toBe('IMEI-0001|IMEI-0002'));
        expect(await screen.findByText('IMEI-0002 ditambahkan (2 nomor).')).toBeTruthy();

        Maju();
        tiruan.terbaca('imei-0001');
        expect(await screen.findByText('imei-0001 sudah ada, tidak ditambahkan lagi.')).toBeTruthy();
        expect(screen.getByLabelText('isi').textContent).toBe('IMEI-0001|IMEI-0002');
        expect(screen.getByRole('dialog')).toBeTruthy();
    });

    it('mode tambah: batas jumlah dan kode terlalu panjang ditolak', async () => {
        const tiruan = SiapkanKameraTiruan(kamera);
        const Maju = PasangJam();

        RenderUji(<WadahTambah awal={['A1']} maksimal={1} />);
        await BukaKamera('Pindai nomor seri Ponsel X', tiruan);

        tiruan.terbaca('A2');
        expect(await screen.findByText('Sudah mencapai batas 1 nomor seri.')).toBeTruthy();

        Maju();
        tiruan.terbaca('X'.repeat(101));
        expect(await screen.findByText(/Kode terlalu panjang/)).toBeTruthy();
        expect(screen.getByLabelText('isi').textContent).toBe('A1');
    });

    it('mode centang: hanya nomor yang tersedia dicentang (huruf kecil dipulihkan ke tulisan aslinya), selain itu ditolak', async () => {
        const tiruan = SiapkanKameraTiruan(kamera);
        const Maju = PasangJam();

        RenderUji(<WadahCentang tersedia={['SN-001', 'SN-002']} />);
        await BukaKamera('Pindai nomor seri retur Ponsel X', tiruan);

        tiruan.terbaca('sn-002');
        await waitFor(() => expect(screen.getByLabelText('isi').textContent).toBe('SN-002'));
        expect(await screen.findByText('SN-002 dicentang (1 nomor).')).toBeTruthy();

        Maju();
        tiruan.terbaca('SN-999');
        expect(await screen.findByText('SN-999 tidak termasuk nomor seri yang bisa dipilih di sini.')).toBeTruthy();

        Maju();
        tiruan.terbaca('SN-002');
        expect(await screen.findByText('SN-002 sudah ada, tidak ditambahkan lagi.')).toBeTruthy();
        expect(screen.getByLabelText('isi').textContent).toBe('SN-002');
    });

    it('jenis kode yang diminta kamera adalah Seri (QR, DataMatrix ikut dibaca)', async () => {
        const tiruan = SiapkanKameraTiruan(kamera);

        RenderUji(<WadahTambah />);
        await BukaKamera('Pindai nomor seri Ponsel X', tiruan);

        expect(tiruan.opsiTerakhir()).toMatchObject({ jenisKode: 'Seri' });
        expect(screen.getByRole('dialog', { name: 'Pindai nomor seri atau IMEI dengan kamera' })).toBeTruthy();
    });
});

describe('BidangNomorSeri + kamera', () => {
    beforeEach(() => {
        kamera.mulai.mockReset();
        kamera.daftar.mockReset();
    });
    afterEach(() => cleanup());

    it('nomor dari kamera masuk ke kotak (satu per baris) dan ke nilai yang dikirim', async () => {
        const tiruan = SiapkanKameraTiruan(kamera);
        const SaatBerubah = vi.fn();

        RenderUji(
            <BidangNomorSeri label="Nomor seri Ponsel X" nilai={['A1']} saatBerubah={SaatBerubah} maksimal={50} />,
        );
        await BukaKamera('Pindai nomor seri dengan kamera Nomor seri Ponsel X', tiruan);
        tiruan.terbaca('B2');

        await waitFor(() => expect(SaatBerubah).toHaveBeenCalledWith(['A1', 'B2']));
        expect((screen.getByLabelText('Nomor seri Ponsel X') as HTMLTextAreaElement).value).toBe('A1\nB2');
    });
});

describe('PemilihPelacakan + kamera (nomor seri keluar)', () => {
    beforeEach(() => {
        kamera.mulai.mockReset();
        kamera.daftar.mockReset();
        vi.stubGlobal(
            'fetch',
            vi.fn(() =>
                Promise.resolve({
                    ok: true,
                    json: () =>
                        Promise.resolve({
                            Batch: [],
                            Seri: [
                                { Uuid: 'S-1', Nomor: 'IMEI-0001' },
                                { Uuid: 'S-2', Nomor: 'IMEI-0002' },
                            ],
                        }),
                }),
            ),
        );
    });
    afterEach(() => {
        cleanup();
        vi.unstubAllGlobals();
        vi.restoreAllMocks();
    });

    function Tampilkan(pelacakan: 'Seri' | 'Batch', SaatBerubah = vi.fn()) {
        RenderUji(
            <PemilihPelacakan
                nama="Ponsel X"
                pelacakan={pelacakan}
                uuidProduk="P-1"
                uuidGudang="G-1"
                simbolSatuan="pcs"
                nilai={null}
                saatBerubah={SaatBerubah}
            />,
        );

        return SaatBerubah;
    }

    it('label yang dipindai memilih nomor seri tersedia yang cocok', async () => {
        const tiruan = SiapkanKameraTiruan(kamera);
        const SaatBerubah = Tampilkan('Seri');

        await waitFor(() => expect(screen.getByRole('button', { name: 'Pindai nomor seri Ponsel X' })).toBeTruthy());
        await waitFor(() =>
            expect(
                (screen.getByRole('button', { name: 'Pindai nomor seri Ponsel X' }) as HTMLButtonElement).disabled,
            ).toBe(false),
        );
        await BukaKamera('Pindai nomor seri Ponsel X', tiruan);
        tiruan.terbaca('imei-0002');

        await waitFor(() => expect(SaatBerubah).toHaveBeenCalledWith('S-2', 'IMEI-0002'));
    });

    it('nomor yang tidak tersedia di lokasi ini tidak dipilih dan dijelaskan', async () => {
        const tiruan = SiapkanKameraTiruan(kamera);
        const SaatBerubah = Tampilkan('Seri');

        await waitFor(() =>
            expect(
                (screen.getByRole('button', { name: 'Pindai nomor seri Ponsel X' }) as HTMLButtonElement).disabled,
            ).toBe(false),
        );
        await BukaKamera('Pindai nomor seri Ponsel X', tiruan);
        tiruan.terbaca('IMEI-9999');

        expect(await screen.findByText('IMEI-9999 tidak tersedia di lokasi ini.')).toBeTruthy();
        expect(SaatBerubah).not.toHaveBeenCalled();
    });

    it('pemilih batch tidak punya tombol kamera', async () => {
        Tampilkan('Batch');

        await waitFor(() => expect(screen.getByRole('combobox', { name: 'Batch Ponsel X' })).toBeTruthy());
        expect(screen.queryByRole('button', { name: /Pindai nomor seri/ })).toBeNull();
    });
});
