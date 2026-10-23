import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:adaptor_perangkat/AdaptorPerangkat.dart';
import 'package:inti/Inti.dart';
import 'package:kasir/Data/RepositoriKasir.dart';
import 'package:kasir/Domain/Dapur/LayananTiketDapur.dart';
import 'package:kasir/Domain/Struk/PemindaiPrinter.dart';
import 'package:kasir/Domain/Struk/ProfilPrinter.dart';
import 'package:kasir/Tampilan/LayarPengaturan.dart';
import 'package:kasir/Tampilan/RuangKerja/RuangKerja.dart';
import 'package:kasir/Tampilan/Struk/BagianPrinter.dart';
import 'package:sistem_desain/SistemDesain.dart';

import '../Pendukung/KatalogUji.dart';
import '../Pendukung/LingkunganUji.dart';
import '../Pendukung/PasangAplikasi.dart';

/// Cetak struk di aplikasi (PRD v1.79): atur printer di Pengaturan (cetak uji sebelum simpan), cetak otomatis setelah
/// bayar tunai (plus buka laci), cetak ulang bertanda, galat printer tampil tanpa membatalkan transaksi, bilah status.
void main() {
  Future<LingkunganUji> Masuk(WidgetTester tester, Size ukuran, {ProfilPrinter? printer, bool meja = false}) async {
    final u = LingkunganUji.Buat();
    await tester.runAsync(() async {
      await u.SiapkanAktif();
      await u.SiapkanKatalog();
      if (meja) {
        await u.SiapkanMeja();
      }
      await printer?.Simpan(u.repositori);
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
    // Digulir ke tengah agar tidak tertutup bilah atas ruang kerja.
    await tester.runAsync(() => Scrollable.ensureVisible(tester.element(finder), alignment: 0.5));
    await tester.pump();
    // Kolom teks yang masih fokus menggulir kursornya ke layar dengan animasi setelah lompatan di atas; selama animasi
    // itu Scrollable mengabaikan sentuhan, jadi biarkan selesai lalu pastikan tombol masih terlihat sebelum mengetuk.
    await tester.pump(const Duration(milliseconds: 600));
    await tester.pump(const Duration(milliseconds: 600));
    await tester.runAsync(() => Scrollable.ensureVisible(tester.element(finder), alignment: 0.5));
    await tester.pump(const Duration(milliseconds: 600));
    await tester.tap(finder);
    await Tunggu(tester);
  }

  /// Gulir area kerja ke atas sampai [finder] terbangun (daftar Pengaturan dibangun lazy di layar sempit).
  Future<void> GulirKe(WidgetTester tester, Finder finder) async {
    // Pakai daftar Pengaturan, bukan Scrollable pertama di pohon (itu rel navigasi Ruang Kerja).
    await tester.scrollUntilVisible(
      finder,
      -200,
      scrollable: find.descendant(of: find.byType(LayarPengaturan), matching: find.byType(Scrollable)).first,
    );
    await tester.pump();
  }

  Future<void> BayarUangPas(WidgetTester tester, Size ukuran) async {
    await Ketuk(tester, find.byWidgetPredicate((w) => w is UbinProduk && w.nama.startsWith('Americano')));
    if (ukuran.width < 600) {
      await Ketuk(tester, find.textContaining('Keranjang | '));
    }
    await Ketuk(tester, find.widgetWithText(FilledButton, 'Bayar').last);
    await Ketuk(tester, find.widgetWithText(ChoiceChip, 'Tunai'));
    await Ketuk(tester, find.widgetWithText(FilledButton, 'Uang pas'));
    expect(find.text('Pembayaran berhasil'), findsOneWidget);
  }

  for (final ukuran in const [Size(1280, 900), Size(800, 1000), Size(360, 740)]) {
    final dp = '${ukuran.width.toInt()} dp';

    testWidgets('atur printer di Pengaturan: validasi, cetak uji, simpan → bilah status "Printer siap" ($dp)', (
      tester,
    ) async {
      final u = await Masuk(tester, ukuran);
      await Ketuk(tester, NavPengaturan().last);
      await Ketuk(tester, find.widgetWithText(FilledButton, 'Atur printer'));

      await Ketuk(tester, find.widgetWithText(FilledButton, 'Simpan printer'));
      expect(find.text('Isi alamat IP printer, misal 192.168.1.50.'), findsOneWidget);

      await tester.enterText(find.widgetWithText(TextField, 'Alamat IP printer'), '192.168.1.50');
      await Ketuk(tester, find.text('80 mm'));
      await Ketuk(tester, find.widgetWithText(OutlinedButton, 'Cetak uji'));
      expect(u.printer.AmbilTeks(), contains('CETAK UJI'));
      expect(u.printer.AmbilTeks(), contains('1234567890' * 4), reason: 'Penggaris 48 kolom kertas 80 mm.');
      expect(find.text('Printer belum diatur'), findsOneWidget, reason: 'Cetak uji belum menyimpan printer.');

      await Ketuk(tester, find.widgetWithText(FilledButton, 'Simpan printer'));
      await GulirKe(tester, find.text('Printer LAN/Wi-Fi 192.168.1.50:9100 | 80 mm'));
      expect(find.text('Printer LAN/Wi-Fi 192.168.1.50:9100 | 80 mm'), findsOneWidget);
      expect(find.text('Printer siap'), findsOneWidget);
      final profil = await tester.runAsync(() => ProfilPrinter.Muat(u.repositori));
      expect(profil?.alamat, '192.168.1.50');
      expect(tester.takeException(), isNull);
      await Lepas(tester, u);
    });

    testWidgets('bayar tunai → struk tercetak otomatis + laci terbuka; cetak lagi bertanda CETAK ULANG ($dp)', (
      tester,
    ) async {
      final u = await Masuk(tester, ukuran, printer: const ProfilPrinter(alamat: '192.168.1.50'));
      await BayarUangPas(tester, ukuran);
      await Tunggu(tester);
      expect(find.text('Struk sudah dicetak.'), findsOneWidget);
      expect(u.printer.kiriman, hasLength(1));
      expect(u.printer.AmbilTeks(), contains('INV/SLB/'));
      expect(u.printer.AmbilTeks(), contains('Americano'));
      expect(u.printer.CekBukaLaci(), isTrue);

      await Ketuk(tester, find.widgetWithText(OutlinedButton, 'Cetak ulang struk'));
      expect(u.printer.kiriman, hasLength(2));
      expect(u.printer.AmbilTeks(), contains('CETAK ULANG'));
      expect(u.printer.CekBukaLaci(), isFalse, reason: 'Laci hanya dibuka saat pembayaran, bukan saat cetak ulang.');
      expect(tester.takeException(), isNull);
      await Lepas(tester, u);
    });
  }

  testWidgets('printer gagal: pesan tampil, transaksi tetap tersimpan, bilah status "Printer bermasalah"', (
    tester,
  ) async {
    final u = await Masuk(tester, const Size(1280, 900), printer: const ProfilPrinter(alamat: '192.168.1.50'));
    u.printer.galat =
        'Printer di 192.168.1.50:9100 tidak tersambung. Pastikan printer menyala dan satu jaringan, '
        'lalu coba lagi.';
    await BayarUangPas(tester, const Size(1280, 900));
    await Tunggu(tester);
    expect(find.textContaining('tidak tersambung'), findsOneWidget);
    expect(find.text('Printer bermasalah'), findsOneWidget);
    expect(find.widgetWithText(OutlinedButton, 'Coba cetak lagi'), findsOneWidget);
    expect(await tester.runAsync(() => u.db.select(u.db.penjualan).get()), hasLength(1));

    // v1.97: cadangan lewat printer sistem (PDF/AirPrint/driver OS); bilah status printer thermal tidak berubah.
    await Ketuk(tester, find.widgetWithText(OutlinedButton, 'Cetak lewat printer sistem / PDF'));
    expect(find.text('Struk sudah dicetak.'), findsOneWidget);
    expect(u.pemindai.sistem.dokumen, hasLength(1));
    final (dokumen, lebar) = u.pemindai.sistem.dokumen.single;
    expect(lebar, LebarKertas.Mm58);
    expect(dokumen.baris.whereType<BarisDuaKolom>().map((b) => b.kiri), contains('TOTAL'));
    expect(find.text('Printer bermasalah'), findsOneWidget);

    u.printer.galat = null;
    await Ketuk(tester, find.widgetWithText(OutlinedButton, 'Cetak ulang struk'));
    expect(find.text('Struk sudah dicetak.'), findsOneWidget);
    expect(find.text('Printer siap'), findsOneWidget);

    // Riwayat: cetak ulang dari daftar transaksi hari ini.
    await Ketuk(tester, find.widgetWithText(FilledButton, 'Transaksi baru'));
    await Ketuk(tester, find.text('Riwayat').last);
    await Ketuk(tester, find.textContaining('INV/SLB/').first);
    await Ketuk(tester, find.widgetWithText(OutlinedButton, 'Cetak ulang struk'));
    expect(find.text('Struk dicetak ulang.'), findsOneWidget);
    expect(u.printer.AmbilTeks(), contains('CETAK ULANG'));
    await Lepas(tester, u);
  });

  testWidgets('printer belum diatur: layar selesai menjelaskan cara mengatur, tidak ada tombol cetak', (tester) async {
    final u = await Masuk(tester, const Size(1280, 900));
    await BayarUangPas(tester, const Size(1280, 900));
    // D-66: layar selesai punya tombol Atur printer langsung, jadi peringatannya memandu ke tombol itu.
    expect(
      find.text('Printer belum diatur | struk belum tercetak. Ketuk Atur printer untuk mencetak.'),
      findsOneWidget,
    );
    expect(find.widgetWithText(OutlinedButton, 'Cetak struk'), findsNothing);
    expect(u.printer.kiriman, isEmpty);

    // D-66: tombol Atur printer di layar selesai membuka pengaturan printer tanpa meninggalkan transaksi.
    await Ketuk(tester, find.byKey(const ValueKey('AturPrinterSelesai')));
    expect(find.byType(BagianPrinter), findsOneWidget);
    expect(find.descendant(of: find.byType(Dialog), matching: find.text('Atur printer')), findsWidgets);
    await Ketuk(tester, find.byTooltip('Tutup'));
    expect(find.byType(BagianPrinter), findsNothing);
    expect(find.text('Pembayaran berhasil'), findsOneWidget);
    await Lepas(tester, u);
  });

  for (final ukuran in const [Size(1280, 900), Size(360, 740)]) {
    testWidgets('printer Bluetooth: cari printer ter-pair, pilih, cetak uji, simpan ($ukuran)', (tester) async {
      final u = await Masuk(tester, ukuran);
      u.pemindai.hasil[JenisTransport.BluetoothKlasik] = const [
        PrinterDitemukan(jenis: JenisTransport.BluetoothKlasik, alamat: '66:22:11:AA:BB:CC', nama: 'RPP02N'),
        PrinterDitemukan(jenis: JenisTransport.BluetoothKlasik, alamat: '00:11:22:33:44:55', nama: 'MTP-II'),
      ];
      await Ketuk(tester, NavPengaturan().last);
      await Ketuk(tester, find.widgetWithText(FilledButton, 'Atur printer'));
      await Ketuk(tester, find.text('Bluetooth'));
      expect(find.widgetWithText(TextField, 'Alamat IP printer'), findsNothing);

      await Ketuk(tester, find.widgetWithText(FilledButton, 'Simpan printer'));
      expect(find.text('Pilih printer dulu. Ketuk Cari printer untuk melihat daftarnya.'), findsOneWidget);

      await Ketuk(tester, find.widgetWithText(OutlinedButton, 'Cari printer'));
      expect(find.text('RPP02N'), findsOneWidget);
      await Ketuk(tester, find.text('MTP-II'));
      await Ketuk(tester, find.widgetWithText(OutlinedButton, 'Cetak uji'));
      expect(u.printer.AmbilTeks(), contains('CETAK UJI'));
      expect(u.pemindai.transportDibuat.last.alamat, '00:11:22:33:44:55');

      await Ketuk(tester, find.widgetWithText(FilledButton, 'Simpan printer'));
      await GulirKe(tester, find.text('Printer Bluetooth MTP-II | 58 mm'));
      expect(find.text('Printer Bluetooth MTP-II | 58 mm'), findsOneWidget);
      expect(find.text('Printer siap'), findsOneWidget);
      final profil = await tester.runAsync(() => ProfilPrinter.Muat(u.repositori));
      expect(
        (profil?.jenis, profil?.alamat, profil?.nama),
        (JenisTransport.BluetoothKlasik, '00:11:22:33:44:55', 'MTP-II'),
      );
      expect(tester.takeException(), isNull);
      await Lepas(tester, u);
    });
  }

  for (final ukuran in const [Size(1280, 900), Size(360, 740)]) {
    testWidgets('D-67 satu daftar printer: printer Bluetooth untuk tiket Dapur saja, printer struk tetap ($ukuran)', (
      tester,
    ) async {
      final u = await Masuk(tester, ukuran, meja: true, printer: const ProfilPrinter(alamat: '192.168.1.50'));
      u.pemindai.hasil[JenisTransport.BluetoothKlasik] = const [
        PrinterDitemukan(jenis: JenisTransport.BluetoothKlasik, alamat: '66:22:11:AA:BB:DD', nama: 'Printer Dapur'),
      ];
      await Ketuk(tester, NavPengaturan().last);
      await GulirKe(tester, find.widgetWithText(OutlinedButton, 'Tambah printer'));
      // Satu daftar: printer struk yang sudah ada tampil dengan lencana kegunaannya.
      expect(find.text('Struk kasir'), findsOneWidget);
      expect(find.text('Printer struk'), findsNothing, reason: 'Tidak ada lagi bagian terpisah untuk struk/dapur.');

      await Ketuk(tester, find.widgetWithText(OutlinedButton, 'Tambah printer'));
      // Printer kedua: struk sudah dipakai printer lain, jadi kegunaan struk mati; pilih stasiun Dapur.
      expect(tester.widget<SwitchListTile>(find.byKey(const ValueKey('KegunaanStruk'))).value, isFalse);
      await Ketuk(tester, find.widgetWithText(FilterChip, 'Dapur'));
      await Ketuk(tester, find.text('Bluetooth'));
      await Ketuk(tester, find.widgetWithText(OutlinedButton, 'Cari printer'));
      await Ketuk(tester, find.text('Printer Dapur'));
      await Ketuk(tester, find.widgetWithText(OutlinedButton, 'Cetak uji'));
      expect(u.printer.AmbilTeks(), contains('CETAK UJI'));
      await Ketuk(tester, find.widgetWithText(FilledButton, 'Simpan printer'));
      await GulirKe(tester, find.text('Printer Bluetooth Printer Dapur | 80 mm'));
      expect(find.text('Printer Bluetooth Printer Dapur | 80 mm'), findsOneWidget);
      expect(find.text('Tiket Dapur'), findsOneWidget);

      final tersimpan = await tester.runAsync(() => PrinterDapur.MuatSemua(u.repositori));
      expect(tersimpan!.containsKey('01K5STAS1VN000000000BAR001'), isFalse);
      expect(
        (
          tersimpan['01K5STAS1VN000000000DAPUR1']?.profil?.jenis,
          tersimpan['01K5STAS1VN000000000DAPUR1']?.profil?.alamat,
        ),
        (JenisTransport.BluetoothKlasik, '66:22:11:AA:BB:DD'),
      );
      final struk = await tester.runAsync(() => ProfilPrinter.Muat(u.repositori));
      expect(struk?.alamat, '192.168.1.50');
      expect(tester.takeException(), isNull);
      await Lepas(tester, u);
    });
  }

  testWidgets('D-67 satu printer untuk struk, dapur, dan bar sekaligus: kartu tunggal berlencana lengkap', (
    tester,
  ) async {
    final u = await Masuk(tester, const Size(1280, 900), meja: true);
    await Ketuk(tester, NavPengaturan().last);
    await Ketuk(tester, find.widgetWithText(FilledButton, 'Atur printer'));
    await tester.enterText(find.widgetWithText(TextField, 'Alamat IP printer'), '192.168.1.70');
    await Ketuk(tester, find.widgetWithText(FilledButton, 'Simpan printer'));
    await GulirKe(tester, find.text('Printer LAN/Wi-Fi 192.168.1.70:9100 | 58 mm'));

    expect(find.text('Struk kasir'), findsOneWidget);
    expect(find.text('Tiket Dapur'), findsOneWidget);
    expect(find.text('Tiket Bar'), findsOneWidget);
    expect(find.text('Printer LAN/Wi-Fi 192.168.1.70:9100 | 58 mm'), findsOneWidget, reason: 'Satu kartu saja.');
    final tersimpan = await tester.runAsync(() => PrinterDapur.MuatSemua(u.repositori));
    expect(tersimpan!.values.every((p) => p.samaDenganStruk), isTrue);
    expect(tersimpan.keys, containsAll(['01K5STAS1VN000000000BAR001', '01K5STAS1VN000000000DAPUR1']));
    await Lepas(tester, u);
  });

  testWidgets('D-67 printer harus punya kegunaan: tanpa struk dan tanpa stasiun tidak bisa disimpan', (tester) async {
    final u = await Masuk(tester, const Size(1280, 900), meja: true);
    await Ketuk(tester, NavPengaturan().last);
    await Ketuk(tester, find.widgetWithText(FilledButton, 'Atur printer'));
    await tester.enterText(find.widgetWithText(TextField, 'Alamat IP printer'), '192.168.1.60');
    // Printer pertama = satu printer untuk semuanya: struk menyala dan semua stasiun terpilih.
    expect(tester.widget<SwitchListTile>(find.byKey(const ValueKey('KegunaanStruk'))).value, isTrue);
    expect(tester.widget<FilterChip>(find.widgetWithText(FilterChip, 'Dapur')).selected, isTrue);
    expect(tester.widget<FilterChip>(find.widgetWithText(FilterChip, 'Bar')).selected, isTrue);
    // Matikan semua kegunaan lalu simpan.
    await Ketuk(tester, find.byKey(const ValueKey('KegunaanStruk')));
    await Ketuk(tester, find.widgetWithText(FilterChip, 'Dapur'));
    await Ketuk(tester, find.widgetWithText(FilterChip, 'Bar'));
    await Ketuk(tester, find.widgetWithText(FilledButton, 'Simpan printer'));
    expect(find.textContaining('Pilih kegunaan printer'), findsOneWidget);
    expect(await tester.runAsync(() => ProfilPrinter.Muat(u.repositori)), isNull);
    await Lepas(tester, u);
  });

  testWidgets('Bluetooth LE: izin ditolak → pesan; tidak ada printer → petunjuk; bayar mencetak lewat BLE', (
    tester,
  ) async {
    final u = await Masuk(tester, const Size(1280, 900));
    await Ketuk(tester, NavPengaturan().last);
    await Ketuk(tester, find.widgetWithText(FilledButton, 'Atur printer'));
    await Ketuk(tester, find.text('Bluetooth LE'));

    u.pemindai.galatSiapkan = 'Bluetooth mati. Nyalakan Bluetooth, lalu coba lagi.';
    await Ketuk(tester, find.widgetWithText(OutlinedButton, 'Cari printer'));
    expect(find.text('Bluetooth mati. Nyalakan Bluetooth, lalu coba lagi.'), findsOneWidget);

    u.pemindai.galatSiapkan = null;
    await Ketuk(tester, find.widgetWithText(OutlinedButton, 'Cari printer'));
    expect(find.textContaining('Tidak ada printer Bluetooth LE di sekitar'), findsOneWidget);

    u.pemindai.hasil[JenisTransport.Ble] = const [
      PrinterDitemukan(jenis: JenisTransport.Ble, alamat: 'BLE-01', nama: 'Printer_5D2B'),
    ];
    await Ketuk(tester, find.widgetWithText(OutlinedButton, 'Cari printer'));
    // Satu printer ditemukan langsung terpilih.
    await Ketuk(tester, find.widgetWithText(FilledButton, 'Simpan printer'));
    await GulirKe(tester, find.text('Printer Bluetooth LE Printer_5D2B | 58 mm'));
    expect(find.text('Printer Bluetooth LE Printer_5D2B | 58 mm'), findsOneWidget);

    await Ketuk(tester, find.text('Jual').last);
    await BayarUangPas(tester, const Size(1280, 900));
    await Tunggu(tester);
    expect(find.text('Struk sudah dicetak.'), findsOneWidget);
    expect(u.pemindai.transportDibuat.last.jenis, JenisTransport.Ble);
    await Lepas(tester, u);
  });

  for (final ukuran in const [Size(1280, 900), Size(360, 740)]) {
    testWidgets('v1.96 wizard uji perangkat: cetak uji, jawab langkah, pindai, simpan (offline → tertunda) ($ukuran)', (
      tester,
    ) async {
      final u = await Masuk(tester, ukuran, printer: const ProfilPrinter(alamat: '192.168.1.50'));
      await Ketuk(tester, NavPengaturan().last);
      // Bagian uji perangkat berada di bawah printer struk: gulir turun (daftar Pengaturan dibangun lazy).
      await tester.scrollUntilVisible(find.text('Mulai uji perangkat'), 200, scrollable: find.byType(Scrollable).first);
      await tester.pump();
      expect(find.text('Perangkat ini belum pernah diuji.'), findsOneWidget);
      await Ketuk(tester, find.text('Mulai uji perangkat'));

      await Ketuk(tester, find.widgetWithText(OutlinedButton, 'Cetak halaman uji'));
      expect(u.printer.AmbilTeks(), contains('CETAK UJI'));
      await Ketuk(tester, find.widgetWithText(ChoiceChip, 'Berhasil').first);
      await Ketuk(tester, find.widgetWithText(ChoiceChip, 'Tidak ada pemotong'));
      await Ketuk(tester, find.widgetWithText(ChoiceChip, 'Tidak pakai laci'));
      final simpan = find.widgetWithText(FilledButton, 'Simpan hasil uji');
      expect(tester.widget<FilledButton>(simpan).onPressed, isNull, reason: 'Pemindai belum diuji.');
      await tester.enterText(find.widgetWithText(TextField, 'Hasil pindai'), '8991002101234');
      await tester.pump();
      await Ketuk(tester, simpan);
      await Tunggu(tester, const Duration(seconds: 2));
      final profil = await tester.runAsync(() => u.repositori.AmbilPengaturan(KunciPengaturan.profilHardwareTertunda));
      expect(profil, '1', reason: 'Server tiruan offline: laporan tertunda.');
      // Bagian menyusut setelah disimpan (di 360 dp daftar lazy membangunnya ulang): kembali ke atas lalu gulir turun ke
      // ringkasan hasil uji terakhir.
      const ringkasan = 'Uji terakhir: cetak berhasil, potong tidak dipakai, laci tidak dipakai, pemindai berhasil.';
      await tester.drag(find.byType(Scrollable).first, const Offset(0, 5000));
      await tester.pump();
      await tester.scrollUntilVisible(find.text(ringkasan), 200, scrollable: find.byType(Scrollable).first);
      await tester.pump();
      expect(find.text(ringkasan), findsOneWidget);
      expect(tester.takeException(), isNull);
      await Lepas(tester, u);
    });
  }
}
