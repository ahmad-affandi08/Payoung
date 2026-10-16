import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';

import TampilanDokumenPenawaran from '@/Komponen/Penawaran/TampilanDokumenPenawaran';
import type { DokumenPenawaran } from '@/Tipe/Penawaran';

function Dokumen(ubah: Partial<DokumenPenawaran['Penawaran']> = {}): DokumenPenawaran {
    return {
        Penawaran: {
            Nomor: 'PNW/UTM/2609/0001',
            Status: 'Terbit',
            StatusTampil: 'MenungguKeputusan',
            LabelStatusTampil: 'Menunggu keputusan',
            BisaDiputuskan: true,
            AlasanBatal: null,
            Tanggal: '2026-09-27',
            BerlakuSampai: '2026-10-11',
            TerminHari: 14,
            Perihal: 'Desain identitas merek',
            NomorReferensi: null,
            HargaTermasukPajak: false,
            TarifPpn: null,
            Subtotal: '12000000.00',
            Diskon: '0.00',
            DasarPengenaanPajak: '12000000.00',
            Pajak: '0.00',
            Total: '12000000.00',
            Catatan: null,
            Syarat: 'Pembayaran 50% di muka.',
            NamaPemutus: null,
            DiputuskanPada: null,
            ...ubah,
        },
        Penjual: { Nama: 'Agensi Kreatif Nusantara', Alamat: null, KodeOutlet: 'UTM', Npwp: null, Pkp: false },
        Pembeli: { Nama: 'CV Karya Bersama Abadi', Alamat: null, NoHp: '', Email: null, Npwp: null },
        Baris: [
            {
                Kunci: '1',
                Nama: 'Desain logo & panduan merek',
                Keterangan: null,
                SimbolSatuan: 'paket',
                Jumlah: '1.0000',
                Harga: '12000000.00',
                Diskon: '0.00',
                Subtotal: '12000000.00',
            },
        ],
    };
}

describe('Tampilan dokumen penawaran', () => {
    afterEach(() => cleanup());

    it('menampilkan nomor, masa berlaku, baris, total, terbilang, dan syarat', () => {
        render(<TampilanDokumenPenawaran dokumen={Dokumen()} />);

        expect(screen.getByText('PNW/UTM/2609/0001')).toBeTruthy();
        expect(screen.getByText('Penawaran')).toBeTruthy();
        expect(screen.getByText('Desain logo & panduan merek')).toBeTruthy();
        expect(screen.getByText('Total penawaran')).toBeTruthy();
        expect(screen.getByText(/dua belas juta rupiah/i)).toBeTruthy();
        expect(screen.getByText('Pembayaran 50% di muka.')).toBeTruthy();
        expect(screen.queryByText('Kedaluwarsa')).toBeNull();
    });

    it('penanda status: kedaluwarsa, diterima, ditolak, dan dibatalkan (dengan alasan) tampil jelas', () => {
        const { rerender: Render } = render(
            <TampilanDokumenPenawaran dokumen={Dokumen({ StatusTampil: 'Kedaluwarsa' })} />,
        );
        expect(screen.getByText('Kedaluwarsa')).toBeTruthy();

        Render(<TampilanDokumenPenawaran dokumen={Dokumen({ Status: 'Diterima', StatusTampil: 'Diterima' })} />);
        expect(screen.getByText('Diterima')).toBeTruthy();

        Render(<TampilanDokumenPenawaran dokumen={Dokumen({ Status: 'Ditolak', StatusTampil: 'Ditolak' })} />);
        expect(screen.getByText('Ditolak')).toBeTruthy();

        Render(
            <TampilanDokumenPenawaran
                dokumen={Dokumen({ Status: 'Dibatalkan', StatusTampil: 'Dibatalkan', AlasanBatal: 'Klien mundur' })}
            />,
        );
        expect(screen.getByText(/Dibatalkan/)).toBeTruthy();
        expect(screen.getByText('Klien mundur')).toBeTruthy();
    });

    it('pajak tampil hanya bila ada tarif atau nilai pajak', () => {
        const { rerender: Render } = render(<TampilanDokumenPenawaran dokumen={Dokumen()} />);
        expect(screen.queryByText('Dasar pengenaan pajak')).toBeNull();

        Render(
            <TampilanDokumenPenawaran
                dokumen={Dokumen({ TarifPpn: '11.000000', Pajak: '1320000.00', Total: '13320000.00' })}
            />,
        );
        expect(screen.getByText('Dasar pengenaan pajak')).toBeTruthy();
        expect(screen.getByText(/PPN/)).toBeTruthy();
    });
});
