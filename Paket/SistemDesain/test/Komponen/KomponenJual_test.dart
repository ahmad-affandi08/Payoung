import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:inti/Inti.dart';
import 'package:sistem_desain/SistemDesain.dart';

/// Komponen layar Jual (PRD §17.2.7): ubin produk, baris keranjang, papan angka.
void main() {
  Future<void> Pasang(WidgetTester tester, Widget anak, {double lebar = 360, double tinggi = 400}) async {
    tester.view.devicePixelRatio = 1;
    tester.view.physicalSize = Size(lebar, tinggi);
    addTearDown(tester.view.reset);
    await tester.pumpWidget(
      MaterialApp(
        theme: BuatTema(),
        home: Scaffold(body: anak),
      ),
    );
  }

  testWidgets('UbinProduk: inisial, nama panjang dipotong dua baris, harga tabular, bisa diketuk', (tester) async {
    var diketuk = 0;
    await Pasang(
      tester,
      Align(
        alignment: Alignment.topLeft,
        // Kotak yang sama dengan yang diberikan grid: lebar kolom, tinggi = lebar + blok teks.
        child: SizedBox(
          width: 148,
          height: 148 + UbinProduk.tinggiTeks,
          child: UbinProduk(
            nama: 'Croissant Mentega Prancis Isi Cokelat Lumer Ukuran Jumbo Edisi Spesial',
            harga: Uang.DariBulat(1250000),
            keterangan: 'Ada pilihan',
            saatDiketuk: () => diketuk++,
          ),
        ),
      ),
    );

    expect(tester.takeException(), isNull);
    expect(find.text('CM'), findsOneWidget);
    expect(find.text('Rp 1.250.000'), findsOneWidget);
    // Tinggi ubin mengikuti lebarnya: gambar persegi + blok teks, bukan tinggi tetap seperti sebelum papan menu.
    expect(tester.getSize(find.byType(UbinProduk)).height, 148 + UbinProduk.tinggiTeks);
    expect(find.byIcon(Icons.tune), findsOneWidget, reason: 'Penanda memakai ikon + teks, bukan warna saja.');
    final nama = tester.widget<Text>(find.textContaining('Croissant'));
    expect(nama.maxLines, 2);
    final harga = tester.widget<Text>(find.text('Rp 1.250.000'));
    expect(harga.style?.fontFeatures, contains(const FontFeature.tabularFigures()));

    await tester.tap(find.byType(UbinProduk));
    expect(diketuk, 1);
    expect(UbinProduk.AmbilInisial('es kopi susu'), 'EK');
    expect(UbinProduk.AmbilInisial('  '), '?');
  });

  testWidgets('UbinProduk: tahan lama memicu aksi tambahan (tandai habis) tanpa ikut mengetuk', (tester) async {
    var diketuk = 0;
    var ditahan = 0;
    await Pasang(
      tester,
      SizedBox(
        width: 160,
        child: UbinProduk(
          nama: 'Teh Tarik',
          harga: Uang.DariBulat(12000),
          keterangan: 'Habis',
          nonaktif: true,
          saatDiketuk: () => diketuk++,
          saatDitahan: () => ditahan++,
        ),
      ),
    );
    await tester.longPress(find.byType(UbinProduk));
    expect(ditahan, 1);
    expect(diketuk, 0);
  });

  testWidgets('UbinProduk tanpa harga menampilkan status berteks', (tester) async {
    await Pasang(
      tester,
      SizedBox(
        width: 160,
        child: UbinProduk(nama: 'Teh Tarik', harga: null, saatDiketuk: () {}),
      ),
    );
    expect(find.text('Harga belum diatur'), findsOneWidget);
  });

  testWidgets('UbinProduk sempit (136 dp di layar 360): nominal jutaan & harga belum diatur tidak meluap', (
    tester,
  ) async {
    for (final harga in [Uang.DariBulat(1000000), Uang.DariBulat(12500000), null]) {
      await Pasang(
        tester,
        Align(
          alignment: Alignment.topLeft,
          child: SizedBox(
            width: 160,
            child: UbinProduk(nama: 'Paket Creambath Rambut Panjang 10x Sesi', harga: harga, saatDiketuk: () {}),
          ),
        ),
      );
      expect(tester.takeException(), isNull, reason: 'harga ${harga?.FormatRupiah()}');
    }
  });

  testWidgets('BarisKeranjang: rincian, jumlah, total; tombol tambah/kurang: kotak 36dp, area ketuk ≥ 44dp', (
    tester,
  ) async {
    var tambah = 0;
    var kurang = 0;
    var ubah = 0;
    await Pasang(
      tester,
      Column(
        children: [
          BarisKeranjang(
            nama: 'Es Kopi Susu Aren Gula Semut Ukuran Besar Sekali',
            jumlah: '12',
            total: Uang.DariBulat(2160000),
            rincian: const ['Cangkir', 'Kurang manis', 'Extra shot', 'Catatan: tanpa es'],
            saatDiketuk: () => ubah++,
            saatTambah: () => tambah++,
            saatKurang: () => kurang++,
          ),
        ],
      ),
    );

    expect(tester.takeException(), isNull, reason: 'Tidak meluap di 360dp.');
    expect(find.text('Rp 2.160.000'), findsOneWidget);
    expect(find.text('12'), findsOneWidget);
    expect(find.text('Cangkir | Kurang manis | Extra shot | Catatan: tanpa es'), findsOneWidget);
    final tombolTambah = find.byTooltip('Tambah Es Kopi Susu Aren Gula Semut Ukuran Besar Sekali');
    expect(tester.getSize(tombolTambah).height, greaterThanOrEqualTo(BarisKeranjang.tinggiTekan));
    expect(
      tester.getSize(find.descendant(of: tombolTambah, matching: find.byType(Container))).height,
      BarisKeranjang.ukuranTombol,
      reason: 'Kotak yang terlihat lebih kecil dari area ketuk.',
    );

    await tester.tap(tombolTambah);
    await tester.tap(find.byTooltip('Kurangi Es Kopi Susu Aren Gula Semut Ukuran Besar Sekali'));
    await tester.tap(find.text('Rp 2.160.000'));
    expect((tambah, kurang, ubah), (1, 1, 1));
  });

  testWidgets('PapanAngka: tombol 1–9, 000, 0, hapus mengirim label; tinggi ≥ 48dp', (tester) async {
    final ditekan = <String>[];
    await Pasang(tester, PapanAngka(saatTekan: ditekan.add));

    await tester.tap(find.text('5'));
    await tester.tap(find.text('000'));
    await tester.tap(find.bySemanticsLabel('Hapus satu angka'));
    expect(ditekan, ['5', '000', PapanAngka.tombolHapus]);
    expect(
      tester.getSize(find.widgetWithText(OutlinedButton, '7')).height,
      greaterThanOrEqualTo(TokenJarak.targetSentuh),
    );
  });

  test('PapanAngka.Terapkan: tanpa nol di depan, hapus satu digit, batas panjang', () {
    var nilai = '';
    for (final t in ['0', '0', '5', '000', '0']) {
      nilai = PapanAngka.Terapkan(nilai, t);
    }
    expect(nilai, '50000');
    expect(PapanAngka.Terapkan(nilai, PapanAngka.tombolHapus), '5000');
    expect(PapanAngka.Terapkan('', PapanAngka.tombolHapus), '');
    expect(PapanAngka.Terapkan('1234567890123', '4'), '1234567890123');
  });

  test('tangga kolom papan menu: makin lebar area katalog, makin banyak kolom', () {
    // Angka acuan dari lebar area katalog sungguhan (sudah dikurangi rel, keranjang, dan padding).
    expect(UbinProduk.HitungKolom(328), 2, reason: 'HP 360dp');
    expect(UbinProduk.HitungKolom(392), 3, reason: 'Tablet 800dp');
    expect(UbinProduk.HitungKolom(792), 5, reason: 'Desktop 1280dp');
    expect(UbinProduk.HitungKolom(1400), 6);
    // Rasio: ubin selalu setinggi lebarnya (gambar persegi) + blok teks.
    expect(UbinProduk.HitungRasio(148), closeTo(148 / (148 + UbinProduk.tinggiTeks), 0.0001));
  });

  testWidgets(
    'LencanaTeks golongan obat (Apotek §9.5) di ubin, baris daftar, dan baris keranjang: teks selalu tampil',
    (tester) async {
      const keras = LencanaTeks(teks: 'K', label: 'Obat keras, wajib resep', nada: NadaStatus.Bahaya);
      await Pasang(
        tester,
        Column(
          children: [
            SizedBox(
              width: 148,
              height: 148 + UbinProduk.tinggiTeks,
              child: UbinProduk(
                nama: 'Amoxicillin Trihydrate 500 mg Kapsul Strip 10',
                harga: Uang.DariBulat(8500),
                saatDiketuk: () {},
                lencana: keras,
              ),
            ),
            BarisProduk(nama: 'Amoxicillin 500 mg', harga: Uang.DariBulat(8500), saatDiketuk: () {}, lencana: keras),
            BarisKeranjang(
              nama: 'Amoxicillin 500 mg',
              jumlah: '1',
              total: Uang.DariBulat(8500),
              saatDiketuk: () {},
              saatTambah: () {},
              saatKurang: () {},
              lencana: keras,
            ),
          ],
        ),
        tinggi: 640,
      );

      expect(tester.takeException(), isNull, reason: 'Tidak meluap di 360dp.');
      expect(find.text('K'), findsNWidgets(3));
      expect(find.bySemanticsLabel(RegExp('Obat keras, wajib resep')), findsWidgets);
    },
  );
}
