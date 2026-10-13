/** F31 (PLT-08): pusat notifikasi back-office web. */
export type JenisNotifikasi =
    'Persetujuan' | 'SelisihKas' | 'StokKritis' | 'PerangkatOffline' | 'PiutangJatuhTempo' | 'StokKedaluwarsa';

export type BarisNotifikasi = {
    Uuid: string;
    Jenis: JenisNotifikasi;
    LabelJenis: string;
    Judul: string;
    Isi: string;
    /** Halaman web tujuan di bawah `/kelola`; null = tidak ada (mis. Persetujuan, dikerjakan di Aplikasi Owner). */
    Tautan: string | null;
    DibacaPada: string | null;
    DibuatPada: string | null;
};

export type PropsNotifikasi = {
    Notifikasi: BarisNotifikasi[];
    BelumDibaca: number;
    /** Jenis yang dibisukan pengguna ini. */
    Dibisukan: JenisNotifikasi[];
    Jenis: { Nilai: JenisNotifikasi; Label: string; BisaDibisukan: boolean }[];
    Saring: { Tampil: 'semua' | 'belum-dibaca' };
};
