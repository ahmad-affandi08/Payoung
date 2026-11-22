import type { HasilTabel } from '@/Komponen/TabelData/Tipe';
import { RingkasDesimal } from '@/Pustaka/Format';

/** Laundry (§9.9): tipe bersama back-office & halaman lacak publik. */

export type ItemLaundry = { Nama: string; Jumlah: number };

export type StatusLaundryPublik = {
    Status: string;
    LabelStatus: string;
    Tahap: { Status: string; Label: string; Selesai: boolean }[];
    JenisLayanan: string;
    Berat: string | null;
    Item: ItemLaundry[];
    Parfum: string | null;
    EstimasiSelesaiPada: string;
    SiapPada: string | null;
    DiambilPada: string | null;
};

export type TiketLaundry = {
    Uuid: string;
    Nomor: string;
    DibuatPada: string | null;
    EstimasiSelesaiPada: string;
    SiapPada: string | null;
    DiambilPada: string | null;
    NamaPelanggan: string;
    NoHp: string | null;
    JenisLayanan: string;
    Berat: string | null;
    Item: ItemLaundry[];
    Parfum: string | null;
    Catatan: string | null;
    Outlet: { Uuid: string | null; Nama: string };
    Status: string;
    LabelStatus: string;
    LewatEstimasi: boolean;
    TerlambatDiambil: boolean;
    NotifikasiTerkirim: boolean;
    StatusBerikutnya: string[];
};

export type PengaturanLaundry = {
    Aktif: boolean;
    JamReguler: number;
    JamExpress: number;
    Parfum: string[];
    NotifikasiSiap: boolean;
    HariBelumDiambil: number;
};

/** Ringkasan isi cucian: "3,5 kg | Bed cover ×1". */
export function RingkasIsiLaundry(t: { Berat: string | null; Item: ItemLaundry[] }): string {
    const bagian: string[] = [];
    if (t.Berat) {
        bagian.push(`${RingkasDesimal(t.Berat).replace('.', ',')} kg`);
    }
    for (const i of t.Item) {
        bagian.push(`${i.Nama} ×${i.Jumlah}`);
    }
    return bagian.length > 0 ? bagian.join(' | ') : '-';
}

export type PropsDaftarLaundry = {
    Tiket: HasilTabel<TiketLaundry>;
    OpsiStatus: { Nilai: string; Label: string }[];
    OpsiOutlet: { Uuid: string; Nama: string }[];
    Pengaturan: PengaturanLaundry;
    ZonaWaktu: string;
    Izin: { Pengaturan: boolean };
};
