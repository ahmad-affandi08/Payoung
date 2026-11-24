import 'dart:async';
import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:inti/Inti.dart';
import 'package:kasir/Data/LayarPelanggan/ModulQr.dart';
import 'package:kasir/Domain/Perangkat/LayananLayarPelanggan.dart';
import 'package:kasir/Tampilan/Jual/DialogQrisDinamis.dart';
import 'package:kasir/Tampilan/RuangKerja/RuangKerja.dart';
import 'package:sistem_desain/SistemDesain.dart';

import '../Pendukung/LingkunganUji.dart';
import '../Pendukung/MuatFont.dart';
import '../Pendukung/KatalogUji.dart';
import '../Pendukung/PasangAplikasi.dart';

/// QRIS dinamis (v2.05) di panel Bayar: tagihan dibuat di server, QR ditampilkan, status dipantau sampai lunas lalu
/// penjualan tersimpan dengan referensi Uuid tagihan. Batal → tagihan dibatalkan di server, tidak ada penjualan.
/// Offline → pesan jelas dan saran metode lain.
void main() {
  // Font sungguhan: lebar teks Lato menentukan apakah dialog muat tanpa digulir (font pengganti test jauh lebih lebar).
  setUpAll(MuatFontMerek);

  Future<LingkunganUji> MasukJual(
    WidgetTester tester,
    Size ukuran,
    Future<http.Response> Function(http.Request p)? qris, {
    bool layarKedua = false,
  }) async {
    final u = LingkunganUji.Buat();
    final dataAwal = DataAwalUji(qrisDinamis: true);
    await tester.runAsync(() async {
      await u.SiapkanAktif(dataAwal: dataAwal);
      if (layarKedua) {
        await const PengaturanLayarPelanggan(mode: ModeLayarPelanggan.layarKedua).Simpan(u.repositori);
      }
      await u.shift.BukaShift(kasir: await u.Staf('Rina Wulandari'), kasAwal: Uang.DariBulat(500000));
    });
    u.server.penangan = (p) async {
      if (p.url.path.endsWith('/data-awal')) {
        return JsonUji(dataAwal);
      }
      if (p.url.path.endsWith('/katalog')) {
        return JsonUji(KatalogUji());
      }
      if (qris != null && p.url.path.contains('/api/pos/v1/qris')) {
        return qris(p);
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
    return u;
  }

  Future<void> Ketuk(WidgetTester tester, Finder finder) async {
    await tester.ensureVisible(finder);
    await tester.pump();
    await tester.tap(finder);
    await Tunggu(tester);
  }

  Future<void> BukaBayarQris(WidgetTester tester, Size ukuran) async {
    await Ketuk(tester, find.byWidgetPredicate((w) => w is UbinProduk && w.nama.startsWith('Americano')));
    await tester.sendKeyEvent(LogicalKeyboardKey.f8);
    await Tunggu(tester);
    await Ketuk(tester, find.widgetWithText(ChoiceChip, 'QRIS Dinamis'));
    await Ketuk(tester, find.widgetWithText(FilledButton, 'Selesaikan pembayaran'));
  }

  Future<List<Map<String, Object?>>> AmbilOutboxPenjualan(WidgetTester tester, LingkunganUji u) async {
    final baris = await tester.runAsync(() => u.db.select(u.db.outbox).get());
    return [
      for (final b in baris!.where((b) => b.Jenis == 'Penjualan.Buat')) jsonDecode(b.Data) as Map<String, Object?>,
    ];
  }

  http.Response Tagihan(Map<String, Object?> kiriman, {String status = 'Menunggu'}) => JsonUji({
    'Uuid': kiriman['Uuid'],
    'NomorPesanan': 'PAYOUNG-QR-0001',
    'IsiQr': '00020101021226620014ID.CO.QRIS.WWW0118936000000000000001520458125303360540${kiriman['Jumlah']}6304ABCD',
    'HalamanBayar': false,
    'KedaluwarsaPada': DateTime.now().toUtc().add(const Duration(minutes: 15)).toIso8601String(),
    'Status': status,
    'Jumlah': kiriman['Jumlah'],
  }, 201);

  for (final ukuran in const [Size(1280, 900), Size(360, 740)]) {
    testWidgets('QRIS dinamis: QR tampil → status lunas → penjualan tersimpan ber-referensi tagihan '
        '(${ukuran.width.toInt()} dp)', (tester) async {
      Map<String, Object?>? dibuat;
      var cek = 0;
      final u = await MasukJual(tester, ukuran, (p) async {
        if (p.method == 'POST' && p.url.path.endsWith('/qris')) {
          dibuat = jsonDecode(p.body) as Map<String, Object?>;
          return Tagihan(dibuat!);
        }
        cek++;
        return JsonUji({'Uuid': dibuat!['Uuid'], 'Status': cek >= 2 ? 'Lunas' : 'Menunggu', 'LunasPada': null});
      }, layarKedua: true);
      await BukaBayarQris(tester, ukuran);

      expect(find.byType(DialogQrisDinamis), findsOneWidget);
      expect(dibuat!['UuidMetode'], '01K5MTD0000000000000000008');
      expect(dibuat!.containsKey('Keterangan'), isFalse, reason: 'Tidak ada data pelanggan ke gerbang pembayaran.');
      final jumlah = dibuat!['Jumlah']! as String;
      expect(find.bySemanticsLabel(RegExp(r'^Kode QRIS Rp ')), findsOneWidget);
      expect(find.text('Menunggu pembayaran'), findsOneWidget);
      expect(find.textContaining('Berlaku '), findsOneWidget);
      // Layar pelanggan ikut menampilkan QR yang sama.
      expect(u.layarPelanggan.isi.last.dataQr, contains('ID.CO.QRIS.WWW'));
      expect(u.layarPelanggan.isi.last.pesan, 'Pindai QRIS untuk membayar');

      await Tunggu(tester, const Duration(seconds: 5));
      expect(find.byType(DialogQrisDinamis), findsNothing);
      expect(find.text('Pembayaran berhasil'), findsOneWidget);
      expect(u.layarPelanggan.isi.last.dataQr, isNull);

      final bayar =
          ((await AmbilOutboxPenjualan(tester, u)).single['Pembayaran']! as List<Object?>).single!
              as Map<String, Object?>;
      expect(bayar['UuidMetodePembayaran'], '01K5MTD0000000000000000008');
      expect(bayar['Referensi'], dibuat!['Uuid']);
      expect(bayar['Jumlah'], '$jumlah.00');
      expect(tester.takeException(), isNull);
      await Lepas(tester, u);
    });
  }

  testWidgets('QRIS dinamis: tombol Cek status sekarang langsung menanyakan server', (tester) async {
    Map<String, Object?>? dibuat;
    var cek = 0;
    final u = await MasukJual(tester, const Size(1280, 900), (p) async {
      if (p.url.path.endsWith('/batal')) {
        return http.Response('', 204);
      }
      if (p.method == 'POST' && p.url.path.endsWith('/qris')) {
        dibuat = jsonDecode(p.body) as Map<String, Object?>;
        return Tagihan(dibuat!);
      }
      cek++;
      return JsonUji({'Uuid': dibuat!['Uuid'], 'Status': 'Menunggu', 'LunasPada': null});
    });
    await BukaBayarQris(tester, const Size(1280, 900));

    // Nominal besar, status berupa kapsul, dan identitas tagihan tampil.
    expect(find.text('Total tagihan'), findsOneWidget);
    expect(find.image(const AssetImage('assets/logo/Qris.png')), findsOneWidget, reason: 'Logo QRIS resmi tampil.');
    for (final nama in const ['Dana', 'ShopeePay', 'Bca', 'Mandiri', 'Bri', 'Bni']) {
      expect(find.image(AssetImage('assets/logo/$nama.png')), findsOneWidget, reason: 'Logo $nama tampil.');
    }
    expect(find.text('Menunggu pembayaran'), findsOneWidget);
    expect(find.text('PAYOUNG-QR-0001'), findsOneWidget);

    final sebelum = cek;
    await Ketuk(tester, find.widgetWithText(FilledButton, 'Cek status sekarang'));
    expect(cek, greaterThan(sebelum), reason: 'Tombol harus memanggil status tanpa menunggu putaran berikutnya.');
    expect(find.byType(DialogQrisDinamis), findsOneWidget, reason: 'Belum lunas: dialog tetap terbuka.');
    await Ketuk(tester, find.widgetWithText(OutlinedButton, 'Batalkan QRIS'));
    expect(tester.takeException(), isNull);
    await Lepas(tester, u);
  });

  // Dialog harus muat tanpa digulir di tablet mendatar (1280x800), jendela pendek, tablet tegak, dan HP.
  for (final ukuran in const [Size(1280, 800), Size(1024, 720), Size(800, 1280), Size(360, 740)]) {
    testWidgets('QRIS dinamis: dialog muat tanpa digulir (${ukuran.width.toInt()}x${ukuran.height.toInt()} dp)', (
      tester,
    ) async {
      Map<String, Object?>? dibuat;
      final u = await MasukJual(tester, ukuran, (p) async {
        if (p.url.path.endsWith('/batal')) {
          return http.Response('', 204);
        }
        if (p.method == 'POST' && p.url.path.endsWith('/qris')) {
          dibuat = jsonDecode(p.body) as Map<String, Object?>;
          return Tagihan(dibuat!);
        }
        return JsonUji({'Uuid': dibuat!['Uuid'], 'Status': 'Menunggu', 'LunasPada': null});
      });
      await BukaBayarQris(tester, ukuran);

      expect(find.byType(DialogQrisDinamis), findsOneWidget);
      final gulir = find.descendant(of: find.byType(DialogQrisDinamis), matching: find.byType(Scrollable));
      final posisi = tester.state<ScrollableState>(gulir.first).position;
      expect(
        posisi.maxScrollExtent,
        0,
        reason:
            'Seluruh dialog QRIS (termasuk tombol) harus terlihat tanpa menggulir (lebih ${posisi.maxScrollExtent} dp).',
      );
      expect(find.widgetWithText(FilledButton, 'Cek status sekarang'), findsOneWidget);
      expect(tester.takeException(), isNull);
      await Ketuk(tester, find.widgetWithText(OutlinedButton, 'Batalkan QRIS'));
      await Lepas(tester, u);
    });
  }

  testWidgets('QRIS dinamis dibatalkan kasir → batal di server, tidak ada penjualan', (tester) async {
    Map<String, Object?>? dibuat;
    var batal = 0;
    final u = await MasukJual(tester, const Size(1280, 900), (p) async {
      if (p.url.path.endsWith('/batal')) {
        batal++;
        return http.Response('', 204);
      }
      if (p.method == 'POST') {
        dibuat = jsonDecode(p.body) as Map<String, Object?>;
        return Tagihan(dibuat!);
      }
      return JsonUji({'Uuid': dibuat!['Uuid'], 'Status': 'Menunggu', 'LunasPada': null});
    });
    await BukaBayarQris(tester, const Size(1280, 900));
    await Ketuk(tester, find.widgetWithText(OutlinedButton, 'Batalkan QRIS'));
    expect(batal, 1);
    expect(find.byType(DialogQrisDinamis), findsNothing);
    expect(find.text('Pembayaran berhasil'), findsNothing);
    expect(await AmbilOutboxPenjualan(tester, u), isEmpty);
    await Lepas(tester, u);
  });

  testWidgets('QRIS dinamis lunas tepat saat dibatalkan (SudahLunas) → pembayaran tetap dipakai', (tester) async {
    Map<String, Object?>? dibuat;
    final u = await MasukJual(tester, const Size(1280, 900), (p) async {
      if (p.url.path.endsWith('/batal')) {
        return http.Response(
          jsonEncode({
            'Galat': {'Kode': 'SudahLunas', 'Pesan': 'Tagihan sudah lunas.'},
          }),
          409,
          headers: {'content-type': 'application/json'},
        );
      }
      if (p.method == 'POST') {
        dibuat = jsonDecode(p.body) as Map<String, Object?>;
        return Tagihan(dibuat!);
      }
      return JsonUji({'Uuid': dibuat!['Uuid'], 'Status': 'Menunggu', 'LunasPada': null});
    });
    await BukaBayarQris(tester, const Size(1280, 900));
    await Ketuk(tester, find.widgetWithText(OutlinedButton, 'Batalkan QRIS'));
    await Tunggu(tester);
    expect(find.text('Pembayaran berhasil'), findsOneWidget);
    final bayar =
        ((await AmbilOutboxPenjualan(tester, u)).single['Pembayaran']! as List<Object?>).single!
            as Map<String, Object?>;
    expect(bayar['Referensi'], dibuat!['Uuid']);
    await Lepas(tester, u);
  });

  testWidgets('QRIS dinamis: lunas dan batal berbarengan hanya menutup dialog sekali (aplikasi tidak jadi kosong)', (
    tester,
  ) async {
    Map<String, Object?>? dibuat;
    var tahanStatus = false;
    final statusTertahan = Completer<http.Response>();
    final batalTertahan = Completer<http.Response>();
    final u = await MasukJual(tester, const Size(1280, 900), (p) {
      if (p.url.path.endsWith('/batal')) {
        return batalTertahan.future;
      }
      if (p.method == 'POST') {
        dibuat = jsonDecode(p.body) as Map<String, Object?>;
        return Future.value(Tagihan(dibuat!));
      }
      if (tahanStatus) {
        return statusTertahan.future;
      }
      return Future.value(JsonUji({'Uuid': dibuat!['Uuid'], 'Status': 'Menunggu', 'LunasPada': null}));
    });
    await BukaBayarQris(tester, const Size(1280, 900));
    tahanStatus = true;
    // Putaran pantau berikutnya menggantung menunggu jawaban status.
    await Tunggu(tester, const Duration(seconds: 3));
    await Ketuk(tester, find.widgetWithText(OutlinedButton, 'Batalkan QRIS'));
    // Pelanggan membayar tepat saat itu: status lunas tiba lebih dulu, lalu penolakan batal (SudahLunas) menyusul
    // selagi dialog masih dalam animasi keluar.
    statusTertahan.complete(JsonUji({'Uuid': dibuat!['Uuid'], 'Status': 'Lunas', 'LunasPada': null}));
    await tester.runAsync(() => Future<void>.delayed(const Duration(milliseconds: 20)));
    await tester.pump();
    batalTertahan.complete(
      http.Response(
        jsonEncode({
          'Galat': {'Kode': 'SudahLunas', 'Pesan': 'Tagihan sudah lunas.'},
        }),
        409,
        headers: {'content-type': 'application/json'},
      ),
    );
    await Tunggu(tester, const Duration(seconds: 1));
    expect(find.byType(RuangKerja), findsOneWidget, reason: 'Rute akar tidak boleh ikut tertutup oleh pop kedua.');
    expect(find.text('Pembayaran berhasil'), findsOneWidget);
    expect(tester.takeException(), isNull);
    await Lepas(tester, u);
  });

  testWidgets('QRIS dinamis offline → pesan jelas, tidak ada penjualan', (tester) async {
    final u = await MasukJual(tester, const Size(1280, 900), null);
    await BukaBayarQris(tester, const Size(1280, 900));
    expect(find.textContaining('QRIS dinamis butuh internet'), findsOneWidget);
    expect(find.widgetWithText(FilledButton, 'Buat QRIS lagi'), findsOneWidget);
    await Ketuk(tester, find.widgetWithText(OutlinedButton, 'Tutup'));
    expect(find.byType(DialogQrisDinamis), findsNothing);
    expect(await AmbilOutboxPenjualan(tester, u), isEmpty);
    await Lepas(tester, u);
  });

  test('SusunModulQr: matriks persegi dengan pola penanda di tiga sudut', () {
    final modul = SusunModulQr(
      '00020101021226620014ID.CO.QRIS.WWW01189360000000000000015204581253033605405250006304ABCD',
    );
    expect(modul.length, greaterThanOrEqualTo(21));
    expect(modul.every((b) => b.length == modul.length), isTrue);
    // Penanda posisi 7×7: tepi atas penuh gelap di kiri atas, kanan atas, dan kiri bawah.
    final n = modul.length;
    expect(modul[0].sublist(0, 7).every((m) => m), isTrue);
    expect(modul[0].sublist(n - 7).every((m) => m), isTrue);
    expect(modul[n - 7].sublist(0, 7).every((m) => m), isTrue);
  });
}
