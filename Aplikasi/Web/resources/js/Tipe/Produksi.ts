import type { HasilTabel } from '@/Komponen/TabelData/Tipe';
import type {
    IzinDokumenPersediaan,
    JurnalDokumenPersediaan,
    OpsiStatus,
    RiwayatDokumenPersediaan,
} from '@/Tipe/DokumenPersediaan';
import type { OpsiGudang, PelacakanProduk } from '@/Tipe/Persediaan';

/** F-05e order produksi (props dari `OrderProduksiKontroler`). */
export type StatusOrderProduksi = 'Draf' | 'Diposting' | 'Dibatalkan';

export type BarisDaftarOrderProduksi = {
    Uuid: string;
    Nomor: string | null;
    Tanggal: string;
    NamaGudang: string;
    NamaOutlet: string | null;
    NamaProduk: string;
    Sku: string | null;
    JumlahHasil: string;
    NomorBatch: string | null;
    Status: StatusOrderProduksi;
    LabelStatus: string;
    NilaiHasil: string;
    HppSatuanHasil: string | null;
    DiubahPada: string;
};

export type PropsDaftarOrderProduksi = {
    Order: HasilTabel<BarisDaftarOrderProduksi>;
    OpsiGudang: OpsiGudang[];
    OpsiStatus: OpsiStatus<StatusOrderProduksi>;
    Izin: IzinDokumenPersediaan;
};

export type BahanOrderProduksi = {
    UuidProduk: string;
    NamaProduk: string;
    Sku: string | null;
    SimbolSatuan: string;
    BolehDesimal: boolean;
    JumlahStandar: string;
    Jumlah: string;
};

export type BahanDetailOrderProduksi = BahanOrderProduksi & { Selisih: string; Nilai: string | null };

export type PropsDetailOrderProduksi = {
    Order: {
        Uuid: string;
        Nomor: string | null;
        Status: StatusOrderProduksi;
        LabelStatus: string;
        NamaGudang: string;
        NamaOutlet: string | null;
        Tanggal: string;
        NamaProduk: string;
        Sku: string | null;
        SimbolSatuan: string;
        JumlahHasil: string;
        VersiResep: number | null;
        BiayaOverhead: string;
        NomorBatch: string | null;
        TanggalKedaluwarsa: string | null;
        Keterangan: string | null;
        TotalNilaiBahan: string;
        NilaiHasil: string;
        HppSatuanHasil: string | null;
        AlasanBatal: string | null;
        DibuatOleh: string | null;
        DipostingOleh: string | null;
        DipostingPada: string | null;
        DibatalkanOleh: string | null;
        DibatalkanPada: string | null;
    };
    Bahan: BahanDetailOrderProduksi[];
    Jurnal: JurnalDokumenPersediaan[];
    Riwayat: RiwayatDokumenPersediaan[];
    Tindakan: { Ubah: boolean; Posting: boolean; Batalkan: boolean; WajibAlasanBatal: boolean };
    Izin: IzinDokumenPersediaan;
};

export type IsianOrderProduksi = {
    Uuid: string;
    UuidGudang: string;
    Tanggal: string;
    Produk: {
        Uuid: string;
        Nama: string;
        Sku: string | null;
        SimbolSatuan: string;
        BolehDesimal: boolean;
        Pelacakan: PelacakanProduk;
    } | null;
    JumlahHasil: string;
    BiayaOverhead: string;
    NomorBatch: string | null;
    TanggalKedaluwarsa: string | null;
    Keterangan: string | null;
    VersiDiubahPada: string;
    Bahan: BahanOrderProduksi[];
};

/** Isian awal formulir baru dari tab Rencana produksi (§9.11); produk, lokasi, dan jumlah sudah terpilih. */
export type AwalOrderProduksi = {
    UuidGudang: string;
    Tanggal: string | null;
    JumlahHasil: string;
    Produk: NonNullable<IsianOrderProduksi['Produk']>;
};

export type PropsFormOrderProduksi = {
    Mode: 'Buat' | 'Ubah';
    Order: IsianOrderProduksi | null;
    Awal: AwalOrderProduksi | null;
    OpsiGudang: OpsiGudang[];
    HariIni: string;
    MaksBahan: number;
    WajibKedaluwarsaBatch: boolean;
};
