import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:inti/Inti.dart';
import 'package:kasir/Aplikasi/Penyedia.dart';
import 'package:kasir/Domain/Penjualan/Keranjang.dart';
import 'package:kasir/Tampilan/RuangKerja/RuangKerja.dart';
import 'package:sistem_desain/SistemDesain.dart';

import '../Pendukung/KatalogUji.dart';
import '../Pendukung/LingkunganUji.dart';
import '../Pendukung/PasangAplikasi.dart';

/// FnB reservasi meja + DP (D-145) di aplikasi kasir (360/800/1280 dp): Riwayat › Reservasi meja menampilkan antrian
/// beserta uang muka, "Tagih di keranjang" menempelkan reservasi ke keranjang berisi, layar Bayar memotong uang muka
/// otomatis, dan pembayaran membawa `UuidReservasiMeja` di `Penjualan.Buat`.
void main() {
  Map<String, Object?> Baris(String status) => {
    'Uuid': '01K7RESERVASIMEJA00000001',
    'Nomor': 'RM/2026/10/0001',
    'MulaiPada': '2026-10-13T12:00:00Z',
    'SelesaiPada': '2026-10-13T14:00:00Z',
    'NamaPemesan': 'Bapak Hendra Wijaya',
    'NoHp': '0812-3456-7890',
    'JumlahTamu': 4,
    'UuidMeja': '01K7MEJA000000000000000007',
    'NamaMeja': '7',
    'Status': status,
    'LabelStatus': status,
    'Catatan': 'Ulang tahun anak',
    'UangMuka': {'Dicatat': '30000.00', 'Terpakai': '0.00', 'Sisa': '30000.00'},
  };

  Finder Ubin(String nama) => find.byWidgetPredicate((w) => w is UbinProduk && w.nama == nama);

  for (final ukuran in const [Size(1280, 900), Size(800, 1000), Size(360, 740)]) {
    testWidgets('tagih reservasi meja dari Riwayat lalu bayar dengan uang muka (${ukuran.width.toInt()} dp)', (
      tester,
    ) async {
      final u = LingkunganUji.Buat();
      await tester.runAsync(() async {
        await u.SiapkanAktif();
        await u.shift.BukaShift(kasir: await u.Staf('Rina Wulandari'), kasAwal: Uang.DariBulat(500000));
      });
      u.server.penangan = (p) async {
        if (p.url.path.endsWith('/data-awal')) {
          return JsonUji(DataAwalUji()..['KodeSektor'] = ['FNB-RST']);
        }
        if (p.url.path.endsWith('/katalog')) {
          return JsonUji(KatalogUji());
        }
        if (p.url.path.endsWith('/reservasi-meja')) {
          return JsonUji({
            'Reservasi': [Baris('Dikonfirmasi')],
            'MetodeUangMuka': {'Uuid': '01K7METODEUANGMUKA00000001', 'Nama': 'Uang muka (DP)'},
          });
        }
        if (p.url.path.endsWith('/hadir')) {
          return JsonUji({'Reservasi': Baris('Hadir'), 'MetodeUangMuka': null});
        }
        throw http.ClientException('offline');
      };
      await PasangAplikasi(tester, u, ukuran: ukuran);
      await Tunggu(tester, const Duration(milliseconds: 600));
      await PilihKasir(tester, 'Rina Wulandari');
      await tester.pump();
      await KetikPin(tester, KasusPin(0)['Pin']! as String);
      await Tunggu(tester);

      Future<void> Ketuk(Finder finder) async {
        await tester.ensureVisible(finder);
        await Tunggu(tester, const Duration(milliseconds: 60));
        await tester.ensureVisible(finder);
        await tester.pump();
        await tester.tap(finder);
        await Tunggu(tester);
      }

      final wadah = ProviderScope.containerOf(tester.element(find.byType(RuangKerja)));

      // Isi keranjang dulu (americano), lalu buka antrian reservasi meja dari Riwayat.
      await Ketuk(Ubin('Americano Panas'));
      expect(wadah.read(penyediaKeranjang).baris, hasLength(1));
      await tester.tap(find.text('Riwayat').last);
      await Tunggu(tester);
      await Ketuk(find.widgetWithText(OutlinedButton, 'Reservasi meja'));
      expect(find.text('19.00–21.00'), findsOneWidget, reason: 'Jam menurut zona outlet (WIB).');
      expect(find.text('Meja 7 | 4 tamu | 0812-3456-7890'), findsOneWidget);
      expect(find.textContaining('Uang muka Rp'), findsOneWidget);

      // Tamu datang dicatat ke server (meja sudah ada, tanpa UuidMeja di badan).
      await Ketuk(find.widgetWithText(OutlinedButton, 'Tamu datang'));
      final hadir = u.server.permintaan.where((p) => p.url.path.endsWith('/hadir')).single;
      expect(jsonDecode(hadir.body), containsPair('UuidPengguna', isA<String>()));
      expect((jsonDecode(hadir.body) as Map<String, Object?>).containsKey('UuidMeja'), isFalse);

      await Ketuk(find.widgetWithText(FilledButton, 'Tagih di keranjang'));

      final keranjang = wadah.read(penyediaKeranjang);
      expect(keranjang.praPesan?.nomor, 'RM/2026/10/0001');
      expect(keranjang.praPesan?.sumber, SumberUangMuka.reservasiMeja);

      await Ketuk(find.widgetWithText(FilledButton, 'Bayar').first);
      expect(find.text('Menagih reservasi meja RM/2026/10/0001'), findsOneWidget);
      expect(find.text('Uang muka (DP)'), findsOneWidget);
      // Uang muka Rp 30.000 menutup seluruh tagihan (Rp 16.500): tinggal selesaikan.
      expect(find.text('Rp 16.500'), findsWidgets);
      await Ketuk(find.widgetWithText(FilledButton, 'Selesaikan pembayaran'));
      expect(find.text('Pembayaran berhasil'), findsOneWidget);

      final jual = (await tester.runAsync(() => u.db.select(u.db.outbox).get()))!
          .where((o) => o.Jenis == 'Penjualan.Buat')
          .map((o) => jsonDecode(o.Data) as Map<String, Object?>)
          .single;
      expect(jual['UuidReservasiMeja'], '01K7RESERVASIMEJA00000001');
      expect(jual.containsKey('UuidPesananPenjualan'), isFalse);
      final bayar = (jual['Pembayaran']! as List<Object?>).cast<Map<String, Object?>>();
      expect(bayar.first['UuidMetodePembayaran'], '01K7METODEUANGMUKA00000001');
      expect(tester.takeException(), isNull);
      await Lepas(tester, u);
    });
  }
}
