/**
 * Kontrak props halaman F-01 Panduan Awal (DesainF01 §E, dibekukan bersama tim Backend).
 * Uang & persen berupa string desimal dari server; jangan diubah ke number (CLAUDE.md #7).
 */
import type { Batas, Kota, Pilihan } from '@/Tipe/Organisasi';

export type LangkahPanduan = 'ProfilUsaha' | 'Sektor' | 'Pajak' | 'Produk' | 'MetodePembayaran' | 'Perangkat';
export type StatusLangkahPanduan = 'Belum' | 'Selesai' | 'Dilewati';
export type RingkasanLangkah = {
    Kunci: LangkahPanduan;
    Slug: string;
    Judul: string;
    Status: StatusLangkahPanduan;
    Tautan: string;
};
export type ProgresPanduan = {
    /** Selalu 6 langkah, berurutan. */
    Langkah: RingkasanLangkah[];
    /** ISO-8601 UTC. */
    SelesaiPada: string | null;
    Outlet: { Uuid: string; Kode: string; Nama: string };
    /** D-24: tenant baru yang belum selesai; hanya langkah Perangkat yang boleh dilewati. */
    Wajib?: boolean;
    /** D-24: langkah wajib yang belum Selesai. */
    WajibBelumSelesai?: LangkahPanduan[];
};
export type PropsIndeksPanduan = { Progres: ProgresPanduan };

export type PropsProfilUsaha = {
    Progres: ProgresPanduan;
    Profil: {
        NamaUsaha: string;
        Alamat: string | null;
        KodeKota: string | null;
        Npwp: string | null;
        Pkp: boolean;
        TautanLogo: string | null;
    };
    Kota: Kota[];
    BatasLogo: { UkuranMaksimalKb: number; Ekstensi: string[] };
};

export type TemplatePilihan = {
    Kode: string;
    Nama: string;
    Keterangan: string | null;
    Versi: number;
    /** Nilai = enum ModeKasir, Label = AmbilLabel(). */
    ModeKasir: Pilihan[];
    Fitur: { Kunci: string; Nama: string; TersediaDiPaket: boolean }[];
    Kategori: string[];
    JumlahAkun: number;
    JumlahProdukContoh: number;
};
export type PropsSektor = {
    Progres: ProgresPanduan;
    /** Kosong = keadaan kosong. */
    Template: TemplatePilihan[];
    TemplateTerpilih: { Kode: string; Nama: string; Versi: number; DiterapkanPada: string } | null;
    SektorLain: string[];
    NamaPaket: string | null;
};

export type TarifTampil = { Tarif: string; BerlakuMulai: string; NomorDasarHukum: string | null };
export type PropsPajak = {
    Progres: ProgresPanduan;
    /** Dari Tenant, hanya dibaca di langkah ini. */
    Pkp: boolean;
    Kota: { Kode: string; Nama: string } | null;
    Nilai: { PungutPbjt: boolean; BiayaLayananAktif: boolean; PersenBiayaLayanan: string; HargaTermasukPajak: boolean };
    SudahDikonfirmasi: boolean;
    TarifPbjt: (TarifTampil & { BiayaLayananMasukDpp: boolean }) | null;
    TarifPpn: (TarifTampil & { PengaliDppPembilang: number; PengaliDppPenyebut: number }) | null;
    KelompokPajak: {
        Nama: string;
        Pajak: {
            KodeJenisPajak: string;
            NamaJenisPajak: string;
            DasarPengenaan: string;
            LabelDasarPengenaan: string;
        }[];
    }[];
    AlasanUsulan: string[];
};

export type ProdukContoh = {
    Nama: string;
    NamaKategori: string | null;
    Harga: string;
    KodeSatuan: string;
    SudahAda: boolean;
};
export type ProdukRingkas = { Uuid: string; Nama: string; NamaKategori: string | null; Harga: string };
export type PropsProdukPanduan = {
    Progres: ProgresPanduan;
    AdaTemplate: boolean;
    ProdukContoh: ProdukContoh[];
    Kategori: { Uuid: string; Nama: string }[];
    /** 50 produk terbaru. */
    Produk: ProdukRingkas[];
    JumlahProduk: number;
    BatasSku: Batas;
};

export type JenisMetodePembayaran =
    | 'Tunai'
    | 'QrisStatis'
    | 'QrisDinamis'
    | 'Edc'
    | 'Transfer'
    | 'Ewallet'
    | 'Tempo'
    | 'Deposit'
    | 'GiftCard'
    | 'Poin'
    | 'Voucher'
    | 'Marketplace'
    | 'UangMuka';
export type MetodePembayaranRingkas = {
    Uuid: string;
    Jenis: JenisMetodePembayaran;
    LabelJenis: string;
    Nama: string;
    NamaBank: string | null;
    NomorRekening: string | null;
    NamaPemilikRekening: string | null;
    /** X8: kanal platform metode Marketplace (GoFood, GrabFood, ShopeeFood, Marketplace). */
    Kanal?: string | null;
    LabelKanal?: string | null;
    PersenBiaya: string;
    /** F-08: hari wajar menunggu pencairan yang berlaku; null = metode tanpa pencairan (tunai, tempo, deposit). */
    BatasHariMenunggu?: number | null;
    /** F-08: batas buatan tenant; null = memakai bawaan jenis metode. */
    BatasHariKustom?: number | null;
    TautanGambarQris: string | null;
    /** D-189: QRIS statis yang teksnya tersimpan; kasir menampilkan QRIS dengan nominal terisi. */
    NominalOtomatis?: boolean;
    Aktif: boolean;
    /** True untuk Tunai: selalu tersedia, tidak bisa dinonaktifkan. */
    Wajib: boolean;
};
export type PropsMetodePembayaranPanduan = {
    Progres: ProgresPanduan;
    MetodePembayaran: MetodePembayaranRingkas[];
    /** QrisStatis, QrisDinamis, Edc, Transfer, Deposit, Marketplace (X8). */
    JenisTersedia: Pilihan[];
    /** X8: platform untuk metode Marketplace (opsional untuk data lama). */
    KanalPlatform?: Pilihan[];
    /** Batas biaya (persen): MDR umum dan komisi platform ojol/marketplace. */
    PersenBiayaMaksimal?: { Umum: string; Platform: string };
    Bank: { Kode: string; Nama: string; Jenis: 'Bank' | 'Ewallet' | 'JaringanEdc' | 'PenerbitQris' }[];
    BatasGambarQris: { UkuranMaksimalKb: number; Ekstensi: string[] };
    /** F-08: gerbang pembayaran aktif dari konsol platform (QRIS dinamis). Tanpa kredensial. */
    /** v2.06: gerbang milik toko; `Tautan` = halaman pengaturan gerbang (opsional untuk data lama). */
    GerbangPembayaran: {
        Aktif: boolean;
        Penyedia: string | null;
        Tautan?: string;
        /** D-162: nama menu & kalimat petunjuk mengikuti edisi (SaaS = Aktivasi QRIS, Lisensi = gerbang sendiri). */
        NamaMenu?: string;
        Petunjuk?: string;
    };
};

export type KodeAktivasiBaru = {
    UuidPerangkat: string;
    NamaPerangkat: string;
    KodePerangkat: string;
    Kode: string;
    KedaluwarsaPada: string;
    QrSvg: string;
};
export type PropsPerangkatPanduan = {
    Progres: ProgresPanduan;
    Outlet: { Uuid: string; Kode: string; Nama: string; BatasPerangkat: Batas };
    Perangkat: {
        Uuid: string;
        Kode: string;
        Nama: string;
        LabelJenis: string;
        Status: 'Aktif' | 'BelumDiaktifkan' | 'Dicabut';
    }[];
    KodeAktivasiBaru: KodeAktivasiBaru | null;
    /** Izin perangkat.kelola. */
    BolehKelolaPerangkat: boolean;
    /** PIN kasir pengguna yang sedang masuk sudah diatur (dipakai masuk di aplikasi kasir). */
    PinSayaDiatur: boolean;
};

/** Alamat POST panduan awal (DesainF01 §D). Hanya string URL; rute dimiliki Backend. */
export const AlamatPanduan = {
    Indeks: '/kelola/panduan-awal',
    ProfilUsaha: '/kelola/panduan-awal/profil-usaha',
    Sektor: '/kelola/panduan-awal/sektor',
    Pajak: '/kelola/panduan-awal/pajak',
    ProdukContoh: '/kelola/panduan-awal/produk/contoh',
    Produk: '/kelola/panduan-awal/produk',
    MetodePembayaran: '/kelola/panduan-awal/metode-pembayaran',
    Perangkat: '/kelola/panduan-awal/perangkat',
    Selesai: '/kelola/panduan-awal/selesai',
} as const;

export function AmbilAlamatLewati(slug: string): string {
    return `/kelola/panduan-awal/langkah/${slug}/lewati`;
}

export function AmbilAlamatTandaiSelesai(slug: string): string {
    return `/kelola/panduan-awal/langkah/${slug}/selesai`;
}
