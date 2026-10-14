import 'dart:convert';
import 'dart:math';

import 'package:crypto/crypto.dart';
import 'package:flutter/services.dart' show PlatformException;
import 'package:local_auth/local_auth.dart';

/// Biometrik perangkat (sidik jari, wajah) untuk membuka kunci aplikasi Owner (OWN-01). Dipisah dari kunci PIN supaya
/// bisa ditiru di test dan karena hanya PIN yang menjadi sumber kebenaran: biometrik hanya jalan pintas membukanya.
abstract class PenyediaBiometrik {
  /// true bila perangkat punya biometrik yang sudah didaftarkan pemiliknya.
  Future<bool> Tersedia();

  /// Meminta pemilik perangkat memverifikasi diri. false bila dibatalkan, gagal, atau tidak tersedia.
  Future<bool> Autentikasi(String alasan);
}

class PenyediaBiometrikPerangkat implements PenyediaBiometrik {
  PenyediaBiometrikPerangkat([LocalAuthentication? autentikasi]) : _autentikasi = autentikasi ?? LocalAuthentication();

  final LocalAuthentication _autentikasi;

  @override
  Future<bool> Tersedia() async {
    try {
      if (!await _autentikasi.canCheckBiometrics) return false;
      return (await _autentikasi.getAvailableBiometrics()).isNotEmpty;
    } on PlatformException {
      return false;
    }
  }

  @override
  Future<bool> Autentikasi(String alasan) async {
    try {
      return await _autentikasi.authenticate(
        localizedReason: alasan,
        biometricOnly: true,
        persistAcrossBackgrounding: true,
      );
    } on LocalAuthException {
      return false;
    } on PlatformException {
      return false;
    }
  }
}

/// Tanpa biometrik (test, pratinjau, perangkat tanpa sensor).
class PenyediaBiometrikTidakAda implements PenyediaBiometrik {
  const PenyediaBiometrikTidakAda();

  @override
  Future<bool> Tersedia() async => false;

  @override
  Future<bool> Autentikasi(String alasan) async => false;
}

/// Sidik PIN kunci aplikasi: PBKDF2-HMAC-SHA256 dengan garam acak per perangkat. PIN tidak pernah disimpan atau dicatat.
/// Sidiknya hanya disimpan di secure storage; pembatasan percobaan ada di `KunciAplikasi`.
abstract class SidikPin {
  static const int iterasi = 10000;
  static const int panjangPin = 6;

  static final RegExp _pola = RegExp(r'^\d{6}$');

  static bool PinSah(String pin) => _pola.hasMatch(pin);

  static String BuatGaram([Random? acak]) {
    final sumber = acak ?? Random.secure();
    return base64Url.encode(List<int>.generate(16, (_) => sumber.nextInt(256)));
  }

  static String Hitung(String pin, String garam) {
    final hmac = Hmac(sha256, utf8.encode(pin));
    final saltBlok = <int>[...base64Url.decode(garam), 0, 0, 0, 1];
    var u = hmac.convert(saltBlok).bytes;
    final hasil = List<int>.of(u);
    for (var i = 1; i < iterasi; i++) {
      u = hmac.convert(u).bytes;
      for (var j = 0; j < hasil.length; j++) {
        hasil[j] ^= u[j];
      }
    }
    return base64Url.encode(hasil);
  }

  /// Perbandingan waktu-tetap supaya lamanya cek tidak membocorkan digit yang benar.
  static bool Cocok(String pin, String garam, String sidikTersimpan) {
    final sidik = Hitung(pin, garam);
    if (sidik.length != sidikTersimpan.length) return false;
    var beda = 0;
    for (var i = 0; i < sidik.length; i++) {
      beda |= sidik.codeUnitAt(i) ^ sidikTersimpan.codeUnitAt(i);
    }
    return beda == 0;
  }
}
