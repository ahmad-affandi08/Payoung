/*
 * Tipe halaman back-office grosir (F-12, §9.7, D-32). Uang & jumlah selalu **string desimal** dari server, tidak
 * pernah number: membacanya sebagai float di peramban akan menggeser angka Rupiah jutaan (CLAUDE.md #7).
 */

export type StatusPesananGrosir = 'Draf' | 'Dikonfirmasi' | 'SebagianDikirim' | 'Selesai' | 'Dibatalkan';

export type StatusDokumenGrosir = 'Diposting' | 'Dibatalkan';

/** Kabar lapangan pengiriman surat jalan (terpisah dari status akuntansi). */
export type StatusPengirimanGrosir = 'Disiapkan' | 'DalamPerjalanan' | 'Diterima' | 'Gagal';

export type SumberPesananGrosir = 'BackOffice' | 'Salesman';

export type HasilKunjungan = 'PesananDibuat' | 'TidakPesan' | 'TokoTutup' | 'Lainnya';

export type IzinGrosir = {
    Kelola: boolean;
    SetujuiKredit: boolean;
    LihatJurnal: boolean;
    LihatPiutang: boolean;
};

export type Opsi = { Nilai: string; Label: string };

export type OpsiOutletGrosir = { Uuid: string; Kode: string; Nama: string };

export type OpsiGudangGrosir = {
    Uuid: string;
    Kode: string;
    Nama: string;
    Jenis: string;
    NamaOutlet: string | null;
    Aktif: boolean;
};

export type RiwayatGrosir = { StatusKe: string; Oleh: string | null; Pada: string; Alasan: string | null };

export type JurnalGrosir = {
    Uuid: string;
    Nomor: string;
    Tanggal: string;
    KunciSumber: string;
    TotalDebit: string;
    TotalKredit: string;
};

export type BarisDaftarPesananGrosir = {
    Uuid: string;
    Nomor: string;
    Tanggal: string;
    TanggalKirimDiminta: string | null;
    NamaPelanggan: string;
    KodeOutlet: string;
    Status: StatusPesananGrosir;
    LabelStatus: string;
    Total: string;
    ButuhPersetujuan: boolean;
};

export type BarisDaftarSuratJalan = {
    Uuid: string;
    Nomor: string;
    Tanggal: string;
    NomorPesanan: string | null;
    NomorFaktur: string | null;
    NamaPelanggan: string;
    KodeOutlet: string;
    Status: StatusDokumenGrosir;
    LabelStatus: string;
    StatusPengiriman: StatusPengirimanGrosir;
    LabelStatusPengiriman: string;
    Total: string;
    TotalHpp: string;
};

export type BarisDaftarRetur = {
    Uuid: string;
    Nomor: string;
    Tanggal: string;
    NomorSuratJalan: string | null;
    NomorFaktur: string | null;
    NamaPelanggan: string;
    KodeOutlet: string;
    Status: StatusDokumenGrosir;
    LabelStatus: string;
    Total: string;
    MengurangiPiutang: boolean;
    PerluTinjauan: boolean;
    Alasan: string;
};

export type BarisDaftarFaktur = {
    Uuid: string;
    Nomor: string;
    NomorFakturPajak: string | null;
    Tanggal: string;
    JatuhTempo: string;
    PeriodePenyerahan: string;
    NamaPelanggan: string;
    KodeOutlet: string;
    Status: StatusDokumenGrosir;
    LabelStatus: string;
    Total: string;
    Sisa: string | null;
    StatusPiutang: string | null;
    LabelStatusPiutang: string | null;
};

export type Tabel<T> = {
    Data: T[];
    Meta: { Halaman: number; PerHalaman: number; Total: number; JumlahHalaman: number };
};

export type BarisDetailPesananGrosir = {
    Urutan: number;
    NamaProduk: string;
    Sku: string | null;
    SimbolSatuan: string;
    /** Batch dialokasikan FEFO oleh server; nomor seri diisi operator saat menyerahkan barang. */
    Pelacakan: 'Tidak' | 'Batch' | 'Seri';
    Konversi: string;
    Jumlah: string;
    JumlahTerkirim: string;
    SisaKirim: string;
    Harga: string;
    Diskon: string;
    Subtotal: string;
};

export type BarisDetailSuratJalan = {
    Urutan: number;
    NamaProduk: string;
    Sku: string | null;
    SimbolSatuan: string;
    Jumlah: string;
    JumlahDiretur: string;
    SisaRetur: string;
    /** Batch/nomor seri yang diserahkan, mis. "UHT-A (30), UHT-B (20)" atau "IMEI-1, IMEI-2"; null bila tanpa pelacakan. */
    Rincian: string | null;
    /** Nomor seri yang diserahkan dan belum diretur (kosong untuk produk tanpa nomor seri). */
    SeriBisaDiretur: string[];
    Harga: string;
    Diskon: string;
    Subtotal: string;
    HppSatuan: string;
    TotalHpp: string;
};

export type PropsDaftarPesananGrosir = {
    Pesanan: Tabel<BarisDaftarPesananGrosir>;
    OpsiStatus: Opsi[];
    Izin: IzinGrosir;
};

export type PropsDaftarSuratJalan = {
    SuratJalan: Tabel<BarisDaftarSuratJalan>;
    OpsiStatus: Opsi[];
    OpsiStatusPengiriman: Opsi[];
    HariIni: string;
    Izin: IzinGrosir;
};

export type PropsDaftarFaktur = {
    Faktur: Tabel<BarisDaftarFaktur>;
    OpsiStatus: Opsi[];
    Izin: IzinGrosir;
};

/** Kunjungan salesman (Modul Salesman bagian 1). Waktu ISO-8601 UTC; koordinat string desimal, bukan number. */
export type BarisKunjunganSales = {
    Uuid: string;
    Tanggal: string;
    MasukPada: string;
    KeluarPada: string | null;
    DurasiMenit: number | null;
    NamaSalesman: string;
    NamaPelanggan: string;
    Hasil: HasilKunjungan;
    LabelHasil: string;
    Catatan: string | null;
    UuidPesananGrosir: string | null;
    NomorPesananGrosir: string | null;
    Latitude: string | null;
    Longitude: string | null;
    AkurasiMeter: number | null;
};

export type PropsDaftarKunjunganSales = {
    Kunjungan: Tabel<BarisKunjunganSales>;
    OpsiSalesman: Opsi[];
    OpsiHasil: Opsi[];
    Izin: IzinGrosir;
};

export type PropsDaftarRetur = {
    Retur: Tabel<BarisDaftarRetur>;
    OpsiStatus: Opsi[];
    Izin: IzinGrosir;
};

export type PropsBuatFaktur = {
    SuratJalan: Tabel<BarisDaftarSuratJalan>;
    HariIni: string;
    Izin: IzinGrosir;
};

export type IsianBarisFormGrosir = {
    UuidProduk: string;
    UuidProdukSatuan: string;
    NamaProduk: string;
    SimbolSatuan: string;
    Jumlah: string;
    Diskon: string;
    Harga?: string;
};

export type IsianFormGrosir = {
    Uuid: string;
    Nomor: string;
    UuidPelanggan: string | null;
    NamaPelanggan: string;
    UuidOutlet: string | null;
    Tanggal: string;
    TanggalKirimDiminta: string | null;
    Catatan: string | null;
    Baris: IsianBarisFormGrosir[];
};

export type PropsFormGrosir = {
    Isian: IsianFormGrosir | null;
    OpsiOutlet: OpsiOutletGrosir[];
    OpsiGudang: OpsiGudangGrosir[];
    HariIni: string;
    Izin: IzinGrosir;
};

export type HasilCariProdukGrosir = {
    Uuid: string;
    Nama: string;
    Sku: string | null;
    SimbolSatuan: string;
    Satuan: { Uuid: string; Simbol: string; Nama: string; Konversi: string; DefaultJual: boolean }[];
};

export type PropsDetailPesananGrosir = {
    Pesanan: {
        Uuid: string;
        Nomor: string;
        NamaPelanggan: string;
        UuidPelanggan: string | null;
        KodeOutlet: string;
        Tanggal: string;
        TanggalKirimDiminta: string | null;
        Status: StatusPesananGrosir;
        LabelStatus: string;
        TerminHari: number;
        TarifPpn: string | null;
        Subtotal: string;
        Diskon: string;
        DasarPengenaanPajak: string;
        Pajak: string;
        Total: string;
        Catatan: string | null;
        AlasanPersetujuanKredit: string | null;
        AlasanBatal: string | null;
        /** Modul Salesman: `Salesman` bila diambil salesman lewat aplikasi (harga tetap dari server). */
        Sumber: SumberPesananGrosir;
        NamaSalesman: string | null;
        BolehDiubah: boolean;
        BolehDikirim: boolean;
    };
    Baris: BarisDetailPesananGrosir[];
    SuratJalan: {
        Uuid: string;
        Nomor: string;
        Tanggal: string;
        Status: StatusDokumenGrosir;
        LabelStatus: string;
        Total: string;
        Difakturkan: boolean;
    }[];
    Riwayat: RiwayatGrosir[];
    Izin: IzinGrosir;
    OpsiGudang: OpsiGudangGrosir[];
    /** Armada aktif; memilihnya di dialog kirim mengisi pengemudi & nomor kendaraan. */
    OpsiArmada?: ArmadaGrosir[];
    HariIni: string;
    Tindakan: { Ubah: boolean; Konfirmasi: boolean; Kirim: boolean; Batalkan: boolean };
};

export type ReturSuratJalan = {
    Uuid: string;
    Nomor: string;
    Tanggal: string;
    Status: StatusDokumenGrosir;
    LabelStatus: string;
    Total: string;
};

export type PropsDetailSuratJalan = {
    SuratJalan: {
        Uuid: string;
        Nomor: string;
        NamaPelanggan: string;
        UuidPelanggan: string | null;
        KodeOutlet: string;
        Tanggal: string;
        Status: StatusDokumenGrosir;
        LabelStatus: string;
        TarifPpn: string | null;
        Subtotal: string;
        Diskon: string;
        DasarPengenaanPajak: string;
        Pajak: string;
        Total: string;
        TotalHpp: string;
        NamaPengirim: string | null;
        NomorKendaraan: string | null;
        NamaPenerima: string | null;
        Catatan: string | null;
        AlasanBatal: string | null;
        StatusPengiriman: StatusPengirimanGrosir;
        LabelStatusPengiriman: string;
        NamaArmada: string | null;
        BerangkatPada: string | null;
        DiterimaPada: string | null;
        CatatanPengiriman: string | null;
        NomorPesanan: string | null;
        UuidPesanan: string | null;
        NomorFaktur: string | null;
        UuidFaktur: string | null;
        BolehDibatalkan: boolean;
    };
    Baris: BarisDetailSuratJalan[];
    Retur: ReturSuratJalan[];
    Jurnal: JurnalGrosir[];
    Riwayat: RiwayatGrosir[];
    RiwayatPengiriman: RiwayatGrosir[];
    OpsiArmada: ArmadaGrosir[];
    Izin: IzinGrosir;
    Tindakan: { Batalkan: boolean; Pengiriman: boolean };
};

export type ArmadaGrosir = {
    Uuid: string;
    Nama: string;
    NomorKendaraan: string;
    NamaPengemudi: string | null;
};

export type BarisArmada = ArmadaGrosir & { Keterangan: string | null; Aktif: boolean };

export type PropsDaftarArmada = {
    Armada: BarisArmada[];
    Izin: IzinGrosir;
};

export type PropsBuatRetur = {
    SuratJalan: PropsDetailSuratJalan['SuratJalan'];
    Baris: BarisDetailSuratJalan[];
    Retur: ReturSuratJalan[];
    Jurnal: JurnalGrosir[];
    Riwayat: RiwayatGrosir[];
    OpsiKondisi: Opsi[];
    /** Akun kas/bank sumber pengembalian uang bila faktur sudah (hampir) dibayar. */
    OpsiAkunRefund: { Uuid: string; Kode: string; Nama: string }[];
    HariIni: string;
    Izin: IzinGrosir;
};

export type BarisDetailRetur = {
    Urutan: number;
    NamaProduk: string;
    Sku: string | null;
    SimbolSatuan: string;
    Jumlah: string;
    Kondisi: 'LayakJual' | 'Rusak';
    LabelKondisi: string;
    NamaGudang: string;
    Harga: string;
    Diskon: string;
    Subtotal: string;
    HppSatuan: string;
    TotalHpp: string;
};

export type PropsDetailRetur = {
    Retur: {
        Uuid: string;
        Nomor: string;
        NamaPelanggan: string;
        UuidPelanggan: string | null;
        KodeOutlet: string;
        Tanggal: string;
        Status: StatusDokumenGrosir;
        LabelStatus: string;
        Alasan: string;
        MengurangiPiutang: boolean;
        TarifPpn: string | null;
        Subtotal: string;
        Diskon: string;
        DasarPengenaanPajak: string;
        Pajak: string;
        Total: string;
        /** Bagian nilai retur yang dikembalikan sebagai uang ke pembeli; "0.00" bila tidak ada. */
        NilaiRefund: string;
        NamaAkunRefund: string | null;
        TotalHpp: string;
        Catatan: string | null;
        PerluTinjauan: boolean;
        AlasanTinjauan: string | null;
        AlasanBatal: string | null;
        NomorSuratJalan: string | null;
        UuidSuratJalan: string | null;
        NomorFaktur: string | null;
        UuidFaktur: string | null;
    };
    Baris: BarisDetailRetur[];
    Jurnal: JurnalGrosir[];
    Riwayat: RiwayatGrosir[];
    Izin: IzinGrosir;
    Tindakan: { Batalkan: boolean };
};

export type PropsDetailFaktur = {
    Faktur: {
        Uuid: string;
        Nomor: string;
        NamaPelanggan: string;
        UuidPelanggan: string | null;
        KodeOutlet: string;
        Tanggal: string;
        JatuhTempo: string;
        Status: StatusDokumenGrosir;
        LabelStatus: string;
        TerminHari: number;
        PeriodePenyerahan: string;
        NomorFakturPajak: string | null;
        TarifPpn: string | null;
        Subtotal: string;
        Diskon: string;
        DasarPengenaanPajak: string;
        Pajak: string;
        Total: string;
        Catatan: string | null;
        AlasanBatal: string | null;
        SisaPiutang: string | null;
        StatusPiutang: string | null;
        LabelStatusPiutang: string | null;
    };
    SuratJalan: {
        Uuid: string;
        Nomor: string;
        Tanggal: string;
        Subtotal: string;
        Diskon: string;
        Pajak: string;
        Total: string;
    }[];
    Jurnal: JurnalGrosir[];
    Riwayat: RiwayatGrosir[];
    Izin: IzinGrosir;
    Tindakan: { Batalkan: boolean; UbahNomorPajak: boolean };
};

/*
 * Halaman cetak A4 (F-12 §9.7 bagian 3). Surat jalan & daftar ambil barang sengaja tidak punya bidang harga sama
 * sekali di tipenya — bukan sekadar tidak ditampilkan, memang tidak dikirim server (lihat `DokumenCetakGrosir`).
 */

export type UsahaCetak = { Nama: string | null; Npwp: string | null };

export type PelangganCetak = { Nama: string; Alamat: string | null; NoHp: string };

export type OutletCetak = { Kode: string; Nama: string; Alamat: string | null };

export type BarisCetakTanpaHarga = {
    Urutan: number;
    NamaProduk: string;
    Sku: string | null;
    SimbolSatuan: string;
    Jumlah: string;
    /** Batch/nomor seri yang diserahkan (surat jalan); tidak ada di dokumen lain. */
    Rincian?: string | null;
};

export type PropsCetakSuratJalan = {
    SuratJalan: {
        Nomor: string;
        Pelanggan: PelangganCetak;
        Outlet: OutletCetak;
        Tanggal: string;
        Status: StatusDokumenGrosir;
        LabelStatus: string;
        AlasanBatal: string | null;
        NamaGudang: string;
        NomorPesanan: string | null;
        NamaPengirim: string | null;
        NomorKendaraan: string | null;
        NamaPenerima: string | null;
        Catatan: string | null;
    };
    Baris: BarisCetakTanpaHarga[];
    Usaha: UsahaCetak;
};

export type PropsCetakFaktur = {
    Faktur: {
        Nomor: string;
        Pelanggan: PelangganCetak;
        Outlet: OutletCetak;
        Tanggal: string;
        JatuhTempo: string;
        Status: StatusDokumenGrosir;
        LabelStatus: string;
        AlasanBatal: string | null;
        TerminHari: number;
        PeriodePenyerahan: string;
        NomorFakturPajak: string | null;
        TarifPpn: string | null;
        Subtotal: string;
        Diskon: string;
        DasarPengenaanPajak: string;
        Pajak: string;
        Total: string;
        Catatan: string | null;
    };
    SuratJalan: { Nomor: string; Tanggal: string; Total: string }[];
    Baris: {
        Kunci: string;
        NomorSuratJalan: string;
        NamaProduk: string;
        Sku: string | null;
        SimbolSatuan: string;
        Jumlah: string;
        Harga: string;
        Diskon: string;
        Subtotal: string;
    }[];
    Usaha: UsahaCetak;
};

export type PropsCetakRetur = {
    Retur: {
        Nomor: string;
        Pelanggan: PelangganCetak;
        Outlet: OutletCetak;
        Tanggal: string;
        Status: StatusDokumenGrosir;
        LabelStatus: string;
        AlasanBatal: string | null;
        Alasan: string;
        MengurangiPiutang: boolean;
        NomorSuratJalan: string | null;
        TanggalSuratJalan: string | null;
        NomorFaktur: string | null;
        TarifPpn: string | null;
        Subtotal: string;
        Diskon: string;
        DasarPengenaanPajak: string;
        Pajak: string;
        Total: string;
        Catatan: string | null;
    };
    Baris: {
        Urutan: number;
        NamaProduk: string;
        Sku: string | null;
        SimbolSatuan: string;
        Jumlah: string;
        LabelKondisi: string;
        Harga: string;
        Diskon: string;
        Subtotal: string;
    }[];
    Usaha: UsahaCetak;
};

export type PropsCetakAmbilBarang = {
    Pesanan: {
        Nomor: string;
        Pelanggan: PelangganCetak;
        Outlet: OutletCetak;
        Tanggal: string;
        TanggalKirimDiminta: string | null;
        Status: StatusPesananGrosir;
        LabelStatus: string;
        Catatan: string | null;
    };
    Baris: (BarisCetakTanpaHarga & { JumlahTerkirim: string; SisaKirim: string })[];
    Usaha: UsahaCetak;
};

// Modul Salesman bagian 3 (§9.7): kanvas = outlet bertanda Kanvas, lokasi stok Toko-nya = bak kendaraan.
export type KendaraanKanvas = {
    Uuid: string;
    Kode: string;
    Nama: string;
    NomorKendaraan: string | null;
    Status: 'Aktif' | 'Diarsipkan';
    UuidGudang: string | null;
    NamaGudang: string | null;
};

/** Satu produk di rekap harian kanvas; semua jumlah string desimal dari buku stok lokasi kendaraan. */
export type BarisRekapKanvas = {
    UuidProduk: string;
    NamaProduk: string;
    Sku: string | null;
    Satuan: string;
    Awal: string;
    Muat: string;
    Terjual: string;
    Retur: string;
    Bongkar: string;
    Lain: string;
    Sisa: string;
};

export type RekapKanvas = {
    Tanggal: string;
    Uang: {
        PenjualanTunai: string;
        PenjualanTempo: string;
        PenjualanLain: string;
        RefundTunai: string;
        NilaiRetur: string;
        Bersih: string;
        JumlahTransaksi: number;
        JumlahVoid: number;
        JumlahRetur: number;
    };
    Setoran: {
        JumlahShiftTertutup: number;
        JumlahShiftBelumDitutup: number;
        KasAwal: string;
        KasSeharusnya: string;
        KasAktual: string;
        Selisih: string;
    };
    Produk: BarisRekapKanvas[];
};

/** D-95: satu transfer muat/bongkar kendaraan pada hari bisnis terpilih. */
export type TransferKanvas = {
    Uuid: string;
    Nomor: string | null;
    Arah: 'Muat' | 'Bongkar';
    Status: string;
    LabelStatus: string;
    NamaLawan: string;
    JumlahBaris: number;
    BisaCetak: boolean;
};

export type PropsDaftarKanvas = {
    Kanvas: KendaraanKanvas[];
    Transfer: TransferKanvas[];
    UuidTerpilih: string | null;
    Tanggal: string;
    Rekap: RekapKanvas | null;
    BatasOutlet: { Batas: number | null; Terpakai: number };
    Izin: IzinGrosir;
    IzinKanvas: { TambahKendaraan: boolean; Transfer: boolean };
};
