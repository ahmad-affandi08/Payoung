import type { HasilTabel } from '@/Komponen/TabelData/Tipe';

/** D-124 Pusat Unduhan: kontrak props & JSON halaman `/kelola/unduhan`. Waktu ISO-8601 UTC. */
export type StatusBerkasUnduhan = 'Menunggu' | 'Diproses' | 'Siap' | 'Gagal' | 'Kedaluwarsa';

export type BarisUnduhan = {
    Uuid: string;
    Judul: string;
    NamaBerkas: string | null;
    Format: 'xlsx' | 'csv';
    Status: StatusBerkasUnduhan;
    LabelStatus: string;
    UkuranByte: number;
    DibuatPada: string | null;
    SelesaiPada: string | null;
    KedaluwarsaPada: string | null;
    DiunduhPada: string | null;
    Galat: string | null;
    BisaDiunduh: boolean;
};

export type PropsDaftarUnduhan = {
    Unduhan: HasilTabel<BarisUnduhan>;
    OpsiStatus: { Nilai: string; Label: string }[];
    HariBerlaku: number;
};
