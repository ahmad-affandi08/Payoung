import 'package:flutter_test/flutter_test.dart';
import 'package:kasir/Domain/Persediaan/LayananInfoBatch.dart';
import 'package:klien_api/KlienApi.dart';

/// K-19: teks peringatan batch terdepan (FEFO) saat menjual & label sisa hari.
void main() {
  BatchProdukPos Info(List<int?> sisaHari, {String pelacakan = 'Batch'}) => BatchProdukPos(
    uuidProduk: 'P1',
    pelacakan: pelacakan,
    simbolSatuan: 'pcs',
    hariSegera: 30,
    jumlahBatch: sisaHari.length,
    batch: [
      for (final (i, h) in sisaHari.indexed)
        BarisBatchPos(nomorBatch: 'B$i', tanggalKedaluwarsa: null, jumlahSisa: '1.0000', sisaHari: h),
    ],
  );

  test('peringatan hanya untuk batch terdepan yang lewat atau ≤ 7 hari. Stok batch kosong diberi tahu', () {
    expect(LayananInfoBatch.SusunPeringatan('Susu', Info([-3, 40])), (
      teks: 'Susu: batch B0 sudah lewat kedaluwarsa 3 hari. Tarik dari rak sebelum dijual.',
      lewat: true,
    ));
    expect(LayananInfoBatch.SusunPeringatan('Susu', Info([0]))?.teks, 'Susu: batch B0 kedaluwarsa hari ini.');
    expect(LayananInfoBatch.SusunPeringatan('Susu', Info([7]))?.teks, 'Susu: batch B0 kedaluwarsa 7 hari lagi.');
    expect(LayananInfoBatch.SusunPeringatan('Susu', Info([8, -1])), isNull, reason: 'Hanya batch terdepan (FEFO).');
    expect(LayananInfoBatch.SusunPeringatan('Susu', Info([null])), isNull);
    expect(LayananInfoBatch.SusunPeringatan('Susu', Info([], pelacakan: 'Tidak')), isNull);
    expect(LayananInfoBatch.SusunPeringatan('Susu', Info([]))?.lewat, isFalse);
  });

  test('label sisa hari', () {
    expect(LayananInfoBatch.LabelSisaHari(null), 'tanpa kedaluwarsa');
    expect(LayananInfoBatch.LabelSisaHari(-2), 'lewat 2 hari');
    expect(LayananInfoBatch.LabelSisaHari(0), 'kedaluwarsa hari ini');
    expect(LayananInfoBatch.LabelSisaHari(12), '12 hari lagi');
  });
}
