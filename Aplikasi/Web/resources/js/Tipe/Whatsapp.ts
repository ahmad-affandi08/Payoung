/** Pusat WhatsApp tenant (D-299). Token perangkat tidak pernah dikirim ke klien. */

export type FungsiNomorWhatsapp = 'Umum' | 'Pelanggan' | 'Karyawan' | 'Broadcast';
export type StatusNomorWhatsapp = 'MenungguPindai' | 'Terhubung' | 'Terputus';

export type NomorPengirimWhatsapp = {
    Uuid: string;
    Label: string;
    Fungsi: FungsiNomorWhatsapp;
    LabelFungsi: string;
    NomorTelepon: string;
    UuidOutlet: string | null;
    NamaOutlet: string;
    Status: StatusNomorWhatsapp;
    LabelStatus: string;
    Aktif: boolean;
    PesanStatus: string | null;
    TerhubungPada: string | null;
};

export type PesanOtomatisWhatsapp = {
    Kunci: string;
    Label: string;
    Keterangan: string;
    Kelompok: 'Pelanggan' | 'Karyawan';
    Aktif: boolean;
};

export type PenerimaLaporanWhatsapp = {
    Uuid: string;
    Nama: string;
    NomorTelepon: string;
    UuidOutlet: string | null;
    NamaOutlet: string;
};

export type RiwayatPesanWhatsapp = {
    Id: number;
    Jenis: string;
    Tujuan: string;
    NamaOutlet: string;
    Status: 'Terkirim' | 'Gagal';
    PesanGalat: string | null;
    DibuatPada: string | null;
};

export type PropsHalamanPusatWhatsapp = {
    PesanOtomatis: PesanOtomatisWhatsapp[];
    Penerima: PenerimaLaporanWhatsapp[];
    MaksPenerima: number;
    Riwayat: RiwayatPesanWhatsapp[];
    Nomor: NomorPengirimWhatsapp[];
    Outlet: { Uuid: string; Nama: string }[];
    OpsiFungsi: { Nilai: FungsiNomorWhatsapp; Label: string }[];
    MaksPerOutlet: number;
    SesiSiap: boolean;
};

export type HasilQrNomor = { Berhasil: boolean; Terhubung: boolean; KodeQr: string | null; Pesan: string };
export type HasilStatusNomor = { Status: StatusNomorWhatsapp; Terhubung: boolean; Pesan: string | null };
