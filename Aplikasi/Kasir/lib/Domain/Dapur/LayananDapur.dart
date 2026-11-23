import 'package:klien_api/KlienApi.dart';

import '../../Data/RepositoriKasir.dart';
import '../GalatKasir.dart';

/// Tingkat umur tiket dapur (F-10): normal < 10 menit, kuning 10–20, merah > 20.
enum UmurTiket { Normal, Lama, Terlambat }

/// Layar dapur (KDS, F-10b fase 1, perangkat berjenis `Kds`): online di fase 1 (mode LAN offline = §18.5 fase 3).
/// Status tiket hanya maju satu langkah `Antre → Dimasak → Siap → Disajikan`, atau mundur satu langkah untuk koreksi
/// salah ketuk (aturan server).
class LayananDapur {
  LayananDapur({required this.klien, required this.repositori});

  static const List<String> urutanStatus = ['Antre', 'Dimasak', 'Siap', 'Disajikan'];
  static const Duration batasLama = Duration(minutes: 10);
  static const Duration batasTerlambat = Duration(minutes: 20);

  final KlienPos klien;
  final RepositoriKasir repositori;

  static String? AmbilStatusBerikutnya(String status) {
    final i = urutanStatus.indexOf(status);
    return i < 0 || i + 1 >= urutanStatus.length ? null : urutanStatus[i + 1];
  }

  static String? AmbilStatusSebelumnya(String status) {
    final i = urutanStatus.indexOf(status);
    return i <= 0 ? null : urutanStatus[i - 1];
  }

  /// Label tombol maju untuk juru masak.
  static String? AmbilLabelMaju(String status) => switch (status) {
    'Antre' => 'Mulai masak',
    'Dimasak' => 'Siap',
    'Siap' => 'Disajikan',
    _ => null,
  };

  static UmurTiket HitungUmur(Duration umur) => umur >= batasTerlambat
      ? UmurTiket.Terlambat
      : umur >= batasLama
      ? UmurTiket.Lama
      : UmurTiket.Normal;

  /// Stasiun yang dipilih perangkat ini (Uuid); kosong = semua stasiun.
  Future<List<String>> AmbilStasiunTerpilih() async {
    final teks = await repositori.AmbilPengaturan(KunciPengaturan.stasiunKds) ?? '';
    return teks.split(',').where((s) => s.isNotEmpty).toList();
  }

  Future<void> SimpanStasiunTerpilih(List<String> uuid) =>
      repositori.SimpanPengaturan(KunciPengaturan.stasiunKds, uuid.join(','));

  /// K-2 lanjutan: perangkat ini menampilkan layar panggil antrian (bukan tiket dapur).
  Future<bool> CekTampilanAntrian() async => await repositori.AmbilPengaturan(KunciPengaturan.tampilanKds) == 'Antrian';

  Future<void> SimpanTampilanAntrian(bool antrian) =>
      repositori.SimpanPengaturan(KunciPengaturan.tampilanKds, antrian ? 'Antrian' : 'Tiket');

  /// Stasiun yang punya layar dapur (tanpa checker: ia hanya menerima salinan di printer, D-310).
  Future<List<StasiunDapurPos>> AmbilStasiun() async =>
      _Jalankan(() async => (await klien.AmbilMeja()).stasiunDapur.where((s) => !s.salinan).toList());

  Future<DaftarTiketDapur> AmbilTiket(List<String> stasiun) => _Jalankan(() => klien.AmbilTiketDapur(stasiun: stasiun));

  Future<String> UbahStatus(String uuidTiket, String status) =>
      _Jalankan(() => klien.UbahStatusTiket(uuidTiket, status));

  static Future<T> _Jalankan<T>(Future<T> Function() aksi) async {
    try {
      return await aksi();
    } on GalatJaringan {
      throw const GalatKasir('Offline', 'Layar dapur belum tersambung ke server. Periksa internet outlet.');
    } on GalatApi catch (galat) {
      throw GalatKasir(galat.kode, galat.pesan);
    }
  }
}
