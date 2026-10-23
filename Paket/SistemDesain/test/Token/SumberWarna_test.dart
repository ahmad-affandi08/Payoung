import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:sistem_desain/SistemDesain.dart';

/// Penjaga satu sumber warna & tanpa mode gelap (PRD §17.6, D-14).
///
/// - Warna hanya boleh ditulis di `Paket/SistemDesain/lib/Token/TokenWarna.dart`.
///   Di luar file itu dilarang `Color(0x...)`, `Color.fromARGB/fromRGBO`, `Colors.*`, dan `CupertinoColors.*`.
///   Pengecualian satu-satunya: `Colors.transparent`, karena "tanpa warna" bukan pilihan palet.
/// - Tidak ada mode gelap: dilarang `darkTheme`, `Brightness.dark`, `ThemeMode.dark`, `ThemeMode.system`,
///   dan `platformBrightness` di kode `lib/`.
/// - Nilai token sama dengan `--color-*` di `Aplikasi/Web/resources/js/Gaya/Aplikasi.css`.
void main() {
  final akar = CariAkarRepo();
  const fileToken = 'Paket/SistemDesain/lib/Token/TokenWarna.dart';

  final polaWarnaLepas = <RegExp>[
    RegExp(r'\bColor\s*\(\s*0x'),
    RegExp(r'\bColor\.from(ARGB|RGBO)\s*\('),
    RegExp(r'(?<![A-Za-z])Colors\.(?!transparent\b)\w+'),
    RegExp(r'\bCupertinoColors\.\w+'),
  ];
  final polaModeGelap = <RegExp>[
    RegExp(r'\bdarkTheme\b'),
    RegExp(r'\bBrightness\.dark\b'),
    RegExp(r'\bThemeMode\.(dark|system)\b'),
    RegExp(r'\bplatformBrightness\b'),
  ];

  test('pola penjaga mengenali pelanggaran dan pengecualian', () {
    bool Melanggar(String baris) => polaWarnaLepas.any((p) => p.hasMatch(baris));
    expect(Melanggar('color: Color(0xFF000000),'), isTrue);
    expect(Melanggar('color: Color.fromARGB(255, 0, 0, 0),'), isTrue);
    expect(Melanggar('color: Colors.red,'), isTrue);
    expect(Melanggar('color: CupertinoColors.systemBlue,'), isTrue);
    expect(Melanggar('color: Colors.transparent,'), isFalse);
    expect(Melanggar('color: TokenWarna.AmbilDari(context).brand,'), isFalse);
    expect(polaModeGelap.any((p) => p.hasMatch('darkTheme: BuatTema(),')), isTrue);
    expect(polaModeGelap.any((p) => p.hasMatch('themeMode: ThemeMode.system,')), isTrue);
    expect(polaModeGelap.any((p) => p.hasMatch('themeMode: ThemeMode.light,')), isFalse);
  });

  test('tidak ada warna lepas di luar TokenWarna.dart', () {
    final berkas = AmbilBerkasDartLib(akar);
    expect(berkas, isNotEmpty, reason: 'Pemindai tidak menemukan file Dart di Aplikasi/*/lib dan Paket/*/lib.');
    expect(berkas.map((b) => RelatifKeAkar(akar, b)), contains(fileToken));
    final pelanggaran = CariPelanggaran(akar, berkas.where((b) => RelatifKeAkar(akar, b) != fileToken), polaWarnaLepas);
    expect(
      pelanggaran,
      isEmpty,
      reason: 'Pindahkan warna ke $fileToken dan pakai tokennya:\n${pelanggaran.join('\n')}',
    );
  });

  test('tidak ada mode gelap di kode aplikasi dan paket (D-14)', () {
    final pelanggaran = CariPelanggaran(akar, AmbilBerkasDartLib(akar), polaModeGelap);
    expect(pelanggaran, isEmpty, reason: 'Mode gelap tidak didukung (D-14):\n${pelanggaran.join('\n')}');
  });

  test('token warna sama dengan palet web di Aplikasi.css', () {
    final css = File('${akar.path}/Aplikasi/Web/resources/js/Gaya/Aplikasi.css').readAsStringSync();
    final warnaWeb = <String, int>{
      for (final m in RegExp(r'--color-([a-z-]+):\s*#([0-9a-fA-F]{6})\s*;').allMatches(css))
        m.group(1)!: 0xFF000000 | int.parse(m.group(2)!, radix: 16),
    };
    const t = TokenWarna.bawaan;
    final warnaFlutter = <String, int>{
      'latar': t.latar.toARGB32(),
      'permukaan': t.permukaan.toARGB32(),
      'garis': t.garis.toARGB32(),
      'garis-input': t.garisInput.toARGB32(),
      'teks-utama': t.teksUtama.toARGB32(),
      'teks-sekunder': t.teksSekunder.toARGB32(),
      'brand': t.brand.toARGB32(),
      'brand-gelap': t.brandGelap.toARGB32(),
      'sukses': t.sukses.toARGB32(),
      'peringatan': t.peringatan.toARGB32(),
      'bahaya': t.bahaya.toARGB32(),
      'info': t.info.toARGB32(),
      'aksen': t.aksen.toARGB32(),
      'brand-tint': t.brandTint.toARGB32(),
      'ubin': t.ubin.toARGB32(),
    };
    expect(warnaWeb.keys.toSet(), warnaFlutter.keys.toSet(), reason: 'Nama token web dan Flutter harus sama.');
    for (final entri in warnaFlutter.entries) {
      expect(
        warnaWeb[entri.key],
        entri.value,
        reason: '--color-${entri.key} di Aplikasi.css berbeda dengan TokenWarna.bawaan.',
      );
    }
  });
}

/// Naik dari direktori kerja test sampai menemukan akar repo (berisi `Aplikasi/` dan `Paket/`).
Directory CariAkarRepo() {
  var dir = Directory.current.absolute;
  while (true) {
    if (Directory('${dir.path}/Aplikasi').existsSync() && Directory('${dir.path}/Paket').existsSync()) {
      return dir;
    }
    final induk = dir.parent;
    if (induk.path == dir.path) {
      throw StateError('Akar repo tidak ditemukan dari ${Directory.current.path}.');
    }
    dir = induk;
  }
}

/// Semua file `.dart` di `Aplikasi/*/lib` dan `Paket/*/lib`.
List<File> AmbilBerkasDartLib(Directory akar) {
  final hasil = <File>[];
  for (final grup in ['Aplikasi', 'Paket']) {
    for (final proyek in Directory('${akar.path}/$grup').listSync().whereType<Directory>()) {
      final lib = Directory('${proyek.path}/lib');
      if (!lib.existsSync()) {
        continue;
      }
      hasil.addAll(lib.listSync(recursive: true).whereType<File>().where((f) => f.path.endsWith('.dart')));
    }
  }
  return hasil;
}

/// Path file relatif ke akar repo dengan pemisah `/`.
String RelatifKeAkar(Directory akar, File berkas) =>
    berkas.absolute.path.substring(akar.path.length + 1).replaceAll(r'\', '/');

/// Baris yang cocok dengan salah satu pola, di luar baris komentar `//`.
List<String> CariPelanggaran(Directory akar, Iterable<File> berkas, List<RegExp> pola) {
  final hasil = <String>[];
  for (final file in berkas) {
    final baris = file.readAsLinesSync();
    for (var i = 0; i < baris.length; i++) {
      final isi = baris[i].trimLeft();
      if (isi.startsWith('//')) {
        continue;
      }
      if (pola.any((p) => p.hasMatch(isi))) {
        hasil.add('${RelatifKeAkar(akar, file)}:${i + 1}: ${isi.trim()}');
      }
    }
  }
  return hasil;
}
