import type { HasilTabel } from '@/Komponen/TabelData/Tipe';
import type { OpsiGudang, PelacakanProduk } from '@/Tipe/Persediaan';

/**
 * Kontrak props & JSON halaman dokumen persediaan F-05b: transfer stok, stok opname, penyesuaian stok. Desimal selalu
 * string: uang "12345.68", jumlah "10.0000", HPP "1234.568000". Tanggal `YYYY-MM-DD`; cap waktu ISO UTC.
 */

export type StatusTransferStok = 'Draf' | 'Dikirim' | 'DiterimaSebagian' | 'Diterima' | 'Dibatalkan';
export type StatusPermintaanStok = 'Diajukan' | 'DiprosesSebagian' | 'Selesai' | 'Ditutup' | 'Ditolak' | 'Dibatalkan';
export type StatusStokOpname = 'Berlangsung' | 'Ditinjau' | 'Disetujui' | 'Dibatalkan';
export type StatusPenyesuaianStok = 'Draf' | 'MenungguPersetujuan' | 'Diposting' | 'Dibatalkan';
export type AlasanPenyesuaian = 'Rusak' | 'Hilang' | 'Kedaluwarsa' | 'Sampel' | 'KonsumsiInternal' | 'Lainnya';

export type IzinDokumenPersediaan = { Lihat: boolean; Kelola: boolean; Setujui: boolean; LihatJurnal: boolean };
export type OpsiStatus<S extends string> = { Nilai: S; Label: string }[];
export type RiwayatDokumenPersediaan = {
    StatusDari: string | null;
    StatusKe: string;
    LabelStatusKe: string;
    Oleh: string | null;
    Pada: string;
    Alasan: string | null;
};
export type JurnalDokumenPersediaan = {
    Uuid: string;
    Nomor: string;
    Tanggal: string;
    Keterangan: string;
    TotalDebit: string;
    Pembalik: boolean;
};

/** GET /kelola/persediaan/pelacakan?produk=&gudang= */
export type PelacakanTersedia = {
    Batch: { Uuid: string; NomorBatch: string; TanggalKedaluwarsa: string | null; JumlahSisa: string }[];
    Seri: { Uuid: string; Nomor: string }[];
};

/** Info produk yang dibawa baris form dokumen (dari pencarian produk stok). */
export type ProdukBarisDokumen = {
    UuidProduk: string;
    NamaProduk: string;
    Sku: string | null;
    SimbolSatuan: string;
    BolehDesimal: boolean;
    Pelacakan: PelacakanProduk;
};

// ---- Transfer ----
export type BarisDaftarTransferStok = {
    Uuid: string;
    Nomor: string | null;
    Tanggal: string;
    NamaGudangAsal: string;
    NamaOutletAsal: string | null;
    NamaGudangTujuan: string;
    NamaOutletTujuan: string | null;
    Status: StatusTransferStok;
    LabelStatus: string;
    JumlahBaris: number;
    TotalNilaiKirim: string;
    DiubahPada: string;
};
export type PropsDaftarTransferStok = {
    Transfer: HasilTabel<BarisDaftarTransferStok>;
    OpsiGudang: OpsiGudang[];
    OpsiStatus: OpsiStatus<StatusTransferStok>;
    Izin: IzinDokumenPersediaan;
};
export type BarisFormTransferStok = ProdukBarisDokumen & {
    Jumlah: string;
    UuidBatchStok: string | null;
    NomorBatch: string | null;
    UuidNomorSeri: string | null;
    NomorSeri: string | null;
};
/** Isi awal form transfer: lokasi dari tombol Muat/Bongkar (D-95), atau lokasi + baris dari permintaan stok yang dipenuhi (D-105). */
export type AwalFormTransferStok = {
    UuidGudangAsal: string;
    UuidGudangTujuan: string;
    UuidPermintaanStok?: string;
    NomorPermintaan?: string;
    Baris?: BarisFormTransferStok[];
};
export type PropsFormTransferStok = {
    Mode: 'Buat' | 'Ubah';
    Transfer: {
        Uuid: string;
        UuidGudangAsal: string;
        UuidGudangTujuan: string;
        Tanggal: string;
        Catatan: string | null;
        VersiDiubahPada: string;
        Baris: BarisFormTransferStok[];
    } | null;
    OpsiGudangAsal: OpsiGudang[];
    OpsiGudangTujuan: OpsiGudang[];
    /** D-95: lokasi awal dari tombol Muat/Bongkar di halaman Kanvas (kosong bila tidak ada). */
    Awal?: AwalFormTransferStok;
    HariIni: string;
    BatasBaris: number;
};
export type BarisDetailTransferStok = ProdukBarisDokumen & {
    Urutan: number;
    JumlahDikirim: string;
    JumlahDiterima: string;
    JumlahSusut: string;
    JumlahDitolak: string;
    JumlahSisa: string;
    NilaiKirim: string;
    NilaiDiterima: string;
    NilaiSusut: string;
    NilaiDitolak: string;
    UuidBatchStok: string | null;
    NomorBatch: string | null;
    TanggalKedaluwarsa: string | null;
    UuidNomorSeri: string | null;
    NomorSeri: string | null;
};
export type PropsDetailTransferStok = {
    Transfer: {
        Uuid: string;
        Nomor: string | null;
        Status: StatusTransferStok;
        LabelStatus: string;
        Tanggal: string;
        NamaGudangAsal: string;
        NamaOutletAsal: string | null;
        NamaGudangTujuan: string;
        NamaOutletTujuan: string | null;
        NamaGudangTransit: string | null;
        /** D-105: permintaan stok yang dijawab transfer ini. */
        Permintaan?: { Uuid: string; Nomor: string } | null;
        Catatan: string | null;
        JumlahBaris: number;
        TotalNilaiKirim: string;
        TotalNilaiDiterima: string;
        TotalNilaiSusut: string;
        AlasanSelisih: string | null;
        AlasanBatal: string | null;
        DibuatOleh: string | null;
        DibuatPada: string;
        DikirimOleh: string | null;
        DikirimPada: string | null;
        DiterimaPada: string | null;
        DitutupOleh: string | null;
        DitutupPada: string | null;
        VersiDiubahPada: string;
    };
    Baris: BarisDetailTransferStok[];
    Jurnal: JurnalDokumenPersediaan[];
    Riwayat: RiwayatDokumenPersediaan[];
    Tindakan: { Ubah: boolean; Kirim: boolean; Batalkan: boolean; Terima: boolean; Tutup: boolean };
    Izin: IzinDokumenPersediaan;
    HariIni: string;
};

// ---- Opname ----
export type BarisDaftarStokOpname = {
    Uuid: string;
    Nomor: string;
    TanggalSnapshot: string;
    NamaGudang: string;
    NamaOutlet: string | null;
    NamaKategori: string | null;
    HitungButa: boolean;
    Status: StatusStokOpname;
    LabelStatus: string;
    JumlahBaris: number;
    JumlahDihitung: number;
    TotalNilaiLebih: string;
    TotalNilaiKurang: string;
};
export type PropsDaftarStokOpname = {
    Opname: HasilTabel<BarisDaftarStokOpname>;
    OpsiGudang: OpsiGudang[];
    OpsiKategori: { Uuid: string; Nama: string; Jalur: string }[];
    OpsiStatus: OpsiStatus<StatusStokOpname>;
    Izin: IzinDokumenPersediaan;
};
export type BarisStokOpname = ProdukBarisDokumen & {
    Urutan: number;
    NomorBatch: string | null;
    TanggalKedaluwarsa: string | null;
    NomorSeri: string | null;
    DariSnapshot: boolean;
    /** null = disembunyikan (hitung buta selama berlangsung). */
    JumlahSistem: string | null;
    JumlahFisik: string | null;
    MutasiSelamaOpname: string | null;
    Selisih: string | null;
    NilaiSelisih: string | null;
};
export type PropsDetailStokOpname = {
    Opname: {
        Uuid: string;
        Nomor: string;
        Status: StatusStokOpname;
        LabelStatus: string;
        UuidGudang: string;
        NamaGudang: string;
        NamaOutlet: string | null;
        NamaKategori: string | null;
        HitungButa: boolean;
        SistemTersembunyi: boolean;
        TanggalSnapshot: string;
        SnapshotPada: string;
        TanggalPosting: string | null;
        Catatan: string | null;
        JumlahBaris: number;
        JumlahDihitung: number;
        TotalNilaiLebih: string;
        TotalNilaiKurang: string;
        AlasanBatal: string | null;
        DibuatOleh: string | null;
        DiajukanOleh: string | null;
        DisetujuiOleh: string | null;
        DisetujuiPada: string | null;
    };
    Baris: BarisStokOpname[];
    Jurnal: JurnalDokumenPersediaan[];
    Riwayat: RiwayatDokumenPersediaan[];
    Tindakan: { Hitung: boolean; Ajukan: boolean; Kembalikan: boolean; Setujui: boolean; Batalkan: boolean };
    Izin: IzinDokumenPersediaan;
};
export type MasukanHitungOpname = {
    Urutan: number | null;
    UuidProduk: string | null;
    JumlahFisik: string | null;
    NomorBatch: string | null;
    TanggalKedaluwarsa: string | null;
    NomorSeri: string | null;
};

// ---- Penyesuaian ----
export type OpsiAlasanPenyesuaian = {
    Nilai: AlasanPenyesuaian;
    Label: string;
    BolehMasuk: boolean;
    WajibKeterangan: boolean;
};
export type BarisDaftarPenyesuaianStok = {
    Uuid: string;
    Nomor: string | null;
    Tanggal: string;
    NamaGudang: string;
    NamaOutlet: string | null;
    KodeAlasan: AlasanPenyesuaian;
    LabelAlasan: string;
    Status: StatusPenyesuaianStok;
    LabelStatus: string;
    JumlahBaris: number;
    NilaiPerkiraan: string;
    DiubahPada: string;
};
export type PropsDaftarPenyesuaianStok = {
    Penyesuaian: HasilTabel<BarisDaftarPenyesuaianStok>;
    OpsiGudang: OpsiGudang[];
    OpsiStatus: OpsiStatus<StatusPenyesuaianStok>;
    OpsiAlasan: OpsiAlasanPenyesuaian[];
    Izin: IzinDokumenPersediaan;
};
export type BarisFormPenyesuaianStok = ProdukBarisDokumen & {
    Arah: 'Masuk' | 'Keluar';
    Jumlah: string;
    HppSatuan: string | null;
    UuidBatchStok: string | null;
    NomorBatch: string | null;
    TanggalKedaluwarsa: string | null;
    UuidNomorSeri: string | null;
    NomorSeri: string | null;
};
export type PropsFormPenyesuaianStok = {
    Mode: 'Buat' | 'Ubah';
    Penyesuaian: {
        Uuid: string;
        UuidGudang: string;
        Tanggal: string;
        KodeAlasan: AlasanPenyesuaian;
        Keterangan: string | null;
        VersiDiubahPada: string;
        Baris: BarisFormPenyesuaianStok[];
    } | null;
    OpsiGudang: OpsiGudang[];
    OpsiAlasan: OpsiAlasanPenyesuaian[];
    HariIni: string;
    BatasBaris: number;
    WajibKedaluwarsaBatch: boolean;
};
export type BarisDetailPenyesuaianStok = ProdukBarisDokumen & {
    Urutan: number;
    Jumlah: string;
    HppSatuan: string | null;
    Nilai: string | null;
    UuidBatchStok: string | null;
    NomorBatch: string | null;
    TanggalKedaluwarsa: string | null;
    UuidNomorSeri: string | null;
    NomorSeri: string | null;
};
export type PropsDetailPenyesuaianStok = {
    Penyesuaian: {
        Uuid: string;
        Nomor: string | null;
        Status: StatusPenyesuaianStok;
        LabelStatus: string;
        NamaGudang: string;
        NamaOutlet: string | null;
        Tanggal: string;
        KodeAlasan: AlasanPenyesuaian;
        LabelAlasan: string;
        Keterangan: string | null;
        JumlahBaris: number;
        NilaiPerkiraan: string;
        TotalNilaiMasuk: string;
        TotalNilaiKeluar: string;
        PerluPersetujuan: boolean;
        AlasanTolak: string | null;
        DibuatOleh: string | null;
        DibuatPada: string;
        DiajukanOleh: string | null;
        DisetujuiOleh: string | null;
        DipostingOleh: string | null;
        DipostingPada: string | null;
        VersiDiubahPada: string;
    };
    Baris: BarisDetailPenyesuaianStok[];
    Jurnal: JurnalDokumenPersediaan[];
    Riwayat: RiwayatDokumenPersediaan[];
    Tindakan: { Ubah: boolean; Ajukan: boolean; Batalkan: boolean; Setujui: boolean; Tolak: boolean };
    Izin: IzinDokumenPersediaan;
    BatasPersetujuan: string;
};

/** D-94: data cetak surat jalan transfer stok (tanpa nilai barang). */
export type PropsCetakTransferStok = {
    Transfer: {
        Nomor: string | null;
        Status: StatusTransferStok;
        LabelStatus: string;
        Tanggal: string;
        NamaGudangAsal: string;
        NamaOutletAsal: string | null;
        NamaGudangTujuan: string;
        NamaOutletTujuan: string | null;
        Catatan: string | null;
        AlasanBatal: string | null;
        NamaPengirim: string | null;
        DikirimPada: string | null;
    };
    Baris: {
        Urutan: number;
        NamaProduk: string;
        Sku: string | null;
        SimbolSatuan: string;
        Jumlah: string;
        NomorBatch: string | null;
        TanggalKedaluwarsa: string | null;
        NomorSeri: string | null;
    }[];
    Usaha: { Nama: string | null; Npwp: string | null };
};

// ---- Permintaan stok (D-105) ----
export type BarisDaftarPermintaanStok = {
    Uuid: string;
    Nomor: string;
    Tanggal: string;
    NamaGudangAsal: string;
    NamaOutletAsal: string | null;
    NamaGudangTujuan: string;
    NamaOutletTujuan: string | null;
    Status: StatusPermintaanStok;
    LabelStatus: string;
    JumlahBaris: number;
    PerluDikirim: boolean;
};
export type PropsDaftarPermintaanStok = {
    Permintaan: HasilTabel<BarisDaftarPermintaanStok>;
    OpsiGudang: OpsiGudang[];
    OpsiStatus: OpsiStatus<StatusPermintaanStok>;
    Izin: IzinDokumenPersediaan;
};
export type BarisFormPermintaanStok = ProdukBarisDokumen & { Jumlah: string };
export type PropsFormPermintaanStok = {
    /** Lokasi yang boleh meminta (lokasi di outlet pengguna) dan lokasi yang bisa diminta mengirim (semua outlet). */
    OpsiGudangTujuan: OpsiGudang[];
    OpsiGudangAsal: OpsiGudang[];
    UuidGudangTujuanAwal: string;
    HariIni: string;
    BatasBaris: number;
};
export type BarisDetailPermintaanStok = ProdukBarisDokumen & {
    Urutan: number;
    JumlahDiminta: string;
    JumlahDikirim: string;
    JumlahSisa: string;
};
export type PropsDetailPermintaanStok = {
    Permintaan: {
        Uuid: string;
        Nomor: string;
        Status: StatusPermintaanStok;
        LabelStatus: string;
        Tanggal: string;
        NamaGudangAsal: string;
        NamaOutletAsal: string | null;
        UuidGudangAsal: string;
        NamaGudangTujuan: string;
        NamaOutletTujuan: string | null;
        UuidGudangTujuan: string;
        Catatan: string | null;
        JumlahBaris: number;
        Alasan: string | null;
        DibuatOleh: string | null;
        DibuatPada: string;
        DitutupOleh: string | null;
        DitutupPada: string | null;
    };
    Baris: BarisDetailPermintaanStok[];
    Transfer: {
        Uuid: string;
        Nomor: string | null;
        Tanggal: string;
        Status: StatusTransferStok;
        LabelStatus: string;
        JumlahBaris: number;
    }[];
    Riwayat: RiwayatDokumenPersediaan[];
    Tindakan: { Penuhi: boolean; Tolak: boolean; Batalkan: boolean; Tutup: boolean };
    Izin: IzinDokumenPersediaan;
};
