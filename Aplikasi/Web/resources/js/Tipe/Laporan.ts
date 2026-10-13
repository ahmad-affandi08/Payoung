import type { HasilTabel } from '@/Komponen/TabelData/Tipe';

/** Tipe laporan F-14a (dasbor pemilik, laporan penjualan, pajak, stok). Uang = string desimal dari server. */

export type OpsiLaporan = { Nilai: string; Label: string };

/** Angka penjualan teragregasi (`DataAgregatPenjualan::KeLarik`). */
export type AngkaPenjualan = {
    Kotor: string;
    Diskon: string;
    Retur: string;
    Bersih: string;
    Pajak: string;
    BiayaLayanan: string;
    Hpp: string;
    LabaKotor: string;
    JumlahTransaksi: number;
    JumlahRetur: number;
    RataRataKeranjang: string;
    JumlahBarang: string | null;
};

export type TitikGrafik = { Tanggal: string; Bersih: string; LabaKotor: string; JumlahTransaksi: number };

export type BarisStokKritis = {
    Kunci: string;
    UuidProduk: string;
    NamaProduk: string;
    Sku: string | null;
    SimbolSatuan: string;
    UuidGudang: string;
    NamaGudang: string;
    NamaOutlet: string;
    Saldo: string;
    StokMinimum: string;
    Kekurangan: string;
};

export type StokKritis = { Jumlah: number; Baris: BarisStokKritis[] };

export type BarisBatchKedaluwarsa = {
    Kunci: string;
    UuidProduk: string;
    NamaProduk: string;
    Sku: string | null;
    SimbolSatuan: string;
    UuidGudang: string;
    NamaGudang: string;
    NamaOutlet: string;
    NomorBatch: string;
    TanggalKedaluwarsa: string;
    SisaHari: number;
    Status: 'Lewat' | 'Segera' | 'Mendekati';
    Sisa: string;
};

export type BatchKedaluwarsa = { Jumlah: number; JumlahLewat: number; Baris: BarisBatchKedaluwarsa[] };

export type DasborPemilik = {
    Tanggal: string;
    HariIni: AngkaPenjualan;
    Kemarin: AngkaPenjualan;
    MingguLalu: AngkaPenjualan;
    Grafik: TitikGrafik[];
    ProdukTerlaris: { Kunci: string; NamaProduk: string; Qty: string; Bersih: string }[];
    PerOutlet: { Kunci: string; NamaOutlet: string; Bersih: string; JumlahTransaksi: number }[];
    StokKritis: StokKritis | null;
    Shift: {
        JumlahTerbuka: number;
        Terbuka: { Uuid: string; NamaOutlet: string; NamaKasir: string; DibukaPada: string }[];
        Tertutup: {
            Uuid: string;
            NamaOutlet: string;
            NamaKasir: string;
            DitutupPada: string | null;
            Selisih: string | null;
        }[];
    };
    JumlahPerluTinjauan: number;
};

export type TabLaporanPenjualan =
    | 'harian'
    | 'detail'
    | 'produk'
    | 'kategori'
    | 'jam'
    | 'kasir'
    | 'kanal'
    | 'metode'
    | 'diskon'
    | 'void-item'
    | 'anti-fraud'
    | 'abc'
    | 'menu'
    | 'pelanggan'
    | 'outlet'
    | 'waktu-saji'
    | 'food-cost'
    | 'performa-kasir'
    | 'prediksi'
    | 'bundling'
    | 'umpan-balik';

export type SaringLaporanPenjualan = {
    Tab: TabLaporanPenjualan;
    Dari: string;
    Sampai: string;
    Outlet: string;
    Kasir: string;
    Kanal: string;
    /** Tab waktu saji: target menit (tiket lebih lama dihitung terlambat). */
    Target?: number;
};

export type BarisHarian = AngkaPenjualan & { Tanggal: string };
/** Satu baris keranjang pada tab Detail penjualan (D-43). `Waktu` ISO 8601 UTC. */
export type BarisDetailPenjualan = {
    Id: number;
    Nomor: string;
    Waktu: string;
    NamaOutlet: string;
    Kanal: string;
    NamaProduk: string;
    Qty: string;
    HargaSatuan: string;
    Kotor: string;
    Diskon: string;
    Pajak: string;
    Total: string;
    Metode: string;
    NamaKasir: string;
    Catatan: string;
};
export type BarisProdukLaporan = {
    IdProduk: number;
    NamaProduk: string;
    Qty: string;
    Kotor: string;
    Diskon: string;
    Retur: string;
    Bersih: string;
    Pajak: string;
    BiayaLayanan: string;
    Hpp: string;
    LabaKotor: string;
    JumlahTransaksi: number;
    JumlahRetur: number;
};
export type BarisKategoriLaporan = {
    Kunci: string;
    NamaKategori: string;
    JumlahProduk: number;
    Qty: string;
    Kotor: string;
    Diskon: string;
    Retur: string;
    Bersih: string;
    Pajak: string;
    Hpp: string;
    LabaKotor: string;
};
export type SelJam = { Hari: number; Jam: number; Bersih: string; JumlahTransaksi: number };
export type IsiJam = { Sel: SelJam[]; PerJam: { Jam: number; Bersih: string; JumlahTransaksi: number }[] };
export type BarisKasirLaporan = AngkaPenjualan & { Kunci: string; NamaKasir: string };
export type BarisKanalLaporan = AngkaPenjualan & { Kunci: string; LabelKanal: string };
/** Waktu saji per stasiun dapur (detik); `null` = KDS tidak menandai tahap itu. */
export type BarisWaktuSaji = {
    Kunci: string;
    NamaStasiun: string;
    JumlahTiket: number;
    TungguRataRata: number | null;
    MasakRataRata: number | null;
    TotalRataRata: number;
    TotalMedian: number;
    TotalP90: number;
    TotalTerlama: number;
    SajikanRataRata: number | null;
    LewatTarget: number;
    PersenLewatTarget: string;
};
/** Food cost per bahan: nilai teoretis (resep × terjual), terbuang, dan selisih stok; kerugian positif. */
export type BarisFoodCost = {
    Kunci: string;
    NamaProduk: string;
    SimbolSatuan: string;
    QtyTeoretis: string;
    NilaiTeoretis: string;
    QtyTerbuang: string;
    NilaiTerbuang: string;
    QtySelisih: string;
    NilaiSelisih: string;
    NilaiAktual: string;
    VarianPersen: string | null;
};
export type IsiFoodCost = {
    Ringkasan: {
        Penjualan: string;
        Teoretis: string;
        Terbuang: string;
        Selisih: string;
        Aktual: string;
        TeoretisPersen: string;
        AktualPersen: string;
        SelisihPoin: string;
    };
    Baris: BarisFoodCost[];
};
export type BarisPerformaKasir = {
    Kunci: string;
    NamaKasir: string;
    JumlahTransaksi: number;
    Bersih: string;
    RataRataKeranjang: string;
    JamKerja: string;
    ShiftDitutup: number;
    TransaksiPerJam: string | null;
    OmzetPerJam: string | null;
    JumlahVoid: number;
    PersenVoid: string;
    SelisihKurang: string;
};
/** Prediksi penjualan 14 hari ke depan (rata-rata hari-dalam-minggu × faktor tren). */
export type BarisPrediksi = {
    Tanggal: string;
    Hari: number;
    Perkiraan: string;
    PerkiraanTransaksi: number;
    Terendah: string;
    Tertinggi: string;
    Sampel: number;
};
export type IsiPrediksi = {
    Cukup: boolean;
    HariData: number;
    FaktorTren: string;
    Baris: BarisPrediksi[];
    TotalPerkiraan: string;
};
/** Pasangan produk yang sering dibeli bersama; `Lift` > 1 = lebih sering bersama daripada kebetulan. */
export type BarisBundling = {
    Kunci: string;
    NamaA: string;
    NamaB: string;
    Support: number;
    PorsiTransaksi: string;
    KeyakinanAB: string;
    KeyakinanBA: string;
    Lift: string;
    HargaNormal: string | null;
    HargaPaket: string | null;
};
export type IsiBundling = { JumlahTransaksi: number; Terpotong: boolean; Baris: BarisBundling[] };
/** F7 (CRM-08): umpan balik pelanggan dari struk digital. */
export type BarisUmpanBalik = {
    Kunci: string;
    Nomor: string;
    Waktu: string;
    NamaOutlet: string;
    NamaPelanggan: string | null;
    Nilai: number;
    Komentar: string | null;
};
export type IsiUmpanBalik = {
    Ringkasan: {
        Jumlah: number;
        RataRata: string;
        PerNilai: Record<'1' | '2' | '3' | '4' | '5', number>;
        JumlahTransaksi: number;
        TingkatRespons: string;
    };
    Baris: BarisUmpanBalik[];
};
/** Penjualan per pelanggan; `Kunci` kosong = transaksi tanpa pelanggan. `Porsi` = % omzet bersih. */
export type BarisPelangganLaporan = AngkaPenjualan & { Kunci: string; NamaPelanggan: string; Porsi: string };
/** Perbandingan outlet; `Margin` = % laba kotor terhadap omzet bersih. */
export type BarisOutletLaporan = AngkaPenjualan & { Kunci: string; NamaOutlet: string; Porsi: string; Margin: string };
export type BarisMetodeLaporan = {
    Kunci: string;
    NamaMetode: string;
    LabelJenis: string;
    Diterima: string;
    Refund: string;
    Bersih: string;
    JumlahTransaksi: number;
};
export type BarisDiskonLaporan = {
    Kunci: string;
    NamaKasir: string;
    JumlahTransaksi: number;
    JumlahBerdiskon: number;
    JumlahDisetujui: number;
    DiskonBaris: string;
    DiskonPesanan: string;
    TotalDiskon: string;
    Kotor: string;
};

/** BR-07.5: void item pesanan meja (item sudah dikirim ke dapur lalu dibatalkan). `Waktu` ISO 8601 UTC. */
export type BarisVoidItemLaporan = {
    Kunci: string;
    Waktu: string;
    NamaOutlet: string;
    Nomor: string;
    NamaProduk: string;
    Jumlah: string;
    Nilai: string;
    Alasan: string;
    NamaPembatal: string;
    NamaPenyetuju: string;
    Terbuang: boolean;
};

/** F-14 anti-fraud (OWN-09): pola & skor risiko per kasir; skor adalah petunjuk untuk diperiksa, bukan bukti. */
export type BarisAntiFraudLaporan = {
    Kunci: string;
    NamaKasir: string;
    JumlahTransaksi: number;
    JumlahVoid: number;
    NilaiVoid: string;
    VoidCepatTunai: number;
    JumlahRetur: number;
    NilaiRetur: string;
    JumlahBerdiskon: number;
    TotalDiskon: string;
    BukaLaciManual: number;
    ShiftSelisihKurang: number;
    SelisihKurang: string;
    Skor: number;
    Tingkat: 'Rendah' | 'Sedang' | 'Tinggi';
    Alasan: string[];
};

/** X6 analisis ABC (Pareto): A ±80% omzet, B sampai 95%, C sisanya. Persen = string 2 desimal ("45.67"). */
export type KelasAbc = 'A' | 'B' | 'C';
export type BarisAbcLaporan = {
    IdProduk: number;
    NamaProduk: string;
    Qty: string;
    Bersih: string;
    Porsi: string;
    PorsiKumulatif: string;
    Kelas: KelasAbc;
};
export type IsiAbc = { Baris: BarisAbcLaporan[]; Ringkasan: Record<KelasAbc, { Jumlah: number; Bersih: string }> };

/** X6 menu engineering (Kasavana & Smith): populer × margin kontribusi per unit. */
export type KelasMenu = 'Star' | 'Plowhorse' | 'Puzzle' | 'Dog';
export type BarisMenuLaporan = {
    IdProduk: number;
    NamaProduk: string;
    Qty: string;
    Bersih: string;
    Hpp: string;
    MarginPerUnit: string;
    HargaRata: string;
    /** Hanya menu Plowhorse (laris, margin rendah): harga baru yang disarankan; selain itu null. */
    SaranHarga: string | null;
    PorsiQty: string;
    Populer: boolean;
    MarginTinggi: boolean;
    Kelas: KelasMenu;
};
export type IsiMenu = { Baris: BarisMenuLaporan[]; BatasPorsiQty: string; RataRataMargin: string };

/** X6 (v3.79): langganan insight mingguan lewat WhatsApp milik pengguna yang sedang masuk. */
export type InsightWhatsappLaporan = { BisaWhatsapp: boolean; Aktif: boolean };

export type PropsLaporanPenjualan = {
    Saring: SaringLaporanPenjualan;
    InsightWhatsapp?: InsightWhatsappLaporan;
    Peringatan: string | null;
    MaksHari: number;
    OpsiOutlet: OpsiLaporan[];
    OpsiKasir: OpsiLaporan[];
    OpsiKanal: OpsiLaporan[];
    OpsiTarget?: number[];
    Total: AngkaPenjualan;
    Isi:
        | BarisHarian[]
        | HasilTabel<BarisProdukLaporan>
        | HasilTabel<BarisDetailPenjualan>
        | BarisKategoriLaporan[]
        | IsiJam
        | BarisKasirLaporan[]
        | BarisKanalLaporan[]
        | BarisPelangganLaporan[]
        | BarisOutletLaporan[]
        | BarisWaktuSaji[]
        | IsiFoodCost
        | BarisPerformaKasir[]
        | IsiPrediksi
        | IsiBundling
        | IsiUmpanBalik
        | BarisMetodeLaporan[]
        | BarisDiskonLaporan[]
        | BarisVoidItemLaporan[]
        | BarisAntiFraudLaporan[]
        | IsiAbc
        | IsiMenu;
};

export type BarisPajakLaporan = {
    Kunci: string;
    Bulan: string;
    KodeJenisPajak: string;
    NamaJenisPajak: string;
    LabelKategori: string;
    Tarif: string;
    NamaOutlet?: string;
    Dpp: string;
    Pajak: string;
    DppRetur: string;
    PajakRetur: string;
    DppBersih: string;
    PajakBersih: string;
    JumlahTransaksi: number;
};

/** TAX-05: PPN pada faktur pembelian per bulan × tarif × dapat/tidaknya dikreditkan, dikurangi PPN retur pembelian. */
export type BarisPpnMasukan = {
    Kunci: string;
    Bulan: string;
    Tarif: string;
    Dikreditkan: boolean;
    JumlahFaktur: number;
    Nilai: string;
    Pajak: string;
    NilaiRetur: string;
    PajakRetur: string;
    PajakBersih: string;
};
export type RingkasanPpn = {
    PpnKeluaran: string;
    PpnMasukanKredit: string;
    PpnMasukanTanpaKredit: string;
    /** Keluaran − masukan dikreditkan: positif kurang bayar, negatif lebih bayar. */
    Selisih: string;
};

export type PropsLaporanPajak = {
    Saring: { Dari: string; Sampai: string; Outlet: string };
    Peringatan: string | null;
    OpsiOutlet: OpsiLaporan[];
    Pbjt: BarisPajakLaporan[];
    Ppn: BarisPajakLaporan[];
    PpnMasukan: BarisPpnMasukan[];
    RingkasanPpn: RingkasanPpn;
};

/** Ringkasan kesiapan ekspor Faktur Pajak Keluaran Coretax (GET /kelola/laporan/pajak/faktur-keluaran, PRD v3.12). */
export type RingkasanFakturPajak = {
    JumlahDiperiksa: number;
    JumlahSiap: number;
    TotalDpp: string;
    TotalPpn: string;
    BisaDiekspor: boolean;
    MasalahUmum: string[];
    MasalahFaktur: { Nomor: string; Alasan: string[] }[];
    Peringatan: string[];
    Selisih: { Nomor: string; SelisihDpp: string; SelisihPpn: string }[];
};

/** Rekap nota retur pajak dari retur grosir (GET /kelola/laporan/pajak/nota-retur, PRD v4.08). */
export type RingkasanNotaReturPajak = {
    JumlahDiperiksa: number;
    JumlahSiap: number;
    TotalDpp: string;
    TotalPpn: string;
    BisaDiekspor: boolean;
    MasalahUmum: string[];
    MasalahRetur: { Nomor: string; Alasan: string[] }[];
    Peringatan: string[];
    Retur: {
        Nomor: string;
        Tanggal: string;
        NomorFaktur: string;
        NomorFakturPajak: string;
        Pembeli: string;
        Dpp: string;
        Ppn: string;
    }[];
};

export type NilaiPersediaan = {
    Total: { Nilai: string; JumlahProduk: number };
    PerGudang: { Kunci: string; NamaGudang: string; NamaOutlet: string; JumlahProduk: number; Nilai: string }[];
    PerKategori: { Kunci: string; NamaKategori: string; JumlahProduk: number; Nilai: string }[];
};

/** X6 saran restock: laju pemakaian `HariDasar` hari terakhir → saran beli untuk `HariCakupan` hari ke depan. */
export type BarisSaranRestock = {
    Kunci: string;
    UuidProduk: string;
    NamaProduk: string;
    Sku: string | null;
    SimbolSatuan: string;
    UuidGudang: string;
    NamaGudang: string;
    NamaOutlet: string;
    Pakai: string;
    RataPerHari: string;
    /** X6 musiman: pengali laju dari periode yang sama tahun lalu (1.00 = tanpa data/tanpa musim). */
    FaktorMusim: string;
    RataPerkiraan: string;
    Saldo: string;
    HariHabis: number | null;
    SaranBeli: string;
};
/** Pembanding tahun lalu: `Lebaran` = selaras Idul Fitri (digeser `SelisihHari`), `TahunLalu` = 364 hari. */
export type MusimRestock = { Jenis: 'Lebaran' | 'TahunLalu'; SelisihHari: number; Lebaran: string | null };
export type SaranRestock = { HariDasar: number; HariCakupan: number; Musim: MusimRestock; Baris: BarisSaranRestock[] };

/** Rencana produksi harian (§9.11): permintaan hari yang sama + pre-order vs saldo sekarang. */
export type BarisRencanaProduksi = {
    Kunci: string;
    UuidProduk: string;
    NamaProduk: string;
    Sku: string | null;
    SimbolSatuan: string;
    UuidGudang: string;
    NamaGudang: string;
    NamaOutlet: string;
    Sampel: number;
    RataHariSama: string;
    PreOrder: string;
    Kebutuhan: string;
    Saldo: string;
    Rekomendasi: string;
};
export type RencanaProduksi = { Target: string; Minggu: number; Baris: BarisRencanaProduksi[] };

/** Slow/fast moving: perputaran = pemakaian rentang ÷ saldo sekarang. */
export type KelasPerputaran = 'Cepat' | 'Normal' | 'Lambat' | 'Mati';
export type BarisPerputaranStok = {
    Kunci: string;
    UuidProduk: string;
    NamaProduk: string;
    Sku: string | null;
    SimbolSatuan: string;
    UuidGudang: string;
    NamaGudang: string;
    NamaOutlet: string;
    Pakai: string;
    RataPerHari: string;
    Saldo: string;
    Nilai: string;
    Perputaran: string | null;
    HariCover: number | null;
    Kelas: KelasPerputaran;
};
export type PerputaranStok = {
    Rentang: number;
    Ringkasan: Record<KelasPerputaran, { Jumlah: number; Nilai: string }>;
    NilaiTertahan: string;
    Baris: BarisPerputaranStok[];
};

export type PropsLaporanStok = {
    Saring: {
        Tab: 'nilai' | 'kritis' | 'kedaluwarsa' | 'restock' | 'perputaran' | 'rencana';
        Tanggal: string;
        Gudang: string;
        Hari: number;
    };
    OpsiGudang: OpsiLaporan[];
    Nilai: NilaiPersediaan | null;
    Kritis: StokKritis | null;
    Kedaluwarsa: BatchKedaluwarsa | null;
    Restock: SaranRestock | null;
    Perputaran: PerputaranStok | null;
    Rencana: RencanaProduksi | null;
};

/** Apotek (§9.5): satu baris penjualan obat keras/psikotropika/narkotika (`DaftarPenjualanObatResep`). */
export type BarisPenjualanObatResep = {
    Uuid: string;
    UuidPenjualan: string | null;
    Tanggal: string | null;
    Nomor: string | null;
    Status: string | null;
    NamaProduk: string;
    Golongan: string | null;
    LabelGolongan: string;
    ObatWajibApotek: boolean;
    Jumlah: string;
    SimbolSatuan: string;
    Batch: string;
    DenganResep: boolean;
    NomorResep: string | null;
    TanggalResep: string | null;
    NamaDokter: string | null;
    NoSipDokter: string | null;
    /** Disamarkan ("B*** S***") bila pelaku tanpa izin `apotek.resep.lihat`. */
    NamaPasien: string | null;
    UmurPasien: string | null;
    AlamatPasien: string | null;
    PasienTersamar: boolean;
    NamaApoteker: string | null;
    NamaKasir: string;
};

/** Apotek: data pendukung SIPNAP per produk psikotropika/narkotika untuk satu bulan (jumlah satuan dasar). */
export type BarisSipnap = {
    UuidProduk: string;
    NamaProduk: string;
    Sku: string | null;
    Golongan: string;
    LabelGolongan: string;
    Prekursor: boolean;
    SimbolSatuan: string;
    StokAwal: string;
    PemasukanPemasok: string;
    PemasukanLain: string;
    PengeluaranPenjualan: string;
    PengeluaranLain: string;
    StokAkhir: string;
};

export type PropsLaporanApotek = {
    Tab: 'resep' | 'sipnap';
    Penjualan: HasilTabel<BarisPenjualanObatResep>;
    OpsiGolongan: { Nilai: string; Label: string }[];
    LihatPasien: boolean;
    Sipnap: { Bulan: string; Outlet: string; Baris: BarisSipnap[] };
    OpsiOutlet: { Nilai: string; Label: string }[];
};

/** §10.10 laporan pembelian (`/kelola/laporan/pembelian`). */
export type TabLaporanPembelian = 'pemasok' | 'produk' | 'po';

export type BarisPembelianPemasok = {
    Kunci: string;
    KodePemasok: string;
    NamaPemasok: string;
    JumlahFaktur: number;
    Subtotal: string;
    Ongkir: string;
    Pajak: string;
    Total: string;
    Retur: string;
    Bersih: string;
    Dibayar: string;
    SisaHutang: string;
};

export type BarisPembelianProduk = {
    Kunci: string;
    NamaProduk: string;
    Qty: string;
    Nilai: string;
    NilaiRetur: string;
    Bersih: string;
    HargaRata: string;
    HargaMin: string;
    HargaMax: string;
    JumlahFaktur: number;
    JumlahPemasok: number;
};

export type BarisPesananOutstanding = {
    Kunci: string;
    Nomor: string;
    NamaPemasok: string;
    Tanggal: string;
    PerkiraanTiba: string | null;
    Status: 'Disetujui' | 'DiterimaSebagian';
    LabelStatus: string;
    UmurHari: number;
    HariTerlambat: number;
    JumlahBaris: number;
    JumlahBarisSisa: number;
    Total: string;
    NilaiSisa: string;
};

export type PropsLaporanPembelian = {
    Saring: { Tab: TabLaporanPembelian; Dari: string; Sampai: string; Outlet: string };
    Peringatan: string | null;
    MaksHari: number;
    OpsiOutlet: OpsiLaporan[];
    Isi: BarisPembelianPemasok[] | BarisPembelianProduk[] | BarisPesananOutstanding[];
};
