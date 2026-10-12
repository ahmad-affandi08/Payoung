/** Props bersama semua halaman Platform Pengelola (BagikanDataInertiaPengelola). */
export type PenggunaPengelola = {
    Uuid: string;
    Nama: string;
    Email: string;
    KodePeran: string[];
    Izin: string[];
};

export type LingkunganPengelola = 'Lokal' | 'Staging' | 'Produksi';

export type PropsBersamaPengelola = {
    NamaAplikasi: string;
    Lingkungan: LingkunganPengelola;
    Kilat: string | null;
    Pengguna: PenggunaPengelola | null;
    PeringatanSuperAdmin: boolean;
    PeringatanIntegrasi: string[];
    // P-11 BR-P11.1: masalah operasional saat ini (scheduler, antrean, backup).
    PeringatanOperasional: string[];
    errors: Record<string, string>;
};

/** Kunci izin (enum IzinPengelola di Backend, format D-06). */
export const IzinPengelola = {
    TimAnggotaLihat: 'tim.anggota.lihat',
    TimAnggotaUndang: 'tim.anggota.undang',
    TimAnggotaNonaktifkan: 'tim.anggota.nonaktifkan',
    TimPeranTetapkan: 'tim.peran.tetapkan',
    AuditLihat: 'audit.lihat',
    ReferensiLihat: 'referensi.lihat',
    ReferensiWilayahKelola: 'referensi.wilayah.kelola',
    ReferensiBankKelola: 'referensi.bank.kelola',
    ReferensiSatuanKelola: 'referensi.satuan.kelola',
    ReferensiTarifPajakAjukan: 'referensi.tarif-pajak.ajukan',
    ReferensiTarifPajakSetujui: 'referensi.tarif-pajak.setujui',
    ReferensiHariLiburAjukan: 'referensi.hari-libur.ajukan',
    ReferensiHariLiburSetujui: 'referensi.hari-libur.setujui',
    KatalogLihat: 'katalog.lihat',
    KatalogFiturKelola: 'katalog.fitur.kelola',
    KatalogPaketAjukan: 'katalog.paket.ajukan',
    KatalogPaketSetujui: 'katalog.paket.setujui',
    KatalogAddonKelola: 'katalog.addon.kelola',
    KatalogKuponKelola: 'katalog.kupon.kelola',
    TemplateLihat: 'template.lihat',
    TemplateDrafKelola: 'template.draf.kelola',
    TemplateIsiUbah: 'template.isi.ubah',
    TemplateAkunUbah: 'template.akun.ubah',
    TemplateTerbitkan: 'template.terbitkan',
    IntegrasiLihat: 'integrasi.lihat',
    IntegrasiKelola: 'integrasi.kelola',
    LegalLihat: 'legal.lihat',
    SitusLihat: 'situs.lihat',
    SitusKelola: 'situs.kelola',
    LegalKelola: 'legal.kelola',
    // P-07 Siklus hidup tenant.
    TenantLihat: 'tenant.lihat',
    TenantCatatanTulis: 'tenant.catatan.tulis',
    TenantTrialPerpanjang: 'tenant.trial.perpanjang',
    TenantOverrideKelola: 'tenant.override.kelola',
    TenantTangguhkan: 'tenant.tangguhkan',
    TenantAktifkan: 'tenant.aktifkan',
    TenantPenandaUbah: 'tenant.penanda.ubah',
    TenantPenghapusanDataKelola: 'tenant.penghapusan-data.kelola',
    // P-08 Tagihan langganan.
    TagihanLihat: 'tagihan.lihat',
    TagihanVerifikasi: 'tagihan.verifikasi',
    TagihanTerbitkan: 'tagihan.terbitkan',
    TagihanRefund: 'tagihan.refund',
    TagihanRefundSetujui: 'tagihan.refund.setujui',
    TagihanPengaturan: 'tagihan.pengaturan',
    // P-09
    DukunganTiketLihat: 'dukungan.tiket.lihat',
    DukunganTiketTangani: 'dukungan.tiket.tangani',
    DukunganAksesMasuk: 'dukungan.akses.masuk',
    DukunganAksesDarurat: 'dukungan.akses.darurat',
    // P-11
    OperasionalLihat: 'operasional.lihat',
    OperasionalKelola: 'operasional.kelola',
    // P-10
    RilisLihat: 'rilis.lihat',
    RilisKelola: 'rilis.kelola',
    FlagFiturKelola: 'flag-fitur.kelola',
    // P-12
    MitraLihat: 'mitra.lihat',
    MitraKelola: 'mitra.kelola',
    MitraPencairan: 'mitra.pencairan',
} as const;

export type Pilihan = { Nilai: string; Label: string };

export type KunciIzinPengelola = (typeof IzinPengelola)[keyof typeof IzinPengelola];

export function PunyaIzin(pengguna: PenggunaPengelola | null, izin: KunciIzinPengelola): boolean {
    return pengguna?.Izin.includes(izin) ?? false;
}

/** D-34: Super Admin tidak terikat tinjau-meninjau (boleh meninjau ajuan sendiri; ajuannya langsung terbit). */
export function CekBebasTinjauan(pengguna: PenggunaPengelola | null): boolean {
    return pengguna?.KodePeran.includes('SuperAdmin') ?? false;
}

/** P-10: satu rilis aplikasi di halaman Rilis aplikasi. */
export type RilisAplikasi = {
    Uuid: string;
    Aplikasi: 'Pos' | 'Pemilik';
    LabelAplikasi: string;
    Platform: 'Android' | 'Ios' | 'Windows';
    Kanal: 'Beta' | 'Stabil';
    Versi: string;
    Build: number | null;
    Status: 'Draf' | 'Aktif' | 'Dihentikan';
    PersenRollout: number;
    UrlUnduh: string | null;
    CatatanRilis: string | null;
    VersiMinimum: string | null;
    VersiMinimumBerlakuPada: string | null;
    PerbaikanKeamanan: boolean;
    DiterbitkanPada: string | null;
    DihentikanPada: string | null;
    AlasanDihentikan: string | null;
};

/** BR-P10.2: perangkat di bawah versi yang akan menjadi minimum. */
export type DampakVersiMinimum = {
    PerangkatDiBawah: number;
    PerangkatDiBawahDenganOutbox: number;
    OutboxTertunda: number;
};

/** P-10: satu aturan flag fitur. */
export type AturanFlagFitur = {
    Uuid: string;
    Kunci: string;
    Cakupan: 'Global' | 'Paket' | 'Tenant' | 'Persentase';
    Objek: string | null;
    Nilai: boolean;
    Persen: number | null;
    Alasan: string;
    DiubahOleh: string | null;
    DiubahPada: string | null;
};

/** P-10 PGL-19 pengumuman platform (waktu ISO UTC). */
export type JenisPengumumanPlatform = 'Info' | 'YangBaru' | 'Pemeliharaan' | 'Penting';
export type SasaranPengumuman = {
    KodePaket: string[];
    Sektor: string[];
    Platform: string[];
    VersiMinimal: string | null;
    VersiMaksimal: string | null;
};
export type BarisPengumumanPlatform = {
    Uuid: string;
    Judul: string;
    Isi: string;
    Jenis: JenisPengumumanPlatform;
    LabelJenis: string;
    Sasaran: SasaranPengumuman;
    Tautan: string | null;
    TampilMulai: string;
    TampilSampai: string;
    PemeliharaanMulai: string | null;
    PemeliharaanSelesai: string | null;
    Status: 'Draf' | 'Terbit' | 'Dicabut';
    LabelStatus: string;
    DiterbitkanPada: string | null;
    DiterbitkanOleh: string | null;
    AlasanCabut: string | null;
};
export type PropsPengumumanPlatform = {
    Pengumuman: BarisPengumumanPlatform[];
    OpsiJenis: Pilihan[];
    OpsiPaket: Pilihan[];
    OpsiSektor: Pilihan[];
    OpsiPlatform: Pilihan[];
};

export type PropsFlagFitur = {
    Aturan: AturanFlagFitur[];
    OpsiKunci: Pilihan[];
    OpsiPaket: Pilihan[];
    OpsiTenant: Pilihan[];
};
