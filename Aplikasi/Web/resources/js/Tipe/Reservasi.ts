import type { HasilTabel } from '@/Komponen/TabelData/Tipe';

/** F-07 mode service: reservasi layanan jasa. */
export type StatusReservasi = 'Menunggu' | 'Dikonfirmasi' | 'Hadir' | 'Selesai' | 'Batal' | 'TidakDatang';

export type BarisReservasi = {
    Uuid: string;
    Nomor: string;
    MulaiPada: string;
    SelesaiPada: string;
    NamaPelanggan: string;
    NoHp: string;
    Layanan: string;
    Staf: { Uuid: string; Nama: string } | null;
    Outlet: { Uuid: string | null; Nama: string };
    Status: StatusReservasi;
    LabelStatus: string;
    Sumber: 'BackOffice' | 'Online' | 'Pos';
    LabelSumber: string;
    Catatan: string | null;
    AlasanBatal: string | null;
    StatusBerikutnya: StatusReservasi[];
};

export type LayananReservasi = { Uuid: string; Nama: string; DurasiMenit: number; Harga: string | null };

export type SlotReservasi = { Jam: string; Staf: { Uuid: string; Nama: string }[] };

export type PengaturanReservasi = {
    OnlineAktif: boolean;
    KonfirmasiOtomatis: boolean;
    IntervalSlotMenit: number;
    JedaMenit: number;
    BatasHariKeDepan: number;
    MinimalMenitSebelum: number;
    PengingatAktif: boolean;
};

export type PropsDaftarReservasi = {
    Reservasi: HasilTabel<BarisReservasi>;
    OpsiStatus: { Nilai: StatusReservasi; Label: string }[];
    OpsiOutlet: { Uuid: string; Nama: string }[];
    OpsiStaf: { Uuid: string; Nama: string }[];
    OpsiLayanan: LayananReservasi[];
    Pengaturan: PengaturanReservasi;
    HariIni: string;
    TautanPublik: string;
    Izin: { Pengaturan: boolean };
};

/** FnB reservasi meja + uang muka (D-145). */
export type StatusReservasiMeja = 'Menunggu' | 'Dikonfirmasi' | 'Hadir' | 'Selesai' | 'Dibatalkan' | 'TidakDatang';

export type BarisReservasiMeja = {
    Uuid: string;
    Nomor: string;
    MulaiPada: string;
    SelesaiPada: string;
    DurasiMenit: number;
    NamaPemesan: string;
    NoHp: string;
    JumlahTamu: number;
    Meja: { Uuid: string | null; Nama: string } | null;
    Area: { Uuid: string | null; Nama: string } | null;
    Outlet: { Uuid: string | null; Nama: string };
    Status: StatusReservasiMeja;
    LabelStatus: string;
    Sumber: 'BackOffice' | 'Online' | 'Pos';
    LabelSumber: string;
    Catatan: string | null;
    AlasanBatal: string | null;
    BolehDiubah: boolean;
    BolehCatatDp: boolean;
    UangMuka: { Dicatat: string; Terpakai: string; Dikembalikan: string; Hangus: string; Sisa: string };
    StatusBerikutnya: StatusReservasiMeja[];
};

export type PilihanMejaReservasi = {
    Meja: { Uuid: string; Nama: string; Kapasitas: number; UuidOutlet: string; NamaArea: string | null }[];
    Area: { Uuid: string; Nama: string; UuidOutlet: string }[];
};

export type PropsDaftarReservasiMeja = {
    Reservasi: HasilTabel<BarisReservasiMeja>;
    OpsiStatus: { Nilai: StatusReservasiMeja; Label: string }[];
    OpsiOutlet: { Uuid: string; Nama: string }[];
    PilihanMeja: PilihanMejaReservasi;
    AkunKasBank: { Uuid: string; Nama: string }[];
    HariIni: string;
};
