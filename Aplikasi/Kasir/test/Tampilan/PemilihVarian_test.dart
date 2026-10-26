import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:inti/Inti.dart';
import 'package:kasir/Domain/Katalog/KatalogLokal.dart';
import 'package:kasir/Tampilan/RuangKerja/RuangKerja.dart';
import 'package:sistem_desain/SistemDesain.dart';

import '../Pendukung/KatalogUji.dart';
import '../Pendukung/LingkunganUji.dart';
import '../Pendukung/PasangAplikasi.dart';

/// K-9 (§9.4): produk induk varian (ukuran × warna) dijual lewat pemilih varian. Grid hanya menampilkan induknya
/// (penanda "Pilih varian"); kombinasi yang tidak dijual dinonaktifkan; anak varian tetap bisa dicari namanya/SKU-nya;
/// outbox membawa produk anak, bukan induk. Format `AtributVarian` mengikuti server (`PenyusunAnakVarian`).
void main() {
  const mHitam = '01K5PRD0000000000KA0SMHTM1';
  const lHitam = '01K5PRD0000000000KA0SLHTM1';
  const mPutih = '01K5PRD0000000000KA0SMPTH1';

  Map<String, Object?> KatalogVarian() {
    final isi = KatalogUji();
    final produk = isi['Produk']! as List<Object?>;
    final i = produk.indexWhere((p) => (p! as Map<String, Object?>)['Uuid'] == UuidUji.kaos);
    produk[i] = {
      ...(produk[i]! as Map<String, Object?>),
      'AtributVarian': [
        {
          'Nama': 'Ukuran',
          'Nilai': ['M', 'L'],
        },
        {
          'Nama': 'Warna',
          'Nilai': ['Hitam', 'Putih'],
        },
      ],
    };
    for (final (uuid, ukuran, warna, harga) in [
      (mHitam, 'M', 'Hitam', '95000.00'),
      (lHitam, 'L', 'Hitam', '99000.00'),
      (mPutih, 'M', 'Putih', '95000.00'),
    ]) {
      final ps = 'PS${uuid.substring(2)}';
      produk.add({
        ...ProdukUji(
          uuid,
          'Kaos Kopi Senja $ukuran / $warna',
          sku: 'KAOS-$ukuran-${warna.substring(0, 3).toUpperCase()}',
        ),
        'UuidInduk': UuidUji.kaos,
        'AtributVarian': [
          {'Nama': 'Ukuran', 'Nilai': ukuran},
          {'Nama': 'Warna', 'Nilai': warna},
        ],
      });
      (isi['ProdukSatuan']! as List<Object?>).add(SatuanProdukUji(ps, uuid, UuidUji.satuanPcs));
      (isi['ProdukHarga']! as List<Object?>).add(HargaUji('HRG${uuid.substring(3)}', uuid, ps, harga));
    }
    return isi;
  }

  test('pengurai AtributVarian: induk = definisi, anak = kombinasi (daftar atau peta)', () {
    final induk = ProdukJual.UraiAtributVarian('[{"Nama":"Ukuran","Nilai":["S","M"]}]');
    expect(induk.definisi.single.nama, 'Ukuran');
    expect(induk.definisi.single.nilai, ['S', 'M']);
    expect(induk.atribut, isEmpty);
    expect(ProdukJual.UraiAtributVarian('[{"Nama":"Ukuran","Nilai":"M"},{"Nama":"Warna","Nilai":"Hitam"}]').atribut, {
      'Ukuran': 'M',
      'Warna': 'Hitam',
    });
    expect(ProdukJual.UraiAtributVarian('{"Ukuran":"L"}').atribut, {'Ukuran': 'L'});
    expect(ProdukJual.UraiAtributVarian('bukan json').atribut, isEmpty);
    expect(ProdukJual.UraiAtributVarian(null).definisi, isEmpty);
  });

  test('katalog: tanpa kata cari hanya induk yang tampil. Anak bisa dicari; AmbilVarian berisi anak aktif', () async {
    final u = LingkunganUji.Buat();
    addTearDown(u.Tutup);
    await u.SiapkanKatalog(KatalogVarian());
    final katalog = await u.MuatKatalog();
    final tampil = katalog.AmbilTampil().map((p) => p.uuid);
    expect(tampil, contains(UuidUji.kaos));
    expect(tampil, isNot(contains(mHitam)));
    expect(katalog.AmbilTampil(kata: 'putih').map((p) => p.uuid), [mPutih]);
    expect(katalog.AmbilVarian(UuidUji.kaos).map((p) => p.uuid).toSet(), {mHitam, lHitam, mPutih});
    expect(katalog.CariProduk(lHitam)!.atributVarian, {'Ukuran': 'L', 'Warna': 'Hitam'});
  });

  for (final ukuran in const [Size(1280, 900), Size(800, 1280), Size(360, 740)]) {
    testWidgets('pilih varian L / Hitam dari induk; L / Putih tidak dijual (${ukuran.width.toInt()} dp)', (
      tester,
    ) async {
      final u = LingkunganUji.Buat();
      final katalog = KatalogVarian();
      await tester.runAsync(() async {
        await u.SiapkanAktif();
        await u.SiapkanKatalog(katalog);
        await u.shift.BukaShift(kasir: await u.Staf('Rina Wulandari'), kasAwal: Uang.DariBulat(500000));
      });
      u.server.penangan = (p) async {
        if (p.url.path.endsWith('/data-awal')) {
          return JsonUji(DataAwalUji());
        }
        if (p.url.path.endsWith('/katalog')) {
          return JsonUji(katalog);
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

      Future<void> Ketuk(Finder f) async {
        await tester.ensureVisible(f);
        await tester.pump();
        await tester.tap(f);
        await Tunggu(tester);
      }

      final induk = find.byWidgetPredicate((w) => w is UbinProduk && w.nama == 'Kaos Kopi Senja');
      expect(tester.widget<UbinProduk>(induk).keterangan, 'Pilih varian');
      expect(tester.widget<UbinProduk>(induk).nonaktif, isFalse);
      expect(find.byWidgetPredicate((w) => w is UbinProduk && w.nama.startsWith('Kaos Kopi Senja M')), findsNothing);

      await Ketuk(induk);
      expect(find.byType(PanelTugas), findsOneWidget);
      expect(find.text('Pilih ukuran dan warna.'), findsOneWidget);
      await Ketuk(find.byKey(const ValueKey('varian-Ukuran-L')));
      final putih = tester.widget<ChoiceChip>(find.byKey(const ValueKey('varian-Warna-Putih')));
      expect(putih.onSelected, isNull, reason: 'L / Putih tidak ada di katalog.');
      await Ketuk(find.byKey(const ValueKey('varian-Warna-Hitam')));
      expect(find.text('Kaos Kopi Senja L / Hitam'), findsOneWidget);
      expect(find.text('Rp 99.000'), findsWidgets);
      await Ketuk(find.widgetWithText(FilledButton, 'Tambah ke keranjang'));
      expect(find.byType(PanelTugas), findsNothing);
      expect(tester.takeException(), isNull);

      if (ukuran.width < 600) {
        await Ketuk(find.textContaining('Keranjang |').first);
      }
      await Ketuk(find.widgetWithText(FilledButton, 'Bayar').last);
      await Ketuk(find.widgetWithText(ChoiceChip, 'Tunai'));
      await Ketuk(find.widgetWithText(FilledButton, 'Uang pas'));
      expect(find.text('Pembayaran berhasil'), findsOneWidget);
      final outbox = (await tester.runAsync(() => u.db.select(u.db.outbox).get()))!
          .where((o) => o.Jenis == 'Penjualan.Buat')
          .map((o) => jsonDecode(o.Data) as Map<String, Object?>)
          .single;
      final baris = (outbox['Baris']! as List<Object?>).cast<Map<String, Object?>>().single;
      expect(baris['UuidProduk'], lHitam);
      await Lepas(tester, u);
    });
  }
}
