/** Bentuk data kampanye email prospek dari server (D-216). */
export type StatusKampanyeProspek = 'Draf' | 'Berjalan' | 'Dijeda' | 'Selesai' | 'Dibatalkan';

export type BarisKampanyeProspek = {
    Uuid: string;
    Nama: string;
    Subjek: string;
    Status: StatusKampanyeProspek;
    LabelStatus: string;
    AlasanJeda: string | null;
    BatasPerHari: number;
    Sektor: string[];
    MulaiKirimPada: string | null;
    SelesaiPada: string | null;
    JumlahPenerima: number;
    JumlahTerkirim: number;
    JumlahGagal: number;
    JumlahDilewati: number;
    DapatDijalankan: boolean;
    DapatDijeda: boolean;
    DapatDibatalkan: boolean;
};

export type RingkasKontakProspek = { Aktif: number; Berhenti: number; Total: number };

export type PilihanSektorProspek = { Nilai: string; Label: string; Aktif: number; Total: number };

export type BarisKontakProspek = {
    Uuid: string;
    Email: string;
    Sektor: string;
    LabelSektor: string;
    Sumber: string;
    Status: 'Aktif' | 'Berhenti' | 'Pentalan';
    LabelStatus: string;
    BerhentiPada: string | null;
    DibuatPada: string;
};

export type PenerimaKampanyeProspekTampil = {
    Id: number;
    Email: string;
    Sektor: string;
    Status: 'Menunggu' | 'Diproses' | 'Terkirim' | 'Gagal' | 'Dilewati';
    Alasan: string | null;
    DikirimPada: string | null;
};
