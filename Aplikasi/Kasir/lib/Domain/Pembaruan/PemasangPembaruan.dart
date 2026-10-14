import 'dart:async';
import 'dart:io';

import 'package:cryptography/cryptography.dart';
import 'package:http/http.dart' as http;

import '../GalatKasir.dart';

/// Tahap pembaruan otomatis yang ditampilkan ke kasir.
enum TahapPembaruan { Mengunduh, Memeriksa, Memasang }

/// Memasang pembaruan aplikasi kasir dari dalam aplikasi (POS-23, D-154). Hanya Windows yang memasang sendiri
/// ([PemasangPembaruanWindows]); Android/iOS memperbarui lewat toko aplikasi dan hanya menampilkan tautannya.
abstract interface class PemasangPembaruan {
  /// True bila aplikasi ini bisa mengunduh dan menjalankan pemasang sendiri.
  bool get bisaPasangSendiri;

  /// Mengunduh [tautan] (wajib https), memeriksa SHA-256 [sha256] dari server, lalu menjalankan pemasang dan menutup
  /// aplikasi. Mengembalikan setelah pemasang dijalankan (aplikasi segera keluar); galat → [GalatKasir].
  Future<void> UnduhDanPasang({
    required String tautan,
    required String sha256,
    void Function(TahapPembaruan tahap, double? bagian)? saatKemajuan,
  });
}

/// Aplikasi tanpa pemasang sendiri (Android, iOS, atau desktop lain): perbarui lewat toko atau tautan unduh.
class PemasangPembaruanTidakAda implements PemasangPembaruan {
  const PemasangPembaruanTidakAda();

  @override
  bool get bisaPasangSendiri => false;

  @override
  Future<void> UnduhDanPasang({
    required String tautan,
    required String sha256,
    void Function(TahapPembaruan tahap, double? bagian)? saatKemajuan,
  }) async => throw const GalatKasir('PembaruanManual', 'Perbarui aplikasi ini lewat toko aplikasi atau tautan unduh.');
}

/// Pembaruan Windows: unduh pemasang Inno Setup (`Payoung-Kasir-Pemasang-x.y.z.exe`), periksa SHA-256, jalankan diam-diam
/// (`/VERYSILENT /CLOSEAPPLICATIONS /RESTARTAPPLICATIONS`: aplikasi ditutup, diganti, lalu dibuka lagi; basis data
/// lokal dan antrean kirim tidak disentuh). Pengaman: hanya https, SHA-256 dari server wajib cocok sebelum apa pun
/// dijalankan, ukuran dibatasi, dan berkas yang gagal diperiksa dihapus.
class PemasangPembaruanWindows implements PemasangPembaruan {
  PemasangPembaruanWindows({
    required this.klien,
    required this.folderUnduhan,
    Future<void> Function(String berkas, List<String> argumen)? jalankan,
    void Function()? keluar,
  }) : _jalankan = jalankan ?? _JalankanPemasang,
       _keluar = keluar ?? _KeluarAplikasi;

  /// Ukuran pemasang paling besar yang diterima (pemasang Flutter Windows ±30–60 MB).
  static const int batasUkuranByte = 400 * 1024 * 1024;

  static const List<String> argumenDiam = [
    '/VERYSILENT',
    '/SUPPRESSMSGBOXES',
    '/NORESTART',
    '/CLOSEAPPLICATIONS',
    '/RESTARTAPPLICATIONS',
  ];

  final http.Client klien;

  /// Folder sementara untuk berkas pemasang (dibuat bila belum ada).
  final Future<Directory> Function() folderUnduhan;
  final Future<void> Function(String berkas, List<String> argumen) _jalankan;
  final void Function() _keluar;

  @override
  bool get bisaPasangSendiri => true;

  /// Pola SHA-256 heksadesimal 64 karakter.
  static final RegExp _polaSha256 = RegExp(r'^[0-9a-fA-F]{64}$');

  @override
  Future<void> UnduhDanPasang({
    required String tautan,
    required String sha256,
    void Function(TahapPembaruan tahap, double? bagian)? saatKemajuan,
  }) async {
    final alamat = Uri.tryParse(tautan);
    if (alamat == null || alamat.scheme != 'https' || alamat.host.isEmpty) {
      throw const GalatKasir('TautanTidakAman', 'Tautan pembaruan harus alamat https. Hubungi dukungan Payoung.');
    }
    if (!_polaSha256.hasMatch(sha256)) {
      throw const GalatKasir(
        'ChecksumTidakAda',
        'Pembaruan otomatis butuh checksum dari server. Unduh manual dari tautan.',
      );
    }

    final folder = await folderUnduhan();
    await folder.create(recursive: true);
    final berkas = File('${folder.path}${Platform.pathSeparator}Payoung-Kasir-Pembaruan.exe');
    if (berkas.existsSync()) {
      await berkas.delete();
    }

    try {
      final hash = await _Unduh(alamat, berkas, saatKemajuan);
      saatKemajuan?.call(TahapPembaruan.Memeriksa, null);
      if (hash != sha256.toLowerCase()) {
        throw const GalatKasir(
          'ChecksumTidakCocok',
          'Berkas pembaruan tidak cocok dengan checksum dari server, jadi tidak dipasang. Coba lagi atau hubungi dukungan.',
        );
      }
      saatKemajuan?.call(TahapPembaruan.Memasang, null);
      await _jalankan(berkas.path, argumenDiam);
      _keluar();
    } on GalatKasir {
      await _Bersihkan(berkas);
      rethrow;
    } on SocketException {
      await _Bersihkan(berkas);
      throw const GalatKasir('PerluOnline', 'Pembaruan gagal diunduh. Periksa koneksi internet, lalu coba lagi.');
    } on http.ClientException {
      await _Bersihkan(berkas);
      throw const GalatKasir('PerluOnline', 'Pembaruan gagal diunduh. Periksa koneksi internet, lalu coba lagi.');
    } on TimeoutException {
      await _Bersihkan(berkas);
      throw const GalatKasir(
        'PerluOnline',
        'Pembaruan terlalu lama diunduh. Periksa koneksi internet, lalu coba lagi.',
      );
    }
  }

  Future<String> _Unduh(Uri alamat, File berkas, void Function(TahapPembaruan, double?)? saatKemajuan) async {
    final permintaan = http.Request('GET', alamat);
    final respons = await klien.send(permintaan).timeout(const Duration(seconds: 30));
    if (respons.statusCode != 200) {
      throw GalatKasir('UnduhGagal', 'Server pembaruan menjawab ${respons.statusCode}. Coba lagi nanti.');
    }
    final panjang = respons.contentLength;
    if (panjang != null && panjang > batasUkuranByte) {
      throw const GalatKasir(
        'UkuranTerlaluBesar',
        'Berkas pembaruan lebih besar dari batas wajar, jadi tidak dipasang.',
      );
    }

    final sink = Sha256().newHashSink();
    final keluaran = berkas.openWrite();
    var terunduh = 0;
    try {
      await for (final potongan in respons.stream.timeout(const Duration(seconds: 60))) {
        terunduh += potongan.length;
        if (terunduh > batasUkuranByte) {
          throw const GalatKasir(
            'UkuranTerlaluBesar',
            'Berkas pembaruan lebih besar dari batas wajar, jadi tidak dipasang.',
          );
        }
        sink.add(potongan);
        keluaran.add(potongan);
        saatKemajuan?.call(TahapPembaruan.Mengunduh, panjang == null || panjang == 0 ? null : terunduh / panjang);
      }
    } finally {
      await keluaran.close();
    }
    sink.close();
    final hash = await sink.hash();
    return hash.bytes.map((b) => b.toRadixString(16).padLeft(2, '0')).join();
  }

  static Future<void> _Bersihkan(File berkas) async {
    try {
      if (berkas.existsSync()) {
        await berkas.delete();
      }
    } on FileSystemException {
      // Berkas yang tak bisa dihapus tidak menghalangi pesan galat ke kasir.
    }
  }

  static Future<void> _JalankanPemasang(String berkas, List<String> argumen) async {
    await Process.start(berkas, argumen, mode: ProcessStartMode.detached);
  }

  static void _KeluarAplikasi() => exit(0);
}
