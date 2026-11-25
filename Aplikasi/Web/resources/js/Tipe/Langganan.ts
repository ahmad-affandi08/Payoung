/** Halaman Langganan tenant (P-08, D-332). Bentuk = `LanggananKontroler::Tampilkan` dan `TagihanLanggananTenant`. */
export type StatusLangganan = 'Trial' | 'Aktif' | 'Tertunggak' | 'Ditangguhkan' | 'Berhenti' | 'Gratis';

export type RingkasanLangganan = {
    Status: StatusLangganan;
    LabelStatus: string;
    KodePaket: string;
    NamaPaket: string;
    SiklusTagihan: 'Bulanan' | 'Tahunan' | 'Kustom';
    /** D-249: lama langganan berjalan dalam bulan. */
    JumlahBulanSiklus: number;
    TrialBerakhirPada: string | null;
    PeriodeMulai: string | null;
    PeriodeSelesai: string | null;
    BatasTenggangPada: string | null;
};

/** Batas paket dan pemakaiannya. `Batas` null = tanpa batas. */
export type BatasPemakaian = { Batas: number | null; Terpakai: number };

export type KapasitasLangganan = { Outlet: BatasPemakaian; Pengguna: BatasPemakaian };

export const jenisStatusLangganan = {
    Trial: 'peringatan',
    Aktif: 'sukses',
    Tertunggak: 'bahaya',
    Ditangguhkan: 'bahaya',
    Berhenti: 'netral',
    Gratis: 'netral',
} as const;
