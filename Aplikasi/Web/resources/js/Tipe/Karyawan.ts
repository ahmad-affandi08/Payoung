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
    /** D-180: ikut absensi (absen lewat login di halaman Absen). */
    IkutAbsensi: boolean;
    /** F-18 bagian 4 (D-37): status wajah terdaftar terakhir. */
    StatusWajah: StatusWajahKaryawan | null;
};

export type StatusWajahKaryawan = 'Menunggu' | 'Disetujui' | 'Ditolak';

/** Isi panel wajah karyawan (`GET /kelola/karyawan/{uuid}/absen-hp`). */
export type AbsenHpKaryawan = {
    /** D-198: tautan absen pribadi yang bisa dibagikan; null bila belum dibuat atau sudah dicabut. */
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
    /** D-180: lokasi janggal (di luar radius, GPS lemah, titik outlet belum diatur); absen tetap tercatat. */
    PerluDicek: boolean;
    CatatanPeriksa: string | null;
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

/** D-180: karyawan yang gagal absen meminta manajer mencatat jamnya ("Minta koreksi ke manajer"). */
export type PermintaanKoreksiAbsensi = {
    Uuid: string;
    UuidKaryawan: string | null;
    NamaKaryawan: string;
    UuidOutlet: string | null;
    NamaOutlet: string | null;
    Jenis: 'Masuk' | 'Keluar';
    Alasan: string;
    DiajukanPada: string;
};

export type PropsAbsensi = {
    Absensi: HasilTabel<BarisAbsensi>;
    /** Permintaan koreksi yang menunggu (hanya untuk `karyawan.kelola`). */
    PermintaanKoreksi?: PermintaanKoreksiAbsensi[];
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
    /** D-180: simpan swafoto tiap absen sebagai bukti (wajah tetap selalu dicocokkan). */
    SimpanFotoBukti: boolean;
    /** D-180: tolak absen di luar radius outlet (bawaan: absen tetap tercatat dan ditandai perlu dicek). */
    TolakDiLuarRadius: boolean;
};

export type PropsAturanKehadiran = {
    Aturan: AturanKehadiran;
    /** Pengiriman WhatsApp aktif untuk usaha ini (integrasi P-05 + fitur paket); tanpa itu notifikasi tidak terkirim. */
    WhatsappAktif: boolean;
    Izin: { Kelola: boolean };
};

/** Satu absen terakhir di halaman Absen (D-180). `PerluDicek` = lokasinya janggal dan sedang menunggu pemeriksaan pengelola. */
export type RiwayatAbsenSaya = {
    MasukPada: string;
    KeluarPada: string | null;
    NamaOutlet: string | null;
    PerluDicek: boolean;
};

/** Props halaman Absen (berlogin `/kelola/absen` maupun tautan pribadi `/{slug}/absen/{token}`, D-198). */
export type PropsAbsenSaya = {
    NamaToko: string;
    NamaKaryawan: string;
    AlamatModelWajah: string;
    /** Alamat dasar titik kirim: `/kelola/absen` (berlogin) atau `/{slug}/absen/{token}` (tautan pribadi, D-198). */
    AlamatDasar: string;
    /** Berlogin: tampilkan Keluar. Lewat tautan pribadi tidak ada sesi untuk diakhiri. */
    Berlogin: boolean;
    /** Akun ini juga punya akses dashboard: tampilkan jalan ke sana. */
    BisaDashboard: boolean;
    Wajah: { Status: 'Menunggu' | 'Disetujui' | 'Ditolak'; Label: string; AlasanTolak: string | null } | null;
    AbsensiTerbuka: { Uuid: string; MasukPada: string; NamaOutlet: string | null } | null;
    Riwayat: RiwayatAbsenSaya[];
    JumlahFotoDaftar: number;
    /** Aturan "simpan foto bukti": foto hanya dikirim bila menyala (wajah tetap dicocokkan lewat sidik). */
    SimpanFoto: boolean;
};
