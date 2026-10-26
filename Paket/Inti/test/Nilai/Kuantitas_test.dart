import 'package:inti/Inti.dart';
import 'package:test/test.dart';

void main() {
  group('Kuantitas (PRD §15.1, CLAUDE.md #7)', () {
    test('menyimpan nilai sebagai string desimal 4 angka', () {
      expect(Kuantitas.DariBulat(2).KeString(), '2.0000');
      expect(Kuantitas.Dari('0.125').toJson(), '0.1250');
    });

    test('menolak lebih dari 4 desimal', () {
      expect(() => Kuantitas.Dari('1.00005'), throwsArgumentError);
    });

    test('menjumlah, mengurangi, menegasi, dan mengalikan dengan pembulatan eksplisit', () {
      final gram = Kuantitas.Dari('250').Tambah(Kuantitas.Dari('0.5')).Kurangi(Kuantitas.DariBulat(50));
      expect(gram.KeString(), '200.5000');
      expect(gram.Negasi().BernilaiNegatif(), isTrue);
      expect(Kuantitas.DariBulat(1).Kali(Decimal.parse('0.33333')).KeString(), '0.3333');
      expect(
        Kuantitas.Dari('0.0001').Kali(Decimal.parse('0.5'), mode: ModePembulatan.SetengahGenap).KeString(),
        '0.0000',
      );
    });

    test('membandingkan nilai', () {
      expect(Kuantitas.Dari('1.5').Bandingkan(Kuantitas.DariBulat(1)), 1);
      expect(Kuantitas.Dari('1.50'), Kuantitas.Dari('1.5'));
      expect(Kuantitas.Nol().BernilaiNol(), isTrue);
    });
  });

  test('DariPembagian (v3.55): harga ÷ harga per kg, 3 desimal setengah menjauhi nol. Penyebut nol ditolak', () {
    expect(Kuantitas.DariPembagian(Decimal.parse('40000'), Decimal.parse('32000')).KeString(), '1.2500');
    expect(Kuantitas.DariPembagian(Decimal.parse('10000'), Decimal.parse('30000')).KeString(), '0.3330');
    expect(Kuantitas.DariPembagian(Decimal.parse('20000'), Decimal.parse('30000')).KeString(), '0.6670');
    expect(() => Kuantitas.DariPembagian(Decimal.one, Decimal.zero), throwsArgumentError);
  });
}
