import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { AturHalamanUji, kirimanForm, tiruanRouter } from '@/Komponen/Katalog/TiruanInertia';
import PanelTransferManual from '@/Komponen/Langganan/PanelTransferManual';
import { PanelPengaturanPenagihan, type PengaturanPenagihan } from '@/Komponen/Langganan/PengaturanPenagihanPengelola';
import {
    AntreanRefund,
    PanelBatasRefund,
    PanelTindakanTagihan,
    RiwayatRefund,
    type BarisRefundMenunggu,
} from '@/Komponen/Langganan/TindakanTagihanPengelola';
import type { RefundLangganan, TagihanLangganan } from '@/Tipe/TagihanLangganan';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

/*
 * D-112 (P-08): tindakan tagihan di konsol (catat pembayaran langsung, hapus-buku, refund), antrean persetujuan refund,
 * dan panel transfer manual di sisi Owner.
 */

const tagihan: TagihanLangganan = {
    Uuid: 'TG-1',
    Nomor: 'INV/2026/09/000001',
    Jenis: 'Aktivasi',
    LabelJenis: 'Aktivasi',
    Status: 'Terbit',
    LabelStatus: 'Belum dibayar',
    Manual: true,
    Keterangan: 'Kontrak Enterprise',
    KodePaket: 'ENTERPRISE',
    NamaPaket: 'Enterprise',
    Siklus: 'Tahunan',
    JumlahBulan: 12,
    Subtotal: '12000000.00',
    SubtotalPaket: '12000000.00',
    RincianAddon: [],
    NaikPaket: null,
    KodeKupon: null,
    Diskon: '0.00',
    TarifPpn: '12.000000',
    PengaliDppPembilang: 11,
    PengaliDppPenyebut: 12,
    DasarPengenaanPajak: '11000000.00',
    JumlahPpn: '1320000.00',
    Total: '13320000.00',
    TerbitPada: '2026-09-23T03:00:00Z',
    JatuhTempoPada: '2026-09-30T03:00:00Z',
    DibayarPada: null,
    DibatalkanPada: null,
    AlasanBatal: null,
    DihapusBukuPada: null,
    AlasanHapusBuku: null,
    DikembalikanPada: null,
    PeriodeMulai: null,
    PeriodeSelesai: null,
};

const izinPenuh = { Terbitkan: true, Refund: true, SetujuiRefund: false };

function RenderPanel(ubah: Partial<TagihanLangganan> = {}, izin = izinPenuh, menunggu = false) {
    return render(
        <PanelTindakanTagihan
            tagihan={{ ...tagihan, ...ubah }}
            izin={izin}
            adaPembayaranMenunggu={menunggu}
            batasRefund="1000000"
        />,
    );
}

describe('PanelTindakanTagihan (D-112)', () => {
    beforeEach(() => AturHalamanUji({}, '/tagihan/TG-1'));
    afterEach(() => cleanup());

    it('tagihan manual terbuka: catat pembayaran mengirim jumlah, tanggal, dan pengirim', () => {
        RenderPanel();

        expect(screen.queryByRole('button', { name: 'Hapus-buku tagihan' })).toBeNull();
        expect(screen.queryByRole('button', { name: 'Ajukan refund' })).toBeNull();
        fireEvent.click(screen.getByRole('button', { name: 'Catat pembayaran masuk' }));
        const dialog = screen.getByRole('dialog', { name: 'Catat pembayaran masuk' });
        fireEvent.change(within(dialog).getByLabelText(/Jumlah masuk di mutasi rekening/), {
            target: { value: '13320000' },
        });
        fireEvent.change(within(dialog).getByLabelText(/Nama pengirim/), { target: { value: 'PT Kopi Nusantara' } });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Catat dan aktifkan langganan' }));

        expect(kirimanForm).toHaveLength(1);
        expect(kirimanForm[0]).toMatchObject({
            metode: 'post',
            url: '/tagihan/TG-1/catat-pembayaran',
            data: { JumlahDiterima: '13320000', NamaPengirim: 'PT Kopi Nusantara' },
        });
    });

    it('tagihan biasa (bukan manual) dan tagihan dengan pembayaran menunggu tidak menawarkan catat pembayaran', () => {
        const { unmount: Lepas } = RenderPanel({ Manual: false });
        expect(screen.queryByRole('button', { name: 'Catat pembayaran masuk' })).toBeNull();
        Lepas();

        RenderPanel({}, izinPenuh, true);
        expect(screen.queryByRole('button', { name: 'Catat pembayaran masuk' })).toBeNull();
    });

    it('hapus-buku hanya untuk tagihan lewat jatuh tempo dan wajib beralasan', () => {
        RenderPanel({ Status: 'JatuhTempo', LabelStatus: 'Lewat jatuh tempo' });

        fireEvent.click(screen.getByRole('button', { name: 'Hapus-buku tagihan' }));
        const dialog = screen.getByRole('alertdialog', { name: 'Hapus-buku tagihan' });
        fireEvent.change(within(dialog).getByLabelText(/Alasan/), {
            target: { value: 'Tenant tidak merespons 60 hari' },
        });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Hapus-buku tagihan' }));

        expect(kirimanForm[0]).toMatchObject({
            metode: 'post',
            url: '/tagihan/TG-1/hapus-buku',
            data: { Alasan: 'Tenant tidak merespons 60 hari' },
        });
    });

    it('refund: tagihan lunas di atas batas menjelaskan perlunya persetujuan Super Admin', () => {
        RenderPanel({ Status: 'Lunas', LabelStatus: 'Lunas' });

        fireEvent.click(screen.getByRole('button', { name: 'Ajukan refund' }));
        const dialog = screen.getByRole('alertdialog', { name: 'Ajukan refund' });
        expect(within(dialog).getByText(/menunggu persetujuan Super Admin/)).toBeTruthy();
        fireEvent.change(within(dialog).getByLabelText(/Alasan refund/), { target: { value: 'Tenant tutup usaha' } });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Ajukan untuk disetujui' }));

        expect(kirimanForm[0]).toMatchObject({
            metode: 'post',
            url: '/tagihan/TG-1/refund',
            data: { Alasan: 'Tenant tutup usaha' },
        });
    });

    it('Super Admin langsung menyetujui; tagihan Rp 0 dan tanpa izin refund tidak punya tombol refund', () => {
        const { unmount: Lepas } = RenderPanel({ Status: 'Lunas' }, { ...izinPenuh, SetujuiRefund: true });
        fireEvent.click(screen.getByRole('button', { name: 'Ajukan refund' }));
        expect(screen.getByRole('button', { name: 'Setujui refund' })).toBeTruthy();
        Lepas();

        const nol = RenderPanel({ Status: 'Lunas', Total: '0.00' });
        expect(screen.queryByRole('button', { name: 'Ajukan refund' })).toBeNull();
        nol.unmount();

        const tanpaIzin = RenderPanel({ Status: 'Lunas' }, { Terbitkan: true, Refund: false, SetujuiRefund: false });
        expect(tanpaIzin.container.textContent).toBe('');
    });
});

const refund: RefundLangganan = {
    Uuid: 'RF-1',
    Status: 'Diajukan',
    LabelStatus: 'Menunggu persetujuan',
    Jumlah: '2650000.00',
    Alasan: 'Tenant tutup usaha, minta refund',
    CatatanPengembalian: null,
    Pengaju: 'Sari Keuangan',
    Pemutus: null,
    DiajukanPada: '2026-09-24T03:00:00Z',
    DiputuskanPada: null,
    AlasanTolak: null,
    LayananDiakhiri: false,
};

describe('Refund (BR-P08.2)', () => {
    beforeEach(() => AturHalamanUji({}, '/tagihan'));
    afterEach(() => cleanup());

    it('riwayat refund menampilkan keputusan, catatan pengembalian, dan akibat pada layanan', () => {
        render(
            <RiwayatRefund
                refund={[
                    {
                        ...refund,
                        Status: 'Disetujui',
                        LabelStatus: 'Disetujui',
                        Pemutus: 'Budi Super',
                        DiputuskanPada: '2026-09-24T05:00:00Z',
                        LayananDiakhiri: true,
                        CatatanPengembalian: 'Transfer balik BCA 1234',
                    },
                    {
                        ...refund,
                        Uuid: 'RF-0',
                        Status: 'Ditolak',
                        LabelStatus: 'Ditolak',
                        AlasanTolak: 'Layanan dipakai penuh',
                    },
                ]}
            />,
        );

        expect(screen.getByText('Layanan dari tagihan ini sudah diakhiri.')).toBeTruthy();
        expect(screen.getByText(/Transfer balik BCA 1234/)).toBeTruthy();
        expect(screen.getByText('Ditolak: Layanan dipakai penuh')).toBeTruthy();
    });

    it('antrean refund: Super Admin menyetujui lewat POST dan menolak dengan alasan; tanpa izin hanya melihat', () => {
        const antrean: BarisRefundMenunggu[] = [
            { ...refund, UuidTagihan: 'TG-9', NomorTagihan: 'INV/2026/09/000009', NamaTenant: 'Kopi Nusantara' },
        ];
        const { unmount: Lepas } = render(<AntreanRefund antrean={antrean} boleh />);

        expect(screen.getByRole('heading', { name: 'Refund menunggu persetujuan (1)' })).toBeTruthy();
        expect(screen.getByRole('link', { name: 'Buka tagihan' }).getAttribute('href')).toBe('/tagihan/TG-9');
        fireEvent.click(screen.getByRole('button', { name: 'Setujui refund' }));
        expect(tiruanRouter.post).toHaveBeenCalledWith('/tagihan/refund/RF-1/setujui', {}, expect.anything());

        fireEvent.click(screen.getByRole('button', { name: 'Tolak' }));
        const dialog = screen.getByRole('alertdialog', { name: 'Tolak refund' });
        fireEvent.change(within(dialog).getByLabelText(/Alasan penolakan/), {
            target: { value: 'Layanan sudah dipakai penuh' },
        });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Tolak refund' }));
        expect(kirimanForm[0]).toMatchObject({
            url: '/tagihan/refund/RF-1/tolak',
            data: { Alasan: 'Layanan sudah dipakai penuh' },
        });
        Lepas();

        render(<AntreanRefund antrean={antrean} boleh={false} />);
        expect(screen.queryByRole('button', { name: 'Setujui refund' })).toBeNull();
    });

    it('antrean kosong tidak merender apa pun', () => {
        const { container } = render(<AntreanRefund antrean={[]} boleh />);
        expect(container.textContent).toBe('');
    });
});

describe('PanelBatasRefund (D-113)', () => {
    beforeEach(() => AturHalamanUji({}, '/tagihan'));
    afterEach(() => cleanup());

    it('Super Admin mengubah batas lewat PUT; peran lain hanya melihat nilainya', () => {
        const { unmount: Lepas } = render(<PanelBatasRefund batas="1000000.00" boleh />);

        expect(screen.getByText(/Rp\s?1\.000\.000/)).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: 'Ubah batas' }));
        const dialog = screen.getByRole('dialog', { name: 'Ubah batas refund' });
        fireEvent.change(within(dialog).getByLabelText(/Batas refund tanpa persetujuan kedua/), {
            target: { value: '5000000' },
        });
        fireEvent.change(within(dialog).getByLabelText(/Alasan perubahan/), {
            target: { value: 'Refund tahunan sering terjadi' },
        });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Simpan batas' }));

        expect(kirimanForm[0]).toMatchObject({
            metode: 'put',
            url: '/tagihan/pengaturan/batas-refund',
            data: { Batas: '5000000', Alasan: 'Refund tahunan sering terjadi' },
        });
        Lepas();

        render(<PanelBatasRefund batas="1000000.00" boleh={false} />);
        expect(screen.queryByRole('button', { name: 'Ubah batas' })).toBeNull();
    });
});

describe('PanelTransferManual (Owner, tagihan manual)', () => {
    beforeEach(() => AturHalamanUji({}, '/kelola/langganan/tagihan/TG-1'));
    afterEach(() => cleanup());

    const rekening = [
        {
            Kode: 'UTAMA',
            NamaBank: 'Bank Central Asia',
            NomorRekening: '1234567890',
            AtasNama: 'PT Kasir Nusantara Digital',
        },
    ];

    it('menampilkan rekening tujuan dan jumlah tepat; form bukti mengirim berkas ke rute unggah', () => {
        render(<PanelTransferManual uuidTagihan="TG-1" total="13320000.00" rekening={rekening} bolehUnggah />);

        expect(screen.getByText('1234567890')).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: 'Unggah bukti transfer' }));
        const berkas = new File(['x'], 'mutasi.png', { type: 'image/png' });
        fireEvent.change(screen.getByLabelText('Bukti transfer'), { target: { files: [berkas] } });
        fireEvent.change(screen.getByLabelText(/Bank pengirim/), { target: { value: 'Bank Mandiri' } });
        fireEvent.change(screen.getByLabelText(/Nama pemilik rekening pengirim/), {
            target: { value: 'Rina Wulandari' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Kirim bukti transfer' }));

        expect(kirimanForm[0]).toMatchObject({
            metode: 'post',
            url: '/kelola/langganan/tagihan/TG-1/bukti',
            data: {
                Jumlah: '13320000',
                BankPengirim: 'Bank Mandiri',
                NamaPengirim: 'Rina Wulandari',
                KodeRekeningTujuan: 'UTAMA',
            },
        });
    });

    it('selagi bukti diperiksa: rekening tetap tampil tetapi tombol unggah hilang', () => {
        render(<PanelTransferManual uuidTagihan="TG-1" total="13320000.00" rekening={rekening} bolehUnggah={false} />);

        expect(screen.queryByRole('button', { name: 'Unggah bukti transfer' })).toBeNull();
        expect(screen.getByText(/sedang diperiksa tim keuangan/)).toBeTruthy();
    });
});

describe('PanelPengaturanPenagihan (D-114)', () => {
    beforeEach(() => AturHalamanUji({}, '/tagihan'));
    afterEach(() => cleanup());

    const pengaturan: PengaturanPenagihan = {
        Boleh: true,
        PlatformPkp: false,
        PkpDariKonsol: false,
        Rekening: [
            {
                Kode: 'UTAMA',
                NamaBank: 'Bank Central Asia',
                NomorRekening: '1234567890',
                AtasNama: 'PT Kasir Nusantara',
            },
        ],
        RekeningDariKonsol: true,
    };

    it('menampilkan status PKP dan rekening; nilai awal server diberi penanda; peran tanpa izin tidak melihat tombol ubah', () => {
        const { unmount: Lepas } = render(<PanelPengaturanPenagihan pengaturan={pengaturan} />);

        expect(screen.getByText(/Bukan PKP \(tagihan tanpa PPN\)/)).toBeTruthy();
        expect(screen.getAllByText('Nilai awal server')).toHaveLength(1);
        expect(screen.getByText('1234567890')).toBeTruthy();
        Lepas();

        render(<PanelPengaturanPenagihan pengaturan={{ ...pengaturan, Boleh: false }} />);
        expect(screen.queryByRole('button', { name: 'Ubah status PKP' })).toBeNull();
        expect(screen.queryByRole('button', { name: 'Ubah rekening' })).toBeNull();
    });

    it('Super Admin mengubah status PKP lewat PUT dengan alasan', () => {
        render(<PanelPengaturanPenagihan pengaturan={pengaturan} />);

        fireEvent.click(screen.getByRole('button', { name: 'Ubah status PKP' }));
        const dialog = screen.getByRole('dialog', { name: 'Ubah status PKP' });
        fireEvent.change(within(dialog).getByLabelText(/Alasan perubahan/), {
            target: { value: 'SPPKP sudah terbit' },
        });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Simpan status PKP' }));

        expect(kirimanForm[0]).toMatchObject({
            metode: 'put',
            url: '/tagihan/pengaturan/pkp',
            data: { Pkp: false, Alasan: 'SPPKP sudah terbit' },
        });
    });

    it('Super Admin menambah rekening kedua dan mengirim seluruh daftar; maksimal lima rekening', () => {
        render(<PanelPengaturanPenagihan pengaturan={pengaturan} />);

        fireEvent.click(screen.getByRole('button', { name: 'Ubah rekening' }));
        const dialog = screen.getByRole('dialog', { name: 'Ubah rekening tujuan transfer' });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Tambah rekening' }));
        fireEvent.change(within(dialog).getByLabelText(/Nama bank \(rekening 2\)/), {
            target: { value: 'Bank Mandiri' },
        });
        fireEvent.change(within(dialog).getByLabelText(/Nomor rekening \(rekening 2\)/), {
            target: { value: '1370012345678' },
        });
        fireEvent.change(within(dialog).getByLabelText(/Atas nama \(rekening 2\)/), {
            target: { value: 'PT Kasir Nusantara' },
        });
        fireEvent.change(within(dialog).getByLabelText(/Alasan perubahan/), {
            target: { value: 'Tambah rekening Mandiri' },
        });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Simpan rekening' }));

        expect(kirimanForm[0]).toMatchObject({
            metode: 'put',
            url: '/tagihan/pengaturan/rekening-tujuan',
            data: {
                Rekening: [
                    { NamaBank: 'Bank Central Asia', NomorRekening: '1234567890', AtasNama: 'PT Kasir Nusantara' },
                    { NamaBank: 'Bank Mandiri', NomorRekening: '1370012345678', AtasNama: 'PT Kasir Nusantara' },
                ],
                Alasan: 'Tambah rekening Mandiri',
            },
        });
    });

    it('tombol Tambah rekening hilang setelah lima rekening', () => {
        render(<PanelPengaturanPenagihan pengaturan={pengaturan} />);

        fireEvent.click(screen.getByRole('button', { name: 'Ubah rekening' }));
        const dialog = screen.getByRole('dialog', { name: 'Ubah rekening tujuan transfer' });
        for (let i = 0; i < 4; i++) {
            fireEvent.click(within(dialog).getByRole('button', { name: 'Tambah rekening' }));
        }

        expect(within(dialog).queryByRole('button', { name: 'Tambah rekening' })).toBeNull();
    });
});
