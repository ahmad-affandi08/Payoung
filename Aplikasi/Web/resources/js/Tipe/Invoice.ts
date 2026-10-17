import type { JurnalGrosir, Opsi, RiwayatGrosir, Tabel } from '@/Tipe/Grosir';

/*
 * Tipe halaman invoice & penagihan (F-21, D-166). Uang & jumlah selalu **string desimal** dari server, tidak pernah
 * number (CLAUDE.md #7).
 */

export type JenisInvoice = 'Penjualan' | 'UangMuka';

export type StatusInvoice = 'Draf' | 'Terbit' | 'Dibatalkan';

/** Status penagihan yang diturunkan server dari piutang & tanggal (bukan kolom). */
export type StatusBayarInvoice =
    'Draf' | 'BelumDibayar' | 'DibayarSebagian' | 'LewatTempo' | 'Lunas' | 'DihapusBuku' | 'Dibatalkan';

export type JenisBarisInvoice = 'Jasa' | 'Barang';

export type IzinInvoice = { Kelola: boolean; LihatJurnal: boolean };

export type OpsiOutletInvoice = { Id: number; Uuid: string; Nama: string; Kode: string };

export type OpsiKelompokPajakInvoice = { Uuid: string; Nama: string };

export type OpsiAkunInvoice = { Uuid: string; Kode: string; Nama: string };

export type BarisDaftarInvoice = {
    Uuid: string;
    Nomor: string | null;
    Jenis: JenisInvoice;
    LabelJenis: string;
    Perihal: string | null;
    Tanggal: string;
    JatuhTempo: string;
    NamaPelanggan: string;
    KodeOutlet: string;
    Status: StatusInvoice;
    LabelStatus: string;
    StatusBayar: StatusBayarInvoice;
    LabelStatusBayar: string;
    Total: string;
    Tagihan: string;
    Sisa: string | null;
    TerkirimPada: string | null;
    DilihatPada: string | null;
};

export type PropsDaftarInvoice = {
    Invoice: Tabel<BarisDaftarInvoice>;
    OpsiStatus: Opsi[];
    OpsiJenis: Opsi[];
    Izin: IzinInvoice;
    HariIni: string;
    Ringkasan: RingkasanPenagihan | null;
};

export type BarisIsianInvoice = {
    Kunci: string;
    UuidProduk: string | null;
    Nama: string;
    Keterangan: string;
    JenisBaris: JenisBarisInvoice;
    SimbolSatuan: string;
    Jumlah: string;
    Harga: string;
    Diskon: string;
    UuidKelompokPajak: string | null;
    HargaTermasukPajak: boolean | null;
};

export type IsianInvoice = {
    Uuid: string;
    Nomor: string | null;
    Jenis: JenisInvoice;
    UuidPelanggan: string;
    NamaPelanggan: string;
    UuidOutlet: string;
    Tanggal: string;
    TerminHari: number;
    Perihal: string;
    NomorReferensi: string;
    HargaTermasukPajak: boolean;
    KodePph: string;
    TarifPph: string;
    Catatan: string;
    Syarat: string;
    InfoPembayaran: string;
    UuidUangMuka: string[];
    Baris: BarisIsianInvoice[];
};

export type UangMukaTersedia = {
    Uuid: string;
    Nomor: string;
    Tanggal: string;
    Dpp: string;
    Pajak: string;
    Total: string;
};

export type PropsFormInvoice = {
    Isian: IsianInvoice | null;
    Jenis: JenisInvoice;
    Kebiasaan: { Syarat: string; InfoPembayaran: string } | null;
    OpsiOutlet: OpsiOutletInvoice[];
    OpsiKelompokPajak: OpsiKelompokPajakInvoice[];
    UangMukaAwal: UangMukaTersedia[];
    HariIni: string;
    Izin: IzinInvoice;
};

export type HasilCariJasaInvoice = {
    Uuid: string;
    Nama: string;
    Sku: string | null;
    Jenis: string;
    SimbolSatuan: string | null;
    Harga: string | null;
    UuidKelompokPajak: string | null;
    HargaTermasukPajak: boolean | null;
};

export type PelunasanInvoice = {
    Uuid: string;
    Nomor: string;
    Tanggal: string;
    Jumlah: string;
    PotonganPph: string;
    NomorBuktiPotong: string | null;
    Status: string;
    LabelStatus: string;
};

export type BarisDetailInvoice = {
    Kunci: string;
    UuidProduk: string | null;
    Nama: string;
    Keterangan: string | null;
    JenisBaris: JenisBarisInvoice;
    SimbolSatuan: string | null;
    Jumlah: string;
    Harga: string;
    Diskon: string;
    Subtotal: string;
    Pajak: string;
    Pendapatan: string;
};

export type KirimanInvoice = {
    Uuid: string;
    Jenis: 'Invoice' | 'Pengingat';
    Tahap: string | null;
    Status: string;
    LabelStatus: string;
    PesanGalat: string | null;
    Pada: string;
};

export type KepalaDetailInvoice = {
    Uuid: string;
    Nomor: string | null;
    Jenis: JenisInvoice;
    LabelJenis: string;
    Status: StatusInvoice;
    LabelStatus: string;
    StatusBayar: StatusBayarInvoice;
    LabelStatusBayar: string;
    NamaPelanggan: string;
    UuidPelanggan: string | null;
    KodeOutlet: string;
    Tanggal: string;
    JatuhTempo: string;
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
    PotonganUangMukaDpp: string;
    PotonganUangMukaPajak: string;
    Tagihan: string;
    KodePph: string | null;
    TarifPph: string | null;
    DasarPph: string;
    NilaiPph: string;
    Catatan: string | null;
    Syarat: string | null;
    InfoPembayaran: string | null;
    AlasanBatal: string | null;
    TerkirimPada: string | null;
    DilihatPada: string | null;
    JumlahDilihat: number;
    DiterbitkanPada: string | null;
    DipakaiDiInvoiceAkhir: boolean;
    /** Penawaran asal invoice ini (F-21 bagian 3), bila ada. */
    Penawaran: { Uuid: string; Nomor: string | null } | null;
    Sisa: string | null;
    Dibayar: string | null;
    TautanPublik: string | null;
};

export type PropsDetailInvoice = {
    Invoice: KepalaDetailInvoice;
    Baris: BarisDetailInvoice[];
    UangMuka: { Uuid: string; Nomor: string; Tanggal: string; Dpp: string; Pajak: string; Total: string }[];
    Pelunasan: PelunasanInvoice[];
    Jurnal: JurnalGrosir[];
    Riwayat: RiwayatGrosir[];
    Pengiriman: KirimanInvoice[];
    Konfirmasi: KonfirmasiInvoice[];
    Izin: IzinInvoice;
    HariIni: string;
    OpsiAkun: OpsiAkunInvoice[];
    Tindakan: {
        Ubah: boolean;
        Terbitkan: boolean;
        Batalkan: boolean;
        CatatPembayaran: boolean;
        Kirim: boolean;
        Ingatkan: boolean;
    };
};

/** Muatan dokumen yang dibaca pelanggan: cetak A4 di back-office dan halaman publik `/i/{kode}`. */
export type DokumenInvoice = {
    Konfirmasi?: KonfirmasiPublikInvoice;
    Invoice: {
        Nomor: string | null;
        Jenis: JenisInvoice;
        LabelJenis: string;
        Status: StatusInvoice;
        StatusBayar: StatusBayarInvoice;
        LabelStatusBayar: string;
        AlasanBatal: string | null;
        Tanggal: string;
        JatuhTempo: string;
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
        PotonganUangMukaDpp: string;
        PotonganUangMukaPajak: string;
        Tagihan: string;
        KodePph: string | null;
        TarifPph: string | null;
        NilaiPph: string;
        PphDipotongPembeli: string | null;
        Catatan: string | null;
        Syarat: string | null;
        InfoPembayaran: string | null;
        Sisa: string | null;
        Dibayar: string | null;
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
    UangMuka: { Nomor: string | null; Tanggal: string; Total: string }[];
    Pelunasan: { Uuid: string; Nomor: string; Tanggal: string; Jumlah: string; PotonganPph: string }[];
};

export type PropsCetakInvoice = DokumenInvoice & { Uuid: string };

export type PropsInvoiceDigital = { Dokumen: DokumenInvoice | null; KodeInvoice: string | null };

/** Klaim "sudah bayar" dari pelanggan (F-21 bagian 4) yang dilihat penagih di detail invoice. */
export type KonfirmasiInvoice = {
    Uuid: string;
    Status: 'Menunggu' | 'Diterima' | 'Ditolak';
    LabelStatus: string;
    NamaPengirim: string;
    TanggalBayar: string;
    Jumlah: string;
    Catatan: string | null;
    AdaBukti: boolean;
    AlasanTolak: string | null;
    Pada: string;
    BisaDiputuskan: boolean;
    TidakDiperlukan: boolean;
};

/** Formulir "sudah bayar" di halaman publik; hanya ada di halaman publik, tidak di cetak A4. */
export type KonfirmasiPublikInvoice = {
    Bisa: boolean;
    Sisa: string | null;
    /** Tanggal hari ini menurut zona waktu usaha (batas atas tanggal bayar). */
    HariIni: string;
    Menunggu: { TanggalBayar: string; Jumlah: string }[];
};

/* Invoice berulang (F-21): templat yang menerbitkan invoice biasa tiap periode. */

export type FrekuensiInvoiceBerulang = 'Mingguan' | 'Bulanan' | 'Tahunan';

export type ModeInvoiceBerulang = 'BuatDraf' | 'Terbitkan' | 'TerbitkanDanKirim';

export type StatusInvoiceBerulang = 'Aktif' | 'Dijeda' | 'Selesai';

export type BarisDaftarInvoiceBerulang = {
    Uuid: string;
    Nama: string;
    NamaPelanggan: string;
    KodeOutlet: string;
    Jadwal: string;
    TanggalMulai: string;
    BerikutnyaPada: string | null;
    JumlahTerbit: number;
    BatasKali: number | null;
    Status: StatusInvoiceBerulang;
    LabelStatus: string;
    Mode: ModeInvoiceBerulang;
    LabelMode: string;
    GalatTerakhir: string | null;
};

export type PropsDaftarInvoiceBerulang = {
    Templat: Tabel<BarisDaftarInvoiceBerulang>;
    OpsiStatus: Opsi[];
    Izin: { Kelola: boolean };
};

export type IsianInvoiceBerulang = {
    Uuid: string;
    Nama: string;
    Frekuensi: FrekuensiInvoiceBerulang;
    Interval: number;
    TanggalMulai: string;
    TanggalBerakhir: string;
    BatasKali: string;
    Mode: ModeInvoiceBerulang;
    JadwalTerkunci: boolean;
    UuidPelanggan: string;
    NamaPelanggan: string;
    UuidOutlet: string;
    TerminHari: string;
    Perihal: string;
    NomorReferensi: string;
    HargaTermasukPajak: boolean;
    KodePph: string;
    TarifPph: string;
    Catatan: string;
    Syarat: string;
    InfoPembayaran: string;
    Baris: BarisIsianInvoice[];
};

export type PropsFormInvoiceBerulang = {
    Isian: IsianInvoiceBerulang | null;
    Kebiasaan: { Syarat: string; InfoPembayaran: string } | null;
    OpsiOutlet: OpsiOutletInvoice[];
    OpsiKelompokPajak: OpsiKelompokPajakInvoice[];
    OpsiFrekuensi: Opsi[];
    OpsiMode: Opsi[];
    HariIni: string;
};

export type PropsDetailInvoiceBerulang = {
    Templat: {
        Uuid: string;
        Nama: string;
        Status: StatusInvoiceBerulang;
        LabelStatus: string;
        NamaPelanggan: string;
        UuidPelanggan: string;
        KodeOutlet: string;
        Jadwal: string;
        TanggalMulai: string;
        TanggalBerakhir: string | null;
        BatasKali: number | null;
        JumlahTerbit: number;
        BerikutnyaPada: string | null;
        Mode: ModeInvoiceBerulang;
        LabelMode: string;
        TerminHari: number | null;
        HargaTermasukPajak: boolean;
        Perihal: string | null;
        NomorReferensi: string | null;
        KodePph: string | null;
        TarifPph: string | null;
        Catatan: string | null;
        Syarat: string | null;
        InfoPembayaran: string | null;
        TerakhirTerbitPada: string | null;
        GalatTerakhir: string | null;
        GalatPada: string | null;
    };
    Baris: {
        Kunci: string;
        Nama: string;
        Keterangan: string | null;
        JenisBaris: JenisBarisInvoice;
        SimbolSatuan: string | null;
        Jumlah: string;
        Harga: string;
        Diskon: string;
    }[];
    InvoiceTerbit: {
        Uuid: string;
        Nomor: string | null;
        Tanggal: string;
        Status: StatusInvoice;
        LabelStatus: string;
        Total: string;
    }[];
    Izin: { Kelola: boolean };
    Tindakan: { Ubah: boolean; Jeda: boolean; Lanjut: boolean; Hentikan: boolean };
};

/** Ringkasan penagihan invoice satu bulan (kartu di Beranda dan daftar Invoice); null = belum pernah ada invoice. */
export type RingkasanPenagihan = {
    Dari: string;
    Sampai: string;
    Ditagih: string;
    Diterima: string;
    Beredar: { Jumlah: number; Sisa: string };
    LewatTempo: { Jumlah: number; Sisa: string };
    Draf: number;
};

/** Muatan kuitansi satu pembayaran invoice (F-21 bagian 5): cetak A4 di back-office dan halaman publik `/i/{kode}/kuitansi/{id}`. */
export type DokumenKuitansiInvoice = {
    Kuitansi: {
        Uuid: string;
        Nomor: string;
        Tanggal: string;
        Status: 'Diposting' | 'Dibatalkan';
        LabelStatus: string;
        /** Bagian tagihan yang dilunasi (termasuk PPh yang dipotong pembeli). */
        Jumlah: string;
        PotonganPph: string;
        /** Uang yang benar-benar diterima = Jumlah − PotonganPph. */
        UangDiterima: string;
        NomorBuktiPotong: string | null;
        SisaSetelah: string;
        AlasanBatal: string | null;
        KodePph: string | null;
        TarifPph: string | null;
    };
    Invoice: { Nomor: string | null; Tanggal: string; Perihal: string | null; Tagihan: string; Status: StatusInvoice };
    Penjual: { Nama: string; Alamat: string | null; KodeOutlet: string; Npwp: string | null; Pkp: boolean };
    Pembeli: { Nama: string; Alamat: string | null; NoHp: string; Email: string | null; Npwp: string | null };
};

export type PropsKuitansiInvoiceDigital = { Dokumen: DokumenKuitansiInvoice | null; KodeInvoice: string };
