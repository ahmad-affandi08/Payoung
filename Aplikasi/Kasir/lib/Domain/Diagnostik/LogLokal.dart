import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:klien_api/KlienApi.dart';

/// Tingkat entri log lokal kasir.
enum TingkatLog {
  info('Info'),
  peringatan('Peringatan'),
  galat('Galat');

  const TingkatLog(this.nilai);

  final String nilai;

  static TingkatLog Dari(String nilai) => values.firstWhere((t) => t.nilai == nilai, orElse: () => info);
}

/// Satu baris log lokal.
class EntriLog {
  const EntriLog({required this.waktu, required this.tingkat, required this.sumber, required this.pesan, this.jejak});

  /// UTC.
  final DateTime waktu;
  final TingkatLog tingkat;
  final String sumber;
  final String pesan;
  final String? jejak;

  Map<String, Object?> KeJson() => {
    'Waktu': waktu.toUtc().toIso8601String(),
    'Tingkat': tingkat.nilai,
    'Sumber': sumber,
    'Pesan': pesan,
    if (jejak != null) 'Jejak': jejak,
  };

  static EntriLog? DariBaris(String baris) {
    try {
      final json = jsonDecode(baris) as Map<String, Object?>;
      return EntriLog(
        waktu: DateTime.parse(json['Waktu']! as String).toUtc(),
        tingkat: TingkatLog.Dari(json['Tingkat']! as String),
        sumber: json['Sumber']! as String,
        pesan: json['Pesan']! as String,
        jejak: json['Jejak'] as String?,
      );
    } on Object {
      return null;
    }
  }
}

/// K-21 (§17.2.6, §20): log lokal aplikasi kasir di folder data aplikasi (`log/kasir.log`, JSON per baris), diputar
/// saat melewati [ukuranMaksimal] (satu cadangan `kasir.1.log`). Setiap pesan & jejak disaring dulu dengan [SaringPii]:
/// email, token bearer, dan deret ≥ 8 angka (HP, NIK, kartu) diganti `[disamarkan]` — PIN, token, dan data pelanggan
/// tidak boleh masuk log (aturan Flutter). Galat yang belum terkirim dikirim ke server lewat [KirimTertunda]
/// (`POST /api/pos/v1/perangkat/galat`, kanal log harian `galat-perangkat`), penanda waktu terakhir terkirim di
/// `log/terkirim.txt`.
class LogLokal {
  LogLokal({required Directory folder, DateTime Function()? jam, this.ukuranMaksimal = 512 * 1024})
    : _folder = Directory('${folder.path}${Platform.pathSeparator}log'),
      _jam = jam ?? DateTime.now;

  final Directory _folder;
  final DateTime Function() _jam;
  final int ukuranMaksimal;

  /// Penulisan berurutan agar baris tidak saling tumpang.
  Future<void>? _antrean;

  static const int batasKirim = 50;
  static const int batasUnggah = 500;
  static const int panjangPesanMaksimal = 500;
  static const int panjangJejakMaksimal = 4000;

  File get _berkas => File('${_folder.path}${Platform.pathSeparator}kasir.log');
  File get _cadangan => File('${_folder.path}${Platform.pathSeparator}kasir.1.log');
  File get _penanda => File('${_folder.path}${Platform.pathSeparator}terkirim.txt');

  /// Samarkan email, token bearer, dan deret ≥ 8 angka (boleh berspasi/strip/+).
  static String SaringPii(String teks) => teks
      .replaceAll(RegExp(r'[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}'), '[disamarkan]')
      .replaceAll(RegExp(r'Bearer\s+[A-Za-z0-9._~+/=|-]+', caseSensitive: false), 'Bearer [disamarkan]')
      .replaceAll(RegExp(r'\+?\d(?:[\s-]?\d){7,}'), '[disamarkan]');

  static String _Potong(String teks, int batas) => teks.length <= batas ? teks : teks.substring(0, batas);

  Future<void> Catat(TingkatLog tingkat, String sumber, Object pesan, {StackTrace? jejak}) {
    final entri = EntriLog(
      waktu: _jam().toUtc(),
      tingkat: tingkat,
      sumber: _Potong(sumber, 60),
      pesan: _Potong(SaringPii('$pesan'), panjangPesanMaksimal),
      jejak: jejak == null ? null : _Potong(SaringPii('$jejak'), panjangJejakMaksimal),
    );
    return _antrean = (_antrean ?? Future<void>.value()).then((_) => _Tulis(entri)).catchError((Object _) {});
  }

  Future<void> _Tulis(EntriLog entri) async {
    await _folder.create(recursive: true);
    if (await _berkas.exists() && await _berkas.length() > ukuranMaksimal) {
      if (await _cadangan.exists()) {
        await _cadangan.delete();
      }
      await _berkas.rename(_cadangan.path);
    }
    await _berkas.writeAsString('${jsonEncode(entri.KeJson())}\n', mode: FileMode.append, flush: true);
  }

  /// Entri terbaru dulu, dari berkas aktif lalu cadangan.
  Future<List<EntriLog>> AmbilTerbaru({int batas = 100}) async {
    await (_antrean ?? Future<void>.value());
    final hasil = <EntriLog>[];
    for (final berkas in [_berkas, _cadangan]) {
      if (!await berkas.exists()) {
        continue;
      }
      final baris = await berkas.readAsLines();
      for (final b in baris.reversed) {
        final entri = EntriLog.DariBaris(b);
        if (entri != null) {
          hasil.add(entri);
          if (hasil.length >= batas) {
            return hasil;
          }
        }
      }
    }
    return hasil;
  }

  Future<DateTime?> _AmbilPenanda() async {
    if (!await _penanda.exists()) {
      return null;
    }
    return DateTime.tryParse((await _penanda.readAsString()).trim())?.toUtc();
  }

  /// Galat & peringatan yang belum terkirim, terlama dulu (maks. [batasKirim]).
  Future<List<EntriLog>> AmbilBelumTerkirim() async {
    final penanda = await _AmbilPenanda();
    return (await AmbilTerbaru(batas: 1000))
        .where((e) => e.tingkat != TingkatLog.info && (penanda == null || e.waktu.isAfter(penanda)))
        .toList()
        .reversed
        .take(batasKirim)
        .toList();
  }

  /// PGL-16 bagian 2: unggah log lokal (semua tingkat, terlama dulu, maks. [batasUnggah] baris) atas permintaan dukungan.
  /// Hanya dipanggil bila `konfigurasi-aplikasi` menandai `PermintaanLog.Diminta`. Mengembalikan jumlah baris yang
  /// diterima server; offline = 0 (dicoba lagi pada pemeriksaan berikutnya). Server menjawab 409 `LogTidakDiminta` bila
  /// permintaan sudah terpenuhi (misal dari pemeriksaan lain): dianggap selesai.
  Future<int> UnggahAtasPermintaan(KlienPos klien) async {
    final entri = (await AmbilTerbaru(batas: batasUnggah)).reversed.toList();
    final baris = entri.isEmpty
        ? ['${_jam().toUtc().toIso8601String()} [Info] LogLokal: log lokal masih kosong.']
        : [for (final e in entri) _FormatBaris(e)];
    try {
      return await klien.UnggahLog(baris);
    } on GalatJaringan {
      return 0;
    } on GalatApi {
      return 0;
    }
  }

  static String _FormatBaris(EntriLog e) {
    final jejak = e.jejak == null ? '' : ' | ${e.jejak!.split('\n').first}';
    return SaringPii('${e.waktu.toIso8601String()} [${e.tingkat.nilai}] ${e.sumber}: ${e.pesan}$jejak');
  }

  /// Kirim galat tertunda ke server. Mengembalikan jumlah terkirim; offline/galat server = 0 (dicoba lagi nanti).
  Future<int> KirimTertunda(KlienPos klien) async {
    final daftar = await AmbilBelumTerkirim();
    if (daftar.isEmpty) {
      return 0;
    }
    try {
      await klien.LaporGalat([for (final e in daftar) e.KeJson()]);
    } on GalatJaringan {
      return 0;
    } on GalatApi {
      return 0;
    }
    await _folder.create(recursive: true);
    await _penanda.writeAsString(daftar.last.waktu.toIso8601String(), flush: true);
    return daftar.length;
  }
}
