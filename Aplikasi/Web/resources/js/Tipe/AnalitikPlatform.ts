import type { Pilihan } from '@/Tipe/Pengelola';

/** Props halaman analitik platform (P-07 PGL-24), sesuai `AnalitikKontroler` & `LaporanAnalitikPlatform` di Backend. */

export type TahapFunnel = {
    Kunci: string;
    Label: string;
    Jumlah: number;
    PersenDaftar: string;
    PersenSebelumnya: string | null;
    RataRataHari: string | null;
};

export type BarisFunnelSektor = {
    Kunci: string;
    Sektor: string;
    Daftar: number;
    Panduan: number;
    Transaksi: number;
    Bayar: number;
    PersenBayar: string;
};

export type FiturAdopsi = { Kunci: string; Label: string };

export type BarisAdopsi = {
    Kunci: string;
    Sektor: string;
    TenantAktif: number;
    Fitur: Record<string, { Jumlah: number; Persen: string }>;
};

export type BarisRetensi = {
    Kunci: string;
    Label: string;
    Ukuran: number;
    /** Indeks = bulan ke-n sejak pendaftaran; null = bulan itu belum tiba. */
    Retensi: ({ Persen: string; Aktif: number } | null)[];
};

export type PropsAnalitikPlatform = {
    Saring: { Dari: string; Sampai: string; Sektor: string };
    PilihanSektor: Pilihan[];
    Funnel: { Tahap: TahapFunnel[]; PerSektor: BarisFunnelSektor[] };
    Adopsi: { Fitur: FiturAdopsi[]; Baris: BarisAdopsi[] };
    Retensi: { Bulan: string[]; Baris: BarisRetensi[] };
    HariFitur: number;
};
