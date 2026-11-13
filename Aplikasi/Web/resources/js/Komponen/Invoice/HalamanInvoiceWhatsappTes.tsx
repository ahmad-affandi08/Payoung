import { cleanup, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanDaftarInvoice from '@/Halaman/Kelola/Invoice/Daftar';
import HalamanDetailInvoice from '@/Halaman/Kelola/Invoice/Detail';
import HalamanFormInvoiceBerulang from '@/Halaman/Kelola/Invoice/Berulang/Form';
import HalamanDetailPenawaran from '@/Halaman/Kelola/Penawaran/Detail';
import { BuatHasilTabel } from '@/Komponen/Katalog/DataUjiKatalog';
import { AturHalamanUji, RenderUji, TambahPropsHalamanUji } from '@/Komponen/Katalog/TiruanInertia';
import { BukaMenu } from '@/Pengujian/InteraksiRadix';
import type {
    BarisDaftarInvoice,
    IsianInvoiceBerulang,
    KepalaDetailInvoice,
    PropsDetailInvoice,
    PropsFormInvoiceBerulang,
} from '@/Tipe/Invoice';
import type { KepalaDetailPenawaran, PropsDetailPenawaran } from '@/Tipe/Penawaran';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

/*
 * D-299: semua yang memakai layanan WhatsApp (kirim invoice/penawaran, pengingat, status kirim, panel pengiriman, mode
 * "terbitkan dan kirim") hilang total, bukan dinonaktifkan, bila layanan WhatsApp tidak tersedia bagi tenant.
 */

const baris: BarisDaftarInvoice = {
    Uuid: '01J9INV0000000000000000001',
    Nomor: 'INV/UTM/2610/0001',
    Jenis: 'Penjualan',
    LabelJenis: 'Invoice',
    Perihal: null,
    Tanggal: '2026-10-01',
    JatuhTempo: '2026-10-15',
    NamaPelanggan: 'PT Sumber Makmur Sentosa',
    KodeOutlet: 'UTM',
    Status: 'Terbit',
    LabelStatus: 'Terbit',
    StatusBayar: 'BelumDibayar',
    LabelStatusBayar: 'Belum dibayar',
    Total: '10000000.00',
    Tagihan: '10000000.00',
    Sisa: '10000000.00',
    TerkirimPada: '2026-10-02T03:00:00Z',
    DilihatPada: null,
};

const invoice: KepalaDetailInvoice = {
    Uuid: baris.Uuid,
    Nomor: baris.Nomor,
    Jenis: 'Penjualan',
    LabelJenis: 'Invoice',
    Status: 'Terbit',
    LabelStatus: 'Terbit',
    StatusBayar: 'BelumDibayar',
    LabelStatusBayar: 'Belum dibayar',
    NamaPelanggan: 'PT Sumber Makmur Sentosa',
    UuidPelanggan: '01J9PLG0000000000000000001',
    KodeOutlet: 'UTM',
    Tanggal: '2026-10-01',
    JatuhTempo: '2026-10-15',
    TerminHari: 14,
    Perihal: null,
    NomorReferensi: null,
    HargaTermasukPajak: false,
    TarifPpn: null,
    Subtotal: '10000000.00',
    Diskon: '0.00',
    DasarPengenaanPajak: '10000000.00',
    Pajak: '0.00',
    Total: '10000000.00',
    PotonganUangMukaDpp: '0.00',
    PotonganUangMukaPajak: '0.00',
    Tagihan: '10000000.00',
    KodePph: null,
    TarifPph: null,
    DasarPph: '0.00',
    NilaiPph: '0.00',
    Catatan: null,
    Syarat: null,
    InfoPembayaran: null,
    AlasanBatal: null,
    TerkirimPada: '2026-10-02T03:00:00Z',
    DilihatPada: null,
    JumlahDilihat: 0,
    DiterbitkanPada: '2026-10-01T03:00:00Z',
    DipakaiDiInvoiceAkhir: false,
    Penawaran: null,
    Sisa: '10000000.00',
    Dibayar: '0.00',
    TautanPublik: 'https://dashboard.payoung.id/i/abc',
};

function PropsDetailInv(): PropsDetailInvoice {
    return {
        Invoice: invoice,
        Baris: [],
        UangMuka: [],
        Pelunasan: [],
        Jurnal: [],
        Riwayat: [],
        Pengiriman: [
            {
                Uuid: '01J9KIR0000000000000000001',
                Jenis: 'Invoice',
                Tahap: null,
                Status: 'Terkirim',
                LabelStatus: 'Terkirim',
                PesanGalat: null,
                Pada: '2026-10-02T03:00:00Z',
            },
        ],
        Konfirmasi: [],
        Izin: { Kelola: true, LihatJurnal: false },
        HariIni: '2026-10-03',
        OpsiAkun: [],
        Tindakan: { Ubah: false, Terbitkan: false, Batalkan: true, CatatPembayaran: true, Kirim: true, Ingatkan: true },
    };
}

const penawaran: KepalaDetailPenawaran = {
    Uuid: '01J9PNW0000000000000000001',
    Nomor: 'PNW/UTM/2610/0001',
    Status: 'Terbit',
    LabelStatus: 'Terbit',
    StatusTampil: 'MenungguKeputusan',
    LabelStatusTampil: 'MenungguKeputusan',
    NamaPelanggan: 'PT Sumber Makmur Sentosa',
    UuidPelanggan: '01J9PLG0000000000000000001',
    KodeOutlet: 'UTM',
    Tanggal: '2026-10-01',
    BerlakuSampai: '2026-10-31',
    TerminHari: 14,
    Perihal: null,
    NomorReferensi: null,
    HargaTermasukPajak: false,
    TarifPpn: null,
    Subtotal: '10000000.00',
    Diskon: '0.00',
    DasarPengenaanPajak: '10000000.00',
    Pajak: '0.00',
    Total: '10000000.00',
    Catatan: null,
    Syarat: null,
    AlasanBatal: null,
    TerkirimPada: '2026-10-02T03:00:00Z',
    DilihatPada: null,
    JumlahDilihat: 0,
    DiterbitkanPada: '2026-10-01T03:00:00Z',
    Keputusan: null,
    Invoice: null,
    TautanPublik: 'https://dashboard.payoung.id/p/abc',
};

function PropsDetailPnw(): PropsDetailPenawaran {
    return {
        Penawaran: penawaran,
        Baris: [],
        Riwayat: [],
        Pengiriman: [
            {
                Uuid: '01J9KIR0000000000000000002',
                Status: 'Terkirim',
                LabelStatus: 'Terkirim',
                PesanGalat: null,
                Pada: '2026-10-02T03:00:00Z',
            },
        ],
        Izin: { Kelola: true },
        HariIni: '2026-10-03',
        Tindakan: {
            Ubah: false,
            Terbitkan: false,
            Batalkan: true,
            Kirim: true,
            Putuskan: false,
            JadikanInvoice: false,
            Salin: false,
        },
    };
}

const isianBerulang: IsianInvoiceBerulang = {
    Uuid: '01J9BRL0000000000000000001',
    Nama: 'Retainer bulanan',
    Frekuensi: 'Bulanan',
    Interval: 1,
    TanggalMulai: '2026-10-01',
    TanggalBerakhir: '',
    BatasKali: '',
    Mode: 'TerbitkanDanKirim',
    JadwalTerkunci: false,
    UuidPelanggan: '01J9PLG0000000000000000001',
    NamaPelanggan: 'PT Sumber Makmur Sentosa',
    UuidOutlet: '01JOUTLET00000000000000001',
    TerminHari: '14',
    Perihal: '',
    NomorReferensi: '',
    HargaTermasukPajak: false,
    KodePph: '',
    TarifPph: '',
    Catatan: '',
    Syarat: '',
    InfoPembayaran: '',
    Baris: [],
};

function PropsFormBerulang(): PropsFormInvoiceBerulang {
    return {
        Isian: isianBerulang,
        Kebiasaan: null,
        OpsiOutlet: [{ Id: 1, Uuid: '01JOUTLET00000000000000001', Nama: 'Outlet Solo', Kode: 'UTM' }],
        OpsiKelompokPajak: [],
        OpsiFrekuensi: [{ Nilai: 'Bulanan', Label: 'Bulanan' }],
        OpsiMode: [
            { Nilai: 'BuatDraf', Label: 'Buat draf saja' },
            { Nilai: 'Terbitkan', Label: 'Terbitkan otomatis' },
            { Nilai: 'TerbitkanDanKirim', Label: 'Terbitkan dan kirim WhatsApp' },
        ],
        HariIni: '2026-10-03',
    };
}

describe('Invoice & penawaran: layanan WhatsApp (D-299)', () => {
    beforeEach(() => {
        AturHalamanUji({}, '/kelola/invoice');
        TambahPropsHalamanUji({ WhatsappTersedia: true });
    });
    afterEach(() => cleanup());

    it('daftar invoice: kolom dikirim dan teks WhatsApp hanya ada saat tersedia', () => {
        const props = {
            Invoice: BuatHasilTabel([baris]),
            OpsiStatus: [],
            OpsiJenis: [],
            Izin: { Kelola: true, LihatJurnal: false },
            HariIni: '2026-10-03',
            Ringkasan: null,
        };
        RenderUji(<HalamanDaftarInvoice {...props} />);
        expect(screen.getByText(/Kirim lewat WhatsApp atau tautan/)).toBeTruthy();
        expect(screen.getAllByText(/Dikirim/).length).toBeGreaterThan(0);
        cleanup();

        TambahPropsHalamanUji({ WhatsappTersedia: false });
        RenderUji(<HalamanDaftarInvoice {...props} />);
        expect(screen.queryByText(/WhatsApp/)).toBeNull();
        expect(screen.queryByText(/Dikirim/)).toBeNull();
        expect(screen.getAllByText(/Belum dilihat/).length).toBeGreaterThan(0);
    });

    it('detail invoice: kirim, pengingat, status kirim, dan panel pengiriman hilang tanpa WhatsApp', () => {
        RenderUji(<HalamanDetailInvoice {...PropsDetailInv()} />);
        expect(screen.getByRole('button', { name: 'Kirim lewat WhatsApp' })).toBeTruthy();
        expect(screen.getByRole('button', { name: 'Kirim pengingat' })).toBeTruthy();
        expect(screen.getByText('Pengiriman WhatsApp')).toBeTruthy();
        cleanup();

        TambahPropsHalamanUji({ WhatsappTersedia: false });
        RenderUji(<HalamanDetailInvoice {...PropsDetailInv()} />);
        expect(screen.queryByRole('button', { name: 'Kirim lewat WhatsApp' })).toBeNull();
        expect(screen.queryByRole('button', { name: 'Kirim pengingat' })).toBeNull();
        expect(screen.queryByText(/WhatsApp/)).toBeNull();
        expect(screen.queryByText(/Dikirim /)).toBeNull();
        expect(screen.getByText('Dilihat pelanggan')).toBeTruthy();
    });

    it('detail penawaran: kirim lewat WhatsApp dan panel pengiriman hilang tanpa WhatsApp', () => {
        RenderUji(<HalamanDetailPenawaran {...PropsDetailPnw()} />);
        expect(screen.getByRole('button', { name: 'Kirim lewat WhatsApp' })).toBeTruthy();
        expect(screen.getByText('Pengiriman WhatsApp')).toBeTruthy();
        cleanup();

        TambahPropsHalamanUji({ WhatsappTersedia: false });
        RenderUji(<HalamanDetailPenawaran {...PropsDetailPnw()} />);
        expect(screen.queryByRole('button', { name: 'Kirim lewat WhatsApp' })).toBeNull();
        expect(screen.queryByText(/WhatsApp/)).toBeNull();
        expect(screen.queryByText(/Dikirim /)).toBeNull();
    });

    it('form invoice berulang: mode "terbitkan dan kirim WhatsApp" hilang dari pilihan tanpa WhatsApp', () => {
        RenderUji(<HalamanFormInvoiceBerulang {...PropsFormBerulang()} />);
        expect(screen.getByRole('combobox', { name: /Yang dilakukan sistem/ }).textContent).toContain('WhatsApp');
        cleanup();

        TambahPropsHalamanUji({ WhatsappTersedia: false });
        RenderUji(<HalamanFormInvoiceBerulang {...PropsFormBerulang()} />);
        const pilihan = screen.getByRole('combobox', { name: /Yang dilakukan sistem/ });
        expect(pilihan.textContent).toContain('Terbitkan otomatis');
        BukaMenu(pilihan);
        expect(screen.queryByText(/WhatsApp/)).toBeNull();
    });
});
