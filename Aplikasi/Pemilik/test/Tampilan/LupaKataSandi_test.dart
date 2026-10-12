import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:pemilik/Aplikasi/Lingkungan.dart';
import 'package:pemilik/Data/PenyimpanSesi.dart';

import '../Pendukung/PasangPemilik.dart';

/// OWN-01: Lupa kata sandi di layar masuk Aplikasi Owner. Tautan atur ulang dikirim server ke email; jawaban server
/// (sama untuk email terdaftar atau tidak) tampil apa adanya, dan galat batas permintaan ikut tampil.
void main() {
  late ServerTiruan server;
  late PenyimpanSesiMemori sesi;
  var statusLupa = 200;

  setUp(() {
    server = ServerTiruan();
    sesi = PenyimpanSesiMemori();
    statusLupa = 200;
    server.penangan = (http.Request p) async {
      final jalur = p.url.path.replaceFirst('/api/pemilik/v1/', '');
      return switch (jalur) {
        'masuk/google/konfigurasi' => JsonUji({'Aktif': false, 'ClientId': null}),
        'lupa-kata-sandi' when statusLupa == 429 => JsonUji({
          'Galat': {'Kode': 'TerlaluBanyakPercobaan', 'Pesan': 'Terlalu banyak permintaan dari jaringan ini.'},
        }, 429),
        'lupa-kata-sandi' => JsonUji({'Pesan': 'Jika email tersebut terdaftar, tautan sudah kami kirim.'}),
        _ => JsonUji({}, 404),
      };
    };
  });

  testWidgets('email diisi: permintaan dikirim ke server dan jawabannya tampil', (tester) async {
    await PasangPemilik(tester, server: server, sesi: sesi, lingkungan: Lingkungan.Dev);

    await tester.enterText(find.widgetWithText(TextField, 'Email'), '  sari@contoh.id ');
    await tester.tap(find.byKey(const ValueKey('LupaKataSandi')));
    await tester.pumpAndSettle();

    final kirim = server.permintaan.singleWhere((p) => p.url.path.endsWith('/lupa-kata-sandi'));
    expect((jsonDecode(kirim.body) as Map<String, Object?>)['Email'], 'sari@contoh.id');
    expect(find.text('Jika email tersebut terdaftar, tautan sudah kami kirim.'), findsOneWidget);
  });

  testWidgets('email kosong: tidak ada permintaan, pengguna diminta mengisi email dulu', (tester) async {
    await PasangPemilik(tester, server: server, sesi: sesi, lingkungan: Lingkungan.Dev);

    await tester.tap(find.byKey(const ValueKey('LupaKataSandi')));
    await tester.pumpAndSettle();

    expect(server.permintaan.where((p) => p.url.path.endsWith('/lupa-kata-sandi')), isEmpty);
    expect(find.text('Isi email akun Anda dulu, lalu ketuk Lupa kata sandi.'), findsOneWidget);
  });

  testWidgets('terlalu banyak permintaan: pesan galat server tampil', (tester) async {
    statusLupa = 429;
    await PasangPemilik(tester, server: server, sesi: sesi, lingkungan: Lingkungan.Dev);

    await tester.enterText(find.widgetWithText(TextField, 'Email'), 'sari@contoh.id');
    await tester.tap(find.byKey(const ValueKey('LupaKataSandi')));
    await tester.pumpAndSettle();

    expect(find.text('Terlalu banyak permintaan dari jaringan ini.'), findsOneWidget);
  });
}
