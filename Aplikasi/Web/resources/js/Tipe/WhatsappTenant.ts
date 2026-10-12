/** D-108: Pengaturan › WhatsApp (kuota nomor Payoung dan WhatsApp milik tenant). */

export type KuotaWhatsapp = {
    /** Bulan kalender WIB, `YYYY-MM`. */
    Periode: string;
    Terpakai: number;
    /** Null = tak terbatas. */
    Batas: number | null;
    /** Null = tak terbatas. */
    Sisa: number | null;
};

export type BidangWhatsapp = {
    Kunci: string;
    Label: string;
    Jenis?: string;
    Wajib: boolean;
    Opsi?: string[];
    Bawaan?: string | number;
    Keterangan?: string;
};

export type OpsiPenyediaWhatsapp = {
    Nilai: string;
    Label: string;
    Keterangan: string;
    BidangPengaturan: BidangWhatsapp[];
    BidangKredensial: BidangWhatsapp[];
};

export type WhatsappMilikTenant = {
    Uuid: string;
    Penyedia: string;
    LabelPenyedia: string;
    Pengaturan: Record<string, string | number>;
    PetunjukKredensial: Record<string, string>;
    StatusUji: 'BelumDiuji' | 'Berhasil' | 'Gagal';
    LabelStatusUji: string;
    PesanUji: string | null;
    DiujiPada: string | null;
    Aktif: boolean;
};

export type PropsWhatsappTenant = {
    Kuota: KuotaWhatsapp;
    NomorPayoungTersedia: boolean;
    Milik: WhatsappMilikTenant | null;
    DaftarPenyedia: OpsiPenyediaWhatsapp[];
};
