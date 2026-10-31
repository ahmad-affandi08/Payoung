import 'package:inti/Inti.dart';
import 'package:test/test.dart';

Decimal BuatDesimal(String nilai) => Decimal.parse(nilai);

void main() {
  group('Uang (PRD §8 F-07, CLAUDE.md #7)', () {
    test('menyimpan nilai sebagai string desimal 2 angka', () {
      expect(Uang.DariBulat(15000).KeString(), '15000.00');
      expect(Uang.Dari('18000.5').KeString(), '18000.50');
      expect(Uang.Dari('63500').toJson(), '63500.00');
    });

    test('menolak nilai dengan lebih dari 2 desimal agar tidak ada pembulatan diam-diam', () {
      expect(() => Uang.Dari('100.005'), throwsArgumentError);
      expect(Uang.Dari('100.500').KeString(), '100.50');
    });

    test('DariPembulatan membulatkan nilai panjang (HPP 6 desimal) ke 2 desimal hanya untuk tampilan', () {
      expect(Uang.DariPembulatan('3636.545455').KeString(), '3636.55');
      expect(Uang.DariPembulatan('3636.545455').FormatRupiah(), 'Rp 3.636,55');
      expect(Uang.DariPembulatan('0.005').KeString(), '0.01');
      expect(Uang.DariPembulatan('0.004999').KeString(), '0.00');
      expect(Uang.DariPembulatan('-3636.545455').FormatRupiah(), '−Rp 3.636,55');
      expect(Uang.DariPembulatan('1000.000000').FormatRupiah(), 'Rp 1.000');
      expect(Uang.DariPembulatan('18000.5').KeString(), '18000.50');
      // Jalur hitung tetap ketat: `Dari` masih menolak lebih dari 2 desimal.
      expect(() => Uang.Dari('3636.545455'), throwsArgumentError);
    });

    test('menghitung contoh struk kafe PRD Lampiran D dengan tepat', () {
      final subtotal = Uang.DariBulat(18000)
          .Kali(BuatDesimal('2'))
          .Tambah(Uang.DariBulat(25000))
          .Kurangi(Uang.DariBulat(6000));
      final biayaLayanan = subtotal.Kali(BuatDesimal('0.05'));
      final pb1 = subtotal.Tambah(biayaLayanan).Kali(BuatDesimal('0.10'));
      final sebelumPembulatan = subtotal.Tambah(biayaLayanan).Tambah(pb1);
      final totalAkhir = sebelumPembulatan.BulatkanKeKelipatan(100, ModePembulatan.MenujuNol);

      expect(subtotal.KeString(), '55000.00');
      expect(biayaLayanan.KeString(), '2750.00');
      expect(pb1.KeString(), '5775.00');
      expect(sebelumPembulatan.KeString(), '63525.00');
      expect(totalAkhir.KeString(), '63500.00');
      expect(totalAkhir.Kurangi(sebelumPembulatan).KeString(), '-25.00');
    });

    test('membulatkan perkalian dengan mode yang disebut eksplisit', () {
      expect(Uang.Dari('10.05').Kali(BuatDesimal('0.5')).KeString(), '5.03');
      expect(Uang.Dari('10.05').Kali(BuatDesimal('0.5'), mode: ModePembulatan.SetengahGenap).KeString(), '5.02');
      expect(Uang.Dari('-10.05').Kali(BuatDesimal('0.5')).KeString(), '-5.03');
    });

    test('membulatkan ke kelipatan dengan semua arah', () {
      final nilai = Uang.DariBulat(63550);
      expect(nilai.BulatkanKeKelipatan(100, ModePembulatan.MenujuNol).KeString(), '63500.00');
      expect(nilai.BulatkanKeKelipatan(100, ModePembulatan.KeAtas).KeString(), '63600.00');
      expect(nilai.BulatkanKeKelipatan(100, ModePembulatan.SetengahMenjauhiNol).KeString(), '63600.00');
      expect(nilai.BulatkanKeKelipatan(100, ModePembulatan.SetengahGenap).KeString(), '63600.00');
      expect(Uang.DariBulat(63450).BulatkanKeKelipatan(100, ModePembulatan.SetengahGenap).KeString(), '63400.00');
      expect(Uang.DariBulat(-63525).BulatkanKeKelipatan(100, ModePembulatan.KeBawah).KeString(), '-63600.00');
      expect(() => nilai.BulatkanKeKelipatan(0, ModePembulatan.MenujuNol), throwsArgumentError);
    });

    test('membandingkan dan memeriksa tanda nilai', () {
      expect(Uang.DariBulat(100).Bandingkan(Uang.DariBulat(200)), -1);
      expect(Uang.Dari('100.00').SamaDengan(Uang.DariBulat(100)), isTrue);
      expect(Uang.Dari('100.00'), Uang.DariBulat(100));
      expect(Uang.Nol().BernilaiNol(), isTrue);
      expect(Uang.DariBulat(-5).BernilaiNegatif(), isTrue);
    });

    test('memformat Rupiah gaya Indonesia', () {
      expect(Uang.DariBulat(1250000).FormatRupiah(), 'Rp 1.250.000');
      expect(Uang.DariBulat(-6000).FormatRupiah(), '−Rp 6.000');
      expect(Uang.Dari('1234.5').FormatRupiah(), 'Rp 1.234,50');
      expect(Uang.Dari('999.05').FormatRupiah(), 'Rp 999,05');
      expect(Uang.Nol().FormatRupiah(), 'Rp 0');
    });
  });
}
