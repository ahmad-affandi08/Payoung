import 'dart:convert';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:inti/Inti.dart';
import 'package:kasir/Aplikasi/Penyedia.dart';
import 'package:kasir/Aplikasi/VersiAplikasi.dart';
import 'package:kasir/Tampilan/LayarPengaturan.dart';
import 'package:kasir/Tampilan/Pengaturan/KategoriPengaturan.dart';
import 'package:kasir/Tampilan/Pengaturan/KesiapanPerangkat.dart';
import 'package:kasir/Tampilan/RuangKerja/RuangKerja.dart';
import 'package:sistem_desain/SistemDesain.dart';

import '../Pendukung/KatalogUji.dart';
import '../Pendukung/LingkunganUji.dart';
import '../Pendukung/PasangAplikasi.dart';

/// D-301: halaman Pengaturan dibagi enam kategori (kiri) dengan isi di kanan dan panel kesiapan perangkat; di HP daftar
/// kategori tampil dulu lalu isi satu kategori dengan tombol kembali. Info perangkat dibaca dari data lokal.
void main() {
  Future<LingkunganUji> Masuk(WidgetTester tester, Size ukuran) async {
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
      throw http.ClientException('offline');
    };
    await PasangAplikasi(tester, u, ukuran: ukuran);
    await Tunggu(tester, const Duration(milliseconds: 600));
    await PilihKasir(tester, 'Rina Wulandari');
    await tester.pump();
    await KetikPin(tester, KasusPin(0)['Pin']! as String);
    await Tunggu(tester);
    expect(find.byType(RuangKerja), findsOneWidget);
    await tester.tap(NavPengaturan().last);
    await Tunggu(tester);
    return u;
  }

  testWidgets('layar lebar: enam kategori di kiri, Perangkat terbuka dulu dengan info perangkat dari data lokal', (
    tester,
  ) async {
    final u = await Masuk(tester, const Size(1280, 900));

    for (final kategori in KategoriPengaturan.values) {
      expect(find.byKey(ValueKey('Kategori-${kategori.name}')), findsOneWidget, reason: kategori.label);
    }
    expect(find.text('Perangkat ini'), findsOneWidget);
    expect(find.text('POS-001'), findsWidgets);
    expect(find.text(VersiAplikasi.lengkap), findsOneWidget);
    expect(find.text('Rina Wulandari'), findsWidgets);
    expect(find.text('Ganti kasir'), findsOneWidget);
    expect(find.text('Perbarui data kasir'), findsOneWidget);
    // Panel kanan: daftar periksa kesiapan perangkat dan jalan ke Bantuan.
    expect(find.text('Ringkasan perangkat'), findsOneWidget);
    expect(find.text('Hubungi tim Payoung'), findsOneWidget);
    // Isi kategori lain belum tampil.
    expect(find.text('Ukuran tampilan'), findsNothing);
    expect(find.text('Mode latihan'), findsNothing);
    await Lepas(tester, u);
  });

  testWidgets('layar lebar: tiap kategori membuka isinya sendiri', (tester) async {
    final u = await Masuk(tester, const Size(1280, 900));
    final isi = <KategoriPengaturan, String>{
      KategoriPengaturan.printer: 'Uji perangkat',
      KategoriPengaturan.tampilan: 'Ukuran tampilan',
      KategoriPengaturan.keamanan: 'Mode latihan',
      KategoriPengaturan.data: 'Status sinkron',
      KategoriPengaturan.bantuan: 'Log & laporan galat',
    };
    for (final MapEntry(key: kategori, value: teks) in isi.entries) {
      await BukaKategoriPengaturan(tester, kategori);
      expect(find.text(teks), findsWidgets, reason: kategori.label);
      expect(find.text('Perangkat ini'), findsNothing, reason: kategori.label);
    }
    // Ukuran tampilan hanya ada di Tampilan & jual.
    await BukaKategoriPengaturan(tester, KategoriPengaturan.tampilan);
    expect(find.text('Ukuran tampilan'), findsOneWidget);
    expect(find.text('Mode latihan'), findsNothing);
    await Lepas(tester, u);
  });

  testWidgets('"Hubungi tim Payoung" di panel kanan membuka kategori Bantuan', (tester) async {
    final u = await Masuk(tester, const Size(1280, 900));
    await tester.tap(find.byKey(const ValueKey('BukaBantuan')));
    await tester.pump(const Duration(milliseconds: 300));
    expect(find.text('Log & laporan galat'), findsWidgets);
    await Lepas(tester, u);
  });

  testWidgets('"Ganti kasir" di Pengaturan mengunci layar ke pilihan kasir tanpa menutup shift', (tester) async {
    final u = await Masuk(tester, const Size(1280, 900));
    final wadah = ProviderScope.containerOf(tester.element(find.byType(LayarPengaturan)));
    await tester.tap(find.byKey(const ValueKey('GantiKasir')));
    await Tunggu(tester);
    expect(wadah.read(penyediaSesi).kunci, KeadaanKunci.GantiKasir);
    await Lepas(tester, u);
  });

  testWidgets('HP: daftar kategori dulu, ketuk satu kategori membuka isinya, "Semua pengaturan" kembali', (
    tester,
  ) async {
    final u = await Masuk(tester, const Size(360, 740));

    for (final kategori in KategoriPengaturan.values) {
      expect(find.byKey(ValueKey('Kategori-${kategori.name}')), findsOneWidget, reason: kategori.label);
    }
    expect(find.text('Perangkat ini'), findsNothing);

    await BukaKategoriPengaturan(tester, KategoriPengaturan.tampilan);
    expect(find.text('Ukuran tampilan'), findsOneWidget);
    expect(find.byKey(const ValueKey('Kategori-tampilan')), findsNothing);

    await tester.tap(find.byKey(const ValueKey('KembaliKeKategori')));
    await tester.pump(const Duration(milliseconds: 300));
    expect(find.byKey(const ValueKey('Kategori-tampilan')), findsOneWidget);
    expect(find.text('Ukuran tampilan'), findsNothing);
    await Lepas(tester, u);
  });

  group('Ubah PIN (D-301)', () {
    Future<void> Buka(WidgetTester tester) async {
      await BukaKategoriPengaturan(tester, KategoriPengaturan.keamanan);
      await tester.tap(find.byKey(const ValueKey('UbahPin')));
      await tester.pump(const Duration(milliseconds: 300));
    }

    Future<void> Isi(WidgetTester tester, String lama, String baru, String ulangi) async {
      await tester.enterText(find.byKey(const ValueKey('PinLama')), lama);
      await tester.enterText(find.byKey(const ValueKey('PinBaru')), baru);
      await tester.enterText(find.byKey(const ValueKey('PinUlangi')), ulangi);
      await tester.tap(find.byKey(const ValueKey('SimpanPin')));
      await Tunggu(tester);
    }

    testWidgets('PIN lama benar: dikirim ke server, data kasir disegarkan, dialog menutup dengan kabar berhasil', (
      tester,
    ) async {
      final u = await Masuk(tester, const Size(1280, 900));
      u.server.penangan = (p) async {
        if (p.url.path.endsWith('/kasir/ubah-pin')) {
          return JsonUji({'Berhasil': true});
        }
        if (p.url.path.endsWith('/data-awal')) {
          return JsonUji(DataAwalUji());
        }
        if (p.url.path.endsWith('/katalog')) {
          return JsonUji(KatalogUji());
        }
        throw http.ClientException('offline');
      };
      await Buka(tester);
      await Isi(tester, '482915', '739204', '739204');

      final kirim = u.server.permintaan.singleWhere((p) => p.url.path.endsWith('/kasir/ubah-pin'));
      final badan = jsonDecode(kirim.body) as Map<String, Object?>;
      expect(badan['PinLama'], '482915');
      expect(badan['PinBaru'], '739204');
      expect(badan['UuidPengguna'], isNotEmpty);
      expect(u.server.permintaan.any((p) => p.url.path.endsWith('/data-awal')), isTrue);
      expect(find.byKey(const ValueKey('SimpanPin')), findsNothing);
      expect(find.text('PIN sudah diganti.'), findsOneWidget);
      await Lepas(tester, u);
    });

    testWidgets('PIN lama salah: pesan server tampil dan dialog tetap terbuka', (tester) async {
      final u = await Masuk(tester, const Size(1280, 900));
      u.server.penangan = (p) async {
        if (p.url.path.endsWith('/kasir/ubah-pin')) {
          return JsonUji({
            'Galat': {
              'Kode': 'PinSalah',
              'Pesan': 'PIN salah. Sisa 4 percobaan sebelum PIN dikunci sementara.',
              'Bidang': 'Pin',
            },
          }, 422);
        }
        throw http.ClientException('offline');
      };
      await Buka(tester);
      await Isi(tester, '111222', '739204', '739204');

      expect(find.textContaining('Sisa 4 percobaan'), findsOneWidget);
      expect(find.byKey(const ValueKey('SimpanPin')), findsOneWidget);
      await Lepas(tester, u);
    });

    testWidgets('salah isi di perangkat tidak menghubungi server: PIN sama, bukan 6 angka, konfirmasi beda', (
      tester,
    ) async {
      final u = await Masuk(tester, const Size(1280, 900));
      await Buka(tester);
      u.server.permintaan.clear();

      await Isi(tester, '482915', '482915', '482915');
      expect(find.text('PIN baru harus berbeda dari PIN lama.'), findsOneWidget);
      await Isi(tester, '482915', '73920', '73920');
      expect(find.text('PIN baru harus 6 angka.'), findsOneWidget);
      await Isi(tester, '482915', '739204', '739205');
      expect(find.text('Ulangi PIN baru belum sama.'), findsOneWidget);
      expect(u.server.permintaan.where((p) => p.url.path.endsWith('/kasir/ubah-pin')), isEmpty);
      await Lepas(tester, u);
    });

    testWidgets('offline: pesan perlu internet dan dialog tetap terbuka', (tester) async {
      final u = await Masuk(tester, const Size(1280, 900));
      await Buka(tester);
      await Isi(tester, '482915', '739204', '739204');

      expect(find.byKey(const ValueKey('GalatUbahPin')), findsOneWidget);
      expect(tester.widget<Text>(find.byKey(const ValueKey('GalatUbahPin'))).data, contains('tersambung ke internet'));
      expect(find.byKey(const ValueKey('SimpanPin')), findsOneWidget);
      await Lepas(tester, u);
    });
  });

  group('SusunKesiapanPerangkat', () {
    final sekarang = DateTime(2026, 11, 14, 11);
    List<ButirKesiapan> Susun({
      StatusKoneksi koneksi = StatusKoneksi.Online,
      int tertunda = 0,
      StatusPrinter printer = const StatusPrinter(keadaan: KeadaanPrinter.Siap),
      int? selisih = 2,
      DateTime? data,
      int? responsMs,
    }) => SusunKesiapanPerangkat(
      koneksi: koneksi,
      tertunda: tertunda,
      printer: printer,
      selisihJamDetik: selisih,
      dataDiperbarui: data ?? sekarang.subtract(const Duration(hours: 1)),
      sekarang: sekarang,
      responsServerMs: responsMs,
    );

    test('semua beres: tidak ada butir peringatan', () {
      expect(Susun().where((b) => b.nada == NadaStatus.Peringatan), isEmpty);
    });

    test(
      'offline, data tertunda, printer belum diatur, jam melenceng, dan data basi masing-masing jadi peringatan',
      () {
        final butir = Susun(
          koneksi: StatusKoneksi.Offline,
          tertunda: 3,
          printer: const StatusPrinter(),
          selisih: 900,
          data: sekarang.subtract(const Duration(days: 2)),
        );
        final judul = [for (final b in butir.where((b) => b.nada == NadaStatus.Peringatan)) b.judul];
        expect(judul, [
          'Tidak tersambung ke server',
          '3 data belum terkirim',
          'Printer struk belum diatur',
          'Jam perangkat tidak tepat',
          'Data kasir sudah lama',
        ]);
      },
    );

    test('waktu respons server: cepat jadi centang, lambat (> 2 detik) jadi peringatan, offline atau belum terukur tak tampil', () {
      expect(
        Susun(responsMs: 180).firstWhere((b) => b.judul == 'Server cepat menjawab').keterangan,
        'Menjawab dalam 180 ms.',
      );
      final lambat = Susun(responsMs: 3400).firstWhere((b) => b.judul == 'Server lambat menjawab');
      expect(lambat.nada, NadaStatus.Peringatan);
      expect(lambat.keterangan, startsWith('Menjawab dalam 3,4 detik'));
      expect(Susun(responsMs: 2000).any((b) => b.judul == 'Server lambat menjawab'), isFalse);
      expect(Susun().any((b) => b.judul.startsWith('Server ')), isFalse);
      expect(Susun(koneksi: StatusKoneksi.Offline, responsMs: 3400).any((b) => b.judul.startsWith('Server ')), isFalse);
    });

    test('printer gagal membawa pesan galatnya', () {
      final butir = Susun(
        printer: const StatusPrinter(keadaan: KeadaanPrinter.Gagal, pesan: 'Kertas habis'),
      );
      expect(butir.firstWhere((b) => b.judul == 'Printer struk bermasalah').keterangan, 'Kertas habis');
    });
  });

  test('versi aplikasi sama dengan pubspec.yaml', () {
    final pubspec = File('pubspec.yaml').readAsStringSync();
    expect(RegExp(r'^version:\s*(\S+)', multiLine: true).firstMatch(pubspec)!.group(1), VersiAplikasi.lengkap);
  });
}
