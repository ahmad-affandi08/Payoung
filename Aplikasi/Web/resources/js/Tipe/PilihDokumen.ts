/** Satu dokumen hulu yang bisa dipilih untuk memulai dokumen turunannya (D-287); bentuknya dari `DokumenHuluPembelian` dkk. */
export type ButirPilihDokumen = {
    Uuid: string;
    Nomor: string;
    /** Pemasok, pelanggan, atau outlet peminta: pihak di seberang dokumen. */
    Pihak: string;
    Tanggal: string;
    /** Satu baris rincian berpemisah " | " yang sudah dirangkai server. */
    Rincian: string;
    Lencana: { Jenis: 'sukses' | 'peringatan' | 'bahaya' | 'netral'; Teks: string } | null;
    /** Alamat tujuan saat butir dipilih (form turunan, atau rincian dokumen hulu). */
    Tautan: string;
};

export type AlternatifPilihDokumen = {
    Judul: string;
    Teks: string;
    Tautan: { Teks: string; Href: string }[];
};

export type PropsPilihDokumen = {
    Teks: {
        Judul: string;
        Petunjuk: string;
        JudulDaftar: string;
        LabelCari: string;
        LabelAksi: string;
        Kosong: string;
    };
    Butir: ButirPilihDokumen[];
    Alternatif: AlternatifPilihDokumen | null;
};
