import type { HasilTabel } from '@/Komponen/TabelData/Tipe';
/**
 * Kontrak props halaman F-03 Master Produk, Harga & Pajak (DesainF03 bagian E, dibekukan di Wave 0).
 *
 * Uang, kuantitas, dan persen SELALU string desimal (CLAUDE.md #7). Props tiap halaman adalah
 * `PropsBersamaAplikasi & {…}`; tipe di bawah adalah bagian `{…}`-nya. Perubahan bentuk = versi kontrak baru.
 */
import type { Batas, Pilihan } from '@/Tipe/Organisasi';

// E.1 Tipe bersama.
export type JenisProduk =
    'Stok' | 'IndukVarian' | 'Resep' | 'Produksi' | 'Paket' | 'Jasa' | 'NonStok' | 'BahanBaku' | 'Konsinyasi';
export type PelacakanProduk = 'Tidak' | 'Batch' | 'Seri';
export type StatusProduk = 'Aktif' | 'Diarsipkan';
export type KategoriPajakProduk = 'KenaPpn' | 'BebasPpn' | 'KenaPbjt' | 'NonPajak' | 'Lainnya';
export type KanalPenjualan = 'MakanDiTempat' | 'BawaPulang' | 'Antar' | 'Online' | 'PesanSendiri' | 'Marketplace';
export type TigaKeadaan = 'Ikut' | 'Ya' | 'Tidak';
export type AturanJenisProduk = {
    Nilai: JenisProduk;
    Label: string;
    PunyaStok: boolean;
    BisaDijual: boolean;
    BolehPelacakan: boolean;
    BolehResep: boolean;
    BolehKomponen: boolean;
    BolehPilihan: boolean;
    DihitungBatasSku: boolean;
};
export type TabProduk = {
    Kunci: 'Ringkasan' | 'Harga' | 'Pilihan' | 'Resep' | 'Komponen';
    Label: string;
    Tautan: string;
};
export type KepalaProduk = {
    Uuid: string;
    Nama: string;
    Sku: string | null;
    Jenis: JenisProduk;
    LabelJenis: string;
    Status: StatusProduk;
    UrlGambarKecil: string | null;
    UuidInduk: string | null;
    NamaInduk: string | null;
    Tab: TabProduk[];
};
export type OpsiKategori = {
    Uuid: string;
    Nama: string;
    /** Misal "Minuman › Kopi". */
    Jalur: string;
    Kedalaman: number;
    UuidInduk: string | null;
};
export type OpsiSatuan = { Uuid: string; Nama: string; Simbol: string; BolehDesimal: boolean };
export type OpsiKelompokPajak = {
    Uuid: string;
    Nama: string;
    Kategori: KategoriPajakProduk | null;
    LabelKategori: string;
};
export type BarisHarga = { JumlahMinimum: string; Harga: string };
export type IzinKatalog = { Kelola: boolean; UbahHarga: boolean; KelolaPersediaan: boolean; KelolaPajak: boolean };

// E.2 Kelola/Produk/Daftar.
export type BarisProduk = {
    Uuid: string;
    Nama: string;
    Sku: string | null;
    Jenis: JenisProduk;
    LabelJenis: string;
    NamaKategori: string | null;
    Merek: string | null;
    HargaDasar: string | null;
    SimbolSatuan: string;
    JumlahVarian: number;
    TampilDiPos: boolean;
    Favorit: boolean;
    DiubahPada: string | null;
    Status: StatusProduk;
    UrlGambarKecil: string | null;
};
export type PropsDaftarProduk = {
    Produk: HasilTabel<BarisProduk>;
    Kategori: OpsiKategori[];
    Jenis: AturanJenisProduk[];
    BatasSku: Batas;
    Izin: IzinKatalog;
};

// E.3 Kelola/Produk/Form (buat/ubah).
export type FormSatuanProduk = {
    Uuid: string | null;
    UuidSatuan: string;
    KonversiKeDasar: string;
    DefaultJual: boolean;
    DefaultBeli: boolean;
    Barcode: string[];
    /** Hanya untuk satuan baru; [] = tanpa harga. */
    HargaAwal: BarisHarga[];
};
/** Apotek (§9.5): golongan obat (`Produk.GolonganObat`). */
export type GolonganObat = 'Bebas' | 'BebasTerbatas' | 'Keras' | 'Psikotropika' | 'Narkotika';

/** Label golongan obat untuk tampilan (sama dengan `GolonganObat::AmbilLabel`). */
export const labelGolonganObat: Record<GolonganObat, string> = {
    Bebas: 'Obat bebas',
    BebasTerbatas: 'Obat bebas terbatas',
    Keras: 'Obat keras',
    Psikotropika: 'Psikotropika',
    Narkotika: 'Narkotika',
};

export type FormProduk = {
    Uuid: string;
    Nama: string;
    NamaStruk: string;
    Sku: string;
    Jenis: JenisProduk;
    UuidKategori: string | null;
    Merek: string;
    UuidSatuanDasar: string;
    Pelacakan: PelacakanProduk;
    UuidKelompokPajak: string | null;
    HargaTermasukPajak: TigaKeadaan;
    BolehMinus: TigaKeadaan;
    TampilDiPos: boolean;
    TampilOnline: boolean;
    /** K-16: produk favorit pilihan pemilik, tampil di kategori Favorit kasir. */
    Favorit: boolean;
    /** K-25: kasir mengetik harga per transaksi (Stok, NonStok, Jasa, Resep); tidak ada = false. */
    HargaTerbuka?: boolean;
    Satuan: FormSatuanProduk[];
    AtributVarian: { Nama: string; Nilai: string[] }[];
    /** F-07 mode service: lama layanan jasa (menit) untuk reservasi; null = tidak bisa direservasi. */
    DurasiMenit?: number | null;
    /** F-05h: masa garansi standar (bulan) produk bernomor seri; null = tanpa garansi. */
    MasaGaransiBulan?: number | null;
    /** BR-05.4: lokasi stok tempat bahan resep dikurangi saat dijual (produk Resep); null = Toko. */
    LokasiProduksi?: 'Dapur' | 'Bar' | null;
    /** Faktur Pajak Coretax: kode barang/jasa 6 digit dan kode satuan `UM.00xx`; null = kode umum. */
    KodeBarangJasaCoretax?: string | null;
    KodeUnitCoretax?: string | null;
    /** Apotek (§9.5): golongan obat (null = bukan obat), Obat Wajib Apotek (hanya Keras), prekursor farmasi. */
    GolonganObat?: GolonganObat | null;
    ObatWajibApotek?: boolean;
    Prekursor?: boolean;
    /** D-23 B: "Jual sebagai paket sesi" saat membuat produk Jasa; tidak ada/null = bukan paket. */
    PaketSesi?: { JumlahSesi: string; MasaBerlakuHari: string } | null;
    /** Audit kemudahan pakai #11: stok sekarang produk baru (dikirim hanya bila Jumlah diisi). */
    StokAwal?: IsianStokAwalProduk | null;
};

export type IsianStokAwalProduk = { Jumlah: string; HargaBeli: string; UuidGudang: string };

/** Lokasi stok untuk isian "Stok sekarang" (null = pelaku tidak boleh mencatat stok awal). */
export type OpsiStokAwalProduk = { Lokasi: { Uuid: string; Nama: string; NamaOutlet: string | null }[] };
export type PropsFormProduk = {
    Mode: 'Buat' | 'Ubah';
    /** Buat: Uuid = ULID baru dari server (kunci idempotensi). */
    Produk: FormProduk;
    Kepala: KepalaProduk | null;
    Kategori: OpsiKategori[];
    Satuan: OpsiSatuan[];
    KelompokPajak: OpsiKelompokPajak[];
    Jenis: AturanJenisProduk[];
    JenisTerkunci: boolean;
    BatasSku: Batas;
    /** HargaTermasukPajakOutlet adalah label. */
    Pengaturan: { HargaTermasukPajakOutlet: string; StokBolehMinus: boolean };
    Izin: IzinKatalog;
    /** D-23 B: fitur paket sesi termasuk paket langganan (hanya mode Buat). */
    FiturPaketSesi?: boolean;
    StokAwal?: OpsiStokAwalProduk | null;
};

// E.4 Kelola/Produk/Detail.
export type SatuanDetail = {
    Uuid: string;
    Nama: string;
    Simbol: string;
    KonversiKeDasar: string;
    DefaultJual: boolean;
    DefaultBeli: boolean;
    BisaDijual: boolean;
    Barcode: { Uuid: string; Barcode: string }[];
};
export type BarisVarian = {
    Uuid: string;
    Nama: string;
    Sku: string | null;
    Atribut: { Nama: string; Nilai: string }[];
    HargaDasar: string | null;
    /** Barcode pertama varian (audit kemudahan pakai #18); null = belum ada. */
    Barcode?: string | null;
    Status: StatusProduk;
};
export type BarisBatasStok = {
    UuidGudang: string;
    NamaGudang: string;
    NamaOutlet: string;
    StokMinimum: string;
    /** "" = kosong. */
    StokMaksimum: string;
};
export type BarisKetersediaanOutlet = {
    UuidOutlet: string;
    NamaOutlet: string;
    Habis: boolean;
};
export type DetailProduk = {
    Uuid: string;
    Nama: string;
    NamaStruk: string | null;
    Sku: string | null;
    Jenis: JenisProduk;
    LabelJenis: string;
    NamaKategori: string | null;
    Merek: string | null;
    SatuanDasar: OpsiSatuan;
    Pelacakan: PelacakanProduk;
    LabelPelacakan: string;
    KelompokPajak: OpsiKelompokPajak | null;
    HargaTermasukPajak: TigaKeadaan;
    BolehMinus: TigaKeadaan;
    TampilDiPos: boolean;
    TampilOnline: boolean;
    Favorit: boolean;
    HargaTerbuka?: boolean;
    GolonganObat?: GolonganObat | null;
    ObatWajibApotek?: boolean;
    Prekursor?: boolean;
    UrlGambar: string | null;
    UrlGambarKecil: string | null;
    Satuan: SatuanDetail[];
    AtributVarian: { Nama: string; Nilai: string[] }[];
    AlasanTidakBisaDihapus: string | null;
    DibuatPada: string;
    DiubahPada: string;
    DiarsipkanPada: string | null;
};
export type PropsDetailProduk = {
    Kepala: KepalaProduk;
    Produk: DetailProduk;
    Varian: BarisVarian[];
    /** null = bukan jenis yang punya stok. */
    BatasStok: BarisBatasStok[] | null;
    /** null = produk induk varian (ditandai per varian). */
    Ketersediaan: BarisKetersediaanOutlet[] | null;
    Riwayat: { Peristiwa: string; NamaPengguna: string | null; DibuatPada: string }[];
    Jenis: AturanJenisProduk[];
    BatasSku: Batas;
    Izin: IzinKatalog;
};

// E.5 Kelola/Kategori/Daftar dan Kelola/Satuan/Daftar.
export type PropsDaftarKategori = {
    Kategori: (OpsiKategori & {
        JumlahProduk: number;
        Urutan: number;
        UuidStasiunDapur: string | null;
        NamaStasiunDapur: string | null;
    })[];
    OpsiStasiunDapur: { Nilai: string; Label: string }[];
    Izin: IzinKatalog;
};
// Form: { Nama: string; UuidInduk: string|null; Urutan: string; UuidStasiunDapur?: string|null } → POST /kelola/kategori,
// PUT/DELETE /kelola/kategori/{uuid}. UuidStasiunDapur (F-10a) hanya dikirim bila tenant punya stasiun dapur.

// F-10a Kelola/StasiunDapur/Daftar.
export type StasiunDapur = { Uuid: string; Nama: string; Urutan: number; Status: 'Aktif' | 'Diarsipkan' };
export type PropsDaftarStasiunDapur = { Stasiun: StasiunDapur[]; Izin: IzinKatalog };
export type PropsDaftarSatuan = {
    Satuan: (OpsiSatuan & { KodeStandar: string | null; JumlahProduk: number })[];
    Izin: IzinKatalog;
};
// Form: { Nama: string; Simbol: string; BolehDesimal: boolean } → POST /kelola/satuan, PUT/DELETE /kelola/satuan/{uuid}.

// E.6 Kelola/Produk/Harga.
export type BarisRiwayatHarga = {
    DibuatPada: string;
    NamaSatuan: string;
    NamaDaftarHarga: string | null;
    JumlahMinimum: string;
    HargaLama: string | null;
    HargaBaru: string | null;
    NamaPengubah: string | null;
    Sumber: string;
    LabelSumber: string;
};
export type PropsHargaProduk = {
    Kepala: KepalaProduk;
    Satuan: {
        UuidProdukSatuan: string;
        Nama: string;
        Simbol: string;
        KonversiKeDasar: string;
        BolehDesimal: boolean;
        HargaDasar: BarisHarga[];
    }[];
    DaftarHarga: {
        Uuid: string;
        Nama: string;
        Aktif: boolean;
        Ringkasan: string;
        Harga: (BarisHarga & { UuidProdukSatuan: string })[];
    }[];
    Riwayat: HasilTabel<BarisRiwayatHarga>;
    OpsiSumberRiwayat: Pilihan[];
    LabelHargaTermasukPajak: string;
    Izin: IzinKatalog;
};

// E.7 Kelola/DaftarHarga/Daftar dan /Detail.
export type FormDaftarHarga = {
    Nama: string;
    /** [] = semua outlet. */
    UuidOutlet: string[];
    Kanal: KanalPenjualan | '';
    TierPelanggan: string;
    MulaiPada: string;
    /** 'YYYY-MM-DDTHH:mm' di zona waktu tenant, '' = kosong (berlaku juga untuk MulaiPada). */
    SelesaiPada: string;
    Prioritas: string;
};
export type BarisDaftarHarga = {
    Uuid: string;
    Nama: string;
    NamaOutlet: string[] | null;
    Kanal: KanalPenjualan | null;
    LabelKanal: string | null;
    TierPelanggan: string | null;
    MulaiPada: string | null;
    SelesaiPada: string | null;
    Prioritas: number;
    Aktif: boolean;
    JumlahProduk: number;
};
export type PropsDaftarDaftarHarga = {
    DaftarHarga: HasilTabel<BarisDaftarHarga>;
    Outlet: Pilihan[];
    Kanal: Pilihan[];
    /** F-16b: tier pelanggan aktif. */
    OpsiTier?: Pilihan[];
    ZonaWaktu: string;
    Izin: IzinKatalog;
};
export type PropsBuatDaftarHarga = Omit<PropsDaftarDaftarHarga, 'DaftarHarga' | 'Izin'>;
export type PropsDetailDaftarHarga = {
    DaftarHarga: FormDaftarHarga & { Uuid: string; Aktif: boolean };
    Baris: HasilTabel<{
        UuidProduk: string;
        NamaProduk: string;
        Sku: string | null;
        UuidProdukSatuan: string;
        NamaSatuan: string;
        HargaDasar: string | null;
        Harga: BarisHarga[];
    }>;
    Outlet: Pilihan[];
    Kanal: Pilihan[];
    OpsiTier?: Pilihan[];
    ZonaWaktu: string;
    Izin: IzinKatalog;
};

// E.8 Kelola/KelompokPajak/Daftar.
export type PropsDaftarKelompokPajak = {
    KelompokPajak: (OpsiKelompokPajak & {
        Pajak: {
            KodeJenisPajak: string;
            NamaJenisPajak: string;
            DasarPengenaan: string;
            LabelDasarPengenaan: string;
            /** F-17 bagian 3: ongkir yang ditagih ke pembeli ikut DPP pajak ini. */
            KenaBiayaKirim: boolean;
        }[];
        JumlahProduk: number;
    })[];
    JenisPajak: { Kode: string; Nama: string; Cakupan: string }[];
    Kategori: Pilihan[];
    DasarPengenaan: Pilihan[];
    Izin: IzinKatalog;
};
export type PropsBuatKelompokPajak = Omit<PropsDaftarKelompokPajak, 'KelompokPajak' | 'Izin'>;
// Form { Nama; Kategori: KategoriPajakProduk; Pajak: { KodeJenisPajak; DasarPengenaan; KenaBiayaKirim }[] } → POST /kelola/kelompok-pajak, PUT /kelola/kelompok-pajak/{uuid}.

// E.9 Halaman Tim 3.
export type FormPilihan = {
    Uuid: string | null;
    Nama: string;
    Harga: string;
    Aktif: boolean;
    UuidProdukBahan: string | null;
    Jumlah: string;
};
export type FormKelompokPilihan = {
    Nama: string;
    MinimalPilih: string;
    MaksimalPilih: string;
    Urutan: string;
    Pilihan: FormPilihan[];
};
export type PropsDaftarKelompokPilihan = {
    KelompokPilihan: (FormKelompokPilihan & {
        Uuid: string;
        Wajib: boolean;
        JumlahProduk: number;
        Pilihan: (FormPilihan & { NamaProdukBahan: string | null; SimbolSatuanBahan: string | null })[];
    })[];
    Izin: IzinKatalog;
};
export type PropsBuatKelompokPilihan = Pick<PropsDaftarKelompokPilihan, 'Izin'>;
export type PropsPilihanProduk = {
    Kepala: KepalaProduk;
    Terpasang: { Uuid: string; Nama: string; Ringkasan: string }[];
    Tersedia: { Uuid: string; Nama: string; Ringkasan: string }[];
    DariInduk: boolean;
    Izin: IzinKatalog;
};
export type BahanResep = {
    UuidProdukBahan: string;
    NamaBahan: string;
    Sku: string | null;
    Jumlah: string;
    UuidSatuan: string;
    SimbolSatuan: string;
    JumlahDasar: string;
    SimbolSatuanDasar: string;
    PersenSusut: string;
};
export type PropsResepProduk = {
    Kepala: KepalaProduk;
    Resep: {
        Versi: number;
        JumlahHasil: string;
        SimbolSatuanHasil: string;
        Catatan: string | null;
        DibuatPada: string;
        NamaPembuat: string | null;
        Bahan: BahanResep[];
    } | null;
    VersiTerbaru: number | null;
    DaftarVersi: { Versi: number; DibuatPada: string; NamaPembuat: string | null }[];
    Hpp: {
        Status: 'Tersedia' | 'BelumTersedia' | 'TanpaResep';
        HppSatuan: string | null;
        Baris: { NamaBahan: string; JumlahKotor: string; HppSatuanBahan: string | null; Subtotal: string | null }[];
    };
    Izin: IzinKatalog;
};
export type PropsKomponenProduk = {
    Kepala: KepalaProduk;
    Komponen: {
        UuidProdukKomponen: string;
        Nama: string;
        Sku: string | null;
        Jumlah: string;
        SimbolSatuan: string;
        /** "" = otomatis. */
        AlokasiHarga: string;
    }[];
    Izin: IzinKatalog;
};

// E.10 Halaman Tim 4.
export type StatusImporProduk =
    'Diunggah' | 'MenungguPemetaan' | 'Memvalidasi' | 'Pratinjau' | 'Menerapkan' | 'Selesai' | 'Gagal' | 'Dibatalkan';
/** Kunci dari daftar Backend (DesainF03 C.6). */
export type BidangImpor = string;
export type OpsiImpor = {
    Mode: 'TambahSaja' | 'TambahDanPerbarui';
    UuidKelompokPajakBawaan: string | null;
    JenisBawaan: JenisProduk;
    BuatKategoriBaru: boolean;
    BuatSatuanBaru: boolean;
};
export type RingkasanImpor = {
    Uuid: string;
    NamaBerkas: string;
    Sumber: string;
    LabelSumber: string;
    Status: StatusImporProduk;
    LabelStatus: string;
    JumlahBaris: number;
    JumlahValid: number;
    JumlahGalat: number;
    JumlahDiterapkan: number;
    JumlahDibuat: number;
    JumlahDiperbarui: number;
    JumlahDilewati: number;
    JumlahGagal: number;
    Progres: number;
    PesanGalat: string | null;
    /** Tanggal ISO. */
    BerkasPernahDiimpor: string | null;
    BolehLanjutkan: boolean;
    DibuatPada: string;
    SelesaiPada: string | null;
    NamaPengguna: string | null;
};
export type PropsDaftarImpor = {
    Riwayat: HasilTabel<RingkasanImpor>;
    OpsiStatus: { Nilai: StatusImporProduk; Label: string }[];
    Preset: { Kode: string; Nama: string; Keterangan: string; Asumsi: boolean }[];
    BatasBerkas: { UkuranMaksimalKb: number; MaksimalBaris: number; Ekstensi: string[] };
    BatasSku: Batas;
    Izin: IzinKatalog;
};
export type PropsDetailImpor = {
    Impor: RingkasanImpor;
    Pemetaan: {
        KolomSumber: { Indeks: number; Judul: string; Contoh: string[] }[];
        Bidang: { Kunci: BidangImpor; Label: string; Wajib: boolean; Harga: boolean; Keterangan: string }[];
        Pemetaan: Record<BidangImpor, number | null>;
        Opsi: OpsiImpor;
    } | null;
    Pratinjau: {
        BarisGalat: { NomorBaris: number; Galat: { Bidang: string; Pesan: string }[]; Data: Record<string, string> }[];
        RingkasanAksi: { Buat: number; Perbarui: number; Lewati: number };
        Peringatan: string[];
        DiblokirBatasSku: string | null;
    } | null;
    KelompokPajak: OpsiKelompokPajak[];
    Jenis: AturanJenisProduk[];
    Izin: IzinKatalog;
};

// D.2 Respons JSON sesi (dibaca lewat TanStack Query; cermin kontrak Backend, bukan props halaman).
/** GET /kelola/produk/cari?kata=&jenis[]=&batas= */
export type HasilCariProduk = {
    Data: {
        Uuid: string;
        Nama: string;
        Sku: string | null;
        Jenis: JenisProduk;
        UuidSatuanDasar: string;
        Satuan: { Uuid: string; Nama: string; Simbol: string; BolehDesimal: boolean; KonversiKeDasar: string }[];
    }[];
};
/** GET /kelola/produk/impor/{uuid}/status */
export type StatusImpor = {
    Status: StatusImporProduk;
    LabelStatus: string;
    Progres: number;
    JumlahDiterapkan: number;
    JumlahGagal: number;
    PesanGalat: string | null;
};

/** F-16d bagian 2: definisi paket sesi (produk Jasa yang dijual sebagai N sesi). */
export type BarisPaketSesi = {
    Uuid: string;
    Nama: string;
    JumlahSesi: number;
    MasaBerlakuHari: number | null;
    SemuaProdukJasa: boolean;
    JumlahProdukBerlaku: number;
    Aktif: boolean;
};

export type PropsDaftarPaketSesi = {
    PaketSesi: HasilTabel<BarisPaketSesi>;
    Izin: IzinKatalog;
    FiturAktif: boolean;
};

export type IsianPaketSesi = {
    Uuid: string;
    UuidProduk: string;
    NamaProduk: string;
    JumlahSesi: number;
    MasaBerlakuHari: number | null;
    SemuaProdukJasa: boolean;
    ProdukBerlaku: string[];
    Aktif: boolean;
};

export type PropsFormulirPaketSesi = {
    Paket: IsianPaketSesi | null;
    PilihanJasa: { Nilai: string; Label: string; SudahPaket: boolean }[];
    FiturAktif: boolean;
};

/** PRD-10: satu baris cetak label = satu satuan produk (barcode terikat ke satuan). */
export type BarisLabelServer = {
    /** Uuid `ProdukSatuan`. */
    Uuid: string;
    UuidProduk: string;
    Nama: string;
    Sku: string | null;
    NamaSatuan: string;
    SimbolSatuan: string;
    KonversiKeDasar: string;
    Barcode: string[];
    /** Harga dasar satuan ini; null bila belum ada. */
    Harga: string | null;
    Jumlah: number;
};

export type PropsLabelProduk = {
    Baris: BarisLabelServer[];
    /** True bila daftar dipotong karena melebihi batas baris. */
    Terpotong: boolean;
    Batas: { Baris: number; JumlahPerBaris: number; Total: number };
    Izin: { Kelola: boolean };
};

/** GET /kelola/produk/label/cari?kata= */
export type HasilCariLabel = { Data: BarisLabelServer[] };

/** POST /kelola/produk/label/barcode-internal */
export type HasilBarcodeLabel = { Data: { Uuid: string; Barcode: string; Baru: boolean }[] };
