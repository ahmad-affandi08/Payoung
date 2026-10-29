import 'package:inti/Inti.dart';

/// Format tampilan Indonesia untuk Aplikasi Owner.
abstract final class FormatTampilan {
  static const List<String> _hari = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'];
  static const List<String> _bulan = [
    'Jan',
    'Feb',
    'Mar',
    'Apr',
    'Mei',
    'Jun',
    'Jul',
    'Agu',
    'Sep',
    'Okt',
    'Nov',
    'Des',
  ];

  /// `Sabtu, 26 Sep 2026`.
  static String Tanggal(DateTime t) => '${_hari[t.weekday - 1]}, ${t.day} ${_bulan[t.month - 1]} ${t.year}';

  /// `26 Sep 2026` (tanpa nama hari), untuk kapsul saringan yang sempit.
  static String TanggalPendek(DateTime t) => '${t.day} ${_bulan[t.month - 1]} ${t.year}';

  /// `26 Sep 14.05`.
  static String TanggalJam(DateTime t) =>
      '${t.day} ${_bulan[t.month - 1]} ${t.hour.toString().padLeft(2, '0')}.${t.minute.toString().padLeft(2, '0')}';

  static String Rupiah(String nilai) => Uang.Dari(nilai).FormatRupiah();

  /// Perbandingan [nilai] terhadap [pembanding]: `+12%`, `-5%`, atau null bila pembanding nol.
  static String? Perubahan(String nilai, String pembanding) {
    final a = double.tryParse(nilai) ?? 0;
    final b = double.tryParse(pembanding) ?? 0;
    if (b == 0) {
      return null;
    }
    final persen = ((a - b) / b * 100).round();
    return '${persen > 0 ? '+' : ''}$persen%';
  }

  /// Kuantitas desimal dari server ("25.5000") ke tampilan Indonesia ("25,5"), tanpa nol di belakang koma.
  static String Jumlah(String nilai) {
    var t = nilai;
    if (t.contains('.')) {
      t = t.replaceFirst(RegExp(r'0+$'), '').replaceFirst(RegExp(r'\.$'), '');
    }
    return t.replaceAll('.', ',');
  }
}
