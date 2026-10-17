import type { HasilTabel } from '@/Komponen/TabelData/Tipe';
import type { PenjualanShift } from '@/Tipe/Penjualan';

/** F-06 shift & kas (back-office). Uang = string desimal dari server; tidak pernah number/float. */

export type StatusShift = 'Terbuka' | 'Menutup' | 'Tertutup' | 'DibukaUlang';
export type JenisMutasiKas = 'Masuk' | 'Keluar' | 'Setoran';
export type JenisKategoriKas = 'Masuk' | 'Keluar';

export type RingkasanKasShift = {
    TotalMasuk: string;
    TotalKeluar: string;
    TotalSetoran: string;
    /** Kas awal + masuk − keluar − setoran; penjualan tunai ditambahkan F-07. */
    KasNonPenjualan: string;
};

export type BarisShift = RingkasanKasShift & {
    Uuid: string;
    NamaOutlet: string;
    Perangkat: string;
    NamaKasir: string;
    DibukaPada: string;
    TanggalBisnis: string;
    Status: StatusShift;
    LabelStatus: string;
    Bersama: boolean;
    PerluTinjauan: boolean;
    KasAwal: string;
    /** F-11: hasil tutup shift; null selama shift belum ditutup. */
    DitutupPada: string | null;
    KasAktual: string | null;
    Selisih: string | null;
};

export type PropsDaftarShift = {
    Shift: HasilTabel<BarisShift>;
    OpsiOutlet: { Uuid: string; Nama: string }[];
    OpsiStatus: { Nilai: StatusShift; Label: string }[];
};

export type PecahanKas = { Nominal: string; Jumlah: number };

export type BarisMutasiKas = {
    Uuid: string;
    Jenis: JenisMutasiKas;
    LabelJenis: string;
    NamaKategori: string | null;
    Jumlah: string;
    Catatan: string | null;
    /** K-18: ada foto bukti dari kasir (`/kelola/kasir/mutasi-kas/{uuid}/bukti`). */
    AdaBukti: boolean;
    DicatatOleh: string;
    DicatatPada: string;
    DisetujuiOleh: string | null;
    NomorJurnal: string | null;
    UuidJurnal: string | null;
    /** F-11: kas yang tiba setelah shift ditutup. */
    PerluTinjauan: boolean;
    AlasanTinjauan: string | null;
};

/** K-18: buka ulang shift oleh supervisor beserta hasil tutup yang dibatalkan. */
export type BarisBukaUlangShift = {
    Uuid: string;
    Urutan: number;
    Alasan: string;
    DimintaOleh: string;
    DisetujuiOleh: string;
    DibukaUlangPada: string;
    DitutupOlehSebelumnya: string | null;
    DitutupPadaSebelumnya: string | null;
    KasAktualSebelumnya: string | null;
    SelisihSebelumnya: string | null;
};

/** Cetak struk bagian 4: buka laci manual tanpa transaksi (§19.2 selalu dicatat). */
export type BarisBukaLaci = {
    Uuid: string;
    Alasan: string;
    DibukaOleh: string;
    DisetujuiOleh: string | null;
    DibukaPada: string;
    PerluTinjauan: boolean;
    AlasanTinjauan: string | null;
};

/** F-11: total satu metode pembayaran di laporan shift (tunai = diterima − kembalian). */
export type MetodeLaporanShift = { UuidMetodePembayaran: string; Jenis: string; Nama: string; Jumlah: string };

/** F-11: laporan shift X (berjalan) / Z (setelah tutup) dari data server. */
export type LaporanShift = {
    Penjualan: {
        JumlahTransaksi: number;
        PenjualanKotor: string;
        TotalDiskon: string;
        PenjualanBersih: string;
        TotalPajak: string;
        BiayaLayanan: string;
        Pembulatan: string;
        TotalAkhir: string;
        PerMetode: MetodeLaporanShift[];
        TunaiMasukBersih: string;
        RefundTunai: string;
        JumlahVoid: number;
        NominalVoid: string;
        JumlahRetur: number;
        NominalRetur: string;
    };
    Kas: {
        KasAwal: string;
        TunaiMasukBersih: string;
        TotalMasuk: string;
        TotalKeluar: string;
        TotalSetoran: string;
        RefundTunai: string;
        KasSeharusnya: string;
    };
};

/** F-11: non-tunai per metode menurut sistem dan menurut hitungan kasir (null = tidak diisi). */
export type NonTunaiTutupShift = {
    UuidMetodePembayaran: string;
    Jenis: string;
    Nama: string;
    JumlahSistem: string;
    JumlahDilaporkan: string | null;
};

export type TutupShift = {
    DitutupOleh: string;
    DitutupPada: string;
    KasSeharusnya: string;
    KasAktual: string;
    Selisih: string;
    AlasanSelisih: string | null;
    Penyetuju: string | null;
    PecahanKasAkhir: PecahanKas[];
    NonTunai: NonTunaiTutupShift[];
    NomorJurnal: string | null;
    UuidJurnal: string | null;
};

export type PropsDetailShift = {
    Shift: Omit<BarisShift, 'Uuid'> & {
        Uuid: string;
        DiterimaPada: string;
        AlasanTinjauan: string | null;
        PecahanKasAwal: PecahanKas[];
    };
    MutasiKas: BarisMutasiKas[];
    BukaLaci: BarisBukaLaci[];
    BukaUlang: BarisBukaUlangShift[];
    /** F-07b: penjualan yang dibuat di shift ini. */
    Penjualan: PenjualanShift;
    Laporan: LaporanShift;
    /** F-11: null selama shift belum ditutup. */
    Tutup: TutupShift | null;
    Izin: { TutupPaksa: boolean };
};

export type OpsiAkun = { Uuid: string; Kode: string; Nama: string; Jenis: string };

export type BarisKategoriKas = {
    Uuid: string;
    Nama: string;
    Jenis: JenisKategoriKas;
    LabelJenis: string;
    UuidAkun: string | null;
    Akun: string | null;
    Aktif: boolean;
    Urutan: number;
};

export type PropsKategoriKas = {
    Kategori: BarisKategoriKas[];
    OpsiAkun: Record<JenisKategoriKas, OpsiAkun[]>;
};

export type PembulatanTunai = { Kelipatan: number; Arah: string };

export type PropsPengaturanKasir = {
    BatasKasKeluar: string;
    ShiftBersama: boolean;
    /** F-07b BR-07.3: persen string desimal ("10.00"). */
    BatasDiskonManual: string;
    BatasDiskonPenyetuju: string;
    /** F-07b BR-08.6: null = tanpa pembulatan tunai. */
    PembulatanTunai: PembulatanTunai | null;
    OpsiArahPembulatan: { Nilai: string; Label: string }[];
    /** F-11: kas seharusnya disembunyikan sampai kasir menyimpan hitungan. */
    TutupShiftButa: boolean;
    /** F-11: string desimal ("10000.00"); |selisih| di atasnya wajib alasan + PIN penyetuju. */
    ToleransiSelisihKas: string;
    /** F-09: retur paling lama sekian hari sejak hari bisnis penjualan (0–365, bawaan 7). */
    BatasHariRetur: number;
    /** F-12 BR-12.1: piutang lewat jatuh tempo lebih dari sekian hari = penjualan tempo butuh penyetuju (0–365). */
    BatasHariLewatJatuhTempo: number;
    /** Cetak struk bagian 4: buka laci manual wajib PIN penyetuju `kas.keluar.setujui` (bawaan mati). */
    BukaLaciPerluPin: boolean;
    /** K28: string desimal; Σ retur tanpa struk outlet per hari di atasnya menjadi tinjauan (bawaan Rp 1.000.000). */
    BatasReturTanpaStrukHarian: string;
    /** OWN-03: string desimal; void/retur/diskon bernilai sama atau di atasnya dikabarkan ke pengguna berizin (bawaan Rp 500.000). */
    BatasNotifikasiTransaksi: string;
    /** v3.55 (§9.3): barcode timbangan EAN-13 berawalan 21–29, nilai berat (gram) atau harga (Rupiah). */
    BarcodeTimbangan: BarcodeTimbangan;
};

export type BarcodeTimbangan = { Aktif: boolean; Awalan: string[]; Nilai: 'Berat' | 'Harga' };

/** Pengaturan struk tenant (PLT-06, PRD v1.79): satu untuk semua outlet. Teks null = bawaan aplikasi. */
export type PengaturanStruk = {
    TampilkanLogo: boolean;
    NamaDicetak: string | null;
    TeksKepala: string[];
    TampilkanAlamat: boolean;
    TampilkanTelepon: boolean;
    TampilkanNpwp: boolean;
    TampilkanKasir: boolean;
    TampilkanPelanggan: boolean;
    TampilkanHemat: boolean;
    CatatanKaki: string | null;
    TeksPenutup: string | null;
    /** POS-11: QR & tautan struk digital `/s/{kodeStruk}` di bagian bawah struk. */
    TampilkanStrukDigital: boolean;
    /** F7: struk digital meminta penilaian 1–5 bintang dari pelanggan. */
    TampilkanUmpanBalik: boolean;
};

/** Data profil usaha untuk pratinjau struk (diubah di profil usaha, bukan di halaman ini). */
export type ProfilPratinjauStruk = {
    NamaUsaha: string;
    Npwp: string | null;
    NamaOutlet: string | null;
    /** D-177: alamat & telepon asli outlet yang sedang diatur (null = belum diisi, jadi tidak tercetak). */
    AlamatOutlet: string | null;
    TeleponOutlet: string | null;
    /** Uuid outlet yang sedang diatur, untuk tautan ke data outletnya. */
    UuidOutletProfil: string | null;
    TautanLogo: string | null;
    /** Paket tanpa fitur `struk.tanpa-watermark`: struk diberi baris "Dibuat dengan Payoung". */
    TandaAir: boolean;
};

export type PropsPengaturanStruk = {
    Pengaturan: PengaturanStruk;
    Profil: ProfilPratinjauStruk;
    /** D-76: daftar outlet (kosong bila tenant hanya punya satu outlet aktif) dan outlet yang sedang diatur. */
    Outlet?: { Uuid: string; Nama: string }[];
    UuidOutlet?: string | null;
    /** Outlet punya logo struk sendiri (bukan logo usaha). */
    LogoOutletKhusus?: boolean;
};

/** F-15 tutup harian: peringatan yang boleh diabaikan dengan konfirmasi. */
export type PeringatanTutupHarian = { Kode: string; Pesan: string };

/** Satu tanggal bisnis satu outlet di halaman Tutup harian. */
export type BarisTutupHarian = {
    Kunci: string;
    Outlet: string;
    NamaOutlet: string;
    TanggalBisnis: string;
    Berjalan: boolean;
    Ditutup: boolean;
    DitutupPada: string | null;
    DitutupOleh: string | null;
    /** D-23 D: ditutup sistem karena aman ditutup. Opsional agar kompatibel dengan data lama. */
    DitutupOtomatis?: boolean;
    JumlahTransaksi: number | null;
    PenjualanBersih: string | null;
    ShiftBelumDitutup: number;
    Peringatan: PeringatanTutupHarian[];
};

export type PropsTutupHarian = {
    Hari: BarisTutupHarian[];
    Izin: { Kelola: boolean };
};
