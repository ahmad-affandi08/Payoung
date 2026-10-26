import 'package:flutter_test/flutter_test.dart';
import 'package:kasir/Domain/Penjualan/KonteksPenjualan.dart';
import 'package:kasir/Domain/Sesi/StafLokal.dart';
import 'package:kasir/Tampilan/RuangKerja/ItemNavigasi.dart';

/// D-183: item rel navigasi khusus sektor (Stok, Salesman) tidak bocor ke sektor lain. Pemilik lolos semua izin, jadi
/// hanya penyaringan sektor yang diuji di sini.
const _pemilik = StafLokal(uuid: 'P', nama: 'Pemilik', pemilik: true, izin: []);

bool _CekSektor(List<String> kodeSektor, List<String> awalan) {
  if (kodeSektor.isEmpty) {
    return true;
  }
  return awalan.any((a) => kodeSektor.any((kode) => kode == a || kode.startsWith('$a-')));
}

List<TujuanRuangKerja> _Tujuan(List<String> kodeSektor) =>
    ItemNavigasi.Saring(_pemilik, cekSektor: (awalan) => _CekSektor(kodeSektor, awalan)).map((i) => i.tujuan).toList();

void main() {
  test('sektor belum diketahui: semua item tampil (tidak ada yang hilang diam-diam)', () {
    final tujuan = _Tujuan(const []);
    expect(tujuan, containsAll([TujuanRuangKerja.Stok, TujuanRuangKerja.Salesman]));
  });

  test('toko kelontong: Stok tampil, Salesman tidak', () {
    final tujuan = _Tujuan(const ['RTL-GEN']);
    expect(tujuan, contains(TujuanRuangKerja.Stok));
    expect(tujuan, isNot(contains(TujuanRuangKerja.Salesman)));
  });

  test('grosir: Stok dan Salesman tampil', () {
    final tujuan = _Tujuan(const ['WHS-DST']);
    expect(tujuan, containsAll([TujuanRuangKerja.Stok, TujuanRuangKerja.Salesman]));
  });

  test('jasa umum (tanpa stok): Stok dan Salesman tidak tampil', () {
    final tujuan = _Tujuan(const ['SVC-GEN']);
    expect(tujuan, isNot(contains(TujuanRuangKerja.Stok)));
    expect(tujuan, isNot(contains(TujuanRuangKerja.Salesman)));
  });

  test('salon (jual produk perawatan): Stok tampil', () {
    expect(_Tujuan(const ['SVC-SLN']), contains(TujuanRuangKerja.Stok));
  });

  test('pre-order: kue & katering, fashion, inden elektronik, florist, percetakan, bukan apotek/kelontong/bengkel', () {
    expect(_CekSektor(const ['FNB-BAK'], KonteksPenjualan.sektorPreOrder), isTrue);
    expect(_CekSektor(const ['FNB-CAF'], KonteksPenjualan.sektorPreOrder), isTrue);
    expect(_CekSektor(const ['RTL-FSH'], KonteksPenjualan.sektorPreOrder), isTrue);
    expect(_CekSektor(const ['RTL-ELC'], KonteksPenjualan.sektorPreOrder), isTrue);
    expect(_CekSektor(const ['SVC-GEN'], KonteksPenjualan.sektorPreOrder), isTrue);
    expect(_CekSektor(const ['RTL-PHR'], KonteksPenjualan.sektorPreOrder), isFalse);
    expect(_CekSektor(const ['RTL-GEN'], KonteksPenjualan.sektorPreOrder), isFalse);
    expect(_CekSektor(const ['SVC-WRK'], KonteksPenjualan.sektorPreOrder), isFalse);
  });

  test('komisi staf per item: jasa, F&B, butik/toko HP, bangunan, grosir, bukan kelontong/apotek', () {
    expect(_CekSektor(const ['SVC-SLN'], KonteksPenjualan.sektorKomisiStaf), isTrue);
    expect(_CekSektor(const ['RTL-FSH'], KonteksPenjualan.sektorKomisiStaf), isTrue);
    expect(_CekSektor(const ['RTL-GEN'], KonteksPenjualan.sektorKomisiStaf), isFalse);
    expect(_CekSektor(const ['RTL-PHR'], KonteksPenjualan.sektorKomisiStaf), isFalse);
  });
}
