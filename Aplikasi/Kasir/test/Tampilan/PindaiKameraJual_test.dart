import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:inti/Inti.dart';
import 'package:kasir/Aplikasi/Penyedia.dart';
import 'package:kasir/Domain/Perangkat/PemindaiKamera.dart';
import 'package:kasir/Tampilan/RuangKerja/RuangKerja.dart';
import 'package:sistem_desain/SistemDesain.dart';

import '../Pendukung/KatalogUji.dart';
import '../Pendukung/LingkunganUji.dart';
import '../Pendukung/PasangAplikasi.dart';

/// Pemindai barcode lewat kamera di layar Jual: kamera terus terbuka untuk banyak barang, hasil lewat jalur yang sama
/// dengan pemindai USB, barang yang tetap terlihat hanya dihitung sekali, dan kasir bisa pindah kamera depan/belakang.
void main() {
  Future<(LingkunganUji, PemindaiKameraTiruan)> Masuk(
    WidgetTester tester, {
    Size ukuran = const Size(1280, 900),
    PemindaiKameraTiruan? kamera,
  }) async {
    final u = LingkunganUji.Buat();
    final pabrik = kamera ?? PemindaiKameraTiruan();
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
    await PasangAplikasi(tester, u, ukuran: ukuran, pemindaiKamera: pabrik);
    await Tunggu(tester, const Duration(milliseconds: 600));
    await PilihKasir(tester, 'Rina Wulandari');
    await tester.pump();
    await KetikPin(tester, KasusPin(0)['Pin']! as String);
    await Tunggu(tester);
    expect(find.byType(RuangKerja), findsOneWidget);
    return (u, pabrik);
  }

  Future<void> BukaKamera(WidgetTester tester) async {
    await tester.tap(find.byKey(const ValueKey('TombolKameraPindai')));
    await Tunggu(tester);
  }

  Future<void> Kirim(WidgetTester tester, PemindaiKameraTiruan pabrik, String kode) async {
    pabrik.aktif!.Kirim(kode);
    await Tunggu(tester);
  }

  /// Jumlah (string desimal) tiap baris keranjang, dibaca langsung dari keadaan keranjang.
  List<String> JumlahKeranjang(WidgetTester tester) => [
    for (final b in ProviderScope.containerOf(tester.element(find.byType(RuangKerja))).read(penyediaKeranjang).baris)
      b.jumlah.KeString(),
  ];

  String HasilStrip(WidgetTester tester) => tester.widget<Text>(find.byKey(const ValueKey('HasilKameraPindai'))).data!;

  testWidgets('perangkat tanpa kamera (Windows): tombol kamera tidak ada, pemindai USB dan ketikan tetap jalan', (
    tester,
  ) async {
    final (u, _) = await Masuk(tester, kamera: PemindaiKameraTiruan(tersedia: false));

    expect(find.byKey(const ValueKey('TombolKameraPindai')), findsNothing);
    expect(find.byKey(const ValueKey('JalurKameraPindai')), findsNothing);
    await Lepas(tester, u);
  });

  testWidgets('buka kamera: strip tampil dengan kamera belakang. Kamera tetap terbuka untuk banyak barang', (
    tester,
  ) async {
    final (u, pabrik) = await Masuk(tester);
    await BukaKamera(tester);

    expect(find.byKey(const ValueKey('JalurKameraPindai')), findsOneWidget);
    expect(find.byKey(const ValueKey('PratinjauKameraTiruan')), findsOneWidget);
    expect(find.text('Kamera belakang'), findsOneWidget);
    expect(pabrik.sesi, hasLength(1));
    expect(pabrik.sesi.single.arahAwal, ArahKamera.Belakang);

    await Kirim(tester, pabrik, UuidUji.barcodeRotiLusin);
    expect(find.byType(BarisKeranjang), findsOneWidget);
    expect(HasilStrip(tester), startsWith('Ditambahkan: Roti Tawar Gandum'));

    // Barang lain langsung diterima tanpa membuka ulang kamera.
    await Kirim(tester, pabrik, UuidUji.barcodeAmericano);
    expect(find.text('Roti Tawar Gandum'), findsWidgets);
    expect(pabrik.sesi, hasLength(1), reason: 'Kamera tidak ditutup dan dibuka ulang di antara barang.');
    expect(pabrik.aktif!.tertutup, isFalse);
    expect(tester.takeException(), isNull);
    await Lepas(tester, u);
  });

  testWidgets('barang yang tetap terlihat hanya dihitung sekali. Setelah sempat hilang barang sama dihitung lagi', (
    tester,
  ) async {
    final (u, pabrik) = await Masuk(tester);
    await BukaKamera(tester);
    final jam0 = u.jam;

    await Kirim(tester, pabrik, UuidUji.barcodeRotiLusin);
    expect(JumlahKeranjang(tester), ['1.0000']);

    // Kamera memancarkan kode yang sama tiap 300 ms selama barangnya masih di depan lensa: tidak menambah.
    for (var i = 1; i <= 4; i++) {
      u.jam = jam0.add(Duration(milliseconds: 300 * i));
      await Kirim(tester, pabrik, UuidUji.barcodeRotiLusin);
    }
    expect(JumlahKeranjang(tester), ['1.0000'], reason: 'Barang yang sama terus terlihat: tetap satu.');

    // Barang diangkat (tidak terlihat 1 detik), barang sama berikutnya didekatkan: dihitung lagi.
    u.jam = jam0.add(const Duration(milliseconds: 300 * 4 + 1000));
    await Kirim(tester, pabrik, UuidUji.barcodeRotiLusin);
    expect(JumlahKeranjang(tester), ['2.0000']);
    await Lepas(tester, u);
  });

  testWidgets('kode tidak dikenal: hasil galat di strip, keranjang tidak berubah', (tester) async {
    final (u, pabrik) = await Masuk(tester);
    await BukaKamera(tester);

    await Kirim(tester, pabrik, '9990000000009');

    expect(find.byType(BarisKeranjang), findsNothing);
    expect(HasilStrip(tester), contains('9990000000009'));
    expect(HasilStrip(tester), contains('tidak ditemukan'));
    await Lepas(tester, u);
  });

  testWidgets('ganti kamera depan ↔ belakang tanpa menutup sesi, pilihan diingat untuk pembukaan berikutnya', (
    tester,
  ) async {
    final (u, pabrik) = await Masuk(tester);
    await BukaKamera(tester);
    final wadah = ProviderScope.containerOf(tester.element(find.byType(RuangKerja)));

    await tester.tap(find.byKey(const ValueKey('GantiKameraPindai')));
    await Tunggu(tester);

    expect(pabrik.sesi, hasLength(1));
    expect(pabrik.sesi.single.jumlahGanti, 1);
    expect(find.text('Kamera depan'), findsOneWidget);
    expect(wadah.read(penyediaPengaturanPerangkat).kameraPindai, ArahKamera.Depan);
    // Kamera depan tidak punya senter: tombol senter hilang.
    expect(find.byKey(const ValueKey('SenterKameraPindai')), findsNothing);

    // Tutup lalu buka lagi: langsung memakai kamera depan.
    await tester.tap(find.byKey(const ValueKey('TutupKameraPindai')));
    await Tunggu(tester);
    expect(find.byKey(const ValueKey('JalurKameraPindai')), findsNothing);
    expect(pabrik.sesi.single.tertutup, isTrue);
    await BukaKamera(tester);
    expect(pabrik.sesi, hasLength(2));
    expect(pabrik.sesi.last.arahAwal, ArahKamera.Depan);
    expect(find.text('Kamera depan'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('GantiKameraPindai')));
    await Tunggu(tester);
    expect(find.text('Kamera belakang'), findsOneWidget);
    expect(wadah.read(penyediaPengaturanPerangkat).kameraPindai, ArahKamera.Belakang);
    await Lepas(tester, u);
  });

  testWidgets('hanya satu kamera: tombol ganti nonaktif dengan penjelasan. Senter kamera belakang menyala', (
    tester,
  ) async {
    final (u, pabrik) = await Masuk(tester, kamera: PemindaiKameraTiruan(jumlahKamera: 1));
    await BukaKamera(tester);

    final ganti = tester.widget<IconButton>(find.byKey(const ValueKey('GantiKameraPindai')));
    expect(ganti.onPressed, isNull);
    expect(ganti.tooltip, 'Perangkat ini hanya punya satu kamera');

    await tester.tap(find.byKey(const ValueKey('SenterKameraPindai')));
    await Tunggu(tester);
    expect(pabrik.aktif!.jumlahSenter, 1);
    expect(find.byTooltip('Matikan senter'), findsOneWidget);
    await Lepas(tester, u);
  });

  testWidgets('kamera tidak bisa dibuka (izin ditolak): pesan jelas, kasir bisa menutup strip dan tetap mengetik', (
    tester,
  ) async {
    final (u, pabrik) = await Masuk(
      tester,
      kamera: PemindaiKameraTiruan(galat: 'Izin kamera belum diberikan. Izinkan akses kamera untuk aplikasi ini.'),
    );
    await BukaKamera(tester);

    expect(find.byKey(const ValueKey('GalatKameraPindai')), findsOneWidget);
    expect(find.textContaining('Izin kamera belum diberikan'), findsOneWidget);
    expect(find.byKey(const ValueKey('PratinjauKameraTiruan')), findsNothing);

    await tester.tap(find.byKey(const ValueKey('TutupKameraPindai')));
    await Tunggu(tester);
    expect(find.byKey(const ValueKey('JalurKameraPindai')), findsNothing);
    expect(pabrik.sesi.single.tertutup, isTrue);
    await Lepas(tester, u);
  });

  testWidgets('produk berpilihan membuka panel. Pindaian saat panel terbuka diabaikan, keranjang tidak berubah', (
    tester,
  ) async {
    final (u, pabrik) = await Masuk(tester);
    await BukaKamera(tester);

    await Kirim(tester, pabrik, UuidUji.barcodeKopiSusu);
    expect(find.byType(PanelTugas), findsOneWidget);
    expect(HasilStrip(tester), 'Lanjutkan di panel yang terbuka.');

    await Kirim(tester, pabrik, UuidUji.barcodeRotiLusin);
    expect(
      find.byType(BarisKeranjang),
      findsNothing,
      reason: 'Pindaian di belakang panel tidak boleh mengubah keranjang.',
    );
    await Lepas(tester, u);
  });

  testWidgets('pindah ke menu lain mematikan kamera. Kembali ke Jual kamera tertutup sampai dibuka lagi', (
    tester,
  ) async {
    final (u, pabrik) = await Masuk(tester);
    await BukaKamera(tester);
    expect(pabrik.aktif, isNotNull);

    await tester.tap(find.text('Riwayat'));
    await Tunggu(tester);
    await tester.tap(find.text('Jual'));
    await Tunggu(tester);

    expect(pabrik.aktif, isNull, reason: 'Semua sesi kamera ditutup saat meninggalkan layar Jual.');
    expect(find.byKey(const ValueKey('JalurKameraPindai')), findsNothing);
    await Lepas(tester, u);
  });

  testWidgets('pengaturan: kamera pemindai default bisa dipilih dan dipakai saat strip dibuka', (tester) async {
    final (u, pabrik) = await Masuk(tester);
    await tester.tap(find.text('Pengaturan'));
    await Tunggu(tester);

    await tester.ensureVisible(find.byKey(const ValueKey('KameraPindai')));
    await tester.tap(find.text('Kamera depan'));
    await Tunggu(tester);
    final wadah = ProviderScope.containerOf(tester.element(find.byType(RuangKerja)));
    expect(wadah.read(penyediaPengaturanPerangkat).kameraPindai, ArahKamera.Depan);

    await tester.tap(find.text('Jual'));
    await Tunggu(tester);
    await BukaKamera(tester);
    expect(pabrik.aktif!.arahAwal, ArahKamera.Depan);
    await Lepas(tester, u);
  });

  for (final ukuran in const [Size(360, 740), Size(800, 1280)]) {
    testWidgets('strip kamera rapi tanpa overflow di ${ukuran.width.toInt()} dp', (tester) async {
      final (u, pabrik) = await Masuk(tester, ukuran: ukuran);
      await BukaKamera(tester);
      await Kirim(tester, pabrik, UuidUji.barcodeRotiLusin);

      expect(find.byKey(const ValueKey('JalurKameraPindai')), findsOneWidget);
      expect(find.byKey(const ValueKey('TutupKameraPindai')), findsOneWidget);
      expect(tester.takeException(), isNull);
      await Lepas(tester, u);
    });
  }
}
