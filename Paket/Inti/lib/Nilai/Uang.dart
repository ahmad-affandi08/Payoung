import 'package:decimal/decimal.dart';

import 'ModePembulatan.dart';

/// Nilai uang Rupiah dengan presisi tetap 2 desimal (PRD §8 F-07, §15.1, CLAUDE.md #7).
///
/// Padanan `App\Domain\Bersama\Nilai\Uang` di Backend. Tidak pernah memakai tipe pecahan biner.
/// Nilai dengan lebih dari 2 desimal ditolak, kecuali lewat operasi yang menyebut mode pembulatan.
final class Uang implements Comparable<Uang> {
  const Uang._(this._nilai);

  static const int skala = 2;

  final Decimal _nilai;

  /// Dari string desimal, misal `"15000"` atau `"18000.5"`.
  static Uang Dari(String nilai) => DariDesimal(Decimal.parse(nilai));

  /// Dari Rupiah bulat, misal `15000`.
  static Uang DariBulat(int rupiah) => Uang._(Decimal.fromInt(rupiah));

  /// Untuk **tampilan** nilai dari server yang boleh lebih dari 2 desimal (misal HPP per satuan 6 desimal): dibulatkan ke
  /// 2 desimal dengan [mode], bukan ditolak seperti [Dari]. Jangan dipakai untuk menghitung atau menyimpan uang.
  static Uang DariPembulatan(String nilai, {ModePembulatan mode = ModePembulatan.SetengahMenjauhiNol}) =>
      Uang._(BulatkanKeSkala(Decimal.parse(nilai), skala, mode));

  static Uang DariDesimal(Decimal nilai) {
    if (nilai.scale > skala) {
      throw ArgumentError.value(nilai.toString(), 'nilai', 'Uang maksimal $skala desimal');
    }
    return Uang._(nilai);
  }

  static Uang Nol() => Uang._(Decimal.zero);

  Uang Tambah(Uang lain) => Uang._(_nilai + lain._nilai);

  Uang Kurangi(Uang lain) => Uang._(_nilai - lain._nilai);

  /// Mengalikan dengan jumlah/tarif lalu membulatkan ke 2 desimal dengan mode yang disebut eksplisit.
  Uang Kali(Decimal faktor, {ModePembulatan mode = ModePembulatan.SetengahMenjauhiNol}) =>
      Uang._(BulatkanKeSkala(_nilai * faktor, skala, mode));

  /// Pembulatan ke kelipatan tertentu, misal pembulatan tunai ke Rp 100 (PRD Lampiran D).
  Uang BulatkanKeKelipatan(int kelipatan, ModePembulatan mode) {
    if (kelipatan <= 0) {
      throw ArgumentError.value(kelipatan, 'kelipatan', 'Kelipatan pembulatan harus lebih dari 0');
    }
    final sen = _nilai.shift(skala).toBigInt();
    final penyebut = BigInt.from(kelipatan) * BigInt.from(100);
    return Uang._(Decimal.fromBigInt(BagiBulat(sen, penyebut, mode) * BigInt.from(kelipatan)));
  }

  /// Hasil selalu -1, 0, atau 1 (sama dengan `BigDecimal::compareTo` di Backend).
  int Bandingkan(Uang lain) => _nilai.compareTo(lain._nilai).sign;

  bool SamaDengan(Uang lain) => _nilai == lain._nilai;

  bool BernilaiNol() => _nilai == Decimal.zero;

  bool BernilaiNegatif() => _nilai < Decimal.zero;

  Decimal KeDesimal() => _nilai;

  /// Format penyimpanan & API: string desimal, misal `"15000.00"`.
  String KeString() => _nilai.toStringAsFixed(skala);

  /// Format tampilan Indonesia, misal `"Rp 1.250.000"` atau `"−Rp 6.000"`.
  String FormatRupiah() {
    final mutlak = _nilai.abs();
    final bulat = mutlak.truncate();
    final sen = (mutlak - bulat).shift(skala).toBigInt();
    final digit = bulat.toBigInt().toString();
    final kelompok = StringBuffer();
    for (var indeks = 0; indeks < digit.length; indeks++) {
      if (indeks > 0 && (digit.length - indeks) % 3 == 0) {
        kelompok.write('.');
      }
      kelompok.write(digit[indeks]);
    }
    final teksSen = sen == BigInt.zero ? '' : ',${sen.toString().padLeft(2, '0')}';
    return '${BernilaiNegatif() ? '−' : ''}Rp $kelompok$teksSen';
  }

  String toJson() => KeString();

  @override
  int compareTo(Uang other) => Bandingkan(other);

  @override
  bool operator ==(Object other) => other is Uang && SamaDengan(other);

  @override
  int get hashCode => _nilai.hashCode;

  @override
  String toString() => KeString();
}
