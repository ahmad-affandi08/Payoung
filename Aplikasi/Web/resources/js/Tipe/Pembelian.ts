import type { HasilTabel } from '@/Komponen/TabelData/Tipe';
import type { OpsiGudang, PelacakanProduk } from '@/Tipe/Persediaan';

/**
 * Kontrak props & JSON halaman pembelian F-04 fase 1 (PRD "Rincian F-04 fase 1"): pemasok, pesanan pembelian (PO),
 * penerimaan barang (GRN) & belanja stok, faktur, hutang & pembayaran, retur, pengaturan. Desimal selalu string: uang
 * "12345.68", jumlah "10.0000", HPP "1234.568000". Tanggal `YYYY-MM-DD`; cap waktu ISO UTC.
 */

export type StatusPesananPembelian =
    'Draf' | 'MenungguPersetujuan' | 'Disetujui' | 'DiterimaSebagian' | 'Diterima' | 'Ditutup' | 'Dibatalkan';
export type StatusDokumenPembelian = 'Diposting' | 'Dibatalkan';
export type StatusFakturPembelian = 'BelumDibayar' | 'DibayarSebagian' | 'Lunas' | 'Dibatalkan';
export type StatusPembelian = StatusPesananPembelian | StatusDokumenPembelian | StatusFakturPembelian;
export type Opsi<T extends string = string> = { Nilai: T; Label: string };

export type IzinPembelian = { Kelola: boolean; Setujui: boolean; LihatJurnal: boolean; Persediaan: boolean };
export type OpsiPemasok = {
    Uuid: string;
    Kode: string;
    Nama: string;
    Pkp: boolean;
    TerminHari: number;
    Aktif: boolean;
};
export type OpsiAkunKas = { Uuid: string; Kode: string; Nama: string };
export type AturanLampiran = { Ekstensi: string[]; UkuranMaksimalKb: number };
export type RingkasPemasok = { Uuid: string; Kode: string; Nama: string };
export type JurnalDokumen = {
    Uuid: string;
    Nomor: string;
    Tanggal: string;
    Keterangan: string;
    TotalDebit: string;
    Pembalik: boolean;
};
export type RiwayatDokumen = { StatusKe: string; Oleh: string | null; Pada: string; Alasan: string | null };
export type RingkasDokumen = { Uuid: string; Nomor: string; Tanggal: string; Status: string; LabelStatus: string };

/* Pemasok */
export type BarisPemasok = {
    Uuid: string;
    Kode: string;
    Nama: string;
    NamaKontak: string | null;
    NoHp: string | null;
    Email: string | null;
    Alamat: string | null;
    Npwp: string | null;
    Pkp: boolean;
    TerminHari: number;
    NamaBank: string | null;
    NomorRekening: string | null;
    AtasNamaRekening: string | null;
    Catatan: string | null;
    Aktif: boolean;
};
export type PropsDaftarPemasok = { Pemasok: HasilTabel<BarisPemasok>; Izin: IzinPembelian };

/* Daftar dokumen */
export type BarisDaftarPesanan = {
    Uuid: string;
    Nomor: string;
    Tanggal: string;
    PerkiraanTiba: string | null;
    NamaPemasok: string;
    NamaGudang: string;
    NamaOutlet: string | null;
    Status: StatusPesananPembelian;
    LabelStatus: string;
    Total: string;
};
export type BarisDaftarPenerimaan = {
    Uuid: string;
    Nomor: string;
    Tanggal: string;
    NomorPesanan: string | null;
    NamaPemasok: string | null;
    NamaGudang: string;
    NamaOutlet: string | null;
    Status: StatusDokumenPembelian;
    LabelStatus: string;
    BelanjaStok: boolean;
    Difakturkan: boolean;
    Total: string;
};
export type BarisDaftarFaktur = {
    Uuid: string;
    Nomor: string;
    NomorFakturPemasok: string;
    Tanggal: string;
    JatuhTempo: string;
    NamaPemasok: string | null;
    UuidPemasok: string | null;
    Status: StatusFakturPembelian;
    LabelStatus: string;
    BelanjaStok: boolean;
    Total: string;
    Sisa: string;
    HariLewat: number | null;
    Umur: string | null;
    LabelUmur: string | null;
};
export type BarisDaftarPembayaran = {
    Uuid: string;
    Nomor: string;
    Tanggal: string;
    NamaPemasok: string | null;
    Status: StatusDokumenPembelian;
    LabelStatus: string;
    BelanjaStok: boolean;
    Kompensasi: boolean;
    Total: string;
};
export type BarisDaftarRetur = {
    Uuid: string;
    Nomor: string;
    Tanggal: string;
    NomorPenerimaan: string | null;
    NamaPemasok: string | null;
    Alasan: string;
    Status: StatusDokumenPembelian;
    LabelStatus: string;
    Total: string;
};
export type RingkasanHutang = {
    Total: string;
    Kelompok: { Kunci: string; Label: string; Sisa: string; Jumlah: number }[];
};
type PropsDaftarDasar = { OpsiPemasok: OpsiPemasok[]; Izin: IzinPembelian };
export type PropsDaftarPesanan = PropsDaftarDasar & {
    Pesanan: HasilTabel<BarisDaftarPesanan>;
    OpsiStatus: Opsi<StatusPesananPembelian>[];
};
export type PropsDaftarPenerimaan = PropsDaftarDasar & {
    Penerimaan: HasilTabel<BarisDaftarPenerimaan>;
    OpsiStatus: Opsi<StatusDokumenPembelian>[];
};
export type PropsDaftarFaktur = PropsDaftarDasar & {
    Faktur: HasilTabel<BarisDaftarFaktur>;
    OpsiStatus: Opsi<StatusFakturPembelian>[];
};
export type PropsDaftarHutang = PropsDaftarDasar & {
    Hutang: HasilTabel<BarisDaftarFaktur, RingkasanHutang> & { Ringkasan: RingkasanHutang };
    OpsiUmur: Opsi[];
    HariIni: string;
};
export type PropsDaftarPembayaran = PropsDaftarDasar & {
    Pembayaran: HasilTabel<BarisDaftarPembayaran>;
    OpsiStatus: Opsi<StatusDokumenPembelian>[];
};
export type PropsDaftarRetur = PropsDaftarDasar & {
    Retur: HasilTabel<BarisDaftarRetur>;
    OpsiStatus: Opsi<StatusDokumenPembelian>[];
};

/* Produk & baris isian */
export type SatuanPembelian = { Uuid: string; Simbol: string; Nama: string; Konversi: string; DefaultBeli: boolean };
export type ProdukPembelian = {
    Uuid: string;
    Nama: string;
    Sku: string | null;
    Pelacakan: PelacakanProduk;
    SimbolSatuan: string;
    BolehDesimal: boolean;
    Satuan: SatuanPembelian[];
    /** Audit kemudahan pakai #21: harga per satuan beli dari penerimaan terakhir (null = belum pernah diterima). */
    HargaBeliTerakhir?: { Harga: string; Konversi: string; Tanggal: string; DariPemasokIni: boolean } | null;
};
export type BarisIsianBebas = {
    Kunci: string;
    UuidProduk: string;
    NamaProduk: string;
    Sku: string | null;
    Pelacakan: PelacakanProduk;
    SimbolSatuan: string;
    BolehDesimal: boolean;
    Satuan: SatuanPembelian[];
    UuidProdukSatuan: string | null;
    Jumlah: string;
    Harga: string;
    Diskon: string;
    NomorBatch: string;
    TanggalKedaluwarsa: string;
    NomorSeri: string[];
};

/* Pesanan */
export type IsianPesanan = {
    Uuid: string;
    Nomor: string;
    UuidPemasok: string;
    UuidGudang: string;
    Tanggal: string;
    PerkiraanTiba: string | null;
    TerminHari: number;
    Ongkir: string;
    Catatan: string | null;
    Baris: Omit<BarisIsianBebas, 'Kunci' | 'NomorBatch' | 'TanggalKedaluwarsa' | 'NomorSeri'>[];
};
export type PropsFormPesanan = {
    Mode: 'Buat' | 'Ubah';
    Pesanan: IsianPesanan | null;
    OpsiPemasok: OpsiPemasok[];
    OpsiGudang: OpsiGudang[];
    HariIni: string;
    BatasPersetujuanPo: string;
    MaksimalBaris: number;
};
export type DetailPesanan = {
    Uuid: string;
    Nomor: string;
    Tanggal: string;
    PerkiraanTiba: string | null;
    Status: StatusPesananPembelian;
    LabelStatus: string;
    Pemasok: {
        Uuid: string;
        Kode: string;
        Nama: string;
        Alamat: string | null;
        NoHp: string | null;
        Npwp: string | null;
        Pkp: boolean;
    };
    UuidGudang: string | null;
    NamaGudang: string;
    NamaOutlet: string | null;
    TerminHari: number;
    TarifPpn: string | null;
    Subtotal: string;
    Diskon: string;
    Pajak: string;
    Ongkir: string;
    Total: string;
    Catatan: string | null;
    AlasanDitolak: string | null;
    AlasanBatal: string | null;
    DibuatOleh: string | null;
    DisetujuiOleh: string | null;
    DisetujuiPada: string | null;
    IdPembuat: number | null;
};
export type BarisDetailPesanan = {
    Id: number;
    NamaProduk: string;
    Sku: string | null;
    SimbolSatuan: string;
    Konversi: string;
    Jumlah: string;
    Harga: string;
    Diskon: string;
    Subtotal: string;
    JumlahDiterima: string;
    Sisa: string;
};
export type PropsDetailPesanan = {
    Pesanan: DetailPesanan;
    Baris: BarisDetailPesanan[];
    Penerimaan: (RingkasDokumen & { TotalNilai: string })[];
    Riwayat: RiwayatDokumen[];
    Izin: IzinPembelian;
    Tindakan: { Ubah: boolean; Ajukan: boolean; Setujui: boolean; Terima: boolean; Batalkan: boolean; Tutup: boolean };
};
export type PropsCetakPesanan = Omit<PropsDetailPesanan, 'Izin' | 'Tindakan'> & {
    Usaha: { Nama?: string; Npwp?: string | null };
};

/* Penerimaan & belanja stok */
export type BarisPesananUntukPenerimaan = {
    IdBarisPesanan: number;
    NamaProduk: string;
    Sku: string | null;
    Pelacakan: PelacakanProduk;
    SimbolSatuan: string;
    Konversi: string;
    BolehDesimal: boolean;
    Harga: string;
    Jumlah: string;
    JumlahDiterima: string;
    Sisa: string;
};
export type PesananUntukPenerimaan = {
    Uuid: string;
    Nomor: string;
    TanggalPenerimaan: string;
    NamaPemasok: string;
    UuidGudang: string;
    NamaGudang: string;
    Ongkir: string;
    OngkirTerpakai: string;
    Baris: BarisPesananUntukPenerimaan[];
};
export type PropsFormPenerimaan = {
    Mode: 'Penerimaan' | 'BelanjaStok';
    Pesanan: PesananUntukPenerimaan | null;
    OpsiPemasok: OpsiPemasok[];
    OpsiGudang: OpsiGudang[];
    OpsiAkun: OpsiAkunKas[];
    HariIni: string;
    Lampiran: AturanLampiran;
    MaksimalBaris: number;
};
export type DetailPenerimaan = {
    Uuid: string;
    Nomor: string;
    Tanggal: string;
    Status: StatusDokumenPembelian;
    LabelStatus: string;
    Pemasok: RingkasPemasok | null;
    Pesanan: { Uuid: string; Nomor: string } | null;
    Faktur: { Uuid: string; Nomor: string; Status: StatusFakturPembelian } | null;
    NamaGudang: string;
    NamaOutlet: string | null;
    NomorSuratJalan: string | null;
    Catatan: string | null;
    TarifPpn: string | null;
    PpnDikreditkan: boolean;
    Subtotal: string;
    Ongkir: string;
    Pajak: string;
    TotalNilai: string;
    BelanjaStok: boolean;
    Lampiran: { Nama: string; Ukuran: number } | null;
    AlasanBatal: string | null;
    DibuatOleh: string | null;
    DibatalkanOleh: string | null;
};
export type BarisDetailPenerimaan = {
    Id: number;
    NamaProduk: string;
    Sku: string | null;
    SimbolSatuan: string;
    Konversi: string;
    JumlahPesanan: string | null;
    Jumlah: string;
    JumlahDasar: string;
    Harga: string;
    Diskon: string;
    Subtotal: string;
    AlokasiBiaya: string;
    Nilai: string;
    HppSatuan: string;
    NomorBatch: string | null;
    TanggalKedaluwarsa: string | null;
    NomorSeri: string[];
    NomorSeriBisaDiretur: string[];
    JumlahDiretur: string;
    SisaBisaDiretur: string;
    /** PRD-10: satuan dasar (Uuid `ProdukSatuan`) dan jumlah stiker untuk pintasan Cetak label; null bila tak bisa dihitung. */
    UuidSatuanLabel: string | null;
    JumlahLabel: number | null;
};
export type PropsDetailPenerimaanData = {
    Penerimaan: DetailPenerimaan;
    Baris: BarisDetailPenerimaan[];
    Retur: (RingkasDokumen & { Total: string })[];
    Jurnal: JurnalDokumen[];
    Riwayat: RiwayatDokumen[];
};
export type PropsDetailPenerimaan = PropsDetailPenerimaanData & {
    Izin: IzinPembelian;
    Tindakan: { Batalkan: boolean; Retur: boolean; Fakturkan: boolean; BiayaTambahan?: boolean };
};

/* Faktur */
export type BarisPenerimaanUntukFaktur = {
    Id: number;
    NamaProduk: string;
    SimbolSatuan: string;
    Jumlah: string;
    JumlahDasar: string;
    JumlahDiretur: string;
    Konversi: string;
    Harga: string;
    Diskon: string;
    Subtotal: string;
};
export type PenerimaanUntukFaktur = {
    Uuid: string;
    Nomor: string;
    Tanggal: string;
    NamaOutlet: string | null;
    Ongkir: string;
    Pkp: boolean;
    TerminHari: number;
    Baris: BarisPenerimaanUntukFaktur[];
};
export type PropsFormFaktur = {
    OpsiPemasok: OpsiPemasok[];
    UuidPemasok: string | null;
    TerminHari: number | null;
    UuidPenerimaanAwal: string | null;
    Penerimaan: PenerimaanUntukFaktur[];
    HariIni: string;
    Lampiran: AturanLampiran;
};
export type DetailFaktur = {
    Uuid: string;
    Nomor: string;
    NomorFakturPemasok: string;
    Tanggal: string;
    JatuhTempo: string;
    TerminHari: number;
    Status: StatusFakturPembelian;
    LabelStatus: string;
    Pemasok: RingkasPemasok | null;
    TarifPpn: string | null;
    PpnDikreditkan: boolean;
    NilaiPenerimaan: string;
    Subtotal: string;
    Ongkir: string;
    Pajak: string;
    SelisihHarga: string;
    Total: string;
    JumlahDibayar: string;
    JumlahRetur: string;
    Sisa: string;
    BelanjaStok: boolean;
    Catatan: string | null;
    Lampiran: { Nama: string; Ukuran: number } | null;
    AlasanBatal: string | null;
    DibuatOleh: string | null;
};
export type BarisDetailFaktur = {
    Id: number;
    NamaProduk: string;
    SimbolSatuan: string;
    NomorPesanan: string | null;
    NomorPenerimaan: string | null;
    JumlahPesanan: string | null;
    HargaPesanan: string | null;
    JumlahDiterima: string | null;
    Jumlah: string;
    HargaPenerimaan: string;
    Harga: string;
    Diskon: string;
    Subtotal: string;
    SelisihHarga: string;
    Pajak: string;
    JumlahDiretur: string;
};
export type PropsDetailFaktur = {
    Faktur: DetailFaktur;
    Baris: BarisDetailFaktur[];
    Penerimaan: (RingkasDokumen & { TotalNilai: string })[];
    Pembayaran: (RingkasDokumen & { Jumlah: string })[];
    Retur: (RingkasDokumen & { Total: string })[];
    Jurnal: JurnalDokumen[];
    Riwayat: RiwayatDokumen[];
    Izin: IzinPembelian;
    Tindakan: { Bayar: boolean; Batalkan: boolean };
};

/* Pembayaran */
export type FakturTerbuka = {
    Uuid: string;
    Nomor: string;
    NomorFakturPemasok: string;
    Tanggal: string;
    JatuhTempo: string;
    Total: string;
    Sisa: string;
};
export type PropsFormPembayaran = {
    OpsiPemasok: OpsiPemasok[];
    OpsiAkun: OpsiAkunKas[];
    UuidPemasok: string | null;
    UuidFakturAwal: string | null;
    Faktur: FakturTerbuka[];
    HariIni: string;
    Lampiran: AturanLampiran;
};
export type PropsDetailPembayaran = {
    Pembayaran: {
        Uuid: string;
        Nomor: string;
        Tanggal: string;
        Status: StatusDokumenPembelian;
        LabelStatus: string;
        Pemasok: RingkasPemasok | null;
        Akun: string | null;
        Jumlah: string;
        BelanjaStok: boolean;
        /** F-16c bagian 4e: potong hutang dari klaim promo pemasok (tanpa kas, tidak bisa dibatalkan). */
        Kompensasi: boolean;
        Catatan: string | null;
        Lampiran: { Nama: string; Ukuran: number } | null;
        AlasanBatal: string | null;
        DibuatOleh: string | null;
    };
    Alokasi: {
        UuidFaktur: string | null;
        NomorFaktur: string | null;
        NomorFakturPemasok: string | null;
        JatuhTempo: string | null;
        Jumlah: string;
    }[];
    Jurnal: JurnalDokumen[];
    Riwayat: RiwayatDokumen[];
    Izin: IzinPembelian;
    Tindakan: { Batalkan: boolean };
};

/* Retur */
export type PropsFormRetur = PropsDetailPenerimaanData & {
    HariIni: string;
    /** E8: akun kas/bank penerima pengembalian uang dari pemasok (faktur sudah dibayar / belanja stok tunai). */
    OpsiAkunRefund: { Uuid: string; Kode: string; Nama: string }[];
};
export type PropsDetailRetur = {
    Retur: {
        Uuid: string;
        Nomor: string;
        Tanggal: string;
        Status: StatusDokumenPembelian;
        LabelStatus: string;
        Alasan: string;
        Pemasok: RingkasPemasok | null;
        Penerimaan: { Uuid: string; Nomor: string } | null;
        Faktur: { Uuid: string; Nomor: string } | null;
        NamaGudang: string;
        NilaiBarang: string;
        NilaiHutang: string;
        Pajak: string;
        Total: string;
        NilaiRefund: string;
        AlasanBatal: string | null;
        DibuatOleh: string | null;
    };
    Baris: {
        Id: number;
        NamaProduk: string;
        SimbolSatuan: string;
        JumlahDasar: string;
        Nilai: string;
        NilaiHutang: string;
        Pajak: string;
        NomorSeri: string[];
    }[];
    Jurnal: JurnalDokumen[];
    Riwayat: RiwayatDokumen[];
    Izin: IzinPembelian;
    Tindakan: { Batalkan: boolean };
};

/* Pengaturan */
export type PropsPengaturanPembelian = {
    /** D-23 D: DrafPoOtomatis = draf PO disiapkan tiap pagi untuk stok di bawah minimum. */
    Pengaturan: {
        BatasPersetujuanPo: string;
        ToleransiPenerimaanPersen: string;
        DrafPoOtomatis: boolean;
        /** F24: `Minimum` = di bawah stok minimum; `Perkiraan` = dari laju pemakaian 28 hari untuk `HariCakupanDrafPo` hari. */
        SumberDrafPo: 'Minimum' | 'Perkiraan';
        HariCakupanDrafPo: number;
    };
};

/* F-05i (v3.40): konsinyasi (barang titipan). */
export type JenisDokumenKonsinyasi = 'Masuk' | 'Retur';
export type BarisPenitipKonsinyasi = {
    Uuid: string;
    Kode: string;
    Nama: string;
    JumlahProduk: number;
    Terjual: string;
    Dibayar: string;
    Sisa: string;
};
export type PropsDaftarKonsinyasi = { Penitip: BarisPenitipKonsinyasi[]; TotalSisa: string; Izin: IzinPembelian };
export type BarisDokumenKonsinyasi = {
    Uuid: string;
    Nomor: string;
    Tanggal: string;
    Jenis: JenisDokumenKonsinyasi;
    LabelJenis: string;
    NamaPemasok: string;
    NamaGudang: string;
    TotalNilai: string;
};
export type PropsDokumenKonsinyasi = {
    Dokumen: HasilTabel<BarisDokumenKonsinyasi>;
    OpsiJenis: Opsi<JenisDokumenKonsinyasi>[];
    OpsiPemasok: OpsiPemasok[];
    Izin: IzinPembelian;
};
export type PropsFormKonsinyasi = {
    Jenis: JenisDokumenKonsinyasi;
    LabelJenis: string;
    UuidPemasokAwal: string | null;
    OpsiPemasok: OpsiPemasok[];
    OpsiGudang: OpsiGudang[];
    HariIni: string;
    MaksBaris: number;
};
export type PropsDetailDokumenKonsinyasi = {
    Dokumen: {
        Uuid: string;
        Nomor: string;
        Jenis: JenisDokumenKonsinyasi;
        LabelJenis: string;
        Tanggal: string;
        TotalNilai: string;
        Catatan: string | null;
        UuidPemasok: string | null;
        NamaPemasok: string;
        NamaGudang: string;
        NamaOutlet: string | null;
    };
    Baris: {
        Id: number;
        NamaProduk: string;
        Sku: string | null;
        SimbolSatuan: string;
        Jumlah: string;
        HargaSatuan: string;
        Nilai: string;
    }[];
};
export type JurnalSetoranKonsinyasi = {
    Uuid: string;
    Nomor: string;
    Tanggal: string;
    Keterangan: string;
    TotalDebit: string;
    Pembalik: boolean;
};
export type BarisSetoranKonsinyasi = {
    Uuid: string;
    Nomor: string;
    Tanggal: string;
    Jumlah: string;
    Status: 'Diposting' | 'Dibatalkan';
    NamaAkun: string;
    Catatan: string | null;
    AlasanBatal: string | null;
    Jurnal: JurnalSetoranKonsinyasi[];
};
export type BarisProdukPenitip = {
    Uuid: string;
    NamaProduk: string;
    Sku: string | null;
    SimbolSatuan: string;
    Masuk: string;
    Retur: string;
    Terjual: string;
    NilaiTerjual: string;
    Saldo: string;
};
export type PropsPenitipKonsinyasi = {
    Penitip: {
        Uuid: string;
        Kode: string;
        Nama: string;
        NoHp: string | null;
        NamaBank: string | null;
        NomorRekening: string | null;
        AtasNamaRekening: string | null;
    };
    Hutang: { Terjual: string; Dibayar: string; Sisa: string };
    Periode: { Dari: string; Sampai: string; NilaiTerjual: string };
    Produk: BarisProdukPenitip[];
    Setoran: BarisSetoranKonsinyasi[];
    OpsiAkun: OpsiAkunKas[];
    HariIni: string;
    Izin: IzinPembelian;
};

/* v3.41 (INV-14): biaya tambahan pembelian. */
export type JenisBiayaTambahan = 'Ongkir' | 'BeaMasuk' | 'Asuransi' | 'BongkarMuat' | 'Lainnya';
export type DasarAlokasiBiaya = 'Nilai' | 'Jumlah';
export type BarisBiayaTambahan = {
    Uuid: string;
    Nomor: string;
    Tanggal: string;
    Jenis: JenisBiayaTambahan;
    LabelJenis: string;
    NomorPenerimaan: string;
    NamaPenagih: string | null;
    Jumlah: string;
    KePersediaan: string;
    KeHpp: string;
    Status: StatusDokumenPembelian;
};
export type PropsDaftarBiayaTambahan = {
    Biaya: HasilTabel<BarisBiayaTambahan>;
    OpsiJenis: Opsi<JenisBiayaTambahan>[];
    OpsiStatus: Opsi<StatusDokumenPembelian>[];
    Izin: IzinPembelian;
};
export type PropsBuatBiayaTambahan = {
    Penerimaan: {
        Uuid: string;
        Nomor: string;
        Tanggal: string;
        Status: StatusDokumenPembelian;
        NamaGudang: string;
        NamaPemasok: string | null;
        Baris: {
            Id: number;
            NamaProduk: string;
            Sku: string | null;
            Jumlah: string;
            Nilai: string;
            Pelacakan: boolean;
        }[];
    };
    OpsiJenis: Opsi<JenisBiayaTambahan>[];
    OpsiDasar: Opsi<DasarAlokasiBiaya>[];
    OpsiAkun: OpsiAkunKas[];
    OpsiPemasok: OpsiPemasok[];
    HariIni: string;
};
export type PropsDetailBiayaTambahan = {
    Biaya: {
        Uuid: string;
        Nomor: string;
        Tanggal: string;
        Jenis: JenisBiayaTambahan;
        LabelJenis: string;
        LabelDasarAlokasi: string;
        Jumlah: string;
        KePersediaan: string;
        KeHpp: string;
        Status: StatusDokumenPembelian;
        Catatan: string | null;
        AlasanBatal: string | null;
        NamaPenagih: string | null;
        UuidPenerimaan: string | null;
        NomorPenerimaan: string;
        NamaGudang: string;
    };
    Baris: {
        Id: number;
        NamaProduk: string;
        Sku: string | null;
        Alokasi: string;
        KePersediaan: string;
        KeHpp: string;
    }[];
    Jurnal: JurnalSetoranKonsinyasi[];
    Izin: IzinPembelian;
    Tindakan: { Batalkan: boolean };
};

/** PLT-10 (F31): hasil impor pemasok (periksa atau terapkan). */
export type MasalahImporPemasok = { Baris: number; Nama: string; Pesan: string };

export type HasilImporPemasok = {
    Terapkan: boolean;
    NamaBerkas: string;
    JumlahBaris: number;
    Baru: number;
    SudahAda: number;
    Bermasalah: number;
    /** Maksimal 200 baris pertama yang bermasalah. */
    Masalah: MasalahImporPemasok[];
};

export type PropsImporPemasok = { Hasil: HasilImporPemasok | null; MaksimalBaris: number };
