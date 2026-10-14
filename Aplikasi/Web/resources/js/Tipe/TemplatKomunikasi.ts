export type SelTemplat = {
    Kanal: 'Email' | 'WhatsApp' | 'Notifikasi';
    KanalLabel: string;
    Bahasa: 'id' | 'en';
    BahasaLabel: string;
    Sumber: 'Templat' | 'Bawaan';
    VersiTerbit: number | null;
    AdaDraf: boolean;
    StatusBsp: 'MenungguPersetujuan' | 'Disetujui' | 'Ditolak' | null;
};

export type PeristiwaTemplat = { Kode: string; Label: string; Keterangan: string; Sel: SelTemplat[] };

export type VariabelTemplat = { Nama: string; Keterangan: string; Contoh: string; Wajib: boolean };

export type VersiTemplat = {
    Uuid: string;
    Versi: number;
    Status: 'Draf' | 'Terbit';
    Subjek: string | null;
    Judul: string | null;
    Isi: string;
    StatusBsp: 'MenungguPersetujuan' | 'Disetujui' | 'Ditolak' | null;
    CatatanBsp: string | null;
    DiterbitkanPada: string | null;
    DiubahPada: string | null;
};

export type EditorTemplat = {
    Peristiwa: { Kode: string; Label: string; Keterangan: string };
    Kanal: { Nilai: 'Email' | 'WhatsApp' | 'Notifikasi'; Label: string };
    Bahasa: { Nilai: 'id' | 'en'; Label: string };
    Variabel: VariabelTemplat[];
    Bawaan: { Subjek: string | null; Judul: string | null; Isi: string };
    Terbit: VersiTemplat | null;
    Draf: VersiTemplat | null;
    Riwayat: VersiTemplat[];
    Batas: { Subjek: number; Judul: number; Isi: number };
};

export const labelStatusBsp = {
    MenungguPersetujuan: 'Menunggu persetujuan',
    Disetujui: 'Disetujui',
    Ditolak: 'Ditolak',
} as const;
