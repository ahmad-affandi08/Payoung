import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:inti/Inti.dart';
import 'package:kasir/Domain/GalatKasir.dart';
import 'package:kasir/Domain/Struk/LayananKirimStruk.dart';
import 'package:kasir/Tampilan/RuangKerja/RuangKerja.dart';
import 'package:kasir/Tampilan/Struk/TombolKirimStruk.dart';
import 'package:sistem_desain/SistemDesain.dart';

import '../Pendukung/KatalogUji.dart';
import '../Pendukung/LingkunganUji.dart';
import '../Pendukung/PasangAplikasi.dart';

/// Struk digital lewat WhatsApp (v2.05, D-33) dari layar selesai bayar: nomor dirapikan ke 62…, dikirim ke server,
/// status antrean dipantau sampai terkirim; nomor tidak sah ditolak di perangkat; transaksi belum tersinkron diberi
/// pesan jelas.
void main() {
  group('LayananKirimStruk.RapikanTujuan', () {
    test('nomor WhatsApp Indonesia dirapikan ke 62…', () {
      expect(LayananKirimStruk.RapikanTujuan('0812-3456-7890'), '6281234567890');
      expect(LayananKirimStruk.RapikanTujuan('+62 812 3456 7890'), '6281234567890');
      expect(LayananKirimStruk.RapikanTujuan('81234567890'), '6281234567890');
      for (final salah in ['021-555-1234', '0812', 'abc', '+1 415 555 0100']) {
        expect(
          () => LayananKirimStruk.RapikanTujuan(salah),
          throwsA(isA<GalatKasir>().having((g) => g.kode, 'kode', 'TujuanTidakValid')),
          reason: salah,
        );
      }
    });

    test('D-33: struk digital hanya lewat WhatsApp. Alamat email ditolak sebagai nomor tidak sah', () {
      expect(KanalStruk.whatsapp, 'Whatsapp');
      expect(
        () => LayananKirimStruk.RapikanTujuan('siti.rahma@contoh.co.id'),
        throwsA(isA<GalatKasir>().having((g) => g.kode, 'kode', 'TujuanTidakValid')),
      );
    });
  });

  Future<LingkunganUji> BayarTunai(
    WidgetTester tester,
    Size ukuran,
    Future<http.Response> Function(http.Request p) kirim,
  ) async {
    final u = LingkunganUji.Buat();
    await tester.runAsync(() async {
      await u.SiapkanAktif();
      await u.shift.BukaShift(kasir: await u.Staf('Rina Wulandari'), kasAwal: Uang.DariBulat(500000));
    });
    u.server.penangan = (p) async {
      if (p.url.path.endsWith('/data-awal')) {
        return JsonUji(DataAwalUji());
      }
      if (p.url.path.endsWith('/katalog')) {
        return JsonUji(KatalogUji());
      }
      if (p.url.path.contains('/kirim-struk') || p.url.path.contains('/pesan-keluar/')) {
        return kirim(p);
      }
      throw http.ClientException('offline');
    };
    await PasangAplikasi(tester, u, ukuran: ukuran);
    await Tunggu(tester, const Duration(milliseconds: 600));
    await PilihKasir(tester, 'Rina Wulandari');
    await tester.pump();
    await KetikPin(tester, KasusPin(0)['Pin']! as String);
    await Tunggu(tester);
    expect(find.byType(RuangKerja), findsOneWidget);

    Future<void> Ketuk(Finder finder) async {
      await tester.ensureVisible(finder);
      await tester.pump();
      await tester.tap(finder);
      await Tunggu(tester);
    }

    await Ketuk(find.byWidgetPredicate((w) => w is UbinProduk && w.nama.startsWith('Americano')));
    await tester.sendKeyEvent(LogicalKeyboardKey.f8);
    await Tunggu(tester);
    await Ketuk(find.widgetWithText(ChoiceChip, 'Tunai'));
    await Ketuk(find.widgetWithText(FilledButton, 'Uang pas'));
    expect(find.text('Pembayaran berhasil'), findsOneWidget);
    // D-66: layar berhasil berlatar warna primer merek.
    final latarBerhasil = tester.widget<DecoratedBox>(find.byKey(const ValueKey('LayarBerhasilBayar')));
    expect((latarBerhasil.decoration as BoxDecoration).color, TokenWarna.bawaan.brand);
    await Ketuk(find.widgetWithText(OutlinedButton, 'Kirim struk'));
    expect(find.byType(DialogKirimStruk), findsOneWidget);
    return u;
  }

  for (final ukuran in const [Size(1280, 900), Size(360, 740)]) {
    testWidgets('kirim struk WhatsApp → antre → terkirim (${ukuran.width.toInt()} dp)', (tester) async {
      Map<String, Object?>? kiriman;
      String? jalurKirim;
      var cek = 0;
      final u = await BayarTunai(tester, ukuran, (p) async {
        if (p.method == 'POST') {
          jalurKirim = p.url.path;
          kiriman = jsonDecode(p.body) as Map<String, Object?>;
          return JsonUji({'Uuid': kiriman!['Uuid'], 'Status': 'Diantrekan'}, 202);
        }
        cek++;
        return JsonUji({'Uuid': kiriman!['Uuid'], 'Status': cek >= 2 ? 'Terkirim' : 'Diantrekan', 'PesanGalat': null});
      });

      await tester.enterText(find.byType(TextField).last, '0812 3456 7890');
      await tester.tap(find.widgetWithText(FilledButton, 'Kirim struk'));
      await Tunggu(tester);
      expect(kiriman!['Kanal'], 'Whatsapp');
      expect(kiriman!['Tujuan'], '6281234567890');
      final uuidPenjualan = (await tester.runAsync(() => u.db.select(u.db.penjualan).get()))!.single.Uuid;
      expect(jalurKirim, endsWith('/api/pos/v1/penjualan/$uuidPenjualan/kirim-struk'));
      expect(find.text('Struk dalam antrean pengiriman…'), findsOneWidget);

      await Tunggu(tester, const Duration(seconds: 5));
      expect(find.text('Struk terkirim.'), findsOneWidget);
      await tester.tap(find.widgetWithText(TextButton, 'Tutup'));
      await Tunggu(tester);
      expect(find.byType(DialogKirimStruk), findsNothing);
      expect(tester.takeException(), isNull);
      await Lepas(tester, u);
    });
  }

  testWidgets('D-33: tanpa pilihan Email. Nomor tidak sah ditolak di perangkat. Belum tersinkron → pesan jelas', (
    tester,
  ) async {
    var dikirim = 0;
    final u = await BayarTunai(tester, const Size(1280, 900), (p) async {
      dikirim++;
      return http.Response(
        jsonEncode({
          'Galat': {'Kode': 'PenjualanBelumTersinkron', 'Pesan': 'Penjualan belum ada di server.'},
        }),
        404,
        headers: {'content-type': 'application/json'},
      );
    });
    expect(find.text('Email'), findsNothing);
    expect(find.text('Nomor WhatsApp pelanggan'), findsOneWidget);
    await tester.enterText(find.byType(TextField).last, 'siti.rahma@contoh.co.id');
    await tester.tap(find.widgetWithText(FilledButton, 'Kirim struk'));
    await Tunggu(tester);
    expect(find.textContaining('Nomor WhatsApp tidak sah'), findsOneWidget);
    expect(dikirim, 0);

    await tester.enterText(find.byType(TextField).last, '0812 3456 7890');
    await tester.tap(find.widgetWithText(FilledButton, 'Kirim struk'));
    await Tunggu(tester);
    expect(dikirim, 1);
    expect(find.textContaining('Transaksi belum tersinkron'), findsOneWidget);
    await Lepas(tester, u);
  });
}
