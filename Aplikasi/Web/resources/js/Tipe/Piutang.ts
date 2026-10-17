import type { HasilTabel } from '@/Komponen/TabelData/Tipe';
import type { JurnalDokumen, OpsiAkunKas, RiwayatDokumen } from '@/Tipe/Pembelian';

/**
 * Kontrak props & JSON halaman piutang pelanggan F-12 (PRD "Rincian F-12"): piutang terbuka dengan umur, pelunasan
 * (daftar, formulir, detail). Uang selalu string desimal "12345.68"; tanggal `YYYY-MM-DD`.
 */

export type StatusPiutang = 'BelumLunas' | 'DibayarSebagian' | 'Lunas' | 'Dibatalkan' | 'DihapusBuku';
export type StatusPelunasanPiutang = 'Diposting' | 'Dibatalkan';
export type KelompokUmurPiutang =
    'BelumJatuhTempo' | 'Hari0Sampai30' | 'Hari31Sampai60' | 'Hari61Sampai90' | 'LebihDari90';
export type OpsiNilai = { Nilai: string; Label: string };
export type OpsiPelangganPiutang = { Uuid: string; Nama: string };
export type IzinPiutang = { Kelola: boolean; LihatJurnal: boolean; Ingatkan?: boolean };

/** D-23 D: pengingat piutang terakhir (tanpa tujuan pelanggan). */
export type PengingatPiutangTerakhir = {
    Waktu: string | null;
    Kanal: 'Whatsapp' | 'Email';
    Status: 'Diantrekan' | 'Terkirim' | 'Gagal' | 'Dibatalkan';
    LabelStatus: string;
};

export type PengaturanPengingatPiutang = { Aktif: boolean; HariSebelum: number; IngatkanSaatLewat: boolean };

export type BarisPiutang = {
    Uuid: string;
    Nomor: string;
    Tanggal: string;
    JatuhTempo: string;
    HariLewat: number;
    Umur: KelompokUmurPiutang;
    LabelUmur: string;
    UuidPelanggan: string | null;
    NamaPelanggan: string | null;
    Jumlah: string;
    Sisa: string;
    Status: StatusPiutang;
    LabelStatus: string;
    PengingatTerakhir?: PengingatPiutangTerakhir | null;
};

export type RingkasanPiutang = {
    Total: string;
    Kelompok: { Kunci: KelompokUmurPiutang; Label: string; Sisa: string; Jumlah: number }[];
};

export type PropsDaftarPiutang = {
    Piutang: HasilTabel<BarisPiutang, RingkasanPiutang> & { Ringkasan: RingkasanPiutang };
    OpsiUmur: OpsiNilai[];
    OpsiPelanggan: OpsiPelangganPiutang[];
    HariIni: string;
    Izin: IzinPiutang;
    Pengingat?: PengaturanPengingatPiutang;
};

export type BarisPelunasan = {
    Uuid: string;
    Nomor: string;
    Tanggal: string;
    NamaPelanggan: string;
    Jumlah: string;
    Status: StatusPelunasanPiutang;
    LabelStatus: string;
};

/** E8: dokumen hapus-buku piutang. */
export type BarisHapusBuku = {
    Uuid: string;
    Nomor: string;
    NomorPiutang: string;
    Tanggal: string;
    NamaPelanggan: string | null;
    Jumlah: string;
    Alasan: string;
    Status: StatusPelunasanPiutang;
    LabelStatus: string;
    AlasanBatal: string | null;
};

export type PropsDaftarHapusBuku = {
    HapusBuku: HasilTabel<BarisHapusBuku>;
    OpsiStatus: OpsiNilai[];
    Izin: IzinPiutang;
};

export type PropsDaftarPelunasan = {
    Pelunasan: HasilTabel<BarisPelunasan>;
    OpsiStatus: OpsiNilai[];
    Izin: IzinPiutang;
};

export type PiutangTerbuka = {
    Uuid: string;
    Nomor: string;
    TanggalBisnis: string;
    JatuhTempo: string;
    Jumlah: string;
    Sisa: string;
};

export type PropsFormPelunasan = {
    OpsiPelanggan: OpsiPelangganPiutang[];
    OpsiAkun: OpsiAkunKas[];
    UuidPelanggan: string | null;
    NamaPelanggan: string | null;
    UuidPiutangAwal: string | null;
    Piutang: PiutangTerbuka[];
    HariIni: string;
};

export type PropsDetailPelunasan = {
    Pelunasan: {
        Uuid: string;
        Nomor: string;
        Tanggal: string;
        Status: StatusPelunasanPiutang;
        LabelStatus: string;
        Pelanggan: OpsiPelangganPiutang | null;
        Akun: string | null;
        Jumlah: string;
        PotonganPph: string;
        NomorBuktiPotong: string | null;
        Catatan: string | null;
        AlasanBatal: string | null;
        DibuatOleh: string | null;
    };
    Alokasi: { Nomor: string | null; JatuhTempo: string | null; Sisa: string | null; Jumlah: string }[];
    Jurnal: JurnalDokumen[];
    Riwayat: RiwayatDokumen[];
    Izin: IzinPiutang;
    Tindakan: { Batalkan: boolean; CatatBuktiPotong: boolean };
};
