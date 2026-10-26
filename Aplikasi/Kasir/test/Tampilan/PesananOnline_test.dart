import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:inti/Inti.dart';
import 'package:kasir/Aplikasi/Penyedia.dart';
import 'package:kasir/Domain/Penjualan/Keranjang.dart';
import 'package:kasir/Tampilan/RuangKerja/RuangKerja.dart';

import '../Pendukung/KatalogUji.dart';
import '../Pendukung/LingkunganUji.dart';
import '../Pendukung/PasangAplikasi.dart';

/// F-17 di aplikasi kasir: Riwayat › Pesanan toko online menampilkan pesanan outlet ini, memuatnya ke keranjang kanal
/// `Online`, lalu menagihnya dengan uang muka yang sudah dibayar pelanggan di web. Pesanan berongkir ikut membawa
/// ongkirnya ke keranjang (F-17 bagian 3), jadi totalnya sama dengan yang dilihat pembeli.
void main() {
  Map<String, Object?> Daftar({String ongkir = '0.00', String nomor = 'ON/SLB/260930-0001'}) => {
    'Pesanan': [
      {
        'Uuid': '01K5PESANANONLINE000000001',
        'Nomor': nomor,
        'NamaPelanggan': 'Bu Ratna',
        'JenisPemenuhan': ongkir == '0.00' ? 'AmbilSendiri' : 'Kirim',
        'MetodePembayaran': 'QrisOnline',
        'Status': 'Siap',
        'Subtotal': '28000.00',
        'Ongkir': ongkir,
        'Total': ongkir == '0.00' ? '28000.00' : '40000.00',
        'SudahDibayar': true,
        'SisaUangMuka': '28000.00',
        'Catatan': null,
        'DibuatPada': '2026-09-30T02:00:00Z',
        'Baris': [
          {
            'UuidProduk': UuidUji.americano,
            'UuidProdukSatuan': null,
            'NamaProduk': 'Americano Panas',
            'Jumlah': '2.0000',
            'HargaSatuan': '14000.00',
            'HargaPilihan': '0.00',
            'Pilihan': <Object?>[],
            'Catatan': null,
          },
        ],
      },
    ],
    'MetodeUangMuka': {'Uuid': '01K5METODEUANGMUKA00000001', 'Nama': 'Uang muka (DP)'},
  };

  Future<LingkunganUji> MasukJual(WidgetTester tester, Size ukuran, {String ongkir = '0.00'}) async {
    final u = LingkunganUji.Buat();
    await tester.runAsync(() async {
      await u.SiapkanAktif();
      await u.shift.BukaShift(kasir: await u.Staf('Rina Wulandari'), kasAwal: Uang.DariBulat(500000));
    });
    u.server.penangan = (p) async {
      if (p.url.path.endsWith('/data-awal')) {
        return JsonUji(DataAwalUji(tokoOnline: true));
      }
      if (p.url.path.endsWith('/katalog')) {
        return JsonUji(KatalogUji());
      }
      if (p.url.path.endsWith('/pesanan-online')) {
        return JsonUji(Daftar(ongkir: ongkir));
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
    await Tunggu(tester, const Duration(milliseconds: 60));
    await tester.ensureVisible(finder);
    await tester.pump();
    await tester.tap(finder);
    await Tunggu(tester);
  }

  ProviderContainer Wadah(WidgetTester tester) => ProviderScope.containerOf(tester.element(find.byType(RuangKerja)));

  Future<List<Map<String, Object?>>> Outbox(WidgetTester tester, LingkunganUji u, String jenis) async =>
      (await tester.runAsync(() => u.db.select(u.db.outbox).get()))!
          .where((o) => o.Jenis == jenis)
          .map((o) => jsonDecode(o.Data) as Map<String, Object?>)
          .toList();

  Future<void> BukaLembar(WidgetTester tester) async {
    await tester.tap(find.text('Riwayat').last);
    await Tunggu(tester);
    await Ketuk(tester, find.widgetWithText(OutlinedButton, 'Pesanan toko online'));
  }

  for (final ukuran in const [Size(1280, 900), Size(800, 1000)]) {
    testWidgets('tagih pesanan berbayar: keranjang Online terisi & uang muka dipakai (${ukuran.width.toInt()} dp)', (
      tester,
    ) async {
      final u = await MasukJual(tester, ukuran);
      await BukaLembar(tester);
      expect(find.text('ON/SLB/260930-0001'), findsOneWidget);
      expect(find.textContaining('Sudah dibayar online'), findsOneWidget);
      await Ketuk(tester, find.widgetWithText(OutlinedButton, 'Tagih 0001'));

      final keranjang = Wadah(tester).read(penyediaKeranjang);
      expect(keranjang.praPesan?.nomor, 'ON/SLB/260930-0001');
      expect(keranjang.praPesan?.sumber, SumberUangMuka.pesananOnline);

      await Ketuk(tester, find.widgetWithText(FilledButton, 'Bayar').first);
      expect(find.text('Menagih pesanan online ON/SLB/260930-0001'), findsOneWidget);
      expect(find.text('Uang muka (DP)'), findsOneWidget);
      await Ketuk(tester, find.widgetWithText(ChoiceChip, 'Tunai'));
      await Ketuk(tester, find.widgetWithText(FilledButton, 'Uang pas'));
      expect(find.text('Pembayaran berhasil'), findsOneWidget);

      final jual = (await Outbox(tester, u, 'Penjualan.Buat')).single;
      expect(jual['UuidPesananOnline'], '01K5PESANANONLINE000000001');
      expect(jual.containsKey('UuidPesananPenjualan'), isFalse);
      expect(jual['Kanal'], 'Online');
      final bayar = (jual['Pembayaran']! as List<Object?>).cast<Map<String, Object?>>();
      expect(bayar.first['UuidMetodePembayaran'], '01K5METODEUANGMUKA00000001');
      expect(bayar.first['Jumlah'], '28000.00');
      expect(tester.takeException(), isNull);
      await Lepas(tester, u);
    });
  }

  testWidgets('F-17 bagian 3: pesanan kirim berongkir bisa ditagih, ongkirnya masuk keranjang & outbox', (
    tester,
  ) async {
    final u = await MasukJual(tester, const Size(1280, 900), ongkir: '12000.00');
    await BukaLembar(tester);
    final tombol = tester.widget<OutlinedButton>(find.widgetWithText(OutlinedButton, 'Tagih 0001'));
    expect(tombol.onPressed, isNotNull, reason: 'Ongkir sudah bisa ditagih sejak F-17 bagian 3.');
    await Ketuk(tester, find.widgetWithText(OutlinedButton, 'Tagih 0001'));

    expect(Wadah(tester).read(penyediaKeranjang).biayaKirim, Uang.DariBulat(12000));
    // Baris ongkir terbaca kasir di ringkasan keranjang, bukan hanya ikut di total.
    expect(find.text('Ongkir'), findsOneWidget);

    await Ketuk(tester, find.widgetWithText(FilledButton, 'Bayar').first);
    await Ketuk(tester, find.widgetWithText(ChoiceChip, 'Tunai'));
    await Ketuk(tester, find.widgetWithText(FilledButton, 'Uang pas'));
    expect(find.text('Pembayaran berhasil'), findsOneWidget);

    final jual = (await Outbox(tester, u, 'Penjualan.Buat')).single;
    expect(jual['BiayaKirim'], '12000.00');
    expect(jual.containsKey('DiskonKirim'), isFalse);
    expect(jual['UuidPesananOnline'], '01K5PESANANONLINE000000001');
    expect(tester.takeException(), isNull);
    await Lepas(tester, u);
  });

  testWidgets('BR-17.3: pesanan menunggu diterima, diproses & ditandai siap dari kasir. Tolak wajib alasan', (
    tester,
  ) async {
    final u = await MasukJual(tester, const Size(1280, 900));
    var status = 'MenungguKonfirmasi';
    final kiriman = <Map<String, Object?>>[];
    u.server.penangan = (p) async {
      if (p.url.path.endsWith('/pesanan-online')) {
        final d = Daftar();
        ((d['Pesanan']! as List<Object?>).first! as Map<String, Object?>)['Status'] = status;
        return JsonUji(d);
      }
      if (p.url.path.endsWith('/status')) {
        final isi = jsonDecode(p.body) as Map<String, Object?>;
        kiriman.add(isi);
        status = isi['Status']! as String;
        return JsonUji({'Uuid': '01K5PESANANONLINE000000001', 'Status': status});
      }
      throw http.ClientException('offline');
    };
    await BukaLembar(tester);

    expect(find.text('Terima pesanan dulu, lalu siapkan sampai berstatus Siap.'), findsOneWidget);
    expect(find.widgetWithText(OutlinedButton, 'Tagih 0001'), findsNothing);
    await Ketuk(tester, find.byKey(const ValueKey('Status-01K5PESANANONLINE000000001-Dikonfirmasi')));
    await Ketuk(tester, find.byKey(const ValueKey('Status-01K5PESANANONLINE000000001-Diproses')));
    await Ketuk(tester, find.byKey(const ValueKey('Status-01K5PESANANONLINE000000001-Siap')));
    expect(kiriman.map((k) => k['Status']), ['Dikonfirmasi', 'Diproses', 'Siap']);
    expect(kiriman.first['UuidPengguna'], isNotEmpty);
    final tagih = tester.widget<OutlinedButton>(find.widgetWithText(OutlinedButton, 'Tagih 0001'));
    expect(tagih.onPressed, isNotNull);

    status = 'MenungguKonfirmasi';
    await Ketuk(tester, find.widgetWithText(OutlinedButton, 'Muat ulang'));
    await Ketuk(tester, find.byKey(const ValueKey('Status-01K5PESANANONLINE000000001-Ditolak')));
    final konfirmasi = find.widgetWithText(FilledButton, 'Tolak pesanan');
    expect(tester.widget<FilledButton>(konfirmasi).onPressed, isNull, reason: 'Alasan wajib.');
    await tester.enterText(find.byKey(const ValueKey('AlasanTolak')), 'Bahan habis hari ini');
    await tester.pump();
    await Ketuk(tester, konfirmasi);
    expect(kiriman.last, containsPair('Alasan', 'Bahan habis hari ini'));
    expect(kiriman.last['Status'], 'Ditolak');
    expect(tester.takeException(), isNull);
    await Lepas(tester, u);
  });

  testWidgets('BR-17.3: polling 10 detik mengumumkan pesanan online baru dan menampilkan jumlah menunggu', (
    tester,
  ) async {
    final u = await MasukJual(tester, const Size(1280, 900));
    var putaran = 0;
    u.server.penangan = (p) async {
      if (p.url.path.endsWith('/pesanan-online/ringkas')) {
        putaran++;
        return JsonUji({
          'Menunggu': putaran == 1 ? 0 : 1,
          'PerluDitagih': 0,
          'Baru': putaran == 1 ? 0 : 1,
          'WaktuServer': '2026-09-30T02:00:0${putaran}Z',
        });
      }
      if (p.url.path.endsWith('/pesanan-online')) {
        return JsonUji(Daftar());
      }
      throw http.ClientException('offline');
    };
    await Tunggu(tester, const Duration(seconds: 11));
    expect(find.text('Ada pesanan online baru.'), findsNothing, reason: 'Tarikan pertama hanya menetapkan titik awal.');
    await Tunggu(tester, const Duration(seconds: 10));
    expect(find.text('Ada pesanan online baru.'), findsOneWidget);
    expect(find.textContaining('1 pesanan online menunggu'), findsWidgets);
    expect(u.server.permintaan.where((p) => p.url.query.contains('sejak=')), isNotEmpty);
    await Ketuk(tester, find.widgetWithText(SnackBarAction, 'Lihat'));
    expect(find.text('ON/SLB/260930-0001'), findsOneWidget);
    expect(tester.takeException(), isNull);
    await Lepas(tester, u);
  });
}
