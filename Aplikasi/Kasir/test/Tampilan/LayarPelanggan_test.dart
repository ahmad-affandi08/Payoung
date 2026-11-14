import 'package:adaptor_perangkat/AdaptorPerangkat.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:inti/Inti.dart';
import 'package:kasir/Domain/Perangkat/LayananLayarPelanggan.dart';
import 'package:kasir/Tampilan/Pengaturan/KategoriPengaturan.dart';
import 'package:kasir/Tampilan/RuangKerja/RuangKerja.dart';
import 'package:sistem_desain/SistemDesain.dart';

import '../Pendukung/KatalogUji.dart';
import '../Pendukung/LingkunganUji.dart';
import '../Pendukung/PasangAplikasi.dart';

/// v2.01 layar pelanggan (PRD §17.2.5a, POS-15): item & total mengikuti keranjang, "Silakan lakukan pembayaran" saat
/// Bayar, kembalian + terima kasih setelah bayar, lalu siaga; diatur di Pengaturan. Diuji di 360/800/1280dp.
void main() {
  Future<LingkunganUji> Masuk(WidgetTester tester, Size ukuran, {String mode = ModeLayarPelanggan.layarKedua}) async {
    final u = LingkunganUji.Buat();
    await tester.runAsync(() async {
      await u.SiapkanAktif();
      await u.SiapkanKatalog();
      await PengaturanLayarPelanggan(mode: mode).Simpan(u.repositori);
      await u.shift.BukaShift(kasir: await u.Staf('Rina Wulandari'), kasAwal: Uang.DariBulat(500000));
    });
    u.server.penangan = (p) async {
      if (p.url.path.endsWith('/data-awal')) {
        return JsonUji(DataAwalUji());
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
    await tester.runAsync(() => Scrollable.ensureVisible(tester.element(finder), alignment: 0.5));
    await tester.pump();
    await tester.tap(finder);
    await Tunggu(tester);
  }

  for (final ukuran in const [Size(1280, 900), Size(800, 1000), Size(360, 740)]) {
    final dp = '${ukuran.width.toInt()} dp';

    testWidgets('keranjang → bayar → kembalian → siaga tampil di layar pelanggan ($dp)', (tester) async {
      final u = await Masuk(tester, ukuran);
      final layar = u.layarPelanggan;

      await Ketuk(tester, find.byWidgetPredicate((w) => w is UbinProduk && w.nama == 'Americano Panas'));
      final keranjang = layar.isi.last;
      expect(keranjang.keadaan, KeadaanLayarPelanggan.Keranjang);
      expect(keranjang.baris.single.nama, 'Americano Panas');
      expect(keranjang.baris.single.rincian, startsWith('1 × Rp'));
      expect(keranjang.total, isNotNull);

      if (ukuran.width < 600) {
        await Ketuk(tester, find.textContaining('Keranjang | '));
      }
      await Ketuk(tester, find.widgetWithText(FilledButton, 'Bayar').last);
      expect(layar.isi.last.keadaan, KeadaanLayarPelanggan.Bayar);
      expect(layar.isi.last.pesan, 'Silakan lakukan pembayaran');

      await Ketuk(tester, find.widgetWithText(ChoiceChip, 'Tunai'));
      await Ketuk(tester, find.widgetWithText(FilledButton, 'Uang pas'));
      final selesai = layar.isi.last;
      expect(selesai.keadaan, KeadaanLayarPelanggan.Selesai);
      expect(selesai.labelTotal, 'Kembalian');
      expect(selesai.total, 'Rp 0');
      expect(selesai.pesan, 'Terima kasih');

      await Ketuk(tester, find.widgetWithText(FilledButton, 'Transaksi baru'));
      expect(layar.isi.last.keadaan, KeadaanLayarPelanggan.Siaga);
      expect(tester.takeException(), isNull);
      await Lepas(tester, u);
    });
  }

  testWidgets('Pengaturan: pilih layar kedua, tampilkan contoh, matikan (360 dp)', (tester) async {
    final u = await Masuk(tester, const Size(360, 740), mode: ModeLayarPelanggan.mati);
    await Ketuk(tester, NavPengaturan().last);
    await BukaKategoriPengaturan(tester, KategoriPengaturan.tampilan);
    await tester.scrollUntilVisible(
      find.widgetWithText(ChoiceChip, 'Layar kedua / HDMI'),
      300,
      scrollable: find.byType(Scrollable).first,
    );
    await tester.pump();
    expect(u.layarPelanggan.isi, isEmpty, reason: 'Mode mati tidak mengirim apa pun.');

    await Ketuk(tester, find.widgetWithText(ChoiceChip, 'Layar kedua / HDMI'));
    expect(find.text('Layar pelanggan disimpan.'), findsOneWidget);
    expect(u.layarPelanggan.isi.last.keadaan, KeadaanLayarPelanggan.Siaga);
    final tersimpan = await tester.runAsync(() => PengaturanLayarPelanggan.Muat(u.repositori));
    expect(tersimpan!.mode, ModeLayarPelanggan.layarKedua);

    await Ketuk(tester, find.widgetWithText(OutlinedButton, 'Tampilkan contoh'));
    expect(find.textContaining('Contoh dikirim'), findsOneWidget);

    await Ketuk(tester, find.widgetWithText(ChoiceChip, 'Layar VFD (COM port)'));
    await tester.enterText(find.widgetWithText(TextField, 'COM port'), 'com3');
    await Ketuk(tester, find.widgetWithText(OutlinedButton, 'Simpan port'));
    expect((await tester.runAsync(() => PengaturanLayarPelanggan.Muat(u.repositori)))!.portVfd, 'COM3');

    await Ketuk(tester, find.widgetWithText(ChoiceChip, 'Tidak dipakai'));
    expect(find.text('Layar pelanggan dimatikan.'), findsOneWidget);
    expect(u.layarPelanggan.ditutup, greaterThan(0));
    expect(tester.takeException(), isNull);
    await Lepas(tester, u);
  });
}
