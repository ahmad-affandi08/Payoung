/** Bidang pengaturan penyedia gerbang (tidak rahasia). */
export type BidangPengaturanGerbang = {
    Kunci: string;
    Label: string;
    Jenis: 'Teks' | 'Angka' | 'Email' | 'Url' | 'Pilihan';
    Wajib: boolean;
    Opsi?: string[];
    Bawaan?: string | number;
    Keterangan?: string;
};

/** Bidang kredensial penyedia gerbang (terenkripsi, tidak pernah ditampilkan ulang). */
export type BidangKredensialGerbang = { Kunci: string; Label: string; Wajib: boolean };

export type OpsiPenyediaGerbang = {
    Nilai: string;
    Label: string;
    Keterangan: string;
    BidangPengaturan: BidangPengaturanGerbang[];
    BidangKredensial: BidangKredensialGerbang[];
};

/** Gerbang pembayaran QRIS dinamis milik tenant (F-08, v2.06). Kredensial hanya petunjuk 4 karakter terakhir. */
export type GerbangPembayaranTenant = {
    Uuid: string;
    Penyedia: string;
    LabelPenyedia: string;
    PenyediaDiizinkan: boolean;
    Lingkungan: string;
    Pengaturan: Record<string, string | number>;
    PetunjukKredensial: Record<string, string>;
    StatusUji: 'BelumDiuji' | 'Berhasil' | 'Gagal';
    LabelStatusUji: string;
    PesanUji: string | null;
    DiujiPada: string | null;
    Aktif: boolean;
    UrlWebhook: string;
    /** D-314: kunci publik RSA SNAP untuk didaftarkan di dasbor DOKU, dan alamat token B2B. */
    KunciPublikSnap: string | null;
    UrlToken: string;
    WebhookDiterimaPada: string | null;
    WebhookDitolakPada: string | null;
};

export type PropsGerbangPembayaran = {
    /** Aktivasi QRIS otomatis (DOKU Partner) hanya ada di edisi SaaS. */
    AktivasiQrisTersedia?: boolean;
    Gerbang: GerbangPembayaranTenant | null;
    DaftarPenyedia: OpsiPenyediaGerbang[];
    DaftarLingkungan: { Nilai: string; Label: string }[];
};
