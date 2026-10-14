import 'dart:async';

import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../Data/KunciPerangkat.dart';
import '../Data/PenyimpanSesi.dart';
import 'Penyedia.dart';

/// Kunci aplikasi Owner (OWN-01): PIN 6 digit dan, bila perangkat mendukung, sidik jari atau wajah sebagai jalan pintas.
/// Aplikasi terkunci saat dibuka dingin dan setelah di latar lebih dari [jedaKunciOtomatis]. Lima kali salah memutus
/// sesi (keluar), karena PIN hanya dipakai untuk membuka sesi yang masih tersimpan di perangkat.
const Duration jedaKunciOtomatis = Duration(seconds: 60);
const int batasPercobaanPin = 5;

abstract class KunciPenyimpan {
  static const String sidik = 'KunciPinSidik';
  static const String garam = 'KunciPinGaram';
  static const String biometrik = 'KunciBiometrik';
  static const String gagal = 'KunciPinGagal';
  static const String tawaranDitolak = 'KunciTawaranDitolak';
}

enum TahapKunci { Memuat, TidakAktif, Terkunci, Terbuka }

class KeadaanKunci {
  const KeadaanKunci({
    this.tahap = TahapKunci.Memuat,
    this.biometrikAktif = false,
    this.biometrikTersedia = false,
    this.tawaranDitolak = false,
    this.sibuk = false,
    this.pesan,
  });

  final TahapKunci tahap;
  final bool biometrikAktif;
  final bool biometrikTersedia;
  final bool tawaranDitolak;
  final bool sibuk;
  final String? pesan;

  bool get pinAktif => tahap == TahapKunci.Terkunci || tahap == TahapKunci.Terbuka;

  KeadaanKunci Salin({
    TahapKunci? tahap,
    bool? biometrikAktif,
    bool? biometrikTersedia,
    bool? tawaranDitolak,
    bool? sibuk,
    String? pesan,
    bool hapusPesan = false,
  }) => KeadaanKunci(
    tahap: tahap ?? this.tahap,
    biometrikAktif: biometrikAktif ?? this.biometrikAktif,
    biometrikTersedia: biometrikTersedia ?? this.biometrikTersedia,
    tawaranDitolak: tawaranDitolak ?? this.tawaranDitolak,
    sibuk: sibuk ?? this.sibuk,
    pesan: hapusPesan ? null : (pesan ?? this.pesan),
  );
}

final penyediaBiometrik = Provider<PenyediaBiometrik>((ref) => const PenyediaBiometrikTidakAda());

class KunciAplikasi extends Notifier<KeadaanKunci> {
  DateTime? _masukLatar;

  PenyimpanSesi get _penyimpan => ref.read(penyediaPenyimpanSesi);

  @override
  KeadaanKunci build() {
    // Keluar menghapus PIN bersama sesi (PenyimpanSesi.HapusSemua); keadaan memori ikut dibersihkan.
    ref.listen<KeadaanSesi>(penyediaSesi, (lama, baru) {
      if (baru.tahap == TahapSesi.Keluar && lama?.tahap != TahapSesi.Keluar) {
        state = const KeadaanKunci(tahap: TahapKunci.TidakAktif);
      }
    });
    unawaited(Future<void>.microtask(Muat));
    return const KeadaanKunci();
  }

  Future<void> Muat() async {
    final sidik = await _penyimpan.Baca(KunciPenyimpan.sidik);
    final tersedia = await ref.read(penyediaBiometrik).Tersedia();
    final biometrik = (await _penyimpan.Baca(KunciPenyimpan.biometrik)) == '1' && tersedia;
    final ditolak = (await _penyimpan.Baca(KunciPenyimpan.tawaranDitolak)) == '1';
    state = KeadaanKunci(
      tahap: sidik == null || sidik.isEmpty ? TahapKunci.TidakAktif : TahapKunci.Terkunci,
      biometrikAktif: biometrik,
      biometrikTersedia: tersedia,
      tawaranDitolak: ditolak,
    );
  }

  /// Memasang PIN baru. Aplikasi tetap terbuka (pemiliknya baru saja memasangnya).
  Future<bool> AturPin(String pin) async {
    if (!SidikPin.PinSah(pin)) {
      state = state.Salin(pesan: 'PIN harus 6 angka.');
      return false;
    }
    final garam = SidikPin.BuatGaram();
    await _penyimpan.Tulis(KunciPenyimpan.garam, garam);
    await _penyimpan.Tulis(KunciPenyimpan.sidik, SidikPin.Hitung(pin, garam));
    await _penyimpan.Tulis(KunciPenyimpan.gagal, '0');
    state = state.Salin(tahap: TahapKunci.Terbuka, hapusPesan: true);
    return true;
  }

  Future<bool> _PinBenar(String pin) async {
    final garam = await _penyimpan.Baca(KunciPenyimpan.garam);
    final sidik = await _penyimpan.Baca(KunciPenyimpan.sidik);
    return garam != null && sidik != null && SidikPin.PinSah(pin) && SidikPin.Cocok(pin, garam, sidik);
  }

  /// Membuka kunci dengan PIN. Salah lima kali berturut-turut (juga lintas peluncuran aplikasi) memutus sesi.
  Future<bool> Buka(String pin) async {
    state = state.Salin(sibuk: true, hapusPesan: true);
    if (await _PinBenar(pin)) {
      await _penyimpan.Tulis(KunciPenyimpan.gagal, '0');
      state = state.Salin(tahap: TahapKunci.Terbuka, sibuk: false, hapusPesan: true);
      return true;
    }
    final gagal = (int.tryParse(await _penyimpan.Baca(KunciPenyimpan.gagal) ?? '0') ?? 0) + 1;
    if (gagal >= batasPercobaanPin) {
      state = const KeadaanKunci(tahap: TahapKunci.TidakAktif);
      await ref.read(penyediaSesi.notifier).Keluar();
      return false;
    }
    await _penyimpan.Tulis(KunciPenyimpan.gagal, '$gagal');
    state = state.Salin(
      sibuk: false,
      pesan: 'PIN salah. Sisa ${batasPercobaanPin - gagal} percobaan sebelum Anda harus masuk ulang.',
    );
    return false;
  }

  Future<bool> BukaDenganBiometrik() async {
    if (!state.biometrikAktif || state.tahap != TahapKunci.Terkunci) return false;
    if (!await ref.read(penyediaBiometrik).Autentikasi('Buka kunci Payoung Owner')) return false;
    await _penyimpan.Tulis(KunciPenyimpan.gagal, '0');
    state = state.Salin(tahap: TahapKunci.Terbuka, hapusPesan: true);
    return true;
  }

  /// Menyalakan atau mematikan sidik jari/wajah. Menyalakan butuh verifikasi biometrik sekali supaya terbukti berfungsi.
  Future<bool> AturBiometrik(bool aktif) async {
    if (aktif) {
      if (!state.pinAktif || !state.biometrikTersedia) return false;
      if (!await ref.read(penyediaBiometrik).Autentikasi('Aktifkan buka kunci dengan biometrik')) return false;
    }
    await _penyimpan.Tulis(KunciPenyimpan.biometrik, aktif ? '1' : '0');
    state = state.Salin(biometrikAktif: aktif);
    return true;
  }

  /// Mematikan kunci. Wajib PIN saat ini supaya orang yang meminjam ponsel yang sedang terbuka tidak bisa mencabutnya.
  Future<bool> Nonaktifkan(String pinSaatIni) async {
    if (!await _PinBenar(pinSaatIni)) {
      state = state.Salin(pesan: 'PIN salah.');
      return false;
    }
    for (final kunci in [KunciPenyimpan.sidik, KunciPenyimpan.garam, KunciPenyimpan.biometrik, KunciPenyimpan.gagal]) {
      await _penyimpan.Tulis(kunci, '');
    }
    state = state.Salin(tahap: TahapKunci.TidakAktif, biometrikAktif: false, hapusPesan: true);
    return true;
  }

  void KunciSekarang() {
    if (state.pinAktif) state = state.Salin(tahap: TahapKunci.Terkunci, hapusPesan: true);
  }

  Future<void> TolakTawaran() async {
    await _penyimpan.Tulis(KunciPenyimpan.tawaranDitolak, '1');
    state = state.Salin(tawaranDitolak: true);
  }

  /// Dipanggil saat aplikasi masuk latar.
  void CatatLatar() => _masukLatar = ref.read(penyediaJam)();

  /// Dipanggil saat aplikasi kembali ke depan: terkunci bila di latar lebih lama dari [jedaKunciOtomatis].
  void CatatDepan() {
    final sejak = _masukLatar;
    _masukLatar = null;
    if (sejak != null &&
        state.tahap == TahapKunci.Terbuka &&
        ref.read(penyediaJam)().difference(sejak) >= jedaKunciOtomatis) {
      KunciSekarang();
    }
  }
}

final penyediaKunci = NotifierProvider<KunciAplikasi, KeadaanKunci>(KunciAplikasi.new);
