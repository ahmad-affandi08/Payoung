import 'dart:async';

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:http/http.dart' as http;
import 'package:klien_api/KlienApi.dart';

import '../Data/KlienPemilik.dart';
import '../Data/NotifikasiPush.dart';
import '../Data/PenyediaMasukGoogle.dart';
import '../Data/PenyimpanSesi.dart';
import 'Lingkungan.dart';

const String versiAplikasi = '1.0.0';

final penyediaLingkungan = Provider<Lingkungan>((ref) => Lingkungan.Dev);
final penyediaKlienHttp = Provider<http.Client>((ref) => http.Client());
final penyediaPenyimpanSesi = Provider<PenyimpanSesi>((ref) => PenyimpanSesiAman());
final penyediaJam = Provider<DateTime Function()>((ref) => DateTime.now);
final penyediaMasukGoogle = Provider<PenyediaMasukGoogle>((ref) => const PenyediaMasukGoogleTidakAda());
final penyediaNotifikasiPush = Provider<NotifikasiPush>((ref) => const NotifikasiPushTidakAda());

/// D-35: alamat server toko sendiri yang tersimpan saat aplikasi dibuka (diisi `Persiapan` dari secure storage).
final penyediaAlamatServerAwal = Provider<Uri?>((ref) => null);

/// D-35 edisi Lisensi: alamat server toko sendiri (null = alamat bawaan build). Diganti saat masuk; klien API ikut
/// dibuat ulang karena menonton penyedia ini.
class AlamatServer extends Notifier<Uri?> {
  @override
  Uri? build() => ref.watch(penyediaAlamatServerAwal);

  Future<void> Atur(Uri? alamat) async {
    await ref.read(penyediaPenyimpanSesi).Tulis(PenyimpanSesi.kunciAlamatServer, alamat?.toString() ?? '');
    state = alamat;
  }
}

final penyediaAlamatServer = NotifierProvider<AlamatServer, Uri?>(AlamatServer.new);

final penyediaKlien = Provider<KlienPemilik>((ref) {
  final sesi = ref.watch(penyediaPenyimpanSesi);
  return KlienPemilik(
    alamatDasar: ref.watch(penyediaLingkungan).AmbilAlamatServer(tersimpan: ref.watch(penyediaAlamatServer)),
    versiAplikasi: versiAplikasi,
    ambilToken: () => sesi.Baca(PenyimpanSesi.kunciToken),
    ambilTenant: () => sesi.Baca(PenyimpanSesi.kunciTenant),
    klien: ref.watch(penyediaKlienHttp),
  );
});

/// D-57: Client ID Google bila server mengaktifkan Masuk dengan Google; gagal/offline = tombol tidak tampil.
final penyediaClientIdGoogle = FutureProvider.autoDispose<String?>((ref) async {
  // `read`, bukan `watch`: alamat server toko sendiri baru tersimpan saat masuk, dan itu tidak boleh memicu ambil ulang.
  final klien = ref.read(penyediaKlien);
  if (klien.alamatDasar.host.endsWith('.invalid')) {
    return null;
  }
  try {
    return await klien.AmbilClientIdGoogle();
  } on Object {
    return null;
  }
});

enum TahapSesi { Memuat, Keluar, DuaFaktor, PilihTenant, Masuk }

class KeadaanSesi {
  const KeadaanSesi({
    required this.tahap,
    this.namaPengguna = '',
    this.tenant = const [],
    this.namaTenant,
    this.pesan,
    this.sibuk = false,
    this.tokenTantangan,
  });

  final TahapSesi tahap;
  final String namaPengguna;
  final List<TenantPemilik> tenant;
  final String? namaTenant;
  final String? pesan;
  final bool sibuk;
  final String? tokenTantangan;

  KeadaanSesi Salin({
    TahapSesi? tahap,
    String? namaPengguna,
    List<TenantPemilik>? tenant,
    String? namaTenant,
    String? Function()? pesan,
    bool? sibuk,
    String? tokenTantangan,
  }) => KeadaanSesi(
    tahap: tahap ?? this.tahap,
    namaPengguna: namaPengguna ?? this.namaPengguna,
    tenant: tenant ?? this.tenant,
    namaTenant: namaTenant ?? this.namaTenant,
    pesan: pesan == null ? this.pesan : pesan(),
    sibuk: sibuk ?? this.sibuk,
    tokenTantangan: tokenTantangan ?? this.tokenTantangan,
  );
}

/// Sesi OWN-01: masuk email + kata sandi (+ kode 2FA bila aktif), pilih tenant, keluar. Token di secure storage;
/// 401 dari server mana pun → kembali ke layar masuk.
class PengaturSesi extends Notifier<KeadaanSesi> {
  static const String namaPerangkat = 'Aplikasi Owner';

  @override
  KeadaanSesi build() {
    unawaited(_Muat());
    return const KeadaanSesi(tahap: TahapSesi.Memuat);
  }

  PenyimpanSesi get _sesi => ref.read(penyediaPenyimpanSesi);

  Future<void> _Muat() async {
    final token = await _sesi.Baca(PenyimpanSesi.kunciToken);
    if (token == null || token.isEmpty) {
      state = const KeadaanSesi(tahap: TahapSesi.Keluar);
      return;
    }
    final namaPengguna = await _sesi.Baca(PenyimpanSesi.kunciNamaPengguna) ?? '';
    final namaTenant = await _sesi.Baca(PenyimpanSesi.kunciNamaTenant);
    final tenant = await _sesi.Baca(PenyimpanSesi.kunciTenant);
    state = KeadaanSesi(
      tahap: tenant == null ? TahapSesi.PilihTenant : TahapSesi.Masuk,
      namaPengguna: namaPengguna,
      namaTenant: namaTenant,
    );
    // Segarkan daftar tenant; offline tetap memakai sesi tersimpan.
    try {
      final profil = await ref.read(penyediaKlien).AmbilProfil();
      state = state.Salin(tenant: profil.tenant, namaPengguna: profil.namaPengguna);
    } on GalatApi catch (galat) {
      if (galat.statusHttp == 401) {
        await Keluar(pesan: 'Sesi berakhir. Masuk lagi.');
      }
    } on GalatJaringan {
      // Offline: lanjut dengan sesi tersimpan.
    }
  }

  /// [alamatServer] (D-35): server toko sendiri; disimpan sebelum masuk agar klien API memakainya.
  Future<void> Masuk(String email, String kataSandi, {Uri? alamatServer}) async {
    if (email.trim().isEmpty || kataSandi.isEmpty) {
      state = state.Salin(pesan: () => 'Isi email dan kata sandi.');
      return;
    }
    if (alamatServer != null) {
      await ref.read(penyediaAlamatServer.notifier).Atur(alamatServer);
    }
    state = state.Salin(sibuk: true, pesan: () => null);
    try {
      final hasil = await ref
          .read(penyediaKlien)
          .Masuk(email: email, kataSandi: kataSandi, namaPerangkat: namaPerangkat);
      await _Terapkan(hasil);
    } on GalatApi catch (galat) {
      state = state.Salin(sibuk: false, pesan: () => galat.pesan);
    } on GalatJaringan catch (galat) {
      state = state.Salin(sibuk: false, pesan: () => galat.pesan);
    }
  }

  /// D-57: masuk dengan akun Google (menggantikan 2FA). Dialog dibatalkan = diam saja.
  Future<void> MasukGoogle(String clientId) async {
    state = state.Salin(sibuk: true, pesan: () => null);
    try {
      final idToken = await ref.read(penyediaMasukGoogle).AmbilTokenId(clientIdServer: clientId);
      if (idToken == null || idToken.isEmpty) {
        state = state.Salin(sibuk: false);
        return;
      }
      final hasil = await ref.read(penyediaKlien).MasukGoogle(idToken: idToken, namaPerangkat: namaPerangkat);
      await _Terapkan(hasil);
    } on GalatApi catch (galat) {
      state = state.Salin(sibuk: false, pesan: () => galat.pesan);
    } on GalatJaringan catch (galat) {
      state = state.Salin(sibuk: false, pesan: () => galat.pesan);
    } on Object {
      state = state.Salin(
        sibuk: false,
        pesan: () => 'Masuk dengan Google gagal. Coba lagi atau pakai email dan kata sandi.',
      );
    }
  }

  Future<void> KonfirmasiDuaFaktor(String kode) async {
    final tantangan = state.tokenTantangan;
    if (tantangan == null) {
      return;
    }
    state = state.Salin(sibuk: true, pesan: () => null);
    try {
      final hasil = await ref
          .read(penyediaKlien)
          .MasukDuaFaktor(tokenTantangan: tantangan, kode: kode, namaPerangkat: namaPerangkat);
      await _Terapkan(hasil);
    } on GalatApi catch (galat) {
      state = state.Salin(sibuk: false, pesan: () => galat.pesan);
    } on GalatJaringan catch (galat) {
      state = state.Salin(sibuk: false, pesan: () => galat.pesan);
    }
  }

  Future<void> _Terapkan(HasilMasukPemilik hasil) async {
    if (hasil.perluDuaFaktor) {
      state = KeadaanSesi(tahap: TahapSesi.DuaFaktor, tokenTantangan: hasil.tokenTantangan);
      return;
    }
    await _sesi.Tulis(PenyimpanSesi.kunciToken, hasil.token ?? '');
    await _sesi.Tulis(PenyimpanSesi.kunciNamaPengguna, hasil.namaPengguna);
    state = KeadaanSesi(tahap: TahapSesi.PilihTenant, namaPengguna: hasil.namaPengguna, tenant: hasil.tenant);
    if (hasil.tenant.isEmpty) {
      state = state.Salin(pesan: () => 'Akun ini belum terdaftar di usaha mana pun.');
    } else if (hasil.tenant.length == 1) {
      await PilihTenant(hasil.tenant.single);
    }
  }

  Future<void> PilihTenant(TenantPemilik tenant) async {
    await _sesi.Tulis(PenyimpanSesi.kunciTenant, tenant.uuid);
    await _sesi.Tulis(PenyimpanSesi.kunciNamaTenant, tenant.nama);
    state = state.Salin(tahap: TahapSesi.Masuk, namaTenant: tenant.nama, pesan: () => null);
  }

  void GantiTenant() => state = state.Salin(tahap: TahapSesi.PilihTenant);

  Future<void> Keluar({String? pesan}) async {
    if (pesan == null) {
      try {
        await ref.read(penyediaKlien).Keluar();
      } on Object {
        // Token tetap dihapus dari perangkat walau server tidak bisa dihubungi.
      }
    }
    await _sesi.HapusSemua();
    state = KeadaanSesi(tahap: TahapSesi.Keluar, pesan: pesan);
  }
}

final penyediaSesi = NotifierProvider<PengaturSesi, KeadaanSesi>(PengaturSesi.new);

/// Filter dasbor & laporan: tanggal bisnis (lokal) dan outlet (null = semua outlet).
class Saringan {
  const Saringan({required this.tanggal, this.outlet});

  final DateTime tanggal;
  final String? outlet;

  String get tanggalIso =>
      '${tanggal.year.toString().padLeft(4, '0')}-${tanggal.month.toString().padLeft(2, '0')}-'
      '${tanggal.day.toString().padLeft(2, '0')}';
}

class PengaturSaringan extends Notifier<Saringan> {
  @override
  Saringan build() {
    final sekarang = ref.read(penyediaJam)();
    return Saringan(tanggal: DateTime(sekarang.year, sekarang.month, sekarang.day));
  }

  void AturTanggal(DateTime tanggal) =>
      state = Saringan(tanggal: DateTime(tanggal.year, tanggal.month, tanggal.day), outlet: state.outlet);

  void AturOutlet(String? outlet) => state = Saringan(tanggal: state.tanggal, outlet: outlet);
}

final penyediaSaringan = NotifierProvider<PengaturSaringan, Saringan>(PengaturSaringan.new);

/// Galat 401 dari layar data → sesi berakhir.
Future<T> _Jaga<T>(Ref ref, Future<T> Function() ambil) async {
  try {
    return await ambil();
  } on GalatApi catch (galat) {
    if (galat.statusHttp == 401) {
      unawaited(ref.read(penyediaSesi.notifier).Keluar(pesan: 'Sesi berakhir. Masuk lagi.'));
    }
    rethrow;
  }
}

final penyediaDasbor = FutureProvider.autoDispose<DasborPemilik>((ref) {
  final s = ref.watch(penyediaSaringan);
  return _Jaga(ref, () => ref.read(penyediaKlien).AmbilDasbor(tanggal: s.tanggalIso, outlet: s.outlet));
});

final penyediaKelompokLaporan = NotifierProvider<PengaturKelompokLaporan, String>(PengaturKelompokLaporan.new);

class PengaturKelompokLaporan extends Notifier<String> {
  static const List<(String, String)> pilihan = [
    ('Produk', 'Produk'),
    ('Kategori', 'Kategori'),
    ('Kasir', 'Kasir'),
    ('Jam', 'Jam'),
    ('Kanal', 'Kanal'),
  ];

  @override
  String build() => 'Produk';

  void Atur(String kelompok) => state = kelompok;
}

final penyediaLaporan = FutureProvider.autoDispose<LaporanPenjualanPemilik>((ref) {
  final s = ref.watch(penyediaSaringan);
  final kelompok = ref.watch(penyediaKelompokLaporan);
  return _Jaga(
    ref,
    () => ref
        .read(penyediaKlien)
        .AmbilLaporanPenjualan(dari: s.tanggalIso, sampai: s.tanggalIso, kelompok: kelompok, outlet: s.outlet),
  );
});

/// OWN-05/09: layar Laporan menampilkan Penjualan (per kelompok), Laba rugi (bulan dari tanggal terpilih), atau
/// Anti-fraud (7 hari berakhir di tanggal terpilih), atau Stok (OWN-06: nilai persediaan, kritis, saldo, kartu stok).
enum ModeLaporan { Penjualan, LabaRugi, AntiFraud, Stok }

class PengaturModeLaporan extends Notifier<ModeLaporan> {
  @override
  ModeLaporan build() => ModeLaporan.Penjualan;

  void Atur(ModeLaporan mode) => state = mode;
}

final penyediaModeLaporan = NotifierProvider<PengaturModeLaporan, ModeLaporan>(PengaturModeLaporan.new);

/// Laba rugi satu bulan kalender yang memuat tanggal terpilih (pembanding: bulan sebelumnya, dari server).
final penyediaLabaRugi = FutureProvider.autoDispose<LabaRugiPemilik>((ref) {
  final s = ref.watch(penyediaSaringan);
  String Iso(DateTime t) =>
      '${t.year.toString().padLeft(4, '0')}-${t.month.toString().padLeft(2, '0')}-${t.day.toString().padLeft(2, '0')}';
  final awal = DateTime(s.tanggal.year, s.tanggal.month);
  final akhir = DateTime(s.tanggal.year, s.tanggal.month + 1, 0);
  return _Jaga(ref, () => ref.read(penyediaKlien).AmbilLabaRugi(dari: Iso(awal), sampai: Iso(akhir), outlet: s.outlet));
});

final penyediaAntiFraud = FutureProvider.autoDispose<List<RisikoKasirPemilik>>((ref) {
  final s = ref.watch(penyediaSaringan);
  final awal = s.tanggal.subtract(const Duration(days: 6));
  String Iso(DateTime t) =>
      '${t.year.toString().padLeft(4, '0')}-${t.month.toString().padLeft(2, '0')}-${t.day.toString().padLeft(2, '0')}';
  return _Jaga(ref, () => ref.read(penyediaKlien).AmbilAntiFraud(dari: Iso(awal), sampai: s.tanggalIso, outlet: s.outlet));
});

class PengaturCariStok extends Notifier<String> {
  @override
  String build() => '';

  void Atur(String kata) => state = kata;
}

final penyediaCariStok = NotifierProvider<PengaturCariStok, String>(PengaturCariStok.new);

/// OWN-06: cek stok mengikuti outlet terpilih di bilah saringan dan kata cari.
final penyediaStok = FutureProvider.autoDispose<StokPemilik>((ref) {
  final s = ref.watch(penyediaSaringan);
  final cari = ref.watch(penyediaCariStok);
  return _Jaga(ref, () => ref.read(penyediaKlien).AmbilStok(outlet: s.outlet, cari: cari));
});

/// OWN-06: kartu stok satu produk di satu lokasi (kunci `uuidProduk|uuidGudang`).
final penyediaKartuStok = FutureProvider.autoDispose.family<KartuStokPemilik, (String, String)>(
  (ref, kunci) => _Jaga(ref, () => ref.read(penyediaKlien).AmbilKartuStok(uuidProduk: kunci.$1, uuidGudang: kunci.$2)),
);

final penyediaShift = FutureProvider.autoDispose<List<ShiftPemilik>>((ref) {
  final s = ref.watch(penyediaSaringan);
  return _Jaga(ref, () => ref.read(penyediaKlien).AmbilShift(tanggal: s.tanggalIso, outlet: s.outlet));
});

final penyediaPerangkat = FutureProvider.autoDispose<List<PerangkatPemilik>>(
  (ref) => _Jaga(ref, () => ref.read(penyediaKlien).AmbilPerangkat()),
);

/// OWN-11: insight minggu lalu (null = belum ada penjualan untuk dibandingkan).
final penyediaInsight = FutureProvider.autoDispose<InsightMingguanPemilik?>(
  (ref) => _Jaga(ref, () => ref.read(penyediaKlien).AmbilInsight()),
);

/// OWN-10: pantau karyawan (kehadiran hari ini, komisi & target bulan berjalan).
final penyediaKaryawan = FutureProvider.autoDispose<PantauKaryawanPemilik>(
  (ref) => _Jaga(ref, () => ref.read(penyediaKlien).AmbilPantauKaryawan()),
);

/// P-10 PGL-19: pengumuman & jadwal pemeliharaan platform (dibaca saat Beranda dibuka & ditarik untuk menyegarkan).
final penyediaPengumuman = FutureProvider.autoDispose<List<PengumumanAplikasi>>(
  (ref) => _Jaga(ref, () => ref.read(penyediaKlien).AmbilPengumuman()),
);

final penyediaNotifikasi = FutureProvider.autoDispose<DaftarNotifikasiPemilik>(
  (ref) => _Jaga(ref, () => ref.read(penyediaKlien).AmbilNotifikasi()),
);

/// OWN-03 / X4: antrean persetujuan jarak jauh.
final penyediaPersetujuan = FutureProvider.autoDispose<List<PermintaanPersetujuanPos>>(
  (ref) => _Jaga(ref, () => ref.read(penyediaKlien).AmbilPersetujuan()),
);

/// Selang pantau antrean persetujuan saat aplikasi terbuka (belum ada push notification). Null = tidak memantau (test).
final penyediaSelangPantauPersetujuan = Provider<Duration?>((ref) => const Duration(seconds: 15));
