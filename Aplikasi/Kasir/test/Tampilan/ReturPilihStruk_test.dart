import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:inti/Inti.dart';
import 'package:kasir/Domain/Perangkat/PemindaiQr.dart';
import 'package:kasir/Tampilan/Penjualan/LembarRetur.dart';
import 'package:kasir/Tampilan/RuangKerja/RuangKerja.dart';

import '../Pendukung/KatalogUji.dart';
import '../Pendukung/LingkunganUji.dart';
import '../Pendukung/PasangAplikasi.dart';
import '../Pendukung/StrukUji.dart';

/// Retur dari struk tanpa mengetik nomor utuh: pilih dari transaksi terbaru, ketik sebagian nomor, atau pindai QR struk
/// digital (isi QR = tautan `/s/{kode}`, bukan nomor struk).
void main() {
  const tautanQr = 'https://dashboard.payoung.id/s/1a.${UuidStruk.penjualan}';

  Future<({LingkunganUji u, List<Uri> cari, List<Uri> kandidat})> Masuk(
    WidgetTester tester, {
    PemindaiQr? pemindai,
    bool cariHanyaNomorUtuh = false,
  }) async {
    final u = LingkunganUji.Buat();
    final dataAwal = DataAwalUji();
    final cari = <Uri>[];
    final kandidat = <Uri>[];
    await tester.runAsync(() async {
      await u.SiapkanAktif(dataAwal: dataAwal);
      await u.SiapkanKatalog();
      await u.shift.BukaShift(kasir: await u.Staf('Rina Wulandari'), kasAwal: Uang.DariBulat(500000));
    });
    u.server.penangan = (p) async {
      if (p.url.path.endsWith('/penjualan/kandidat')) {
        kandidat.add(p.url);
        return JsonUji({
          'Penjualan': [
            {
              'Uuid': UuidStruk.penjualan,
              'Nomor': UuidStruk.nomor,
              'Status': 'Lunas',
              'LabelStatus': 'Lunas',
              'TanggalBisnis': '2026-10-04',
              'DibuatPada': '2026-10-04T03:15:00Z',
              'TotalAkhir': '287500.00',
            },
          ],
        });
      }
      if (p.url.path.endsWith('/penjualan/cari')) {
        cari.add(p.url);
        if (cariHanyaNomorUtuh && p.url.queryParameters['nomor'] != UuidStruk.nomor) {
          return JsonUji({
            'Galat': {'Kode': 'PenjualanTidakDitemukan', 'Pesan': 'Penjualan dengan nomor ini tidak ditemukan.'},
          }, 404);
        }
        return JsonUji(StrukUji());
      }
      if (p.url.path.endsWith('/data-awal')) {
        return JsonUji(dataAwal);
      }
      if (p.url.path.endsWith('/katalog')) {
        return JsonUji(KatalogUji());
      }
      throw http.ClientException('offline');
    };
    await PasangAplikasi(tester, u, pemindaiQr: pemindai);
    await Tunggu(tester, const Duration(milliseconds: 600));
    await PilihKasir(tester, 'Rina Wulandari');
    await tester.pump();
    await KetikPin(tester, KasusPin(0)['Pin']! as String);
    await Tunggu(tester);
    expect(find.byType(RuangKerja), findsOneWidget);
    await tester.tap(find.text('Riwayat').last);
    await Tunggu(tester);
    await tester.ensureVisible(find.widgetWithText(OutlinedButton, 'Retur dari struk'));
    await tester.tap(find.widgetWithText(OutlinedButton, 'Retur dari struk'));
    await Tunggu(tester);
    expect(find.byType(LembarRetur), findsOneWidget);
    return (u: u, cari: cari, kandidat: kandidat);
  }

  testWidgets('daftar transaksi terbaru tampil sendiri. Ketuk satu langsung membuka struknya', (tester) async {
    final (:u, :cari, :kandidat) = await Masuk(tester);
    expect(kandidat, isNotEmpty, reason: 'Daftar terbaru dimuat saat lembar retur dibuka.');
    expect(kandidat.first.queryParameters['kata'], '');
    expect(find.text('Transaksi terbaru'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('KandidatRetur:${UuidStruk.penjualan}')));
    await Tunggu(tester);

    expect(cari.single.queryParameters['nomor'], UuidStruk.nomor);
    expect(find.byKey(const ValueKey('JumlahRetur-${UuidStruk.kopiLiter}')), findsOneWidget);
    expect(tester.takeException(), isNull);
    await Lepas(tester, u);
  });

  testWidgets('mengetik sebagian nomor memuat pilihan yang cocok (tanpa nomor utuh)', (tester) async {
    final (:u, :cari, :kandidat) = await Masuk(tester);

    await tester.enterText(find.widgetWithText(TextField, 'Nomor struk'), '0007');
    await tester.pump(const Duration(milliseconds: 500));
    await Tunggu(tester);

    expect(kandidat.last.queryParameters['kata'], '0007');
    expect(find.text('Pilih struk'), findsOneWidget);
    expect(cari, isEmpty, reason: 'Sebagian nomor hanya memfilter pilihan, belum membuka struk.');
    await Lepas(tester, u);
  });

  testWidgets('pindai QR struk digital: isi tautan dikirim sebagai pencarian dan struk terbuka', (tester) async {
    final pemindai = PemindaiQrTiruan(hasil: tautanQr);
    final (:u, :cari, kandidat: _) = await Masuk(tester, pemindai: pemindai);

    await tester.tap(find.byKey(const ValueKey('PindaiStrukRetur')));
    await Tunggu(tester);

    expect(pemindai.dipanggil, 1);
    expect(cari.single.queryParameters['nomor'], tautanQr);
    expect(find.byKey(const ValueKey('JumlahRetur-${UuidStruk.kopiLiter}')), findsOneWidget);
    expect(tester.takeException(), isNull);
    await Lepas(tester, u);
  });

  testWidgets('tanpa kamera (Windows): tombol pindai tidak muncul, ketik & daftar pilihan tetap bisa', (tester) async {
    final (:u, cari: _, kandidat: _) = await Masuk(tester);
    expect(find.byKey(const ValueKey('PindaiStrukRetur')), findsNothing);
    expect(find.text('Transaksi terbaru'), findsOneWidget);
    await Lepas(tester, u);
  });

  testWidgets('4 angka terakhir lalu Cari struk: satu yang cocok langsung terbuka (tanpa nomor utuh)', (tester) async {
    final (:u, :cari, kandidat: _) = await Masuk(tester, cariHanyaNomorUtuh: true);

    await tester.enterText(find.widgetWithText(TextField, 'Nomor struk'), '0007');
    await tester.tap(find.widgetWithText(OutlinedButton, 'Cari struk'));
    await Tunggu(tester);

    expect(cari.map((u) => u.queryParameters['nomor']), ['0007', UuidStruk.nomor]);
    expect(find.byKey(const ValueKey('JumlahRetur-${UuidStruk.kopiLiter}')), findsOneWidget);
    expect(tester.takeException(), isNull);
    await Lepas(tester, u);
  });
}
