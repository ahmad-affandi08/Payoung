import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:kasir/Data/PenyimpanRahasia.dart';

import '../Pendukung/LingkunganUji.dart';
import '../Pendukung/MuatFont.dart';
import '../Pendukung/PasangAplikasi.dart';

/// F-02 langkah 5 di aplikasi kasir: kode aktivasi dipindai dari QR atau diketik.
/// Pemindai (`mobile_scanner`) hanya ada di Android & iOS; di Windows layar ini hanya menyediakan isian manual.
void main() {
  testWidgets('tanpa pemindai hanya ada isian manual, tanpa tombol pindai', (tester) async {
    final u = LingkunganUji.Buat();
    await PasangAplikasi(tester, u, pemindaiQr: PemindaiQrTiruan(tersedia: false));

    expect(find.text('Aktifkan perangkat kasir'), findsOneWidget);
    expect(find.widgetWithText(OutlinedButton, 'Pindai kode QR'), findsNothing);
    expect(find.text('atau ketik kodenya'), findsNothing);
    expect(find.widgetWithText(TextField, 'Kode aktivasi'), findsOneWidget);

    await Lepas(tester, u);
  });

  testWidgets('dengan pemindai, tombol pindai muncul di atas isian manual', (tester) async {
    final u = LingkunganUji.Buat();
    await PasangAplikasi(tester, u, pemindaiQr: PemindaiQrTiruan(hasil: 'A7K9M2QT'));

    expect(find.widgetWithText(OutlinedButton, 'Pindai kode QR'), findsOneWidget);
    // Isian manual tetap jadi jalur utama, bukan disembunyikan.
    expect(find.text('atau ketik kodenya'), findsOneWidget);
    expect(find.widgetWithText(TextField, 'Kode aktivasi'), findsOneWidget);

    await Lepas(tester, u);
  });

  testWidgets('hasil pindai langsung mengaktifkan perangkat tanpa ketukan tambahan', (tester) async {
    final u = LingkunganUji.Buat();
    final pemindai = PemindaiQrTiruan(hasil: 'A7K9M2QT');
    await PasangAplikasi(tester, u, pemindaiQr: pemindai);

    await tester.tap(find.widgetWithText(OutlinedButton, 'Pindai kode QR'));
    await Tunggu(tester, const Duration(milliseconds: 600));

    expect(pemindai.dipanggil, 1);
    // Aktivasinya berhasil, jadi layar aktivasi selesai dan layar pilih kasir mengambil alih. Asersi lama di
    // sini mencari kode di isian setelah aktivasi sukses — isian itu memang sudah tidak ada lagi.
    expect(find.text('Aktifkan perangkat kasir'), findsNothing);
    expect(find.widgetWithText(TextField, 'Kode aktivasi'), findsNothing);

    await Lepas(tester, u);
  });

  testWidgets('kode hasil pindai yang ditolak tetap terisi supaya bisa diperbaiki', (tester) async {
    final u = LingkunganUji.Buat();
    // Kurang dari 6 karakter ditolak di perangkat sebelum dikirim (LayananPerangkat.Aktifkan).
    final pemindai = PemindaiQrTiruan(hasil: 'A7K9');
    await PasangAplikasi(tester, u, pemindaiQr: pemindai);

    await tester.tap(find.widgetWithText(OutlinedButton, 'Pindai kode QR'));
    await Tunggu(tester, const Duration(milliseconds: 600));

    expect(pemindai.dipanggil, 1);
    expect(find.text('Masukkan kode aktivasi dari back-office menu Perangkat.'), findsOneWidget);
    // Kode dari QR tetap di isian, jadi pengguna bisa melihat & memperbaikinya.
    expect(find.widgetWithText(TextField, 'A7K9'), findsOneWidget);

    await Lepas(tester, u);
  });

  testWidgets('kode berisi tanda hubung tidak ditolak di perangkat', (tester) async {
    final u = LingkunganUji.Buat();
    await PasangAplikasi(tester, u, pemindaiQr: PemindaiQrTiruan(tersedia: false));

    await tester.enterText(find.widgetWithText(TextField, 'Kode aktivasi'), 'A7K9-M2QT');
    await tester.tap(find.widgetWithText(FilledButton, 'Aktifkan perangkat'));
    await Tunggu(tester, const Duration(milliseconds: 600));

    // Back-office pernah menampilkan kode berstrip, sedangkan validasi lokal hanya menerima huruf & angka;
    // kodenya kini dinormalkan dulu seperti di server, jadi galatnya bukan lagi "kode tidak valid".
    expect(find.text('Masukkan kode aktivasi dari back-office menu Perangkat.'), findsNothing);

    await Lepas(tester, u);
  });

  testWidgets('pindai dibatalkan tidak mengisi kode dan tidak mengaktifkan', (tester) async {
    final u = LingkunganUji.Buat();
    final pemindai = PemindaiQrTiruan();
    await PasangAplikasi(tester, u, pemindaiQr: pemindai);

    await tester.tap(find.widgetWithText(OutlinedButton, 'Pindai kode QR'));
    await Tunggu(tester, const Duration(milliseconds: 600));

    expect(pemindai.dipanggil, 1);
    expect(find.text('Aktifkan perangkat kasir'), findsOneWidget);
    expect(find.widgetWithText(TextField, 'A7K9M2QT'), findsNothing);

    await Lepas(tester, u);
  });

  testWidgets('D-35: QR server toko sendiri menyimpan alamatnya dan mengaktifkan ke server itu', (tester) async {
    final u = LingkunganUji.Buat();
    final pemindai = PemindaiQrTiruan(hasil: 'https://kasir.tokoabc.id/aktivasi-perangkat?kode=A7K9M2QT');
    await PasangAplikasi(tester, u, pemindaiQr: pemindai);

    await tester.tap(find.widgetWithText(OutlinedButton, 'Pindai kode QR'));
    await Tunggu(tester, const Duration(milliseconds: 600));

    expect(u.rahasia.isi[PenyimpanRahasia.kunciAlamatServer], 'https://kasir.tokoabc.id/');
    expect(u.server.permintaan.first.url.toString(), startsWith('https://kasir.tokoabc.id/api/pos/v1/'));
    expect(find.text('Aktifkan perangkat kasir'), findsNothing);

    await Lepas(tester, u);
  });

  testWidgets('D-35: alamat server toko diketik manual. Alamat tidak sah ditolak sebelum dikirim', (tester) async {
    final u = LingkunganUji.Buat();
    await PasangAplikasi(tester, u, pemindaiQr: PemindaiQrTiruan(tersedia: false));

    await tester.tap(find.text('Toko memakai server sendiri?'));
    await tester.pump();
    await tester.enterText(find.widgetWithText(TextField, 'Kode aktivasi'), 'A7K9M2QT');
    await tester.enterText(find.byKey(const ValueKey('AlamatServer')), 'https://kasir.tokoabc.id/?x=1');
    await tester.tap(find.widgetWithText(FilledButton, 'Aktifkan perangkat'));
    await tester.pump();

    expect(find.text('Isi alamat server toko, misal https://kasir.tokoanda.com'), findsOneWidget);
    expect(u.server.permintaan, isEmpty);

    await tester.enterText(find.byKey(const ValueKey('AlamatServer')), 'kasir.tokoabc.id');
    await tester.tap(find.widgetWithText(FilledButton, 'Aktifkan perangkat'));
    await Tunggu(tester, const Duration(milliseconds: 600));

    expect(u.server.permintaan.first.url.host, 'kasir.tokoabc.id');
    expect(u.rahasia.isi[PenyimpanRahasia.kunciAlamatServer], 'https://kasir.tokoabc.id/');

    await Lepas(tester, u);
  });

  // Layar pertama yang dilihat pemilik toko saat memasang Payoung: panel merek + kartu isian, dua kolom di layar
  // lega dan satu kolom di HP (PRD §17.2.7, §17.6).
  for (final (nama, ukuran) in [('1280', const Size(1280, 900)), ('360', const Size(360, 740))]) {
    testWidgets('golden layar aktivasi di lebar $nama dp', (tester) async {
      await MuatFontMerek();
      final u = LingkunganUji.Buat();
      await PasangAplikasi(
        tester,
        u,
        ukuran: ukuran,
        pemindaiQr: PemindaiQrTiruan(hasil: 'A7K9M2QT'),
      );

      await expectLater(find.byType(MaterialApp), matchesGoldenFile('Golden/Aktivasi$nama.png'));

      await Lepas(tester, u);
    });
  }
}
