import type { HasilTabel } from '@/Komponen/TabelData/Tipe';
/**
 * Kontrak props halaman jurnal F-05a (baca saja, DesainF05a E). Uang = string desimal ("12345.68"), tanggal
 * `YYYY-MM-DD`, cap waktu ISO UTC. Pemilik file: Tim 0 (perubahan lewat permintaan ke lead).
 */
import type { Pilihan } from '@/Tipe/Pengelola';

export type BarisDaftarJurnal = {
    Uuid: string;
    Nomor: string;
    Tanggal: string;
    JenisSumber: string;
    LabelJenisSumber: string;
    NomorSumber: string | null;
    TautanSumber: string | null;
    Keterangan: string;
    TotalDebit: string;
    Otomatis: boolean;
    Dibalik: boolean;
    Pembalik: boolean;
};
export type PropsDaftarJurnal = {
    Jurnal: HasilTabel<BarisDaftarJurnal>;
    OpsiJenisSumber: Pilihan[];
    Izin: { Kelola: boolean };
};
export type PropsDetailJurnal = {
    Jurnal: BarisDaftarJurnal & {
        Periode: string;
        DibuatOleh: string | null;
        DibuatPada: string;
        UuidJurnalDibalik: string | null;
        NomorJurnalDibalik: string | null;
        UuidPembalik: string | null;
        NomorPembalik: string | null;
    };
    Baris: {
        Urutan: number;
        KodeAkun: string;
        NamaAkun: string;
        NamaOutlet: string | null;
        Debit: string;
        Kredit: string;
        Memo: string | null;
    }[];
    Total: { Debit: string; Kredit: string };
};

/*
 * F-13a (PRD "Rincian F-13a"): bagan akun, pemetaan akun, transaksi kas & bank, laporan keuangan. Uang = string
 * desimal, tanggal `YYYY-MM-DD`.
 */
export type TipeAkun = 'Aset' | 'Kewajiban' | 'Ekuitas' | 'Pendapatan' | 'Hpp' | 'Beban';

export type BarisBaganAkun = {
    Uuid: string;
    Kode: string;
    Nama: string;
    Jenis: TipeAkun;
    LabelJenis: string;
    SaldoNormal: 'Debit' | 'Kredit';
    Kontra: boolean;
    KasBank: boolean;
    Aktif: boolean;
    Sistem: boolean;
    Kedalaman: number;
    UuidInduk: string | null;
    KodeInduk: string | null;
    AdaJurnal: boolean;
    PeranDipetakan: string[];
    PunyaAnak: boolean;
    BisaDihapus: boolean;
};
export type PropsBaganAkun = {
    Akun: BarisBaganAkun[];
    OpsiTipe: { Nilai: TipeAkun; Label: string; DigitAwal: string }[];
    Izin: { Kelola: boolean };
};

export type StatusPemetaanAkun = 'Sesuai' | 'TipeSalah' | 'BelumDipetakan' | 'AkunNonaktif';
export type BarisPemetaanAkun = {
    Id: string;
    Kunci: string;
    LabelPeran: string;
    TipeWajib: TipeAkun;
    LabelTipeWajib: string;
    WajibKontra: boolean;
    UuidOutlet: string | null;
    NamaOutlet: string | null;
    UuidAkun: string | null;
    KodeAkun: string | null;
    NamaAkun: string | null;
    Status: StatusPemetaanAkun;
    PesanStatus: string | null;
};
export type PropsPemetaanAkun = {
    Pemetaan: BarisPemetaanAkun[];
    OpsiAkun: { Uuid: string; Kode: string; Nama: string; Jenis: TipeAkun; Kontra: boolean }[];
    OpsiOutlet: { Uuid: string; Nama: string }[];
    Izin: { Kelola: boolean; UbahSemuaOutlet: boolean };
};

export type JenisTransaksiKasBank = 'Pengeluaran' | 'Penerimaan' | 'Transfer';
export type BarisTransaksiKasBank = {
    Uuid: string;
    Nomor: string;
    Tanggal: string;
    Jenis: JenisTransaksiKasBank;
    LabelJenis: string;
    NamaOutlet: string | null;
    AkunSumber: string;
    AkunTujuan: string;
    Jumlah: string;
    Keterangan: string;
    Pembalik: boolean;
    AdaLampiran: boolean;
    Dibalik: boolean;
};
export type BarisSaldoKasBank = { Uuid: string; Kode: string; Nama: string; Aktif: boolean; Saldo: string };
export type OpsiAkunKasBank = { Uuid: string; Kode: string; Nama: string; Jenis: TipeAkun; KasBank: boolean };
export type PropsDaftarTransaksiKasBank = {
    Transaksi: HasilTabel<BarisTransaksiKasBank>;
    Saldo: BarisSaldoKasBank[];
    OpsiJenis: Pilihan[];
    OpsiOutlet: { Uuid: string; Nama: string }[];
    Izin: { Kelola: boolean };
};
/** D-23 D: transaksi kas & bank berulang (`/kelola/akuntansi/kas-bank/berulang`). */
export type BarisJadwalKasBank = {
    Uuid: string;
    Keterangan: string;
    Jenis: JenisTransaksiKasBank;
    LabelJenis: string;
    AkunSumber: string;
    AkunTujuan: string;
    Jumlah: string;
    Frekuensi: 'Mingguan' | 'Bulanan';
    LabelFrekuensi: string;
    TanggalBerikutnya: string;
    Aktif: boolean;
    JumlahDicatat: number;
    GalatTerakhir: string | null;
};
export type PropsJadwalKasBank = { Jadwal: HasilTabel<BarisJadwalKasBank>; Izin: { Kelola: boolean } };
/** Halaman penuh "Catat transaksi kas & bank" (`/kelola/akuntansi/kas-bank/buat`). */
export type PropsBuatTransaksiKasBank = {
    OpsiJenis: Pilihan[];
    OpsiOutlet: { Uuid: string; Nama: string }[];
    OpsiAkun: OpsiAkunKasBank[];
    WajibOutlet: boolean;
    Lampiran: { Ekstensi: string[]; UkuranMaksimalKb: number };
};
export type PropsDetailTransaksiKasBank = {
    Transaksi: BarisTransaksiKasBank & {
        DibuatOleh: string | null;
        DibuatPada: string;
        UuidDibalik: string | null;
        NomorDibalik: string | null;
        UuidPembalik: string | null;
        NomorPembalik: string | null;
        Lampiran: { Nama: string; Ukuran: number } | null;
    };
    Jurnal: {
        Uuid: string;
        Nomor: string;
        Tanggal: string;
        Keterangan: string;
        TotalDebit: string;
        Pembalik: boolean;
    }[];
    Izin: { Kelola: boolean };
};

export type SaringLaporanKeuangan = { Dari: string; Sampai: string; Outlet: string };
export type BarisBukuBesar = {
    Id: string;
    Tanggal: string;
    UuidJurnal: string;
    NomorJurnal: string;
    Keterangan: string;
    Memo: string | null;
    LabelSumber: string;
    NomorSumber: string | null;
    TautanSumber: string | null;
    NamaOutlet: string | null;
    Debit: string;
    Kredit: string;
    Saldo: string;
};
export type RingkasanBukuBesar = { SaldoAwal: string; TotalDebit: string; TotalKredit: string; SaldoAkhir: string };
export type PropsBukuBesar = {
    Saring: SaringLaporanKeuangan & { Akun: string };
    Akun: { Uuid: string; Kode: string; Nama: string; SaldoNormal: 'Debit' | 'Kredit' } | null;
    Mutasi: HasilTabel<BarisBukuBesar, RingkasanBukuBesar>;
    OpsiAkun: Pilihan[];
    OpsiOutlet: { Uuid: string; Nama: string }[];
};
export type KolomNeracaSaldo =
    'SaldoAwalDebit' | 'SaldoAwalKredit' | 'Debit' | 'Kredit' | 'SaldoAkhirDebit' | 'SaldoAkhirKredit';
export type BarisNeracaSaldo = {
    Uuid: string;
    Kode: string;
    Nama: string;
    Jenis: TipeAkun;
    LabelJenis: string;
} & Record<KolomNeracaSaldo, string>;
export type PropsNeracaSaldo = {
    Saring: SaringLaporanKeuangan;
    Laporan: { Baris: BarisNeracaSaldo[]; Total: Record<KolomNeracaSaldo, string>; Seimbang: boolean };
    OpsiOutlet: { Uuid: string; Nama: string }[];
};
export type BarisLabaRugi = {
    Id: string;
    Jenis: 'Kepala' | 'Akun' | 'Subtotal' | 'Laba';
    Kelompok: string;
    Kode: string | null;
    Label: string;
    Nilai: string | null;
    NilaiSebelumnya: string | null;
};
export type NilaiBanding = { Nilai: string; NilaiSebelumnya: string };
export type PropsLabaRugi = {
    Saring: SaringLaporanKeuangan;
    Laporan: {
        Periode: { Dari: string; Sampai: string; DariSebelumnya: string; SampaiSebelumnya: string };
        Baris: BarisLabaRugi[];
        Ringkasan: Record<'Pendapatan' | 'Hpp' | 'LabaKotor' | 'Beban' | 'LabaBersih', NilaiBanding>;
    };
    OpsiOutlet: { Uuid: string; Nama: string }[];
};
export type BarisNeraca = {
    Id: string;
    Jenis: 'Kepala' | 'Akun' | 'Laba' | 'Subtotal' | 'Total';
    Kelompok: string;
    Kode: string | null;
    Label: string;
    Nilai: string | null;
    NilaiAwal: string | null;
};
export type NilaiPosisi = { Nilai: string; NilaiAwal: string };
export type PropsNeraca = {
    Saring: SaringLaporanKeuangan;
    Laporan: {
        Posisi: { Akhir: string; Awal: string };
        Baris: BarisNeraca[];
        Ringkasan: Record<'Aset' | 'Kewajiban' | 'Ekuitas' | 'KewajibanEkuitas' | 'LabaBerjalan', NilaiPosisi>;
        Seimbang: boolean;
    };
    OpsiOutlet: { Uuid: string; Nama: string }[];
};
export type BarisArusKas = {
    Id: string;
    Jenis: 'Kepala' | 'Rincian' | 'Subtotal' | 'Total' | 'Saldo';
    Aktivitas: string;
    Kode: string | null;
    Label: string;
    Nilai: string | null;
};
export type PropsArusKas = {
    Saring: SaringLaporanKeuangan;
    Laporan: {
        Periode: { Dari: string; Sampai: string };
        Baris: BarisArusKas[];
        Ringkasan: Record<'Operasi' | 'Investasi' | 'Pendanaan' | 'Kenaikan' | 'SaldoAwal' | 'SaldoAkhir', string>;
        AdaAkunKas: boolean;
    };
    OpsiOutlet: { Uuid: string; Nama: string }[];
};

/** F-15 tutup buku: satu periode akuntansi `YYYY-MM`. */
export type BarisPeriodeAkuntansi = {
    Periode: string;
    Label: string;
    Terkunci: boolean;
    DikunciPada: string | null;
    DikunciOleh: string | null;
    /** Bulan berjalan (belum bisa dikunci). */
    Berjalan: boolean;
    /** Shift yang belum ditutup di periode ini (syarat kunci). */
    ShiftBelumDitutup: number;
    /** Tahun bukunya sudah ditutup (J-15.1): kunci tidak bisa dibuka. */
    TahunDitutup: boolean;
};

/** F-15 tutup tahun (J-15.1): satu tahun buku di halaman Tutup buku. */
export type BarisTahunBuku = {
    Tahun: number;
    BulanTerkunci: number;
    Ditutup: boolean;
    DitutupPada: string | null;
    DitutupOleh: string | null;
    NomorJurnal: string | null;
    UuidJurnal: string | null;
};

export type PropsTutupBuku = {
    Periode: BarisPeriodeAkuntansi[];
    Tahun: BarisTahunBuku[];
    Izin: { Kelola: boolean };
};

/*
 * F-08 BR-08.4 (J-08.1) pencairan dana non-tunai. Uang selalu **string desimal** dari server, tidak pernah number
 * (CLAUDE.md #7). `Biaya` boleh bertanda minus: platform menyetor lebih besar daripada nilai transaksinya.
 */

export type StatusPencairan = 'Diposting' | 'Dibatalkan';

export type OpsiMetodePencairan = {
    Uuid: string;
    Nama: string;
    Jenis: string;
    PersenBiaya: string;
    BiayaTetap: string;
};

/** Isi akun kliring yang masih menunggu uang masuk rekening, per metode. */
export type RingkasanBelumDicairkan = { Uuid: string; Nama: string; Jumlah: number; Total: string };

export type BarisPencairan = {
    Uuid: string;
    Nomor: string;
    Tanggal: string;
    NamaMetode: string;
    KodeOutlet: string;
    Status: StatusPencairan;
    LabelStatus: string;
    JumlahKotor: string;
    JumlahBersih: string;
    Biaya: string;
    BiayaDiharapkan: string;
    SelisihBiaya: string;
    Referensi: string | null;
};

/** Rekap potongan per metode untuk saringan yang sedang aktif (ikut di muatan tabel, bukan prop halaman). */
export type RekapPotonganPencairan = {
    Nama: string;
    Jumlah: number;
    JumlahKotor: string;
    Biaya: string;
    BiayaDiharapkan: string;
    Selisih: string;
    PersenEfektif: string;
};

export type PropsDaftarPencairan = {
    Pencairan: {
        Data: BarisPencairan[];
        Meta: { Halaman: number; PerHalaman: number; Total: number; JumlahHalaman: number };
        Ringkasan?: RekapPotonganPencairan[];
    };
    BelumDicairkan: RingkasanBelumDicairkan[];
    OpsiMetode: OpsiMetodePencairan[];
    OpsiStatus: { Nilai: string; Label: string }[];
    Izin: { Kelola: boolean };
};

/** Satu pembayaran yang belum dicairkan, pilihan di formulir. */
export type BarisPembayaranBelumDicairkan = {
    Uuid: string;
    NomorPenjualan: string;
    TanggalPenjualan: string;
    StatusPenjualan: string;
    LabelStatusPenjualan: string;
    Jumlah: string;
    Referensi: string | null;
    RefEksternal: string | null;
};

export type PropsBuatPencairan = {
    OpsiMetode: OpsiMetodePencairan[];
    OpsiOutlet: { Uuid: string; Nama: string }[];
    OpsiAkun: { Id: number; Uuid: string; Kode: string; Nama: string; Jenis: string }[];
    Terpilih: { Metode: string; Outlet: string; Sampai: string };
    Pembayaran: { Data: BarisPembayaranBelumDicairkan[]; Total: string; Terpotong: boolean };
    HariIni: string;
    MaksimalBaris: number;
};

export type PropsDetailPencairan = {
    Pencairan: {
        Uuid: string;
        Nomor: string;
        Tanggal: string;
        Status: StatusPencairan;
        LabelStatus: string;
        NamaMetode: string;
        JenisMetode: string;
        KodeOutlet: string;
        AkunTujuan: string;
        /** Null = metode tanpa akun kliring sendiri; yang dikredit peran Piutang Pencairan. */
        AkunKliring: string | null;
        JumlahKotor: string;
        JumlahBersih: string;
        Biaya: string;
        BiayaDiharapkan: string;
        SelisihBiaya: string;
        Referensi: string | null;
        Catatan: string | null;
        AlasanBatal: string | null;
    };
    Baris: {
        Urutan: number;
        NomorPenjualan: string;
        TanggalPenjualan: string;
        Jumlah: string;
        RefEksternal: string | null;
    }[];
    Jurnal: {
        Uuid: string;
        Nomor: string;
        Tanggal: string;
        KunciSumber: string;
        TotalDebit: string;
        TotalKredit: string;
    }[];
    Riwayat: { StatusKe: string; Oleh: string | null; Pada: string; Alasan: string | null }[];
    Izin: { Kelola: boolean };
    Tindakan: { Batalkan: boolean };
};

/* FIN-10 (v3.38): aset tetap & penyusutan garis lurus. Uang = string desimal. */
export type StatusAsetTetap = 'Aktif' | 'Dilepas' | 'Dibatalkan';

export type OpsiKelompokAset = { Nilai: string; Label: string; UmurBulan: number };

export type BarisAsetTetap = {
    Uuid: string;
    Nomor: string;
    Nama: string;
    Kelompok: string;
    LabelKelompok: string;
    NamaOutlet: string | null;
    TanggalPerolehan: string;
    HargaPerolehan: string;
    UmurBulan: number;
    Akumulasi: string;
    NilaiBuku: string;
    Status: StatusAsetTetap;
    LabelStatus: string;
};

export type PropsDaftarAsetTetap = {
    Aset: HasilTabel<BarisAsetTetap>;
    OpsiKelompok: OpsiKelompokAset[];
    OpsiStatus: { Nilai: StatusAsetTetap; Label: string }[];
    Izin: { Kelola: boolean };
};

export type OpsiAkunAset = { Uuid: string; Kode: string; Nama: string };

export type PropsBuatAsetTetap = {
    OpsiKelompok: OpsiKelompokAset[];
    OpsiOutlet: { Uuid: string; Nama: string }[];
    OpsiAkun: OpsiAkunAset[];
    UmurMaksimal: number;
};

type TautanJurnalAset = { Uuid: string; Nomor: string } | null;

export type BarisJadwalPenyusutan = {
    Periode: string;
    Jumlah: string;
    Akumulasi: string;
    NilaiBuku: string;
    Dijurnal: boolean;
    Jurnal: TautanJurnalAset;
};

export type PropsDetailAsetTetap = {
    Aset: BarisAsetTetap & {
        NilaiSisa: string;
        AkumulasiAwal: string;
        PeriodeMulai: string;
        SumberDana: 'KasBank' | 'SaldoAwal';
        Catatan: string | null;
        TanggalPelepasan: string | null;
        NilaiPelepasan: string | null;
        AlasanBatal: string | null;
        JurnalPerolehan: TautanJurnalAset;
        JurnalPelepasan: TautanJurnalAset;
        BisaDibatalkan: boolean;
    };
    Jadwal: BarisJadwalPenyusutan[];
    OpsiAkun: OpsiAkunAset[];
    Izin: { Kelola: boolean };
};

/* FIN-09 (v3.39): rekonsiliasi bank. */
export type StatusMutasiBank = 'BelumCocok' | 'Cocok' | 'Diabaikan';

export type BarisJurnalBank = {
    Uuid: string;
    Nomor: string;
    Keterangan: string;
    Tanggal: string;
    Debit: string;
    Kredit: string;
};

export type BarisMutasiBank = {
    Uuid: string;
    Tanggal: string;
    Keterangan: string;
    Masuk: string;
    Keluar: string;
    Saldo: string | null;
    Status: StatusMutasiBank;
    LabelStatus: string;
    AlasanAbaikan: string | null;
    Jurnal: BarisJurnalBank | null;
    Kandidat: BarisJurnalBank[];
};

export type PropsRekonsiliasiBank = {
    Mutasi: HasilTabel<BarisMutasiBank>;
    Akun: { Uuid: string; Kode: string; Nama: string };
    OpsiAkun: { Uuid: string; Kode: string; Nama: string }[];
    Ringkasan: {
        BelumCocok: number;
        Cocok: number;
        Diabaikan: number;
        TanggalTerakhir: string | null;
        SaldoRekeningKoran: string | null;
        SaldoBuku: string | null;
        Selisih: string | null;
    };
    BukuBelumCocok: BarisJurnalBank[];
    OpsiStatus: { Nilai: StatusMutasiBank; Label: string }[];
    HasilImpor: {
        Baru: number;
        Duplikat: number;
        Dicocokkan?: number;
        Bermasalah: { Baris: number; Pesan: string }[];
    } | null;
    Izin: { Kelola: boolean };
};

/* v3.42 (F-12): giro/cek mundur. */
export type StatusGiro = 'Menunggu' | 'Cair' | 'Ditolak';
export type BarisGiro = {
    Uuid: string;
    Arah: 'Masuk' | 'Keluar';
    NomorGiro: string;
    NamaBank: string;
    NamaPihak: string;
    NomorSumber: string;
    TanggalTerima: string;
    TanggalJatuhTempo: string;
    Jumlah: string;
    Status: StatusGiro;
    LabelStatus: string;
    TanggalCair: string | null;
    AlasanTolak: string | null;
    JurnalCair: { Uuid: string; Nomor: string } | null;
};
export type RingkasanGiro = { MasukJumlah: number; MasukNilai: string; KeluarJumlah: number; KeluarNilai: string };
export type PropsDaftarGiro = {
    Giro: HasilTabel<BarisGiro, RingkasanGiro>;
    OpsiStatus: Pilihan[];
    OpsiArah: Pilihan[];
    OpsiAkun: { Uuid: string; Kode: string; Nama: string }[];
    HariIni: string;
    Izin: { Kelola: boolean };
};

/** E8: uang QRIS yang masuk tanpa penjualan (tagihan QRIS POS `Lunas` tanpa penjualan). */
export type HasilPenutupanQris = 'Dikembalikan' | 'DiakuiPendapatan';

export type BarisQrisTanpaPenjualan = {
    Uuid: string;
    NomorPesanan: string;
    KodeOutlet: string;
    LunasPada: string | null;
    Jumlah: string;
    AlasanTinjauan: string | null;
    Ditutup: boolean;
    HasilPenutupan: HasilPenutupanQris | null;
    LabelHasil: string | null;
    CatatanPenutupan: string | null;
    DitutupPada: string | null;
};

export type PropsDaftarQrisTanpaPenjualan = {
    Tagihan: HasilTabel<BarisQrisTanpaPenjualan>;
    OpsiAkun: { Uuid: string; Kode: string; Nama: string }[];
    OpsiHasil: { Nilai: HasilPenutupanQris; Label: string }[];
    HariIni: string;
    Izin: { Kelola: boolean };
};

/*
 * FIN-05 (D-131): jurnal umum manual. Uang = string desimal, tanggal `YYYY-MM-DD`.
 */
export type JenisJurnalUmum = 'Umum' | 'Penyesuaian' | 'Koreksi';
export type OpsiAkunJurnalUmum = { Uuid: string; Kode: string; Nama: string; Jenis: TipeAkun };
export type PropsBuatJurnalUmum = {
    UuidKlien: string;
    TanggalBawaan: string;
    OpsiJenis: { Nilai: JenisJurnalUmum; Label: string; Penjelasan: string }[];
    OpsiOutlet: { Uuid: string; Nama: string }[];
    OpsiAkun: OpsiAkunJurnalUmum[];
    WajibOutlet: boolean;
    Lampiran: { Ekstensi: string[]; UkuranMaksimalKb: number };
};
export type PropsDetailJurnalUmum = {
    JurnalUmum: {
        Uuid: string;
        Nomor: string;
        Jenis: JenisJurnalUmum;
        LabelJenis: string;
        Tanggal: string;
        Referensi: string | null;
        Keterangan: string;
        TotalNilai: string;
        Pembalik: boolean;
        Dibalik: boolean;
        DibuatOleh: string | null;
        DibuatPada: string;
        UuidDibalik: string | null;
        NomorDibalik: string | null;
        UuidPembalik: string | null;
        NomorPembalik: string | null;
        Lampiran: { Nama: string; Ukuran: number } | null;
    };
    Jurnal: { Uuid: string; Nomor: string };
    Baris: PropsDetailJurnal['Baris'];
    Total: PropsDetailJurnal['Total'];
    Izin: { Kelola: boolean };
};

/** F8 (FIN-11): anggaran vs realisasi. `Status` menilai arah selisih (biaya di atas anggaran = Buruk). */
export type StatusAnggaran = 'Sesuai' | 'Baik' | 'Buruk';
export type BarisAnggaranLaporan = {
    Id: string;
    Jenis: 'Kepala' | 'Akun' | 'Subtotal' | 'Laba';
    Kelompok: string;
    Kode: string | null;
    Label: string;
    Anggaran: string | null;
    Realisasi: string | null;
    Selisih: string | null;
    Capaian: string | null;
    Status: StatusAnggaran | null;
};
export type PropsLaporanAnggaran = {
    Saring: { Tahun: number; BulanDari: number; BulanSampai: number; Outlet: string };
    Laporan: {
        Periode: { Tahun: number; BulanDari: number; BulanSampai: number; Dari: string; Sampai: string };
        AdaAnggaran: boolean;
        Baris: BarisAnggaranLaporan[];
        Ringkasan: Record<
            'Pendapatan' | 'Hpp' | 'LabaKotor' | 'Beban' | 'LabaBersih',
            { Anggaran: string; Realisasi: string }
        >;
    };
    OpsiOutlet: { Uuid: string; Nama: string }[];
    SeluruhUsahaBoleh: boolean;
    Izin: { Kelola: boolean };
};
export type AkunAnggaran = {
    Uuid: string;
    Kode: string;
    Nama: string;
    Jenis: TipeAkun;
    Aktif: boolean;
    /** Dua belas angka (Januari = indeks 0); string kosong = tidak dianggarkan. */
    PerBulan: string[];
};
export type PropsSusunAnggaran = {
    Tahun: number;
    Outlet: string;
    OpsiOutlet: { Uuid: string; Nama: string }[];
    SeluruhUsahaBoleh: boolean;
    Akun: AkunAnggaran[];
};
