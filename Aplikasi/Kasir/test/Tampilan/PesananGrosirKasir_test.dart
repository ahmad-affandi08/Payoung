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

/// POS-05 (§9.7): SO cepat di layar Jual. Di outlet bermode kasir Grosir, keranjang ber-pelanggan dijadikan pesanan
/// grosir dari halaman Bayar (offline, tanpa bayar): outbox `PesananGrosir.Buat` tanpa harga, keranjang kosong, layar
/// konfirmasi. Mode lain tidak menawarkannya.
void main() {
  const pelanggan = PelangganTerpilih(
    uuid: '01K5PELANGGAN0000000000001',
    nama: 'Toko Makmur Jaya',
    noHpSamar: '0813****0077',
    limitKredit: '1000000.00',
    sisaPiutang: '900000.00',
  );

  Future<LingkunganUji> MasukJual(WidgetTester tester, Size ukuran, {required String modeKasir}) async {
    final u = LingkunganUji.Buat();
    final dataAwal = DataAwalUji(modeKasir: modeKasir);
    await tester.runAsync(() async {
      await u.SiapkanAktif(dataAwal: dataAwal);
      await u.shift.BukaShift(kasir: await u.Staf('Rina Wulandari'), kasAwal: Uang.DariBulat(500000));
    });
    u.server.penangan = (p) async {
      if (p.url.path.endsWith('/data-awal')) {
        return JsonUji(dataAwal);
      }
      if (p.url.path.endsWith('/katalog')) {
        return JsonUji(KatalogUji());
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

  Future<void> IsiKeranjang(WidgetTester tester, {bool denganPelanggan = true}) async {
    await tester.enterText(find.byType(TextField).first, '12*RTG-01');
    await tester.testTextInput.receiveAction(TextInputAction.search);
    await Tunggu(tester);
    if (denganPelanggan) {
      final wadah = Wadah(tester);
      wadah.read(penyediaKeranjang.notifier).Ganti(wadah.read(penyediaKeranjang).Salin(pelanggan: () => pelanggan));
      await Tunggu(tester);
    }
  }

  for (final ukuran in const [Size(1280, 900), Size(800, 1000), Size(360, 740)]) {
    testWidgets(
      'mode Grosir: jadikan pesanan grosir dari panel Bayar, tersimpan offline (${ukuran.width.toInt()} dp)',
      (tester) async {
        final u = await MasukJual(tester, ukuran, modeKasir: 'Grosir');
        await IsiKeranjang(tester);

        await Ketuk(tester, find.widgetWithText(FilledButton, 'Bayar').first);
        await Ketuk(tester, find.widgetWithText(OutlinedButton, 'Jadikan pesanan grosir (SO)'));
        expect(find.textContaining('Pesanan atas nama Toko Makmur Jaya'), findsOneWidget);
        // 12 pcs roti bertingkat Rp 11.000 = Rp 132.000; sisa limit Rp 100.000 → peringatan, bukan penolakan.
        expect(find.textContaining('Perkiraan melebihi sisa limit kredit'), findsOneWidget);
        await tester.enterText(find.widgetWithText(TextField, 'Catatan pesanan (opsional)'), 'Kirim Kamis pagi');
        await Ketuk(tester, find.widgetWithText(FilledButton, 'Simpan pesanan grosir'));

        expect(find.text('Pesanan grosir tersimpan'), findsWidgets);
        final item = (await tester.runAsync(() => u.db.select(u.db.outbox).get()))!
            .where((o) => o.Jenis == 'PesananGrosir.Buat')
            .map((o) => jsonDecode(o.Data) as Map<String, Object?>)
            .single;
        expect(item['UuidPelanggan'], pelanggan.uuid);
        expect(item['Catatan'], 'Kirim Kamis pagi');
        final baris = (item['Baris']! as List<Object?>).cast<Map<String, Object?>>().single;
        expect(baris['UuidProduk'], UuidUji.roti);
        expect(baris['Jumlah'], '12.0000');
        expect(baris.containsKey('Harga'), isFalse);
        expect(baris.containsKey('HargaSatuan'), isFalse);
        expect(Wadah(tester).read(penyediaKeranjang).CekKosong, isTrue);
        // Bukan penjualan: tidak ada Penjualan.Buat di outbox.
        final jenis = (await tester.runAsync(() => u.db.select(u.db.outbox).get()))!.map((o) => o.Jenis);
        expect(jenis, isNot(contains('Penjualan.Buat')));

        await Ketuk(tester, find.widgetWithText(FilledButton, 'Transaksi baru'));
        expect(find.byType(RuangKerja), findsOneWidget);
        expect(tester.takeException(), isNull);
        await Lepas(tester, u);
      },
    );
  }

  testWidgets('keranjang berdiskon manual ditolak dengan alasan dan tombol simpan mati', (tester) async {
    final u = await MasukJual(tester, const Size(1280, 900), modeKasir: 'Grosir');
    await IsiKeranjang(tester);
    final wadah = Wadah(tester);
    final awal = wadah.read(penyediaKeranjang);
    wadah
        .read(penyediaKeranjang.notifier)
        .Ganti(awal.Salin(diskonPesanan: () => DiskonManual.DariJumlah(Uang.DariBulat(5000))));
    await Tunggu(tester);

    await Ketuk(tester, find.widgetWithText(FilledButton, 'Bayar').first);
    await Ketuk(tester, find.widgetWithText(OutlinedButton, 'Jadikan pesanan grosir (SO)'));
    expect(find.textContaining('Pesanan grosir memakai harga dari server'), findsOneWidget);
    final simpan = tester.widget<FilledButton>(find.widgetWithText(FilledButton, 'Simpan pesanan grosir'));
    expect(simpan.onPressed, isNull);
    expect(tester.takeException(), isNull);
    await Lepas(tester, u);
  });

  testWidgets('mode selain Grosir tidak menawarkan pesanan grosir, tanpa pelanggan juga tidak', (tester) async {
    final u = await MasukJual(tester, const Size(1280, 900), modeKasir: 'Retail');
    await IsiKeranjang(tester);
    await Ketuk(tester, find.widgetWithText(FilledButton, 'Bayar').first);
    expect(find.widgetWithText(OutlinedButton, 'Jadikan pesanan grosir (SO)'), findsNothing);
    expect(tester.takeException(), isNull);
    await Lepas(tester, u);

    final u2 = await MasukJual(tester, const Size(1280, 900), modeKasir: 'Grosir');
    await IsiKeranjang(tester, denganPelanggan: false);
    await Ketuk(tester, find.widgetWithText(FilledButton, 'Bayar').first);
    expect(find.widgetWithText(OutlinedButton, 'Jadikan pesanan grosir (SO)'), findsNothing);
    expect(tester.takeException(), isNull);
    await Lepas(tester, u2);
  });
}
