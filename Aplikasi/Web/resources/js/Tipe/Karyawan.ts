import type { HasilTabel } from '@/Komponen/TabelData/Tipe';

/** Kontrak props halaman karyawan F-18 bagian 1 (karyawan, jadwal kerja, absensi). Uang string desimal; jam `HH:mm`. */

export type StatusKaryawan = 'Aktif' | 'Nonaktif';
export type OpsiUuidNama = { Uuid: string; Nama: string };

export type BarisKaryawan = {
    Uuid: string;
    Nama: string;
    Jabatan: string | null;
    LevelStaf: string | null;
    /** Hanya untuk pelihat ber-izin `karyawan.kelola`. */
    GajiPokok: string | null;
    /** D-44: tarif lembur per jam, potongan per menit terlambat, potongan per hari tidak masuk (hanya `karyawan.kelola`). */
    TarifLemburPerJam: string | null;
    PotonganTerlambatPerMenit: string | null;
    PotonganTidakMasukPerHari: string | null;
    UuidPengguna: string | null;
    NamaPengguna: string | null;
    UuidOutlet: string | null;
    NamaOutlet: string | null;
    Status: StatusKaryawan;
    LabelStatus: string;
    /** F-18 bagian 4 (D-37): sudah punya tautan absen HP; status wajah terdaftar terakhir. */
    TautanAbsen: boolean;
    StatusWajah: StatusWajahKaryawan | null;
};

export type StatusWajahKaryawan = 'Menunggu' | 'Disetujui' | 'Ditolak';

/** Isi panel "Absen dari HP" (`GET /kelola/karyawan/{uuid}/absen-hp`). */
export type AbsenHpKaryawan = {
    Tautan: string | null;
    TautanDibuatPada: string | null;
    Wajah: {
        Status: StatusWajahKaryawan;
        Label: string;
        JumlahFoto: number;
        AlasanTolak: string | null;
        DibuatPada: string | null;
        DitinjauPada: string | null;
    } | null;
};

export type SelJadwal = { JamMulai: string; JamSelesai: string; OutletLain: boolean } | null;

export type JenisCuti = 'Cuti' | 'Sakit' | 'Izin';

export type BarisJadwal = {
    Uuid: string;
    Nama: string;
    Jabatan: string | null;
    Aktif: boolean;
    Jadwal: Record<string, SelJadwal>;
    /** Hari cuti/izin yang berlaku minggu ini, kunci = tanggal `TTTT-BB-HH`. */
    Cuti: Record<string, { Jenis: JenisCuti; Berbayar: boolean }>;
};

export type PropsJadwalKerja = {
    OpsiOutlet: OpsiUuidNama[];
    UuidOutlet: string | null;
    Senin: string;
    Jadwal: { Hari: string[]; Baris: BarisJadwal[] };
    Izin: { Kelola: boolean };
};

export type StatusKehadiran = 'TepatWaktu' | 'Terlambat' | 'PulangCepat' | 'Lembur' | 'TanpaJadwal' | 'BelumKeluar';

export type BarisAbsensi = {
    Uuid: string;
    TanggalBisnis: string;
    UuidKaryawan: string | null;
    NamaKaryawan: string | null;
    NamaOutlet: string | null;
    JamMasuk: string;
    JamKeluar: string | null;
    KeluarBeda: boolean;
    DurasiMenit: number | null;
    Jadwal: string | null;
    TerlambatMenit: number;
    PulangCepatMenit: number;
    LemburMenit: number;
    /** D-44: absen POS (offline) yang masuk saat aturan wajib-jadwal aktif tetapi tidak sesuai jadwal. */
    DiluarJadwal: boolean;
    Status: StatusKehadiran;
    LabelStatus: string;
    AdaSwafotoMasuk: boolean;
    AdaSwafotoKeluar: boolean;
    /** v3.34: `Pos` (absen dari kasir) atau `Manual` (dicatat pengelola); v4.21 `Web` = HP pribadi (D-37). */
    Sumber: 'Pos' | 'Manual' | 'Web';
    Dikoreksi: boolean;
    AlasanKoreksi: string | null;
    /** Absen dari HP: jarak ke outlet (meter) & kemiripan wajah (0–1, teks desimal). */
    JarakMasukMeter: number | null;
    JarakKeluarMeter: number | null;
    KemiripanWajahMasuk: string | null;
    KemiripanWajahKeluar: string | null;
};

export type PropsAbsensi = {
    Absensi: HasilTabel<BarisAbsensi>;
    OpsiKaryawan: OpsiUuidNama[];
    OpsiOutlet: OpsiUuidNama[];
    /** v3.34: `karyawan.kelola` boleh menambah absensi terlewat & mengoreksi jam. */
    BolehKoreksi: boolean;
};

/* F-18 bagian 2: komisi. */
export type CakupanKomisi = 'Semua' | 'Kategori' | 'Produk';
export type JenisKomisi = 'Persen' | 'Tetap';

export type BarisAturanKomisi = {
    Uuid: string;
    Nama: string;
    Cakupan: CakupanKomisi;
    LabelCakupan: string;
    UuidProduk: string | null;
    UuidKategori: string | null;
    NamaSasaran: string;
    LevelStaf: string | null;
    Jenis: JenisKomisi;
    Nilai: string;
    Status: 'Aktif' | 'Diarsipkan';
};

export type PropsAturanKomisi = {
    Aturan: BarisAturanKomisi[];
    OpsiKategori: OpsiUuidNama[];
    Izin: { Kelola: boolean };
};

/** Halaman penuh "Tambah aturan komisi" (`/kelola/karyawan/komisi/buat`). */
export type PropsBuatAturanKomisi = Pick<PropsAturanKomisi, 'OpsiKategori'>;

export type BarisLaporanKomisi = {
    Uuid: string;
    Nama: string;
    Jabatan: string | null;
    JumlahBaris: number;
    TotalDasar: string;
    Kotor: string;
    Dibatalkan: string;
    Bersih: string;
};

export type PropsLaporanKomisi = {
    Komisi: HasilTabel<BarisLaporanKomisi, { Bersih: string }> & { Ringkasan: { Bersih: string } };
    OpsiOutlet: OpsiUuidNama[];
};

/** F-18 bagian 3: kasbon karyawan. */
export type StatusKasbon = 'Aktif' | 'Lunas' | 'Dibatalkan';

export type BarisKasbon = {
    Uuid: string;
    Karyawan: string;
    UuidKaryawan: string | null;
    Tanggal: string;
    Jumlah: string;
    Sisa: string;
    Status: StatusKasbon;
    LabelStatus: string;
    Keterangan: string | null;
    AkunKasBank: string;
    AlasanBatal: string | null;
};

export type PropsKasbon = {
    Kasbon: HasilTabel<BarisKasbon>;
    TotalSisa: string;
    OpsiKaryawan: OpsiUuidNama[];
    OpsiAkunKasBank: OpsiUuidNama[];
    Izin: { Kelola: boolean };
};

/** F-18 EMP-03: cuti/izin/sakit karyawan. */
export type StatusCuti = 'Aktif' | 'Dibatalkan';

export type BarisCuti = {
    Uuid: string;
    Karyawan: string;
    UuidKaryawan: string | null;
    Jenis: JenisCuti;
    TanggalMulai: string;
    TanggalSelesai: string;
    Berbayar: boolean;
    Alasan: string | null;
    Status: StatusCuti;
    LabelStatus: string;
    AlasanBatal: string | null;
};

export type PropsCuti = {
    Cuti: HasilTabel<BarisCuti>;
    OpsiKaryawan: OpsiUuidNama[];
    Izin: { Kelola: boolean };
};

/** F-18 bagian 3: rekap gaji bulanan. Uang = string desimal. */
export type StatusRekapGaji = 'Draf' | 'Dibayar';

export type BarisRekapGaji = {
    Uuid: string;
    Periode: string;
    LabelPeriode: string;
    Status: StatusRekapGaji;
    LabelStatus: string;
    TotalKotor: string;
    TotalPotongan: string;
    TotalBersih: string;
    TanggalBayar: string | null;
};

export type PropsDaftarRekapGaji = {
    Rekap: HasilTabel<BarisRekapGaji>;
    OpsiPeriode: { Nilai: string; Label: string }[];
    OpsiStatus: { Nilai: StatusRekapGaji; Label: string }[];
};

export type BarisGajiKaryawan = {
    UuidKaryawan: string;
    Nama: string;
    Jabatan: string | null;
    GajiPokok: string;
    Komisi: string;
    Tambahan: string;
    /** D-44: lembur & potongan kehadiran dari absensi vs jadwal; bisa disesuaikan selama draf. */
    LemburMenit: number;
    Lembur: string;
    TerlambatMenit: number;
    PotonganTerlambat: string;
    HariTidakMasuk: number;
    PotonganTidakMasuk: string;
    Kotor: string;
    PotonganKasbon: string;
    PotonganLain: string;
    Bersih: string;
    Catatan: string | null;
    /** Sisa kasbon aktif saat ini: batas potongan kasbon selama draf. */
    SisaKasbon: string;
};

export type OpsiAkunGaji = { Uuid: string; Kode: string; Nama: string };

export type PropsDetailRekapGaji = {
    Rekap: BarisRekapGaji & {
        AkunKasBank: string | null;
        AkunBeban: string | null;
        Jurnal: { Uuid: string; Nomor: string } | null;
    };
    Baris: BarisGajiKaryawan[];
    OpsiAkunKasBank: OpsiAkunGaji[];
    OpsiAkunBeban: OpsiAkunGaji[];
};

/** F-18 bagian 3: target penjualan bulanan per outlet/karyawan + progres. */
export type CakupanTargetPenjualan = 'Outlet' | 'Karyawan';

export type BarisTargetPenjualan = {
    Uuid: string;
    Cakupan: CakupanTargetPenjualan;
    LabelCakupan: string;
    UuidSasaran: string;
    NamaSasaran: string;
    Nilai: string;
    Realisasi: string;
    /** Persen realisasi ÷ target, 1 desimal ("82.5"); bisa > 100. */
    Persen: string;
    Sisa: string;
    /** Hanya bulan berjalan: realisasi ÷ hari berlalu × jumlah hari. */
    Proyeksi: string | null;
};

export type PropsTargetPenjualan = {
    Periode: string;
    Berjalan: boolean;
    OpsiPeriode: { Nilai: string; Label: string }[];
    Target: BarisTargetPenjualan[];
    OpsiOutlet: OpsiUuidNama[];
    OpsiKaryawan: OpsiUuidNama[];
    Izin: { Kelola: boolean };
};

/** v3.35: slip gaji per karyawan (halaman cetak). */
export type PropsSlipGaji = {
    Rekap: PropsDetailRekapGaji['Rekap'];
    Baris: BarisGajiKaryawan[];
    Usaha: { Nama: string | null; Npwp: string | null };
};

/* F-18 bagian 4 (D-37, K37): kalibrasi ambang kemiripan wajah absensi web. */
export type KalibrasiWajah = {
    Ambang: string;
    Hari: number;
    JumlahDiterima: number;
    JumlahDitolak: number;
    PersenDitolak: string | null;
    TerendahDiterima: string | null;
    MedianDiterima: string | null;
    TertinggiDitolak: string | null;
    CukupData: boolean;
    Kelompok: { Dari: string; Sampai: string; Diterima: number; Ditolak: number }[];
};

/** F-18 bagian 5 (D-44): aturan kehadiran tenant — jadwal kerja ↔ absensi ↔ notifikasi WhatsApp. Semua menit bulat. */
export type AturanKehadiran = {
    WajibJadwal: boolean;
    MasukPalingAwalMenit: number;
    ToleransiTerlambatMenit: number;
    ToleransiPulangCepatMenit: number;
    LemburSetelahMenit: number;
    PengingatShiftAktif: boolean;
    PengingatShiftMenitSebelum: number;
    PeringatanPengelolaAktif: boolean;
    PeringatanPengelolaSetelahMenit: number;
};

export type PropsAturanKehadiran = {
    Aturan: AturanKehadiran;
    /** Pengiriman WhatsApp aktif untuk usaha ini (integrasi P-05 + fitur paket); tanpa itu notifikasi tidak terkirim. */
    WhatsappAktif: boolean;
    Izin: { Kelola: boolean };
};
