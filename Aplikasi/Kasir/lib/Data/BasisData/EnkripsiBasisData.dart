import 'dart:io';
import 'dart:math';

import 'package:drift/drift.dart';
import 'package:drift/native.dart';
import 'package:sqlite3/sqlite3.dart';

import '../PenyimpanRahasia.dart';

/// K-7 (§17.2.6): basis data lokal kasir dienkripsi SQLite3 Multiple Ciphers (pustaka `sqlite3mc`, dipilih di
/// `hooks.user_defines` pubspec akar) dengan kunci acak 256 bit yang hanya ada di secure storage. Basis data lama yang
/// masih polos dienkripsi di tempat saat aplikasi dibuka pertama kali setelah pembaruan, tanpa memindahkan baris apa
/// pun, sehingga outbox yang belum terkirim tetap utuh (§17.2, aturan Flutter).
class EnkripsiBasisData {
  /// Header berkas SQLite yang tidak terenkripsi.
  static const String headerPolos = 'SQLite format 3\u0000';

  /// Kunci baru: 32 byte acak (`Random.secure`) dalam heksadesimal.
  static String BuatKunci([Random? acak]) {
    final sumber = acak ?? Random.secure();
    return [for (var i = 0; i < 32; i++) sumber.nextInt(256).toRadixString(16).padLeft(2, '0')].join();
  }

  /// Kunci yang tersimpan, atau kunci baru yang langsung disimpan. Kunci ini sengaja tidak ikut terhapus saat perangkat
  /// dicabut (`PenyimpanRahasia.HapusSemua`), karena outbox yang belum terkirim tetap harus bisa dibaca.
  static Future<String> AmbilAtauBuatKunci(PenyimpanRahasia rahasia) async {
    final ada = await rahasia.Baca(PenyimpanRahasia.kunciBasisData);
    if (ada != null && ada.isNotEmpty) {
      return ada;
    }
    final baru = BuatKunci();
    await rahasia.Tulis(PenyimpanRahasia.kunciBasisData, baru);
    return baru;
  }

  /// Berkas ada dan masih polos (header SQLite terbaca apa adanya).
  static bool CekPolos(File berkas) {
    if (!berkas.existsSync() || berkas.lengthSync() < headerPolos.length) {
      return false;
    }
    final awal = berkas.openSync();
    try {
      return String.fromCharCodes(awal.readSync(headerPolos.length)) == headerPolos;
    } finally {
      awal.closeSync();
    }
  }

  /// Enkripsi berkas lama yang masih polos di tempat (`PRAGMA rekey` pada basis data tanpa kunci). Tidak melakukan apa
  /// pun untuk berkas yang belum ada atau sudah terenkripsi.
  static void EnkripsiBerkasPolos(File berkas, String kunci) {
    if (!CekPolos(berkas)) {
      return;
    }
    final db = sqlite3.open(berkas.path);
    try {
      PastikanPustakaEnkripsi(db);
      db.execute("PRAGMA rekey = '${_Kutip(kunci)}'");
    } finally {
      db.close();
    }
  }

  /// Pasang kunci pada koneksi baru, lalu baca skema sekali: kunci salah langsung gagal di sini ("file is not a
  /// database"), bukan di kueri pertama aplikasi.
  static void PasangKunci(Database db, String kunci) {
    PastikanPustakaEnkripsi(db);
    db.execute("PRAGMA key = '${_Kutip(kunci)}'");
    db.select('SELECT count(*) FROM sqlite_master');
  }

  /// SQLite biasa mengabaikan `PRAGMA key` tanpa galat, jadi berkas akan tetap polos tanpa ada yang tahu. Pustaka
  /// SQLite3 Multiple Ciphers menjawab `PRAGMA cipher`; tanpa jawaban itu aplikasi menolak membuka basis data.
  static void PastikanPustakaEnkripsi(Database db) {
    final hasil = db.select('PRAGMA cipher');
    if (hasil.isEmpty) {
      throw StateError('Pustaka SQLite tanpa dukungan enkripsi. Basis data kasir tidak dibuka.');
    }
  }

  /// Basis data kasir terenkripsi di [berkas]: berkas polos lama dienkripsi dulu, lalu dibuka di isolate latar.
  static QueryExecutor Buka(File berkas, String kunci) {
    EnkripsiBerkasPolos(berkas, kunci);
    return NativeDatabase.createInBackground(berkas, setup: (db) => PasangKunci(db, kunci));
  }

  static String _Kutip(String nilai) => nilai.replaceAll("'", "''");
}
