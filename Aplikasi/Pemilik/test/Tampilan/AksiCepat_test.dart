import 'dart:convert';
import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:pemilik/Aplikasi/Penyedia.dart';
import 'package:pemilik/Data/KameraNota.dart';
import 'package:pemilik/Data/PenyimpanSesi.dart';

import '../Pendukung/PasangPemilik.dart';

/// OWN-07: aksi cepat Owner. Ubah harga, tandai habis per outlet, nyalakan/matikan promo, dan catat pengeluaran dengan
/// foto nota; tiap aksi memanggil endpoint `aksi-cepat/*` dan galat server tampil sebagai pesan.
/// PNG 1x1 transparan (gambar sah supaya pratinjau foto bisa didekode di test).
const String _png1x1 =
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

class KameraNotaTiruan implements KameraNota {
  KameraNotaTiruan({this.foto});

  final Uint8List? foto;
  int dipanggil = 0;

  @override
  bool Tersedia() => true;

  @override
  Future<Uint8List?> Ambil() async {
    dipanggil++;
    return foto;
  }
}

void main() {
  late ServerTiruan server;
  late PenyimpanSesiMemori sesi;
  late List<http.Request> aksi;
  late Map<String, http.Response Function(http.Request)> jawaban;

  setUp(() {
    server = ServerTiruan();
    aksi = [];
    sesi = PenyimpanSesiMemori()
      ..isi[PenyimpanSesi.kunciToken] = '5|rahasia'
      ..isi[PenyimpanSesi.kunciTenant] = 'T1';
    jawaban = {};
    server.penangan = (p) async {
      final jalur = p.url.path.replaceFirst('/api/pemilik/v1/', '');
      if (jalur.startsWith('aksi-cepat/')) {
        aksi.add(p);
        final penangan = jawaban['${p.method} $jalur'];
        if (penangan != null) return penangan(p);
      }
      return switch (jalur) {
        'profil' => JsonUji({
          'Pengguna': {'Nama': 'Bu Sari'},
          'Tenant': [
            {'Uuid': 'T1', 'Nama': 'Kopi Senja', 'Pemilik': true},
          ],
        }),
        'dasbor' => JsonUji(DasborUji()),
        _ => JsonUji({}, 404),
      };
    };
  });

  Future<void> BukaAksiCepat(WidgetTester tester, {KameraNota? kamera}) async {
    await PasangPemilik(
      tester,
      server: server,
      sesi: sesi,
      tambahan: [if (kamera != null) penyediaKameraNota.overrideWithValue(kamera)],
    );
    await tester.tap(find.byTooltip('Aksi cepat'));
    await tester.pumpAndSettle();
  }

  Map<String, Object?> Produk(
    String uuid,
    String nama, {
    String? harga = '18000.00',
    bool habis = false,
    String jenis = 'Stok',
  }) => {
    'Uuid': uuid,
    'Nama': nama,
    'Sku': 'SKU-$uuid',
    'Jenis': jenis,
    'HargaDasar': harga,
    'BisaUbahHarga': harga != null && jenis != 'IndukVarian',
    'BisaTandaiHabis': jenis != 'IndukVarian',
    'Habis': jenis == 'IndukVarian' ? null : habis,
  };

  testWidgets('Aksi cepat: empat pintasan tampil di bilah atas Beranda', (tester) async {
    await BukaAksiCepat(tester);

    expect(find.text('Ubah harga'), findsOneWidget);
    expect(find.text('Tandai habis'), findsOneWidget);
    expect(find.text('Promo'), findsOneWidget);
    expect(find.text('Catat pengeluaran'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  group('ubah harga', () {
    setUp(() {
      jawaban['GET aksi-cepat/produk'] = (p) => JsonUji({
        'Produk': [
          Produk('P1', 'Kopi Susu Gula Aren'),
          Produk('P2', 'Kaos Polos', harga: null, jenis: 'IndukVarian'),
          Produk('P3', 'Roti Tanpa Harga', harga: null),
        ],
      });
    });

    testWidgets('mencari, memilih produk, mengisi harga baru, lalu PUT harga dan pesan hasilnya', (tester) async {
      jawaban['PUT aksi-cepat/produk/P1/harga'] = (p) => JsonUji({
        'Produk': {'Uuid': 'P1', 'Nama': 'Kopi Susu Gula Aren'},
        'HargaLama': '18000.00',
        'HargaBaru': '20000.00',
        'Berubah': true,
      });
      await BukaAksiCepat(tester);
      await tester.tap(find.text('Ubah harga'));
      await tester.pumpAndSettle();

      expect(find.text('Kopi Susu Gula Aren'), findsOneWidget);
      expect(find.textContaining('SKU-P1'), findsOneWidget);
      expect(
        find.text('Ubah harga per varian di dashboard.'),
        findsOneWidget,
        reason: 'Induk varian tidak bisa diubah.',
      );
      expect(find.text('Belum punya harga jual. Atur di dashboard.'), findsOneWidget);

      await tester.tap(find.text('Kopi Susu Gula Aren'));
      await tester.pumpAndSettle();
      expect(find.text('Harga sekarang Rp 18.000'), findsOneWidget);
      await tester.enterText(find.byType(TextField).last, '20000');
      await tester.pump();
      expect(find.text('Rp 20.000'), findsOneWidget);
      await tester.tap(find.text('Simpan harga'));
      await tester.pumpAndSettle();

      final put = aksi.singleWhere((p) => p.method == 'PUT');
      expect(jsonDecode(put.body), {'Harga': '20000'});
      expect(find.text('Harga Kopi Susu Gula Aren menjadi Rp 20.000.'), findsOneWidget);
    });

    testWidgets('harga kosong atau nol: tombol Simpan harga nonaktif; produk tanpa harga jual tidak bisa dipilih', (
      tester,
    ) async {
      await BukaAksiCepat(tester);
      await tester.tap(find.text('Ubah harga'));
      await tester.pumpAndSettle();

      await tester.tap(find.text('Roti Tanpa Harga'));
      await tester.pumpAndSettle();
      expect(find.text('Simpan harga'), findsNothing, reason: 'Produk tanpa harga jual tidak membuka dialog.');

      await tester.tap(find.text('Kopi Susu Gula Aren'));
      await tester.pumpAndSettle();
      await tester.enterText(find.byType(TextField).last, '0');
      await tester.pump();
      expect(tester.widget<FilledButton>(find.widgetWithText(FilledButton, 'Simpan harga')).onPressed, isNull);
    });

    testWidgets('galat izin dari server tampil sebagai pesan, bukan layar kosong', (tester) async {
      jawaban['PUT aksi-cepat/produk/P1/harga'] = (p) => JsonUji({
        'Galat': {'Kode': 'TanpaIzin', 'Pesan': 'Anda tidak punya izin mengubah harga.'},
      }, 403);
      await BukaAksiCepat(tester);
      await tester.tap(find.text('Ubah harga'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Kopi Susu Gula Aren'));
      await tester.pumpAndSettle();
      await tester.enterText(find.byType(TextField).last, '25000');
      await tester.pump();
      await tester.tap(find.text('Simpan harga'));
      await tester.pumpAndSettle();

      expect(find.text('Anda tidak punya izin mengubah harga.'), findsOneWidget);
    });
  });

  group('tandai habis', () {
    testWidgets('dua outlet: pilih outlet dulu; saklar mengirim PUT dengan outlet, lalu daftar dimuat ulang', (
      tester,
    ) async {
      var habis = false;
      jawaban['GET aksi-cepat/produk'] = (p) => JsonUji({
        'Produk': [Produk('P1', 'Roti Bakar Cokelat', habis: habis)],
      });
      jawaban['PUT aksi-cepat/produk/P1/habis'] = (p) {
        habis = (jsonDecode(p.body) as Map<String, Object?>)['Habis'] == true;
        return JsonUji({
          'Produk': {'Uuid': 'P1', 'Nama': 'Roti Bakar Cokelat'},
          'Habis': habis,
        });
      };
      await BukaAksiCepat(tester);
      await tester.tap(find.text('Tandai habis'));
      await tester.pumpAndSettle();

      expect(find.text('Pilih outlet dulu.'), findsOneWidget);
      await tester.tap(find.byType(DropdownButtonFormField<String>));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Kartasura').last);
      await tester.pumpAndSettle();

      expect(aksi.firstWhere((p) => p.method == 'GET').url.queryParameters['outlet'], 'O2');
      expect(find.text('Tersedia'), findsOneWidget);
      await tester.tap(find.byType(Switch));
      await tester.pumpAndSettle();

      final put = aksi.singleWhere((p) => p.method == 'PUT');
      expect(jsonDecode(put.body), {'Outlet': 'O2', 'Habis': true});
      expect(find.text('Roti Bakar Cokelat ditandai habis.'), findsOneWidget);
      expect(find.text('Habis'), findsOneWidget, reason: 'Daftar dimuat ulang dengan status baru.');
    });

    testWidgets('induk varian: saklar nonaktif dengan penjelasan', (tester) async {
      jawaban['GET aksi-cepat/produk'] = (p) => JsonUji({
        'Produk': [Produk('P2', 'Kaos Polos', harga: null, jenis: 'IndukVarian')],
      });
      await BukaAksiCepat(tester);
      await tester.tap(find.text('Tandai habis'));
      await tester.pumpAndSettle();
      await tester.tap(find.byType(DropdownButtonFormField<String>));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Solo Baru').last);
      await tester.pumpAndSettle();

      expect(find.text('Tandai habis per varian di dashboard.'), findsOneWidget);
      expect(tester.widget<Switch>(find.byType(Switch)).onChanged, isNull);
    });
  });

  group('promo', () {
    testWidgets('daftar promo; saklar mematikan lewat PUT status Aktif=false dan memuat ulang', (tester) async {
      var aktif = true;
      jawaban['GET aksi-cepat/promo'] = (p) => JsonUji({
        'Promo': [
          {
            'Uuid': 'PR1',
            'Kode': 'HEMAT5K',
            'Nama': 'Hemat 5 Ribu',
            'Aktif': aktif,
            'MulaiPada': null,
            'SelesaiPada': null,
            'Berakhir': false,
          },
          {
            'Uuid': 'PR2',
            'Kode': 'LEBARAN',
            'Nama': 'Promo Lebaran',
            'Aktif': false,
            'MulaiPada': null,
            'SelesaiPada': '2026-04-01T00:00:00+00:00',
            'Berakhir': true,
          },
        ],
      });
      jawaban['PUT aksi-cepat/promo/PR1/status'] = (p) {
        aktif = (jsonDecode(p.body) as Map<String, Object?>)['Aktif'] == true;
        return JsonUji({
          'Promo': {'Uuid': 'PR1', 'Nama': 'Hemat 5 Ribu', 'Aktif': aktif},
        });
      };
      await BukaAksiCepat(tester);
      await tester.tap(find.text('Promo'));
      await tester.pumpAndSettle();

      expect(find.text('Hemat 5 Ribu'), findsOneWidget);
      expect(find.text('LEBARAN | Masa berlaku sudah lewat'), findsOneWidget);
      await tester.tap(find.byType(Switch).first);
      await tester.pumpAndSettle();

      expect(jsonDecode(aksi.singleWhere((p) => p.method == 'PUT').body), {'Aktif': false});
      expect(find.text('Hemat 5 Ribu dimatikan.'), findsOneWidget);
      expect(tester.widget<Switch>(find.byType(Switch).first).value, isFalse);
    });

    testWidgets('tanpa promo: keadaan kosong menjelaskan langkah berikutnya', (tester) async {
      jawaban['GET aksi-cepat/promo'] = (p) => JsonUji({'Promo': <Object?>[]});
      await BukaAksiCepat(tester);
      await tester.tap(find.text('Promo'));
      await tester.pumpAndSettle();

      expect(find.text('Belum ada promo.'), findsOneWidget);
    });
  });

  group('catat pengeluaran', () {
    final opsi = {
      'Kategori': [
        {'Uuid': 'K1', 'Nama': 'Listrik'},
        {'Uuid': 'K2', 'Nama': 'Air'},
      ],
      'Sumber': [
        {'Uuid': 'A1', 'Kode': '1-1100', 'Nama': 'Kas Utama'},
        {'Uuid': 'A2', 'Kode': '1-1200', 'Nama': 'Bank BCA'},
      ],
    };

    Future<void> Isi(WidgetTester tester) async {
      await tester.tap(find.text('Catat pengeluaran'));
      await tester.pumpAndSettle();
      await tester.tap(find.byType(DropdownButtonFormField<String>).first);
      await tester.pumpAndSettle();
      await tester.tap(find.text('Listrik').last);
      await tester.pumpAndSettle();
      await tester.enterText(find.widgetWithText(TextField, 'Jumlah (Rp)'), '350000');
      await tester.pump();
      await tester.tap(find.byType(DropdownButtonFormField<String>).at(1));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Kas Utama').last);
      await tester.pumpAndSettle();
    }

    testWidgets(
      'tombol Simpan baru aktif setelah kategori, jumlah, dan sumber terisi; kirim multipart dengan foto dan kunci idempotensi',
      (tester) async {
        jawaban['GET aksi-cepat/pengeluaran/opsi'] = (p) => JsonUji(opsi);
        jawaban['POST aksi-cepat/pengeluaran'] = (p) => JsonUji({
          'Pengeluaran': {'Uuid': 'KB1', 'Nomor': 'KB/2026/09/0001', 'Jumlah': '350000.00', 'BerLampiran': true},
        }, 201);
        final kamera = KameraNotaTiruan(foto: base64Decode(_png1x1));
        await BukaAksiCepat(tester, kamera: kamera);
        await tester.tap(find.text('Catat pengeluaran'));
        await tester.pumpAndSettle();
        expect(tester.widget<FilledButton>(find.widgetWithText(FilledButton, 'Simpan pengeluaran')).onPressed, isNull);

        await tester.pageBack();
        await tester.pumpAndSettle();
        await Isi(tester);
        await tester.enterText(find.widgetWithText(TextField, 'Catatan (boleh dikosongkan)'), 'Token listrik Oktober');
        await tester.tap(find.text('Foto nota'));
        await tester.pumpAndSettle();
        expect(kamera.dipanggil, 1);
        expect(find.text('Foto nota terlampir.'), findsOneWidget);

        await tester.tap(find.text('Simpan pengeluaran'));
        await tester.pumpAndSettle();

        final kirim = aksi.singleWhere((p) => p.method == 'POST');
        final badan = latin1.decode(kirim.bodyBytes);
        expect(kirim.headers['Idempotency-Key'], isNotEmpty);
        expect(kirim.headers['content-type'], contains('multipart/form-data'));
        expect(
          badan,
          allOf(contains('name="UuidKategori"'), contains('K1'), contains('name="Jumlah"'), contains('350000')),
        );
        expect(
          badan,
          allOf(
            contains('name="UuidAkunSumber"'),
            contains('A1'),
            contains('name="Foto"'),
            contains('Token listrik Oktober'),
          ),
        );
        expect(find.text('Pengeluaran Rp 350.000 tercatat (KB/2026/09/0001).'), findsOneWidget);
        expect(find.text('Catat pengeluaran'), findsOneWidget, reason: 'Kembali ke daftar aksi cepat.');
      },
    );

    testWidgets('galat server: pesan tampil dan formulir tetap terisi supaya bisa dicoba lagi', (tester) async {
      jawaban['GET aksi-cepat/pengeluaran/opsi'] = (p) => JsonUji(opsi);
      jawaban['POST aksi-cepat/pengeluaran'] = (p) => JsonUji({
        'Galat': {'Kode': 'PeriodeTerkunci', 'Pesan': 'Periode ini sudah ditutup.'},
      }, 422);
      await BukaAksiCepat(tester);
      await Isi(tester);
      await tester.tap(find.text('Simpan pengeluaran'));
      await tester.pumpAndSettle();

      expect(find.text('Periode ini sudah ditutup.'), findsOneWidget);
      expect(find.text('Simpan pengeluaran'), findsOneWidget);
      expect(tester.widget<FilledButton>(find.widgetWithText(FilledButton, 'Simpan pengeluaran')).onPressed, isNotNull);
    });

    testWidgets('tanpa kategori atau akun kas: keadaan kosong menyuruh menyiapkan di dashboard', (tester) async {
      jawaban['GET aksi-cepat/pengeluaran/opsi'] = (p) => JsonUji({'Kategori': <Object?>[], 'Sumber': <Object?>[]});
      await BukaAksiCepat(tester);
      await tester.tap(find.text('Catat pengeluaran'));
      await tester.pumpAndSettle();

      expect(find.text('Pengeluaran belum bisa dicatat.'), findsOneWidget);
    });

    testWidgets('kamera tidak tersedia: tombol foto tidak ditampilkan', (tester) async {
      jawaban['GET aksi-cepat/pengeluaran/opsi'] = (p) => JsonUji(opsi);
      await BukaAksiCepat(tester);
      await tester.tap(find.text('Catat pengeluaran'));
      await tester.pumpAndSettle();

      expect(find.text('Foto nota'), findsNothing);
    });
  });

  for (final ukuran in const [Size(360, 640), Size(800, 1000)]) {
    testWidgets('aksi cepat muat di ${ukuran.width.toInt()} lebar tanpa luapan', (tester) async {
      jawaban['GET aksi-cepat/produk'] = (p) => JsonUji({
        'Produk': [Produk('P1', 'Es Kopi Susu Gula Aren Premium Ukuran Besar dengan Topping Tambahan Banyak')],
      });
      await PasangPemilik(tester, server: server, sesi: sesi, ukuran: ukuran);
      await tester.tap(find.byTooltip('Aksi cepat'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Ubah harga'));
      await tester.pumpAndSettle();

      expect(find.textContaining('Es Kopi Susu'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });
  }
}
