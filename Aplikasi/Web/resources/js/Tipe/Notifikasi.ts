/** F31 (PLT-08): pusat notifikasi back-office web. */
export type JenisNotifikasi =
    | 'Persetujuan'
    | 'SelisihKas'
    | 'StokKritis'
    | 'PerangkatOffline'
    | 'PiutangJatuhTempo'
    | 'StokKedaluwarsa'
    | 'TransaksiBesar'
    | 'PesananOnline'
    | 'PesanPlatform';

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
    /** PLT-08: jenis yang tetap tampil di web tetapi tidak dikirim sebagai push. */
    TanpaPush: JenisNotifikasi[];
    /** PLT-08: Uuid outlet yang kejadiannya tidak dikabarkan kepada pengguna ini. */
    OutletDibisukan: string[];
    /** Outlet yang boleh diakses pengguna (pengaturan outlet tampil bila lebih dari satu). */
    Outlet: { Uuid: string; Nama: string }[];
    Jenis: { Nilai: JenisNotifikasi; Label: string; BisaDibisukan: boolean }[];
    Saring: { Tampil: 'semua' | 'belum-dibaca' };
};
