import 'dart:convert';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:inti/Inti.dart';
import 'package:kasir/Domain/Diagnostik/LogLokal.dart';
import 'package:kasir/Tampilan/LayarPengaturan.dart';
import 'package:kasir/Tampilan/RuangKerja/RuangKerja.dart';

import '../Pendukung/KatalogUji.dart';
import '../Pendukung/LingkunganUji.dart';
import '../Pendukung/PasangAplikasi.dart';

/// P-09: Pengaturan › Bantuan. Staf yang masuk membuat tiket (log perangkat terbaru ikut, sudah tersaring), melihat
/// batas respons SLA jam kerja, dan hanya tiketnya sendiri yang diminta dari server.
void main() {
  testWidgets('hubungi bantuan: tiket terkirim dengan staf, kategori, layar asal, dan log terfilter; daftar tampil', (
    tester,
  ) async {
    final u = LingkunganUji.Buat();
    final folder = Directory.systemTemp.createTempSync('bantuan-kasir-');
    addTearDown(() => folder.deleteSync(recursive: true));
    late final LogLokal log;
    late final String uuidRina;
    await tester.runAsync(() async {
      log = LogLokal(folder: folder, jam: () => u.jam);
      await u.SiapkanAktif();
      final rina = await u.Staf('Rina Wulandari');
      uuidRina = rina.uuid;
      await u.shift.BukaShift(kasir: rina, kasAwal: Uang.DariBulat(500000));
      await log.Catat(TingkatLog.galat, 'Printer', 'Gagal cetak struk untuk 0812-3456-7890');
    });
    final tiket = <Map<String, Object?>>[];
    Map<String, Object?>? badan;
    Uri? alamatDaftar;
    u.server.penangan = (p) async {
      if (p.url.path.endsWith('/data-awal')) {
        return JsonUji(DataAwalUji());
      }
      if (p.url.path.endsWith('/katalog')) {
        return JsonUji(KatalogUji());
      }
      if (p.url.path.endsWith('/bantuan/tiket') && p.method == 'POST') {
        badan = jsonDecode(p.body) as Map<String, Object?>;
        tiket.add({
          'Uuid': '01K5TIKET00000000000000001',
          'Nomor': 'TKT-2026-000012',
          'Judul': badan!['Judul'],
          'LabelKategori': 'Perangkat & printer',
          'LabelPrioritas': 'Normal',
          'Status': 'Baru',
          'LabelStatus': 'Baru',
          'DibuatPada': '2026-09-24T02:00:00Z',
          'BatasSlaPada': '2026-09-24T10:00:00Z',
          'ResponsPertamaPada': null,
          'LewatSla': false,
          'SlaJamKerja': true,
          'BisaDibalas': true,
          'Pesan': <Object?>[],
        });
        return JsonUji({'Tiket': tiket.single}, 201);
      }
      if (p.url.path.endsWith('/bantuan/tiket') && p.method == 'GET') {
        alamatDaftar = p.url;
        return JsonUji({'Tiket': tiket});
      }
      throw http.ClientException('offline');
    };
    await PasangAplikasi(tester, u, ukuran: const Size(1280, 900), logLokal: log);
    await Tunggu(tester, const Duration(milliseconds: 600));
    await PilihKasir(tester, 'Rina Wulandari');
    await tester.pump();
    await KetikPin(tester, KasusPin(0)['Pin']! as String);
    await Tunggu(tester);
    expect(find.byType(RuangKerja), findsOneWidget);

    await tester.tap(NavPengaturan().last);
    await Tunggu(tester);
    final daftarPengaturan = find.descendant(of: find.byType(LayarPengaturan), matching: find.byType(Scrollable)).first;
    Future<void> Tampakkan(Finder finder) async {
      await tester.scrollUntilVisible(finder, 300, scrollable: daftarPengaturan);
      await tester.runAsync(() => Scrollable.ensureVisible(tester.element(finder), alignment: 0.5));
      await tester.pump();
    }

    await Tampakkan(find.text('Hubungi bantuan'));
    await tester.tap(find.byKey(const ValueKey('BukaTiketBantuan')));
    await Tunggu(tester);

    await tester.tap(find.text('Kirim tiket'));
    await Tunggu(tester);
    expect(find.text('Judul minimal 5 huruf.'), findsOneWidget);
    expect(badan, isNull, reason: 'Isian kosong tidak boleh terkirim.');

    await tester.enterText(find.widgetWithText(TextField, 'Judul'), 'Printer struk tidak mencetak');
    await tester.enterText(
      find.widgetWithText(TextField, 'Apa yang terjadi?'),
      'Sejak pagi printer Bluetooth tidak mencetak.',
    );
    await tester.tap(find.text('Kirim tiket'));
    // Membaca berkas log memakai IO nyata: beri waktu nyata sampai tiket terkirim.
    for (var i = 0; i < 40 && badan == null; i++) {
      await tester.runAsync(() => Future<void>.delayed(const Duration(milliseconds: 100)));
      await Tunggu(tester);
    }

    expect(badan, isNotNull);
    expect(badan!['UuidPengguna'], uuidRina);
    expect(badan!['Kategori'], 'Perangkat');
    expect(badan!['LayarAsal'], 'Pengaturan');
    final logSingkat = (badan!['LogSingkat']! as List<Object?>).cast<String>();
    expect(
      logSingkat.single,
      contains('Gagal cetak struk untuk [disamarkan]'),
      reason: 'Log ikut dan nomor HP sudah tersamar.',
    );
    expect(logSingkat.single, isNot(contains('0812')));

    // Daftar dimuat atas permintaan: hanya tiket staf yang masuk.
    await Tampakkan(find.text('Segarkan'));
    await tester.tap(find.text('Segarkan'));
    for (var i = 0; i < 40 && find.textContaining('TKT-2026-000012').evaluate().isEmpty; i++) {
      await tester.runAsync(() => Future<void>.delayed(const Duration(milliseconds: 100)));
      await Tunggu(tester);
    }
    expect(alamatDaftar!.queryParameters['UuidPengguna'], uuidRina);
    expect(find.text('TKT-2026-000012 | Printer struk tidak mencetak'), findsOneWidget);
    expect(find.textContaining('(jam kerja)'), findsOneWidget);
    expect(tester.takeException(), isNull);
    await Lepas(tester, u);
  });
}
