import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:inti/Inti.dart';
import 'package:kasir/Aplikasi/Penyedia.dart';
import 'package:kasir/Domain/Sesi/StafLokal.dart';
import 'package:kasir/Tampilan/LayarJual.dart';
import 'package:kasir/Tampilan/RuangKerja/RuangKerja.dart';
import 'package:sistem_desain/SistemDesain.dart';

import '../Pendukung/KatalogUji.dart';
import '../Pendukung/LingkunganUji.dart';
import '../Pendukung/MuatFont.dart';
import '../Pendukung/PasangAplikasi.dart';

/// Layar Jual & Bayar (Rincian F-07c, PRD §17.2.3, §17.2.7) di 360/800/1280dp dengan server tiruan: katalog diunduh
/// saat masuk, tambah produk, bayar, dan outbox berisi `Penjualan.Buat`. Sinkron dibuat offline agar outbox terlihat.
void main() {
  const ukuranHp = Size(360, 740);
  const ukuranTablet = Size(800, 1280);
  const ukuranDesktop = Size(1280, 900);

  /// PNG 1×1 untuk gambar QRIS statis.
  final pngQris = base64Decode(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==',
  );

  Future<http.Response> Function(http.Request) PenanganServer({
    Map<String, Object?>? dataAwal,
    Map<String, Object?>? katalog,
    Set<String> produkHabis = const {},
  }) => (p) async {
    final jalur = p.url.path;
    if (jalur.endsWith('/produk-habis')) {
      return JsonUji({'Produk': produkHabis.toList()});
    }
    if (jalur.endsWith('/habis') && p.method == 'POST') {
      final isi = jsonDecode(p.body) as Map<String, Object?>;
      return JsonUji({'Uuid': jalur.split('/')[jalur.split('/').length - 2], 'Habis': isi['Habis']});
    }
    if (jalur.endsWith('/data-awal')) {
      return JsonUji(dataAwal ?? DataAwalUji());
    }
    if (jalur.endsWith('/katalog')) {
      return JsonUji(katalog ?? KatalogUji());
    }
    if (jalur.contains('/katalog/gambar/')) {
      return BytesUji(pngQris);
    }
    if (jalur.endsWith('/gambar-qris')) {
      return http.Response.bytes(pngQris, 200, headers: {'content-type': 'image/png'});
    }
    throw http.ClientException('offline');
  };

  /// Perangkat aktif, shift Rina terbuka, katalog diunduh dari server tiruan saat aplikasi dibuka; masuk sebagai Rina.
  Future<LingkunganUji> MasukJual(
    WidgetTester tester, {
    Size ukuran = ukuranDesktop,
    Map<String, Object?>? dataAwal,
    Map<String, Object?>? katalog,
    Set<String> produkHabis = const {},
  }) async {
    final u = LingkunganUji.Buat();
    await tester.runAsync(() async {
      await u.SiapkanAktif(dataAwal: dataAwal);
      await u.shift.BukaShift(kasir: await u.Staf('Rina Wulandari'), kasAwal: Uang.DariBulat(500000));
    });
    u.server.penangan = PenanganServer(dataAwal: dataAwal, katalog: katalog, produkHabis: produkHabis);
    await PasangAplikasi(tester, u, ukuran: ukuran);
    await Tunggu(tester, const Duration(milliseconds: 600));
    await PilihKasir(tester, 'Rina Wulandari');
    await tester.pump();
    await KetikPin(tester, KasusPin(0)['Pin']! as String);
    await Tunggu(tester);
    expect(find.byType(RuangKerja), findsOneWidget);
    return u;
  }

  Finder Ubin(String nama) => find.byWidgetPredicate((w) => w is UbinProduk && w.nama == nama);

  testWidgets('ubin produk menampilkan gambar katalog yang diunduh dengan token perangkat', (tester) async {
    final katalog = KatalogUji();
    final produk = (katalog['Produk']! as List<Object?>).cast<Map<String, Object?>>();
    produk.firstWhere((p) => p['Uuid'] == UuidUji.americano)['UrlGambarKecil'] =
        'http://server-internal/api/pos/v1/katalog/gambar/${UuidUji.americano}?ukuran=kecil&versi=VERSI1';

    final u = await MasukJual(tester, katalog: katalog);
    final ubin = Ubin('Americano Panas');
    expect(find.descendant(of: ubin, matching: find.byType(Image)), findsOneWidget);
    final permintaanGambar = u.server.permintaan.firstWhere((p) => p.url.path.contains('/katalog/gambar/'));
    expect(permintaanGambar.url.host, isNot('server-internal'));
    expect(permintaanGambar.url.path, contains('/api/pos/v1/katalog/gambar/'));
    expect(permintaanGambar.headers['Authorization'], startsWith('Bearer '));
    await Lepas(tester, u);
  });

  testWidgets('F-17 BR-17.2: produk habis tampil "Habis" dan tidak bisa ditambah; tahan ubin menandai tersedia lagi', (
    tester,
  ) async {
    final u = await MasukJual(tester, produkHabis: {UuidUji.americano});
    expect(find.descendant(of: Ubin('Americano Panas'), matching: find.text('Habis')), findsOneWidget);

    await tester.ensureVisible(Ubin('Americano Panas'));
    await tester.pump();
    await tester.tap(Ubin('Americano Panas'));
    await Tunggu(tester);
    expect(find.textContaining('ditandai habis di outlet ini'), findsOneWidget);

    await tester.longPress(Ubin('Americano Panas'));
    await Tunggu(tester);
    expect(find.text('Tandai Americano Panas tersedia lagi?'), findsOneWidget);
    await tester.tap(find.widgetWithText(FilledButton, 'Tandai tersedia'));
    await Tunggu(tester);

    final kirim = u.server.permintaan.lastWhere((p) => p.method == 'POST' && p.url.path.endsWith('/habis'));
    expect(kirim.url.path, '/api/pos/v1/produk/${UuidUji.americano}/habis');
    expect(jsonDecode(kirim.body), containsPair('Habis', false));
    expect(find.descendant(of: Ubin('Americano Panas'), matching: find.text('Habis')), findsNothing);
    await Lepas(tester, u);
  });

  Future<void> Ketuk(WidgetTester tester, Finder finder) async {
    await tester.ensureVisible(finder);
    await tester.pump();
    await tester.tap(finder);
    await Tunggu(tester);
  }

  Future<List<Map<String, Object?>>> AmbilOutboxPenjualan(WidgetTester tester, LingkunganUji u) async {
    final baris = await tester.runAsync(() => u.db.select(u.db.outbox).get());
    return [
      for (final b in baris!.where((b) => b.Jenis == 'Penjualan.Buat')) jsonDecode(b.Data) as Map<String, Object?>,
    ];
  }

  for (final (nama, ukuran) in [('360', ukuranHp), ('800', ukuranTablet), ('1280', ukuranDesktop)]) {
    testWidgets('alur jual tunai lengkap di $nama dp: katalog → tambah → bayar → selesai → outbox Penjualan.Buat', (
      tester,
    ) async {
      final u = await MasukJual(tester, ukuran: ukuran);

      // Katalog dari server tiruan: produk tampil, bahan baku tersembunyi, kategori.
      expect(u.server.permintaan.any((p) => p.url.path.endsWith('/katalog')), isTrue);
      expect(Ubin('Americano Panas'), findsOneWidget);
      expect(Ubin('Gula Aren Cair'), findsNothing);
      expect(find.widgetWithText(ChoiceChip, 'Makanan'), findsOneWidget);
      expect(tester.takeException(), isNull);

      await Ketuk(tester, Ubin('Americano Panas'));
      await Ketuk(tester, Ubin('Croissant Mentega Prancis Isi Cokelat Lumer Ukuran Jumbo'));
      await Ketuk(tester, Ubin('Americano Panas'));

      // 2 × 15.000 + 25.000 = 55.000 + PBJT 10% = 60.500.
      if (ukuran.width < 600) {
        expect(find.text('Keranjang | 2 baris'), findsOneWidget);
        expect(find.text('Rp 60.500'), findsOneWidget);
      } else {
        expect(find.text('Keranjang | 3 item'), findsOneWidget);
        expect(find.text('PBJT 10%'), findsOneWidget);
        expect(find.text('Rp 60.500'), findsOneWidget);
        expect(find.byType(BarisKeranjang), findsNWidgets(2));
      }
      expect(tester.takeException(), isNull);

      await Ketuk(tester, find.widgetWithText(FilledButton, 'Bayar'));
      // Pembayaran punya halaman sendiri: kepala berjudul + tombol kembali, dan katalog tidak ikut tampil.
      // Kalau ini kembali jadi panel di atas keranjang, ubin katalog akan ikut ditemukan.
      expect(find.byTooltip('Kembali ke keranjang'), findsOneWidget);
      expect(find.byType(PanelTugas), findsNothing);
      expect(Ubin('Americano Panas'), findsNothing, reason: 'Katalog tidak ikut tampil di halaman Bayar.');
      for (final metode in ['Tunai', 'QRIS', 'EDC BCA', 'Transfer BCA', 'GoPay']) {
        expect(find.widgetWithText(ChoiceChip, metode), findsOneWidget, reason: 'Metode $metode');
      }
      expect(find.widgetWithText(ChoiceChip, 'Kasbon'), findsNothing, reason: 'Metode di luar fase 1 disembunyikan.');

      await Ketuk(tester, find.widgetWithText(ChoiceChip, 'Tunai'));
      expect(find.text('Tagihan tunai'), findsOneWidget);
      expect(find.widgetWithText(FilledButton, 'Uang pas'), findsOneWidget);
      await Ketuk(tester, find.widgetWithText(OutlinedButton, 'Rp 100.000'));
      expect(find.text('Kembalian'), findsOneWidget);
      expect(find.text('Rp 39.500'), findsOneWidget);
      await Ketuk(tester, find.widgetWithText(FilledButton, 'Selesaikan pembayaran'));
      await Tunggu(tester);

      expect(find.text('Pembayaran berhasil'), findsOneWidget);
      expect(find.text('INV/SLB/260924/POS-001-0001'), findsOneWidget);
      expect(find.text('Rp 39.500'), findsOneWidget);
      expect(tester.takeException(), isNull);

      final outbox = await AmbilOutboxPenjualan(tester, u);
      expect(outbox, hasLength(1));
      expect(outbox.single['Nomor'], 'INV/SLB/260924/POS-001-0001');
      expect((outbox.single['Ringkasan']! as Map<String, Object?>)['TotalAkhir'], '60500.00');
      expect((outbox.single['Ringkasan']! as Map<String, Object?>)['Kembalian'], '39500.00');
      expect((outbox.single['Pembayaran']! as List<Object?>).single, containsPair('Jumlah', '100000.00'));
      expect(find.text('2 belum terkirim'), findsOneWidget, reason: 'Shift.Buka + Penjualan.Buat menunggu sinkron.');

      await Ketuk(tester, find.widgetWithText(FilledButton, 'Transaksi baru'));
      expect(find.byType(PanelTugas), findsNothing);
      expect(find.text('Rp 60.500'), findsNothing, reason: 'Keranjang kosong untuk transaksi baru.');

      // Riwayat transaksi hari ini dengan status sinkron.
      await Ketuk(tester, find.text('Riwayat'));
      expect(find.text('Riwayat transaksi hari ini'), findsOneWidget);
      expect(find.text('INV/SLB/260924/POS-001-0001'), findsOneWidget);
      expect(find.text('Belum terkirim'), findsOneWidget);
      expect(tester.takeException(), isNull);
      await Lepas(tester, u);
    });
  }

  testWidgets(
    'F-05h produk bernomor seri: panel nomor seri (Enter menambah), jumlah = banyaknya nomor, terkirim di outbox',
    (tester) async {
      final u = await MasukJual(tester, katalog: KatalogPonselUji());

      await Ketuk(tester, Ubin('Ponsel Android 8/256 GB Hitam'));
      expect(find.text('Nomor seri / IMEI'), findsOneWidget);
      expect(find.text('Jumlah'), findsNothing, reason: 'Jumlah mengikuti banyaknya nomor seri.');

      await Ketuk(tester, find.widgetWithText(FilledButton, 'Tambah ke keranjang'));
      expect(find.text('Isi minimal satu nomor seri.'), findsOneWidget);

      Future<void> TambahSeri(String nomor) async {
        await tester.enterText(find.widgetWithText(TextField, 'Ketik atau pindai nomor seri'), nomor);
        await tester.testTextInput.receiveAction(TextInputAction.done);
        await Tunggu(tester);
      }

      await TambahSeri('IMEI-0001');
      await TambahSeri('IMEI-0002');
      await TambahSeri('imei-0001');
      expect(find.text('Nomor seri imei-0001 sudah ada di keranjang ini.'), findsOneWidget);
      expect(find.text('2 unit'), findsOneWidget);

      await Ketuk(tester, find.widgetWithText(FilledButton, 'Tambah ke keranjang'));
      expect(find.textContaining('No. seri: IMEI-0001, IMEI-0002'), findsOneWidget);
      expect(tester.takeException(), isNull);

      await Ketuk(tester, find.widgetWithText(FilledButton, 'Bayar'));
      await Ketuk(tester, find.widgetWithText(ChoiceChip, 'Tunai'));
      await Ketuk(tester, find.widgetWithText(FilledButton, 'Uang pas'));
      expect(find.text('Pembayaran berhasil'), findsOneWidget);

      final outbox = await AmbilOutboxPenjualan(tester, u);
      final baris = (outbox.single['Baris']! as List<Object?>).cast<Map<String, Object?>>().single;
      expect(baris['Jumlah'], '2.0000');
      expect(baris['NomorSeri'], ['IMEI-0001', 'IMEI-0002']);
      await Lepas(tester, u);
    },
  );

  testWidgets('audit kemudahan pakai: bayar tunai 2 ketukan (Tunai terpilih otomatis, Uang pas menyelesaikan); '
      'pindai barang di layar berhasil langsung memulai transaksi baru', (tester) async {
    final u = await MasukJual(tester, ukuran: ukuranDesktop);
    await Ketuk(tester, Ubin('Americano Panas'));

    await Ketuk(tester, find.widgetWithText(FilledButton, 'Bayar'));
    expect(find.text('Tagihan tunai'), findsOneWidget, reason: 'Tunai sudah terpilih tanpa diketuk.');
    await Ketuk(tester, find.widgetWithText(FilledButton, 'Uang pas'));
    await Tunggu(tester);
    expect(find.text('Pembayaran berhasil'), findsOneWidget);
    final jual = (await AmbilOutboxPenjualan(tester, u)).single;
    expect((jual['Ringkasan']! as Map<String, Object?>)['Kembalian'], '0.00');

    // Layar berhasil menutupi katalog; pindaian barang pembeli berikutnya langsung memulai transaksi baru.
    for (final karakter in UuidUji.barcodeAmericano.split('')) {
      await tester.sendKeyEvent(LogicalKeyboardKey(karakter.codeUnitAt(0)));
    }
    await tester.sendKeyEvent(LogicalKeyboardKey.enter);
    await Tunggu(tester);
    expect(find.text('Pembayaran berhasil'), findsNothing);
    expect(find.text('Keranjang | 1 item'), findsOneWidget, reason: 'Produk masuk ke transaksi baru.');
    expect(tester.takeException(), isNull);
    await Lepas(tester, u);
  });

  testWidgets('pilihan wajib & opsional lewat panel item; produk induk varian ditolak dengan pesan jelas', (
    tester,
  ) async {
    final u = await MasukJual(tester);

    await Ketuk(tester, Ubin('Kaos Kopi Senja'));
    expect(find.textContaining('Pilih salah satu variannya'), findsOneWidget);

    await Ketuk(tester, Ubin('Es Kopi Susu Aren'));
    expect(tester.widget<PanelTugas>(find.byType(PanelTugas)).judul, 'Es Kopi Susu Aren');
    expect(find.text('Level gula | wajib, pilih 1'), findsOneWidget);
    expect(find.text('Tambahan | opsional, maks. 2'), findsOneWidget);

    await Ketuk(tester, find.widgetWithText(FilledButton, 'Tambah ke keranjang'));
    expect(find.text('Pilih minimal 1 untuk "Level gula".'), findsOneWidget);

    await Ketuk(tester, find.widgetWithText(FilterChip, 'Normal'));
    await Ketuk(tester, find.widgetWithText(FilterChip, 'Kurang manis'));
    await Ketuk(tester, find.widgetWithText(FilterChip, 'Extra shot +Rp 5.000'));
    await Ketuk(tester, find.widgetWithText(FilledButton, 'Tambah ke keranjang'));

    expect(find.byType(PanelTugas), findsNothing);
    expect(find.text('@ Rp 18.000/Cangkir | Kurang manis | Extra shot +Rp 5.000'), findsOneWidget);
    // 23.000 + PBJT 2.300.
    expect(find.text('Rp 23.000'), findsNWidgets(2));
    expect(find.text('Rp 25.300'), findsOneWidget);
    await Lepas(tester, u);
  });

  testWidgets('BR-07.3 diskon item di atas batas → PIN penyetuju; bayar QRIS statis dengan konfirmasi kasir', (
    tester,
  ) async {
    final u = await MasukJual(tester);

    await Ketuk(tester, Ubin('Croissant Mentega Prancis Isi Cokelat Lumer Ukuran Jumbo'));
    await Ketuk(tester, find.descendant(of: find.byType(BarisKeranjang), matching: find.textContaining('Croissant')));
    expect(find.text('Diskon item'), findsOneWidget);
    await tester.enterText(find.widgetWithText(TextField, 'Diskon (%)'), '20');
    await Ketuk(tester, find.widgetWithText(FilledButton, 'Simpan perubahan'));

    expect(find.text('Persetujuan supervisor'), findsOneWidget);
    expect(find.textContaining('Diskon ini di atas 10%'), findsOneWidget);
    await PilihPenyetuju(tester, 'Budi Santoso');
    await KetikPin(tester, KasusPin(1)['Pin']! as String);
    await Tunggu(tester);

    expect(find.byType(PanelTugas), findsNothing);
    expect(find.textContaining('Diskon 20%'), findsOneWidget);
    // 25.000 − 5.000 = 20.000 + PBJT 2.000.
    expect(find.text('Rp 22.000'), findsOneWidget);

    await tester.sendKeyEvent(LogicalKeyboardKey.f8);
    await Tunggu(tester);
    await Ketuk(tester, find.widgetWithText(ChoiceChip, 'QRIS'));
    expect(find.bySemanticsLabel('Kode QRIS QRIS'), findsOneWidget);
    await Ketuk(tester, find.widgetWithText(FilledButton, 'Selesaikan pembayaran'));
    expect(find.text('Pastikan dana QRIS sudah masuk, lalu centang konfirmasi.'), findsOneWidget);
    await Ketuk(tester, find.byType(CheckboxListTile));
    await Ketuk(tester, find.widgetWithText(FilledButton, 'Selesaikan pembayaran'));
    await Tunggu(tester);
    expect(find.text('Pembayaran berhasil'), findsOneWidget);

    final data = (await AmbilOutboxPenjualan(tester, u)).single;
    expect(data['UuidPenyetujuDiskon'], '01K5STAF000000000000000002');
    final baris = ((data['Baris']! as List<Object?>).single! as Map<String, Object?>);
    expect(baris['DiskonManual'], {'Persen': '20'});
    final bayar = (data['Pembayaran']! as List<Object?>).single! as Map<String, Object?>;
    expect(bayar['UuidMetodePembayaran'], '01K5MTD0000000000000000002');
    expect(bayar['Jumlah'], '22000.00');
    await Lepas(tester, u);
  });

  testWidgets('PRD v1.46 (f): kasir tanpa izin diskon manual tetap bisa mengisi diskon → PIN penyetuju', (
    tester,
  ) async {
    final dataAwal = DataAwalUji();
    final staf = (dataAwal['Staf']! as List<Object?>).cast<Map<String, Object?>>();
    staf[0] = StafJson('01K5STAF000000000000000001', 'Rina Wulandari', ['penjualan.buat'], 0);
    final u = await MasukJual(tester, dataAwal: dataAwal);

    await Ketuk(tester, Ubin('Croissant Mentega Prancis Isi Cokelat Lumer Ukuran Jumbo'));
    await Ketuk(tester, find.descendant(of: find.byType(BarisKeranjang), matching: find.textContaining('Croissant')));
    expect(find.text('Diskon item'), findsOneWidget);
    expect(find.text('Diskon perlu disetujui supervisor dengan PIN.'), findsOneWidget);
    await tester.enterText(find.widgetWithText(TextField, 'Diskon (%)'), '5');
    await Ketuk(tester, find.widgetWithText(FilledButton, 'Simpan perubahan'));

    // Di bawah batas manual 10% pun tetap diarahkan ke PIN penyetuju, bukan ditolak.
    expect(find.text('Persetujuan supervisor'), findsOneWidget);
    expect(find.textContaining('Diskon manual perlu persetujuan'), findsOneWidget);
    await PilihPenyetuju(tester, 'Budi Santoso');
    await KetikPin(tester, KasusPin(1)['Pin']! as String);
    await Tunggu(tester);

    expect(find.byType(PanelTugas), findsNothing);
    expect(find.textContaining('Diskon 5%'), findsOneWidget);
    await Lepas(tester, u);
  });

  testWidgets('audit #25: penyetuju tunggal terpilih otomatis; persetujuan diskon berlaku sementara untuk kasir sama', (
    tester,
  ) async {
    final u = await MasukJual(tester);

    await Ketuk(tester, Ubin('Croissant Mentega Prancis Isi Cokelat Lumer Ukuran Jumbo'));
    await Ketuk(tester, find.descendant(of: find.byType(BarisKeranjang), matching: find.textContaining('Croissant')));
    await tester.enterText(find.widgetWithText(TextField, 'Diskon (%)'), '20');
    await Ketuk(tester, find.widgetWithText(FilledButton, 'Simpan perubahan'));
    // Hanya Budi yang berhak menyetujui diskon: papan PIN-nya langsung tampil tanpa memilih nama.
    expect(find.text('PIN Budi Santoso'), findsOneWidget);
    expect(find.widgetWithText(OutlinedButton, 'Budi Santoso'), findsNothing);
    await KetikPin(tester, KasusPin(1)['Pin']! as String);
    await Tunggu(tester);
    expect(find.textContaining('Diskon 20%'), findsOneWidget);

    // Diskon berikutnya dalam 5 menit: tidak ditanya PIN lagi, penyetuju tetap Budi.
    await Ketuk(tester, find.descendant(of: find.byType(BarisKeranjang), matching: find.textContaining('Croissant')));
    await tester.enterText(find.widgetWithText(TextField, 'Diskon (%)'), '15');
    await Ketuk(tester, find.widgetWithText(FilledButton, 'Simpan perubahan'));
    expect(find.text('Persetujuan supervisor'), findsNothing);
    expect(find.textContaining('Diskon 15%'), findsOneWidget);
    await Lepas(tester, u);
  });

  test('audit #25: persetujuan sementara kedaluwarsa setelah 5 menit dan tidak berlaku untuk kasir lain', () {
    var sekarang = DateTime(2026, 10, 3, 9);
    final wadah = ProviderContainer(overrides: [penyediaJam.overrideWithValue(() => sekarang)]);
    addTearDown(wadah.dispose);
    const budi = StafLokal(uuid: 'S2', nama: 'Budi Santoso', pemilik: false, izin: ['penjualan.diskon.setujui']);
    final sementara = wadah.read(penyediaPersetujuanSementara.notifier);

    sementara.Catat('penjualan.diskon.setujui', budi, 'S1');
    expect(sementara.Ambil('penjualan.diskon.setujui', 'S1')?.uuid, 'S2');
    expect(sementara.Ambil('penjualan.diskon.setujui', 'S3'), isNull, reason: 'Kasir lain tetap minta PIN.');
    expect(sementara.Ambil('penjualan.tempo.setujui', 'S1'), isNull, reason: 'Izin lain tidak ikut.');

    sekarang = sekarang.add(const Duration(minutes: 5));
    expect(sementara.Ambil('penjualan.diskon.setujui', 'S1'), isNull);
  });

  testWidgets('BR-08.1 split: EDC dengan nomor approval lalu sisa tunai', (tester) async {
    final u = await MasukJual(tester);
    await Ketuk(tester, Ubin('Croissant Mentega Prancis Isi Cokelat Lumer Ukuran Jumbo'));
    await Ketuk(tester, find.widgetWithText(FilledButton, 'Bayar'));

    await Ketuk(tester, find.widgetWithText(ChoiceChip, 'EDC BCA'));
    await tester.enterText(find.widgetWithText(TextField, 'Jumlah'), '20000');
    await Tunggu(tester);
    expect(find.widgetWithText(FilledButton, 'Tambah pembayaran EDC BCA'), findsOneWidget);
    await Ketuk(tester, find.widgetWithText(FilledButton, 'Tambah pembayaran EDC BCA'));
    expect(find.text('Isi nomor approval dari struk EDC.'), findsOneWidget);
    await tester.enterText(find.widgetWithText(TextField, 'Bank penerbit kartu (opsional)'), 'Mandiri');
    await tester.enterText(find.widgetWithText(TextField, 'Nomor approval (di struk EDC)'), '004512');
    await Ketuk(tester, find.widgetWithText(FilledButton, 'Tambah pembayaran EDC BCA'));

    expect(find.text('Sisa'), findsOneWidget);
    expect(find.text('Rp 7.500'), findsOneWidget);
    await Ketuk(tester, find.widgetWithText(ChoiceChip, 'Tunai'));
    await Ketuk(tester, find.widgetWithText(FilledButton, 'Uang pas'));
    await Tunggu(tester);
    expect(find.text('Pembayaran berhasil'), findsOneWidget);

    final bayar = ((await AmbilOutboxPenjualan(tester, u)).single['Pembayaran']! as List<Object?>)
        .cast<Map<String, Object?>>();
    expect(bayar.map((b) => (b['UuidMetodePembayaran'], b['Jumlah'], b['Referensi'])), [
      ('01K5MTD0000000000000000003', '20000.00', 'Mandiri | 004512'),
      ('01K5MTD0000000000000000001', '7500.00', null),
    ]);
    await Lepas(tester, u);
  });

  testWidgets(
    'BR-03.2: produk diarsipkan tidak masuk keranjang lewat pindai barcode (barcode timbangan memakai penjaga yang sama)',
    (tester) async {
      final katalog = KatalogUji();
      final daftar = katalog['Produk']! as List<Object?>;
      final indeks = daftar.indexWhere((p) => (p! as Map<String, Object?>)['Uuid'] == UuidUji.americano);
      daftar[indeks] = ProdukUji(UuidUji.americano, 'Americano Panas', jenis: 'Resep', sku: 'AMR-01', aktif: false);
      final u = await MasukJual(tester, katalog: katalog);

      Future<void> Pindai(String kode) async {
        for (final karakter in kode.split('')) {
          await tester.sendKeyEvent(LogicalKeyboardKey(karakter.codeUnitAt(0)));
        }
        await tester.sendKeyEvent(LogicalKeyboardKey.enter);
        await Tunggu(tester);
      }

      const pesan =
          '"Americano Panas" sudah diarsipkan dan tidak dijual lagi. Pulihkan di back-office menu Produk bila masih dijual.';

      // Tidak tampil di grid, dan pindai barcode ditolak dengan pesan jelas; keranjang tetap kosong.
      expect(find.text('Americano Panas'), findsNothing);
      await Pindai(UuidUji.barcodeAmericano);
      expect(find.text(pesan), findsOneWidget);
      expect(find.textContaining('Keranjang | 1 item'), findsNothing);

      // Produk lain tetap bisa dipindai.
      await Pindai(UuidUji.barcodeRotiLusin);
      expect(find.text('Keranjang | 1 item'), findsOneWidget);
      await Lepas(tester, u);
    },
  );

  testWidgets('pemindai barcode tanpa fokus & pintasan §17.2.3 F1 / F8 / F9 / Esc (desktop)', (tester) async {
    final u = await MasukJual(tester);

    Future<void> Pindai(String kode) async {
      for (final karakter in kode.split('')) {
        await tester.sendKeyEvent(LogicalKeyboardKey(karakter.codeUnitAt(0)));
      }
      await tester.sendKeyEvent(LogicalKeyboardKey.enter);
      await Tunggu(tester);
    }

    // Pindai tanpa mengetuk kolom cari: produk masuk keranjang; pindai lagi → jumlah +1.
    await Pindai(UuidUji.barcodeAmericano);
    await Pindai(UuidUji.barcodeAmericano);
    expect(find.text('Keranjang | 2 item'), findsOneWidget);
    expect(find.descendant(of: find.byType(BarisKeranjang), matching: find.text('2')), findsOneWidget);

    // Barcode satuan lusin → baris dengan satuan Lusin.
    await Pindai(UuidUji.barcodeRotiLusin);
    expect(find.textContaining('/Lusin'), findsOneWidget);

    // Kode tidak dikenal → pesan jelas, keranjang tidak berubah.
    await Pindai('1234567');
    expect(find.text('Kode 1234567 tidak ditemukan di katalog. Perbarui katalog atau cari manual.'), findsOneWidget);

    // F2 dicadangkan untuk pelanggan: tidak memindahkan fokus.
    await tester.sendKeyEvent(LogicalKeyboardKey.f2);
    await tester.pump();
    final cari = tester.widget<TextField>(find.widgetWithText(TextField, 'Cari nama, SKU, atau barcode (F1)'));
    expect(cari.focusNode!.hasFocus, isFalse);

    // F1 → fokus ke kolom cari; ketikan di kolom cari tidak dianggap pindaian.
    await tester.sendKeyEvent(LogicalKeyboardKey.f1);
    await tester.pump();
    expect(cari.focusNode!.hasFocus, isTrue);
    await tester.enterText(find.byWidget(cari), 'croissant');
    await Tunggu(tester);
    expect(Ubin('Croissant Mentega Prancis Isi Cokelat Lumer Ukuran Jumbo'), findsOneWidget);
    expect(Ubin('Americano Panas'), findsNothing);
    await tester.testTextInput.receiveAction(TextInputAction.search);
    await Tunggu(tester);
    expect(find.text('Keranjang | 4 item'), findsOneWidget, reason: 'Enter di kolom cari dengan satu hasil menambah.');

    // F8 → halaman Bayar; Esc → kembali ke keranjang.
    await tester.sendKeyEvent(LogicalKeyboardKey.f8);
    await Tunggu(tester);
    expect(find.byTooltip('Kembali ke keranjang'), findsOneWidget);
    expect(Ubin('Americano Panas'), findsNothing);
    await tester.sendKeyEvent(LogicalKeyboardKey.escape);
    await Tunggu(tester);
    expect(Ubin('Americano Panas'), findsOneWidget, reason: 'Esc mengembalikan katalog & keranjang.');

    // Esc tanpa panel → hapus item terakhir (bukan batal transaksi, tanpa dialog).
    await tester.sendKeyEvent(LogicalKeyboardKey.escape);
    await Tunggu(tester);
    expect(find.text('Batalkan transaksi ini?'), findsNothing);
    expect(find.text('Keranjang | 3 item'), findsOneWidget);
    expect(
      find.text('Croissant Mentega Prancis Isi Cokelat Lumer Ukuran Jumbo dihapus dari keranjang.'),
      findsOneWidget,
    );

    // F9 tanpa panel → Bayar langsung tunai uang pas lalu tersimpan: 2 × 15.000 + PBJT 3.000 + 1 lusin 130.000.
    await tester.sendKeyEvent(LogicalKeyboardKey.f9);
    await Tunggu(tester, const Duration(milliseconds: 600));
    expect(find.text('Pembayaran berhasil'), findsOneWidget);
    var outbox = await AmbilOutboxPenjualan(tester, u);
    expect((outbox.single['Pembayaran']! as List<Object?>).single, containsPair('Jumlah', '163000.00'));
    expect((outbox.single['Ringkasan']! as Map<String, Object?>)['Kembalian'], '0.00');
    await tester.sendKeyEvent(LogicalKeyboardKey.escape);
    await Tunggu(tester);
    expect(Ubin('Americano Panas'), findsOneWidget, reason: 'Esc di halaman selesai = transaksi baru.');

    // F8 lalu F9 di dalam panel Bayar → tunai uang pas tersimpan.
    await Pindai(UuidUji.barcodeAmericano);
    await tester.sendKeyEvent(LogicalKeyboardKey.f8);
    await Tunggu(tester);
    await tester.sendKeyEvent(LogicalKeyboardKey.f9);
    await Tunggu(tester, const Duration(milliseconds: 600));
    expect(find.text('Pembayaran berhasil'), findsOneWidget);
    outbox = await AmbilOutboxPenjualan(tester, u);
    expect(outbox.map((o) => (o['Ringkasan']! as Map<String, Object?>)['TotalAkhir']), ['163000.00', '16500.00']);
    await Ketuk(tester, find.widgetWithText(FilledButton, 'Transaksi baru'));

    // Batalkan transaksi hanya lewat tombol keranjang dengan konfirmasi.
    await Pindai(UuidUji.barcodeAmericano);
    await Ketuk(tester, find.byTooltip('Batalkan transaksi'));
    expect(find.text('Batalkan transaksi ini?'), findsOneWidget);
    await Ketuk(tester, find.widgetWithText(FilledButton, 'Batalkan transaksi'));
    expect(find.text('Keranjang masih kosong'), findsOneWidget);
    expect(find.text('Ketuk produk atau pindai barcode untuk mulai.'), findsOneWidget);

    // Pemindai tidak aktif saat layar lain terbuka.
    await Ketuk(tester, find.text('Kas'));
    await Pindai(UuidUji.barcodeAmericano);
    await Ketuk(tester, find.text('Jual'));
    expect(find.text('Keranjang masih kosong'), findsOneWidget);
    expect(find.text('Ketuk produk atau pindai barcode untuk mulai.'), findsOneWidget);
    await Lepas(tester, u);
  });

  testWidgets('pesanan tertahan: tahan → daftar → buka kembali; keranjang di kiri sesuai pengaturan', (tester) async {
    final u = LingkunganUji.Buat();
    await tester.runAsync(() async {
      await u.SiapkanAktif();
      await u.repositori.SimpanPengaturan('PosisiKeranjang', 'Kiri');
      await u.shift.BukaShift(kasir: await u.Staf('Rina Wulandari'), kasAwal: Uang.DariBulat(500000));
    });
    u.server.penangan = PenanganServer();
    await PasangAplikasi(tester, u, ukuran: ukuranTablet);
    await Tunggu(tester, const Duration(milliseconds: 600));
    await PilihKasir(tester, 'Rina Wulandari');
    await tester.pump();
    await KetikPin(tester, KasusPin(0)['Pin']! as String);
    await Tunggu(tester);

    // Keranjang di kiri katalog.
    final xKeranjang = tester.getTopLeft(find.text('Keranjang')).dx;
    final xKatalog = tester.getTopLeft(Ubin('Americano Panas')).dx;
    expect(xKeranjang, lessThan(xKatalog));

    await Ketuk(tester, Ubin('Americano Panas'));
    await Ketuk(tester, find.widgetWithText(OutlinedButton, 'Tahan'));
    expect(find.text('Pesanan ditahan. Buka lagi lewat tombol Tertahan.'), findsOneWidget);
    expect(find.text('Keranjang masih kosong'), findsOneWidget);
    expect(find.text('Ketuk produk atau pindai barcode untuk mulai.'), findsOneWidget);

    await Ketuk(tester, find.byTooltip('Pesanan tertahan (1)'));
    expect(tester.widget<PanelTugas>(find.byType(PanelTugas)).judul, 'Pesanan tertahan');
    expect(find.textContaining('Americano Panas | '), findsOneWidget);
    await Ketuk(tester, find.widgetWithText(OutlinedButton, 'Buka'));
    expect(find.byType(PanelTugas), findsNothing);
    expect(find.text('Keranjang | 1 item'), findsOneWidget);
    expect(await tester.runAsync(() => u.db.select(u.db.pesananTertahan).get()), isEmpty);
    expect(await AmbilOutboxPenjualan(tester, u), isEmpty, reason: 'Pesanan tertahan tidak dikirim ke server.');
    await Lepas(tester, u);
  });

  testWidgets('D-40: halaman Bayar tunai muat tanpa gulir di 1280×800 (layar 12,4 inci)', (tester) async {
    // Font merek sungguhan: lebar teks di perangkat, bukan font uji persegi yang selalu lebih lebar.
    await MuatFontMerek();
    final u = await MasukJual(tester, ukuran: const Size(1280, 800));
    await Ketuk(tester, Ubin('Americano Panas'));
    await Ketuk(tester, find.widgetWithText(FilledButton, 'Bayar'));
    await Ketuk(tester, find.widgetWithText(ChoiceChip, 'Tunai'));
    await Ketuk(tester, find.widgetWithText(OutlinedButton, 'Rp 100.000'));

    // Papan angka, uang diterima, dan kembalian semuanya terlihat tanpa menggulir panel pembayaran.
    expect(find.text('Tagihan tunai'), findsOneWidget);
    expect(find.text('Uang diterima'), findsOneWidget);
    expect(find.text('Kembalian'), findsOneWidget);
    final gulir = tester.state<ScrollableState>(
      find.descendant(of: find.byKey(const ValueKey('GulirPembayaranBayar')), matching: find.byType(Scrollable)).first,
    );
    expect(
      gulir.position.maxScrollExtent,
      0,
      reason: 'Isi pembayaran harus muat di satu layar 1280×800: ${gulir.position.maxScrollExtent}',
    );
    expect(tester.takeException(), isNull);
    await Lepas(tester, u);
  });

  testWidgets('golden: layar Jual berisi keranjang & halaman Bayar tunai (1280dp)', (tester) async {
    await MuatFontMerek();
    final u = await MasukJual(tester);
    await Ketuk(tester, Ubin('Americano Panas'));
    await Ketuk(tester, Ubin('Croissant Mentega Prancis Isi Cokelat Lumer Ukuran Jumbo'));
    await Ketuk(tester, Ubin('Americano Panas'));
    await expectLater(find.byType(LayarJual), matchesGoldenFile('Golden/LayarJual1280.png'));

    await tester.sendKeyEvent(LogicalKeyboardKey.f8);
    await Tunggu(tester);
    await Ketuk(tester, find.widgetWithText(ChoiceChip, 'Tunai'));
    await Ketuk(tester, find.widgetWithText(OutlinedButton, 'Rp 100.000'));
    await expectLater(find.byType(LayarJual), matchesGoldenFile('Golden/HalamanBayar1280.png'));

    // Halaman hasil: langkah terakhir alur bayar, juga halaman sendiri.
    await Ketuk(tester, find.widgetWithText(FilledButton, 'Selesaikan pembayaran'));
    await Tunggu(tester);
    await expectLater(find.byType(LayarJual), matchesGoldenFile('Golden/HalamanSelesai1280.png'));
    await Lepas(tester, u);
  });
}
