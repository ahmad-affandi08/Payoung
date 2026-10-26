/** Bentuk data laporan Google Analytics 4 dari `GET /analitik/situs/data` (D-215). Angka dikirim apa adanya sebagai string. */
export type JenisKolomGa = 'Teks' | 'Bilangan' | 'Persen' | 'Durasi' | 'Desimal';

export type KolomGa = { Kunci: string; Label: string; Jenis: JenisKolomGa };

export type BarisGa = Record<string, string>;

export type TabelGa = { Id: string; Judul: string; Kolom: KolomGa[]; Baris: BarisGa[] };

export type KartuGa = {
    Kunci: string;
    Label: string;
    Jenis: JenisKolomGa;
    Nilai: string;
    /** Nilai periode sebelumnya; null untuk laporan tanpa pembanding (real-time). */
    Pembanding: string | null;
};

export type RentangGa = { Dari: string; Sampai: string; Hari: number };

export type LaporanGa = {
    Laporan: string;
    Kartu: KartuGa[];
    Tabel: TabelGa[];
    Rentang: RentangGa;
    Pembanding: RentangGa;
};

export type GalatGa = { Galat: string };

export type PropsAnalitikSitus = {
    Status: { Aktif: boolean; IdProperti: string | null };
    Rentang: RentangGa;
    MaksHari: number;
};
