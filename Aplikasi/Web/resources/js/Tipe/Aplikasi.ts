/** Props bersama halaman tenant (BagikanDataInertia). */
export type PenggunaAplikasi = { Uuid: string; Nama: string; Email: string; EmailTerverifikasi: boolean };

/** BR-P06.5: versi materiil dokumen legal yang diumumkan dan belum berlaku (hanya untuk Owner). */
export type PengumumanLegal = { Label: string; Versi: number; BerlakuMulai: string; Tautan: string };

/** P-10 PGL-19: pengumuman platform yang berlaku untuk tenant aktif (waktu ISO UTC). */
export type PengumumanPlatform = {
    Uuid: string;
    Judul: string;
    Isi: string;
    Jenis: 'Info' | 'YangBaru' | 'Pemeliharaan' | 'Penting';
    LabelJenis: string;
    Tautan: string | null;
    BolehDitutup: boolean;
    PemeliharaanMulai: string | null;
    PemeliharaanSelesai: string | null;
    TampilSampai: string;
};

/** PGL-16: sesi akses dukungan yang sedang berjalan (null = pengguna biasa). */
export type AksesDukunganAktif = {
    NamaPetugas: string;
    Cakupan: 'BacaSaja' | 'BacaUbah';
    LabelCakupan: string;
    Darurat: boolean;
    DataPribadiTerbuka: boolean;
    BerakhirPada: string;
};

/** F-00 (BR-00.7): status `Langganan` tenant aktif, untuk banner Tertunggak/Ditangguhkan. */
export type StatusLanggananTenant = 'Trial' | 'Aktif' | 'Tertunggak' | 'Ditangguhkan' | 'Berhenti' | 'Gratis';

export type TagihanTertundaTenant = {
    Uuid: string;
    Nomor: string;
    Total: string;
    JatuhTempoPada: string;
};

export type TenantAktif = {
    Id?: number;
    KodePelanggan?: string;
    Nama: string;
    Slug?: string;
    TautanLogo?: string | null;
    NamaPaket?: string;
    KodePaket?: string;
    StatusLangganan: StatusLanggananTenant | null;
    PeriodeSelesai: string | null;
    /** Saat langganan Tertunggak akan ditangguhkan (akhir periode + masa tenggang). */
    BatasTenggangPada: string | null;
    TagihanTertunda?: TagihanTertundaTenant | null;
};

export type PropsBersamaAplikasi = {
    NamaAplikasi: string;
    /** D-35: `Lisensi` = dashboard dipasang pembeli sendiri (tanpa pendaftaran, langganan, tiket bantuan Payoung). */
    Edisi?: 'Saas' | 'Lisensi';
    /** D-20: alamat situs pemasaran (absolut bila domain pemasaran terpisah, selain itu `/`). */
    UrlPemasaran?: string;
    Kilat: string | null;
    Pengguna: PenggunaAplikasi | null;
    TenantAktif: TenantAktif | null;
    PengumumanLegal: PengumumanLegal[];
    /** D-38: 2FA wajib paket Bisnis ditunda selama trial; true = tampilkan banner pengingat. */
    PengingatDuaFaktor?: boolean;
    /** P-10 PGL-19: banner pengumuman & pemeliharaan platform (kosong/absen di luar back-office). */
    PengumumanPlatform?: PengumumanPlatform[];
    /** Audit #33: kode template sektor outlet tenant aktif (`FNB-RST`, `WHS-DST`, …); kosong = tidak diketahui. */
    SektorOutlet?: string[];
    /** PGL-16: ada hanya selama petugas Payoung masuk lewat akses dukungan. */
    AksesDukungan?: AksesDukunganAktif | null;
    /** F-02: hak akses di tenant aktif (null di luar back-office). */
    Akses: { Pemilik: boolean; Izin: string[]; IzinNonaktif?: string[] } | null;
    /** D-180: karyawan ini wajib absen, jadi menu "Absen" tampil. */
    AbsenSaya?: boolean;
    /** D-23: fitur di luar paket (menu tetap tampil; klik = dialog naik paket / add-on). */
    FiturPaket?: FiturPaket | null;
    /** F31 (PLT-08): jumlah notifikasi belum dibaca pengguna di tenant aktif (lencana lonceng); 0/absen di luar back-office. */
    NotifikasiBelumDibaca?: number;
    /** D-204: jumlah butir Kotak Tindakan yang perlu ditindak pengguna ini (lencana merah di menu samping); 0/absen di luar back-office. */
    TindakanPerluDicek?: number;
    /** PLT-12: kode tur produk yang sudah dilihat pengguna; hanya dibawa Beranda (penanda boleh menawarkan tur). */
    TurDilihat?: string[];
    errors: Record<string, string>;
};

/** D-23: penawaran untuk satu fitur yang terkunci. */
export type PenawaranFitur = {
    Nama: string;
    Paket: { Kode: string; Nama: string; HargaBulanan: string | null } | null;
    Addon: {
        Kode: string;
        Nama: string;
        HargaBulanan: string;
        /** D-49: bisa dibeli mandiri (langganan berbayar aktif); selain itu `AlasanTidakBisa` menjelaskan. */
        BisaDibeli: boolean;
        AlasanTidakBisa: string | null;
        HargaProrata: string | null;
    } | null;
};

export type FiturPaket = { NamaPaket: string | null; Terkunci: Record<string, PenawaranFitur> };

/** D-87: iklan harga di panel halaman masuk/daftar; berasal dari katalog (`PaketPublik::AmbilPenawaranTermurah`). */
export type PenawaranAuth = {
    NamaPaket: string;
    HargaBulanan: string;
    MasaTrialHari: number;
    Promo: {
        HargaBulananNormal: string;
        HargaTahunanNormal: string;
        BerlakuSampai: string;
        PersenDiskon: number;
        HargaTerkunci: boolean;
    } | null;
    /** Semua paket berbayar yang dijual, apa adanya dari katalog server (tanpa angka tulisan tangan). */
    Paket: { Nama: string; HargaBulanan: string; HargaNormal: string | null; Sorotan: string[] }[];
};
