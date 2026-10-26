import { cleanup, fireEvent, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanRekonsiliasiBank from '@/Halaman/Kelola/Akuntansi/Rekonsiliasi';
import { AturHalamanUji, RenderUji, tiruanRouter } from '@/Komponen/Katalog/TiruanInertia';
import { BuatHasilTabel } from '@/Komponen/Persediaan/DataUjiPersediaan';
import type { BarisMutasiBank, PropsRekonsiliasiBank } from '@/Tipe/Akuntansi';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

const Akun = { Uuid: '01J9AKN0000000000000000002', Kode: '1-1200', Nama: 'Bank BCA' };
const Mutasi: BarisMutasiBank = {
    Uuid: '01J9MTS0000000000000000001',
    Tanggal: '2026-10-05',
    Keterangan: 'TRSF MASUK QRIS',
    Masuk: '500000.00',
    Keluar: '0.00',
    Saldo: '12000000.00',
    Status: 'BelumCocok',
    LabelStatus: 'Belum cocok',
    AlasanAbaikan: null,
    Jurnal: null,
    Kandidat: [
        {
            Uuid: 'J1',
            Nomor: 'JU-2610-000003',
            Keterangan: 'Setoran QRIS',
            Tanggal: '2026-10-05',
            Debit: '500000.00',
            Kredit: '0.00',
        },
    ],
};

function Props(ubah: Partial<PropsRekonsiliasiBank> = {}): PropsRekonsiliasiBank {
    return {
        Mutasi: BuatHasilTabel([Mutasi]),
        Akun,
        OpsiAkun: [Akun],
        Ringkasan: {
            BelumCocok: 1,
            Cocok: 3,
            Diabaikan: 0,
            TanggalTerakhir: '2026-10-10',
            SaldoRekeningKoran: '12485000.00',
            SaldoBuku: '12500000.00',
            Selisih: '-15000.00',
        },
        BukuBelumCocok: [],
        OpsiStatus: [{ Nilai: 'BelumCocok', Label: 'Belum cocok' }],
        HasilImpor: null,
        Izin: { Kelola: true },
        ...ubah,
    };
}

describe('Rekonsiliasi bank (FIN-09, v3.39)', () => {
    beforeEach(() => {
        AturHalamanUji({}, `/kelola/akuntansi/rekonsiliasi/${Akun.Uuid}`);
        window.history.replaceState({}, '', `/kelola/akuntansi/rekonsiliasi/${Akun.Uuid}`);
    });
    afterEach(() => cleanup());

    it('ringkasan selisih dan jumlah kandidat. Cocokkan otomatis', () => {
        RenderUji(<HalamanRekonsiliasiBank {...Props()} />);
        expect(screen.getByText('−Rp 15.000')).toBeTruthy();
        expect(screen.getAllByText('1 kemungkinan pasangan di buku').length).toBeGreaterThan(0);
        fireEvent.click(screen.getByRole('button', { name: 'Cocokkan otomatis' }));
        expect(tiruanRouter.post).toHaveBeenCalledWith(
            `/kelola/akuntansi/rekonsiliasi/${Akun.Uuid}/cocokkan-otomatis`,
            {},
            expect.anything(),
        );
    });

    it('hanya lihat: tanpa unggah & tanpa cocokkan otomatis', () => {
        RenderUji(<HalamanRekonsiliasiBank {...Props({ Izin: { Kelola: false } })} />);
        expect(screen.queryByRole('button', { name: 'Impor mutasi' })).toBeNull();
        expect(screen.queryByRole('button', { name: 'Cocokkan otomatis' })).toBeNull();
    });
});
