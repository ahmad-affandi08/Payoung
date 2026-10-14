import type { HasilTabel } from '@/Komponen/TabelData/Tipe';

/** F-07b penjualan dari POS (back-office, baca saja). Uang & jumlah = string desimal dari server; tidak pernah number. */

/** F-09: `DireturSebagian` = sebagian barang diretur; `Diretur` = semua baris habis diretur. */
export type StatusPenjualan = 'Lunas' | 'Void' | 'DireturSebagian' | 'Diretur';

export type BarisPenjualan = {
    Uuid: string;
    Nomor: string;
    DibuatOfflinePada: string;
    TanggalBisnis: string;
    NamaOutlet: string;
    NamaKasir: string;
    Kanal: string;
    LabelKanal: string;
    TotalAkhir: string;
    Metode: string[];
    Status: StatusPenjualan;
    LabelStatus: string;
    PerluTinjauan: boolean;
};

type Opsi = { Nilai: string; Label: string };

export type PropsDaftarPenjualan = {
    Penjualan: HasilTabel<BarisPenjualan>;
    OpsiOutlet: { Uuid: string; Nama: string }[];
    OpsiStatus: Opsi[];
    OpsiKanal: Opsi[];
};

export type RingkasanPenjualan = {
    Uuid: string;
    Nomor: string;
    Status: StatusPenjualan;
    LabelStatus: string;
    Kanal: string;
    LabelKanal: string;
    NamaOutlet: string;
    Perangkat: string;
    NamaKasir: string;
    NamaPenyetujuDiskon: string | null;
    /** F-16a: pelanggan penjualan (null = tanpa pelanggan). */
    Pelanggan: { Uuid: string; Nama: string } | null;
    DibuatOfflinePada: string;
    DiterimaPada: string;
    TanggalBisnis: string;
    HargaTermasukPajak: boolean;
    PersenBiayaLayanan: string;
    Subtotal: string;
    DiskonBaris: string;
    DiskonPesanan: string;
    /** F-16b: poin pelanggan yang ditukar dan nilai diskonnya (bagian dari DiskonPesanan). */
    PoinDitukar: number;
    /** F-16c: promo yang dipakai penjualan ini. */
    Promo: { Uuid: string; Kode: string; Nama: string; JumlahDiskon: string }[];
    DiskonPoin: string;
    TotalDiskon: string;
    BiayaLayanan: string;
    TotalPajak: string;
    Pembulatan: string;
    TotalAkhir: string;
    TotalDibayar: string;
    Kembalian: string;
    TotalHpp: string;
    Catatan: string | null;
    /** v3.52: nomor panggil & nama pemesan penjualan bayar-dulu (FnB). */
    NomorAntrian: string | null;
    NamaPemesan: string | null;
    PerluTinjauan: boolean;
    AlasanTinjauan: string | null;
    /** Alasan tinjauan dengan label manusiawi (kode mesin tidak ditampilkan). */
    DaftarAlasanTinjauan: AlasanTinjauan[];
    UuidShift: string | null;
};

export type BarisDetailPenjualan = {
    Uuid: string;
    NamaProduk: string;
    Jumlah: string;
    SimbolSatuan: string;
    HargaSatuan: string;
    /** Harga sebelum diubah kasir (price engine lapis 1); null bila harga mengikuti price engine. */
    HargaAsli: string | null;
    AlasanHargaManual: string | null;
    HargaPilihan: string;
    Pilihan: string[];
    Bruto: string;
    JumlahDiskon: string;
    JumlahDiskonPesanan: string;
    JumlahPajak: string;
    TotalBaris: string;
    /** F-09: jumlah (satuan jual) yang sudah diretur. */
    JumlahDiretur: string;
    HppSatuan: string;
    TotalHpp: string;
    Catatan: string | null;
    /** Apotek bagian 3: komposisi obat racikan (jumlah per satu racikan, satuan dasar). */
    Racikan: RacikanDetailPenjualan | null;
};

export type RacikanDetailPenjualan = {
    Nama: string;
    JumlahKemasan: number;
    AturanPakai: string | null;
    Komponen: { NamaProduk: string; Jumlah: string; GolonganObat: string | null }[];
};

export type BarisPajakPenjualan = {
    KodeJenisPajak: string;
    Tarif: string;
    DasarPengenaan: string;
    Dpp: string;
    Jumlah: string;
};

export type BarisPembayaranPenjualan = {
    Uuid: string;
    NamaMetode: string;
    LabelJenis: string;
    Jumlah: string;
    Referensi: string | null;
};

export type BarisMutasiPenjualan = {
    Kunci: string;
    NamaProduk: string;
    NamaGudang: string;
    Jumlah: string;
    SimbolSatuan: string;
    TotalHpp: string;
    TautanKartuStok: string | null;
};

/** F-09: void seluruh penjualan (satu per penjualan). */
export type VoidPenjualan = {
    Uuid: string;
    Alasan: string;
    NamaKasir: string;
    NamaPenyetuju: string;
    DivoidPada: string;
    RefundTunai: string;
    RefundNonTunai: string;
    /** Detik dari waktu penjualan sampai void (pola anti-fraud BR-09.3). */
    JedaDetik: number;
};

/** F-09: retur yang merujuk penjualan ini. */
export type ReturRingkasPenjualan = {
    Uuid: string;
    Nomor: string;
    DibuatOfflinePada: string;
    NamaKasir: string;
    Alasan: string;
    LabelMetodeRefund: string;
    TotalRefund: string;
};

/** TAX-07: Faktur Pajak per transaksi kasir (yang aktif, atau yang terakhir dibatalkan). */
export type FakturPajakPenjualan = {
    Uuid: string;
    Status: 'Diposting' | 'Dibatalkan';
    NomorFakturPajak: string | null;
    Catatan: string | null;
    NamaPembeli: string;
    DibuatPada: string | null;
    DibatalkanPada: string | null;
    AlasanBatal: string | null;
};

/** TAX-07: pembeli yang bisa dipilih untuk Faktur Pajak; `JenisIdentitas` null = belum punya NPWP/NIK sah. */
export type PembeliFakturPajak = {
    Uuid: string;
    Nama: string;
    JenisIdentitas: 'NPWP' | 'NIK' | null;
    IdentitasTersamar: string | null;
};

export type PropsDetailPenjualan = {
    Penjualan: RingkasanPenjualan;
    Baris: BarisDetailPenjualan[];
    Pajak: BarisPajakPenjualan[];
    Pembayaran: BarisPembayaranPenjualan[];
    MutasiStok: BarisMutasiPenjualan[];
    Jurnal: { Uuid: string; Nomor: string }[];
    Void: VoidPenjualan | null;
    Retur: ReturRingkasPenjualan[];
    FakturPajak: FakturPajakPenjualan | null;
    Izin: { KelolaFakturPajak: boolean };
};

/** F-09: baris daftar Void & Retur (dasar laporan anti-fraud BR-09.3). */
export type JenisVoidRetur = 'Void' | 'Retur';

export type BarisVoidRetur = {
    Kunci: string;
    Jenis: JenisVoidRetur;
    Uuid: string;
    Waktu: string;
    TanggalBisnis: string;
    /** Void: nomor penjualan; retur: nomor retur. */
    Nomor: string;
    NomorPenjualan: string;
    UuidPenjualan: string;
    /** K28: retur tanpa struk (tanpa penjualan asal; NomorPenjualan kosong). */
    TanpaStruk: boolean;
    Tautan: string;
    NamaOutlet: string;
    NamaKasir: string;
    NamaPenyetuju: string;
    Nominal: string;
    RefundTunai: string;
    Alasan: string;
    JedaDetik: number;
};

export type PropsDaftarVoidRetur = {
    VoidRetur: HasilTabel<BarisVoidRetur>;
    OpsiOutlet: { Uuid: string; Nama: string }[];
};

export type RingkasanRetur = {
    Uuid: string;
    Nomor: string;
    LabelStatus: string;
    LabelMetodeRefund: string;
    /** K28: retur tanpa struk tidak punya penjualan asal (tiga kolom berikut null). */
    TanpaStruk: boolean;
    UuidPenjualan: string | null;
    NomorPenjualan: string | null;
    WaktuPenjualan: string | null;
    NamaOutlet: string;
    Perangkat: string;
    NamaKasir: string;
    NamaPenyetuju: string;
    Alasan: string;
    DibuatOfflinePada: string;
    DiterimaPada: string;
    TanggalBisnis: string;
    TotalNilai: string;
    TotalPajak: string;
    TotalBiayaLayanan: string;
    TotalRefund: string;
    RefundTunai: string;
    TotalHpp: string;
    PerluTinjauan: boolean;
    AlasanTinjauan: string | null;
    /** Alasan tinjauan dengan label manusiawi (kode mesin tidak ditampilkan). */
    DaftarAlasanTinjauan: AlasanTinjauan[];
    UuidShift: string | null;
};

export type BarisDetailRetur = {
    Uuid: string;
    NamaProduk: string;
    Jumlah: string;
    Kondisi: 'LayakJual' | 'Rusak';
    LabelKondisi: string;
    NamaGudang: string | null;
    NilaiBaris: string;
    Pajak: string;
    BiayaLayanan: string;
    TotalHpp: string;
};

export type BarisRefundRetur = {
    Uuid: string;
    NamaMetode: string;
    LabelJenis: string;
    Jumlah: string;
};

export type PropsDetailRetur = {
    Retur: RingkasanRetur;
    Baris: BarisDetailRetur[];
    Refund: BarisRefundRetur[];
    MutasiStok: BarisMutasiPenjualan[];
    Jurnal: { Uuid: string; Nomor: string }[];
};

/** Satu alasan tinjauan dokumen POS: `Kode` null bila tidak dikenal. */
export type AlasanTinjauan = {
    Kode: string | null;
    Label: string;
    Keterangan: string;
};

/** Penjualan satu shift (detail shift F-06). */
export type PenjualanShift = {
    /** Paling banyak 200 penjualan terakhir shift. */
    Daftar: BarisPenjualan[];
    /** Ada penjualan shift yang tidak ikut `Daftar` (lebih dari 200). */
    DaftarTerpotong: boolean;
    JumlahTransaksi: number;
    TotalPenjualan: string;
};
