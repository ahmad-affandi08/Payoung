/** P-11 PGL-21: isi halaman status layanan publik (sama dengan `status.json`). */
export type KondisiKomponen = 'Normal' | 'Sebagian' | 'Besar';

export type KomponenStatus = {
    Kode: string;
    Nama: string;
    Kondisi: KondisiKomponen;
    LabelKondisi: string;
};

export type PembaruanStatus = {
    Status: string;
    LabelStatus: string;
    Isi: string;
    Waktu: string | null;
};

export type InsidenStatus = {
    Uuid: string;
    Judul: string;
    Tingkat: 'Kecil' | 'Besar' | 'Kritis';
    Status: string;
    LabelStatus: string;
    Dampak: string;
    Komponen: string[];
    MulaiPada: string;
    SelesaiPada: string | null;
    PostMortem: string | null;
    Pembaruan: PembaruanStatus[];
};

export type StatusPlatformData = {
    DiperbaruiPada: string;
    Keseluruhan: KondisiKomponen;
    LabelKeseluruhan: string;
    Komponen: KomponenStatus[];
    Aktif: InsidenStatus[];
    Riwayat: InsidenStatus[];
};

/** Warna label per kondisi (status selalu bertulisan, warna hanya penguat). */
export function AmbilJenisKondisi(kondisi: KondisiKomponen): 'sukses' | 'peringatan' | 'bahaya' {
    return kondisi === 'Normal' ? 'sukses' : kondisi === 'Sebagian' ? 'peringatan' : 'bahaya';
}
