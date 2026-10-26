import { cleanup, fireEvent, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanDaftarPermintaan from '@/Halaman/Kelola/Pembelian/Permintaan/Daftar';
import HalamanDetailPermintaan from '@/Halaman/Kelola/Pembelian/Permintaan/Detail';
import HalamanFormPermintaan from '@/Halaman/Kelola/Pembelian/Permintaan/Form';
import { AturHalamanUji, RenderUji, tiruanRouter } from '@/Komponen/Katalog/TiruanInertia';
import { BuatHasilTabel, GudangUtama, NamaPanjang } from '@/Komponen/Persediaan/DataUjiPersediaan';
import type {
    BarisDaftarPermintaan,
    IzinPembelian,
    OpsiPemasok,
    PropsDetailPermintaan,
    PropsFormPermintaan,
} from '@/Tipe/Pembelian';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

/* F-04 PR: daftar, form (kirim PUT/POST), dan detail (tombol mengikuti Tindakan) permintaan pembelian. */

const Uuid = '01J9PRM0000000000000000001';
const IzinPenuh: IzinPembelian = { Kelola: true, Setujui: true, LihatJurnal: false, Persediaan: true };
const IzinLihat: IzinPembelian = { Kelola: false, Setujui: false, LihatJurnal: false, Persediaan: true };
const Pemasok: OpsiPemasok = {
    Uuid: '01J9PMS0000000000000000001',
    Kode: 'SUP-001',
    Nama: 'PT Beras Delanggu Sejahtera',
    Pkp: false,
    TerminHari: 30,
    Aktif: true,
};

const baris: BarisDaftarPermintaan = {
    Uuid,
    Nomor: 'PP/UTAMA/2610/0001',
    Tanggal: '2026-10-13',
    DibutuhkanPada: '2026-10-16',
    NamaPemasok: Pemasok.Nama,
    NamaGudang: 'Gudang Utama',
    NamaOutlet: 'Kopi Nusantara Solo',
    JumlahBaris: 3,
    Status: 'Diajukan',
    LabelStatus: 'Menunggu persetujuan',
};

function PropsDetail(
    status: PropsDetailPermintaan['Permintaan']['Status'],
    tindakan: Partial<PropsDetailPermintaan['Tindakan']> = {},
): PropsDetailPermintaan {
    return {
        Permintaan: {
            Uuid,
            Nomor: 'PP/UTAMA/2610/0001',
            Tanggal: '2026-10-13',
            DibutuhkanPada: null,
            Status: status,
            LabelStatus: status,
            Pemasok: null,
            UuidGudang: GudangUtama.Uuid,
            NamaGudang: 'Gudang Utama',
            NamaOutlet: null,
            Catatan: null,
            AlasanDitolak: null,
            AlasanBatal: null,
            DibuatOleh: 'Rina',
            DiputuskanOleh: null,
            DiputuskanPada: null,
            Pesanan: null,
        },
        Baris: [
            {
                Id: 1,
                NamaProduk: NamaPanjang,
                Sku: 'KOPI-JMB',
                SimbolSatuan: 'pcs',
                Jumlah: '12.0000',
                HargaPerkiraan: '45000.00',
            },
        ],
        Riwayat: [],
        Izin: IzinPenuh,
        OpsiPemasok: [Pemasok],
        Tindakan: { Ubah: false, Ajukan: false, Putuskan: false, JadikanPo: false, Batalkan: false, ...tindakan },
    };
}

function PropsForm(timpa: Partial<PropsFormPermintaan> = {}): PropsFormPermintaan {
    return {
        Mode: 'Ubah',
        Permintaan: {
            Uuid,
            Nomor: 'PP/UTAMA/2610/0001',
            UuidPemasok: null,
            UuidGudang: GudangUtama.Uuid,
            Tanggal: '2026-10-13',
            DibutuhkanPada: null,
            Catatan: null,
            Baris: [
                {
                    UuidProduk: '01J9PRD0000000000000000001',
                    NamaProduk: NamaPanjang,
                    Sku: 'KOPI-JMB',
                    Pelacakan: 'Tidak',
                    SimbolSatuan: 'pcs',
                    BolehDesimal: false,
                    Satuan: [],
                    UuidProdukSatuan: null,
                    Jumlah: '12',
                    Harga: '0.00',
                    Diskon: '0.00',
                },
            ],
        },
        OpsiPemasok: [Pemasok],
        OpsiGudang: [GudangUtama],
        HariIni: '2026-10-13',
        MaksimalBaris: 500,
        ...timpa,
    };
}

describe('permintaan pembelian (F-04 PR)', () => {
    beforeEach(() => AturHalamanUji({}, '/kelola/pembelian/permintaan'));
    afterEach(cleanup);

    it('daftar: baris menautkan ke detail, jumlah barang & status tampil, tombol buat hanya bila berizin', () => {
        RenderUji(
            <HalamanDaftarPermintaan
                Permintaan={BuatHasilTabel([baris])}
                OpsiStatus={[{ Nilai: 'Diajukan', Label: 'Menunggu persetujuan' }]}
                Izin={IzinPenuh}
            />,
        );

        expect(screen.getByRole('link', { name: 'PP/UTAMA/2610/0001' }).getAttribute('href')).toBe(
            `/kelola/pembelian/permintaan/${Uuid}`,
        );
        expect(screen.getByText('3 item')).toBeTruthy();
        expect(screen.getAllByText('Menunggu persetujuan').length).toBeGreaterThan(0);
        expect(screen.getByRole('link', { name: 'Buat permintaan pembelian' }).getAttribute('href')).toBe(
            '/kelola/pembelian/permintaan/buat',
        );
        cleanup();

        RenderUji(<HalamanDaftarPermintaan Permintaan={BuatHasilTabel([baris])} OpsiStatus={[]} Izin={IzinLihat} />);
        expect(screen.queryByRole('link', { name: 'Buat permintaan pembelian' })).toBeNull();
    });

    it('form ubah: Simpan & ajukan mengirim PUT dengan baris tanpa pemasok wajib', () => {
        RenderUji(<HalamanFormPermintaan {...PropsForm()} />);

        fireEvent.click(screen.getByRole('button', { name: 'Simpan & ajukan' }));

        expect(tiruanRouter.put).toHaveBeenCalledWith(
            `/kelola/pembelian/permintaan/${Uuid}`,
            expect.objectContaining({
                Lanjutkan: true,
                UuidGudang: GudangUtama.Uuid,
                UuidPemasok: null,
                Baris: [expect.objectContaining({ UuidProduk: '01J9PRD0000000000000000001', Jumlah: '12' })],
            }),
            expect.anything(),
        );
    });

    it('form buat: tanpa barang tidak terkirim dan menampilkan pesan', () => {
        RenderUji(<HalamanFormPermintaan {...PropsForm({ Mode: 'Buat', Permintaan: null })} />);

        fireEvent.click(screen.getByRole('button', { name: 'Simpan draf' }));

        expect(tiruanRouter.post).not.toHaveBeenCalled();
        expect(screen.getByText('Tambahkan minimal satu produk.')).toBeTruthy();
    });

    it('detail: draf punya Ubah & Ajukan, yang menunggu persetujuan punya Setujui/Tolak, yang disetujui punya Jadikan PO', () => {
        RenderUji(<HalamanDetailPermintaan {...PropsDetail('Draf', { Ubah: true, Ajukan: true, Batalkan: true })} />);
        expect(screen.getByRole('link', { name: 'Ubah draf' })).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: 'Ajukan permintaan' }));
        expect(tiruanRouter.post).toHaveBeenCalledWith(
            `/kelola/pembelian/permintaan/${Uuid}/ajukan`,
            {},
            expect.anything(),
        );
        expect(screen.queryByRole('button', { name: 'Setujui permintaan' })).toBeNull();
        cleanup();

        RenderUji(<HalamanDetailPermintaan {...PropsDetail('Diajukan', { Putuskan: true, Batalkan: true })} />);
        fireEvent.click(screen.getByRole('button', { name: 'Setujui permintaan' }));
        expect(tiruanRouter.post).toHaveBeenCalledWith(
            `/kelola/pembelian/permintaan/${Uuid}/setujui`,
            {},
            expect.anything(),
        );
        expect(screen.getByRole('button', { name: 'Tolak' })).toBeTruthy();
        cleanup();

        RenderUji(<HalamanDetailPermintaan {...PropsDetail('Disetujui', { JadikanPo: true, Batalkan: true })} />);
        expect(screen.getByRole('button', { name: 'Jadikan pesanan pembelian' })).toBeTruthy();
    });

    it('detail: menunggu persetujuan tanpa hak memutuskan menampilkan penjelasan. Hasil PO ditautkan', () => {
        RenderUji(<HalamanDetailPermintaan {...PropsDetail('Diajukan')} />);
        expect(screen.getByText('Menunggu persetujuan', { selector: 'p, div, h5, h3, strong, span' })).toBeTruthy();
        cleanup();

        const props = PropsDetail('DijadikanPo');
        props.Permintaan.Pesanan = { Uuid: '01J9PO00000000000000000001', Nomor: 'PO/UTAMA/2610/0007' };
        RenderUji(<HalamanDetailPermintaan {...props} />);
        expect(screen.getByRole('link', { name: 'PO/UTAMA/2610/0007' }).getAttribute('href')).toBe(
            '/kelola/pembelian/pesanan/01J9PO00000000000000000001',
        );
    });
});
