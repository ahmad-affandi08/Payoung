import type { RiwayatGrosir, Opsi, Tabel } from '@/Tipe/Grosir';
import type { BarisIsianInvoice, OpsiKelompokPajakInvoice, OpsiOutletInvoice } from '@/Tipe/Invoice';

/*
 * Tipe halaman penawaran harga (F-21 bagian 3, D-168). Uang & jumlah selalu **string desimal** dari server, tidak pernah
 * number (CLAUDE.md #7).
 */

export type StatusPenawaran = 'Draf' | 'Terbit' | 'Diterima' | 'Ditolak' | 'Dibatalkan';

/** Status tampil yang diturunkan server dari status dokumen, tanggal, dan invoice hasilnya (bukan kolom). */
export type StatusTampilPenawaran =
    'Draf' | 'MenungguKeputusan' | 'Diterima' | 'DiInvoicekan' | 'Ditolak' | 'Kedaluwarsa' | 'Dibatalkan';

export type KeputusanPenawaran = 'Terima' | 'Tolak';

export type IzinPenawaran = { Kelola: boolean };

export type BarisDaftarPenawaran = {
    Uuid: string;
    Nomor: string | null;
    Perihal: string | null;
    Tanggal: string;
    BerlakuSampai: string;
    NamaPelanggan: string;
    KodeOutlet: string;
    Status: StatusPenawaran;
    StatusTampil: StatusTampilPenawaran;
    LabelStatusTampil: string;
    Total: string;
    TerkirimPada: string | null;
    DilihatPada: string | null;
};

export type PropsDaftarPenawaran = {
    Penawaran: Tabel<BarisDaftarPenawaran>;
    OpsiStatus: Opsi[];
    Izin: IzinPenawaran;
    HariIni: string;
};

export type IsianPenawaran = {
    Uuid: string | null;
    Nomor: string | null;
    UuidPelanggan: string;
    NamaPelanggan: string;
    UuidOutlet: string;
    Tanggal: string;
    BerlakuSampai: string;
    TerminHari: number;
    Perihal: string;
    NomorReferensi: string;
    HargaTermasukPajak: boolean;
    Catatan: string;
    Syarat: string;
    Baris: BarisIsianInvoice[];
};

export type PropsFormPenawaran = {
    Isian: IsianPenawaran | null;
    Kebiasaan: { Syarat: string } | null;
    MasaBerlakuBawaan: number;
    OpsiOutlet: OpsiOutletInvoice[];
    OpsiKelompokPajak: OpsiKelompokPajakInvoice[];
    HariIni: string;
    Izin: IzinPenawaran;
};

export type KirimanPenawaran = {
    Uuid: string;
    Status: string;
    LabelStatus: string;
    PesanGalat: string | null;
    Pada: string;
};

export type KepalaDetailPenawaran = {
    Uuid: string;
    Nomor: string | null;
    Status: StatusPenawaran;
    LabelStatus: string;
    StatusTampil: StatusTampilPenawaran;
    LabelStatusTampil: string;
    NamaPelanggan: string;
    UuidPelanggan: string | null;
    KodeOutlet: string;
    Tanggal: string;
    BerlakuSampai: string;
    TerminHari: number;
    Perihal: string | null;
    NomorReferensi: string | null;
    HargaTermasukPajak: boolean;
    TarifPpn: string | null;
    Subtotal: string;
    Diskon: string;
    DasarPengenaanPajak: string;
    Pajak: string;
    Total: string;
    Catatan: string | null;
    Syarat: string | null;
    AlasanBatal: string | null;
    TerkirimPada: string | null;
    DilihatPada: string | null;
    JumlahDilihat: number;
    DiterbitkanPada: string | null;
    Keputusan: {
        Sumber: 'Pelanggan' | 'Staf' | null;
        Nama: string | null;
        Catatan: string | null;
        Pada: string;
    } | null;
    Invoice: { Uuid: string; Nomor: string | null; Status: string; LabelStatus: string } | null;
    TautanPublik: string | null;
};

export type PropsDetailPenawaran = {
    Penawaran: KepalaDetailPenawaran;
    Baris: {
        Kunci: string;
        UuidProduk: string | null;
        Nama: string;
        Keterangan: string | null;
        JenisBaris: 'Jasa' | 'Barang';
        SimbolSatuan: string | null;
        Jumlah: string;
        Harga: string;
        Diskon: string;
        Subtotal: string;
        Pajak: string;
    }[];
    Riwayat: RiwayatGrosir[];
    Pengiriman: KirimanPenawaran[];
    Izin: IzinPenawaran;
    HariIni: string;
    Tindakan: {
        Ubah: boolean;
        Terbitkan: boolean;
        Batalkan: boolean;
        Kirim: boolean;
        Putuskan: boolean;
        JadikanInvoice: boolean;
        Salin: boolean;
    };
};

/** Muatan dokumen yang dibaca pelanggan: cetak A4 di back-office dan halaman publik `/p/{kode}`. */
export type DokumenPenawaran = {
    Penawaran: {
        Nomor: string | null;
        Status: StatusPenawaran;
        StatusTampil: StatusTampilPenawaran;
        LabelStatusTampil: string;
        BisaDiputuskan: boolean;
        AlasanBatal: string | null;
        Tanggal: string;
        BerlakuSampai: string;
        TerminHari: number;
        Perihal: string | null;
        NomorReferensi: string | null;
        HargaTermasukPajak: boolean;
        TarifPpn: string | null;
        Subtotal: string;
        Diskon: string;
        DasarPengenaanPajak: string;
        Pajak: string;
        Total: string;
        Catatan: string | null;
        Syarat: string | null;
        NamaPemutus: string | null;
        DiputuskanPada: string | null;
    };
    Penjual: { Nama: string; Alamat: string | null; KodeOutlet: string; Npwp: string | null; Pkp: boolean };
    Pembeli: { Nama: string; Alamat: string | null; NoHp: string; Email: string | null; Npwp: string | null };
    Baris: {
        Kunci: string;
        Nama: string;
        Keterangan: string | null;
        SimbolSatuan: string | null;
        Jumlah: string;
        Harga: string;
        Diskon: string;
        Subtotal: string;
    }[];
};

export type PropsCetakPenawaran = DokumenPenawaran & { Uuid: string };

export type PropsPenawaranDigital = { Dokumen: DokumenPenawaran | null; KodePenawaran: string | null };
