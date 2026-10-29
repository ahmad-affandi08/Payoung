import type { HasilTabel } from '@/Komponen/TabelData/Tipe';
import type { JenisUnit } from '@/Pustaka/UnitServis';

/** Bengkel (§9.10): tipe bersama back-office perintah kerja, kendaraan, dan halaman persetujuan publik. */

export type StatusPerintahKerja =
    | 'Diterima'
    | 'Diagnosis'
    | 'MenungguPersetujuan'
    | 'Disetujui'
    | 'Ditolak'
    | 'Dikerjakan'
    | 'Qc'
    | 'Selesai'
    | 'Ditagih'
    | 'Dibatalkan';

export type JenisBarisBengkel = 'Jasa' | 'Sparepart';

export type Opsi = { Nilai: string; Label: string };
export type OpsiUuid = { Uuid: string; Nama: string };

/** Unit servis ringkas (D-142): `NomorPolisi` = plat kendaraan, atau IMEI / nomor seri perangkat (boleh kosong). */
export type RingkasKendaraan = { Uuid: string; Jenis: JenisUnit; NomorPolisi: string | null; Label: string };

export type Kendaraan = RingkasKendaraan & {
    Merek: string;
    Tipe: string | null;
    Tahun: number | null;
    Warna: string | null;
    NomorRangka: string | null;
    NomorMesin: string | null;
    KmTerakhir: number | null;
    Catatan: string | null;
    Aktif: boolean;
    Pelanggan: { Uuid: string | null; Nama: string };
    JumlahServis: number;
    ServisTerakhirPada: string | null;
};

export type RingkasPenjualan = {
    Uuid: string;
    Nomor: string;
    TanggalBisnis: string;
    TotalAkhir: string;
    Status: string;
};

export type BarisDaftarPerintahKerja = {
    Uuid: string;
    Nomor: string;
    DibuatPada: string | null;
    Status: StatusPerintahKerja;
    LabelStatus: string;
    Kendaraan: RingkasKendaraan | null;
    Pelanggan: { Uuid: string | null; Nama: string };
    KmMasuk: number | null;
    Keluhan: string;
    Total: string;
    TotalDisetujui: string;
    Mekanik: string[];
    Outlet: { Uuid: string | null; Nama: string };
    EstimasiSelesaiPada: string | null;
    ServisBerikutnyaPada: string | null;
    ServisBerikutnyaKm: number | null;
    Penjualan: RingkasPenjualan | null;
    TujuanStatus: StatusPerintahKerja[];
};

export type BarisPerintahKerja = {
    Uuid: string;
    Urutan: number;
    Jenis: JenisBarisBengkel;
    UuidProduk: string | null;
    UuidProdukSatuan: string | null;
    NamaProduk: string;
    Sku: string | null;
    SimbolSatuan: string;
    Jumlah: string;
    HargaSatuan: string;
    Diskon: string;
    Subtotal: string;
    Karyawan: { Uuid: string; Nama: string } | null;
    Catatan: string | null;
    /** Unit sparepart bernomor seri yang akan dipasang (kosong = diisi kasir saat menagih). */
    NomorSeri: string[];
    Disetujui: boolean;
    StokTersedia: string | null;
};

export type PerintahKerja = {
    Uuid: string;
    Nomor: string;
    Status: StatusPerintahKerja;
    LabelStatus: string;
    DibuatPada: string | null;
    KmMasuk: number | null;
    Keluhan: string;
    Diagnosis: string | null;
    EstimasiSelesaiPada: string | null;
    CatatanQc: string | null;
    AlasanBatal: string | null;
    Subtotal: string;
    Diskon: string;
    Pajak: string;
    Total: string;
    TotalDisetujui: string;
    Outlet: { Uuid: string | null; Nama: string; Kode: string; Alamat: string | null };
    Pelanggan: { Uuid: string | null; Nama: string; NoHp: string | null; Alamat: string | null };
    Kendaraan: (RingkasKendaraan & { Warna: string | null; KmTerakhir: number | null }) | null;
    Persetujuan: {
        Tautan: string | null;
        KedaluwarsaPada: string | null;
        DikirimPada: string | null;
        DiputuskanPada: string | null;
        DiputuskanLewat: 'Tautan' | 'Staf' | null;
        CatatanPelanggan: string | null;
    };
    Penjualan: RingkasPenjualan | null;
    DitagihPada: string | null;
    ServisBerikutnyaPada: string | null;
    ServisBerikutnyaKm: number | null;
    PengingatServisTerkirimPada: string | null;
    Baris: BarisPerintahKerja[];
};

export type RiwayatPerintahKerja = {
    Dari: string | null;
    Ke: string;
    Label: string;
    Pada: string | null;
    Alasan: string | null;
};

export type PropsDaftarPerintahKerja = {
    PerintahKerja: HasilTabel<BarisDaftarPerintahKerja>;
    OpsiStatus: Opsi[];
    OpsiOutlet: OpsiUuid[];
    OpsiMekanik: OpsiUuid[];
};

export type PropsDetailPerintahKerja = {
    PerintahKerja: PerintahKerja;
    Riwayat: RiwayatPerintahKerja[];
    Tindakan: {
        Ubah: boolean;
        MintaPersetujuan: boolean;
        CatatPersetujuan: boolean;
        TujuanStatus: Opsi[];
        AturServis: boolean;
    };
    HariIni: string;
    LihatPenjualan: boolean;
};

export type SatuanProdukBengkel = { Uuid: string; Simbol: string; Konversi: string };

/** Pelacakan produk sparepart: batch dialokasikan FEFO saat ditagih, nomor seri dicatat per unit. */
export type PelacakanBengkel = 'Tidak' | 'Batch' | 'Seri';

export type HasilCariProdukBengkel = {
    Uuid: string;
    Nama: string;
    Sku: string | null;
    Jenis: JenisBarisBengkel;
    Pelacakan: PelacakanBengkel;
    StokTersedia: string | null;
    Satuan: SatuanProdukBengkel[];
};

export type IsianBarisPerintahKerja = {
    Jenis: JenisBarisBengkel;
    UuidProduk: string;
    UuidProdukSatuan: string | null;
    NamaProduk: string;
    SimbolSatuan: string;
    Jumlah: string;
    Diskon: string;
    UuidKaryawan: string | null;
    Catatan: string | null;
    NomorSeri: string[];
    Pelacakan: PelacakanBengkel;
    StokTersedia: string | null;
    Satuan: SatuanProdukBengkel[];
};

export type IsianPerintahKerja = {
    Uuid: string;
    Nomor: string;
    UuidOutlet: string | null;
    UuidPelanggan: string | null;
    NamaPelanggan: string;
    Kendaraan: (RingkasKendaraan & { KmTerakhir: number | null }) | null;
    KmMasuk: number | null;
    Keluhan: string;
    Diagnosis: string | null;
    EstimasiSelesaiPada: string | null;
    Baris: IsianBarisPerintahKerja[];
};

export type AwalPerintahKerja = {
    UuidPelanggan: string | null;
    NamaPelanggan: string | null;
    Kendaraan: RingkasKendaraan & { KmTerakhir: number | null };
};

export type PropsFormPerintahKerja = {
    Isian: IsianPerintahKerja | null;
    Awal: AwalPerintahKerja | null;
    OpsiOutlet: OpsiUuid[];
    OpsiMekanik: OpsiUuid[];
    HariBerlakuTautan: number;
};

export type PropsDaftarKendaraan = { Kendaraan: HasilTabel<Kendaraan> };

export type PropsDetailKendaraan = { Kendaraan: Kendaraan; Riwayat: BarisDaftarPerintahKerja[] };

export type PropsCetakPerintahKerja = {
    PerintahKerja: PerintahKerja;
    Usaha: { Nama: string | null; Npwp: string | null };
};

export type BarisPersetujuanPublik = {
    Uuid: string;
    Jenis: JenisBarisBengkel;
    NamaProduk: string;
    Jumlah: string;
    SimbolSatuan: string;
    HargaSatuan: string;
    Diskon: string;
    Subtotal: string;
    Catatan: string | null;
    Disetujui: boolean;
};

export type PropsPersetujuanServis = {
    NamaToko: string;
    UrlLogo?: string | null;
    AlamatDasar: string;
    PerintahKerja: {
        Nomor: string;
        Status: StatusPerintahKerja;
        LabelStatus: string;
        NamaPelanggan: string;
        Kendaraan: Omit<RingkasKendaraan, 'Uuid'> | null;
        KmMasuk: number | null;
        Keluhan: string;
        Diagnosis: string | null;
        EstimasiSelesaiPada: string | null;
        Subtotal: string;
        Diskon: string;
        Pajak: string;
        Total: string;
        TotalDisetujui: string;
        BolehDiputuskan: boolean;
        DiputuskanPada: string | null;
        CatatanPelanggan: string | null;
        Baris: BarisPersetujuanPublik[];
    };
};

/** Jenis label status (warna selalu disertai teks). */
export function JenisStatusPerintahKerja(status: StatusPerintahKerja): 'sukses' | 'peringatan' | 'bahaya' | 'netral' {
    if (status === 'Ditagih' || status === 'Selesai' || status === 'Disetujui') return 'sukses';
    if (status === 'Ditolak' || status === 'Dibatalkan') return 'bahaya';
    if (status === 'MenungguPersetujuan' || status === 'Qc') return 'peringatan';

    return 'netral';
}

/** Label tombol status di back-office. */
export const LabelAksiStatusBengkel: Record<string, string> = {
    Diagnosis: 'Kembali ke diagnosis',
    Dikerjakan: 'Mulai dikerjakan',
    Qc: 'Masuk pemeriksaan akhir',
    Selesai: 'Tandai selesai',
    Dibatalkan: 'Batalkan',
};
