import 'package:flutter_test/flutter_test.dart';
import 'package:pemilik/Tampilan/FormatTampilan.dart';

/// Rupiah di Aplikasi Owner paling banyak 2 desimal. Nilai dari server yang lebih panjang (HPP per satuan 6 desimal)
/// dibulatkan untuk tampilan dan tidak membuat layar gagal.
void main() {
  test('nilai 2 desimal tampil seperti biasa', () {
    expect(FormatTampilan.Rupiah('1250000.00'), 'Rp 1.250.000');
    expect(FormatTampilan.Rupiah('63500'), 'Rp 63.500');
    expect(FormatTampilan.Rupiah('1234.5'), 'Rp 1.234,50');
    expect(FormatTampilan.Rupiah('-6000.00'), '−Rp 6.000');
  });

  test('nilai lebih dari 2 desimal dibulatkan ke 2 desimal, bukan melempar galat', () {
    expect(FormatTampilan.Rupiah('3636.545455'), 'Rp 3.636,55');
    expect(FormatTampilan.Rupiah('333.333333'), 'Rp 333,33');
    expect(FormatTampilan.Rupiah('-3636.545455'), '−Rp 3.636,55');
    expect(FormatTampilan.Rupiah('1000.000000'), 'Rp 1.000');
  });
}
