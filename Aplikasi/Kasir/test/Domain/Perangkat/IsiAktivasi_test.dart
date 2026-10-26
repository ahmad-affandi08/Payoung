import 'package:flutter_test/flutter_test.dart';
import 'package:kasir/Domain/Perangkat/IsiAktivasi.dart';

/// D-35: QR aktivasi edisi Lisensi membawa alamat server toko; QR edisi SaaS tetap berisi kode saja.
void main() {
  test('QR edisi SaaS: isi apa adanya adalah kode, tanpa alamat server', () {
    final isi = IsiAktivasi.Uraikan(' A7K9M2QT ');

    expect(isi.kode, 'A7K9M2QT');
    expect(isi.alamatServer, isNull);
  });

  test('QR edisi Lisensi: alamat dasar & kode diambil dari URL, termasuk subfolder dan port', () {
    final isi = IsiAktivasi.Uraikan('https://kasir.tokoabc.id/aktivasi-perangkat?kode=A7K9M2QT');
    expect(isi.kode, 'A7K9M2QT');
    expect(isi.alamatServer.toString(), 'https://kasir.tokoabc.id/');

    final subfolder = IsiAktivasi.Uraikan('http://192.168.1.10:8080/payoung/aktivasi-perangkat?kode=ZX12');
    expect(subfolder.alamatServer.toString(), 'http://192.168.1.10:8080/payoung/');
    expect(subfolder.kode, 'ZX12');
  });

  test('URL tanpa kode atau skema asing tidak dianggap alamat server', () {
    expect(IsiAktivasi.Uraikan('https://kasir.tokoabc.id/').alamatServer, isNull);
    expect(IsiAktivasi.Uraikan('ftp://kasir.tokoabc.id/aktivasi-perangkat?kode=X').alamatServer, isNull);
  });

  test('alamat ketikan dinormalkan, yang tidak sah ditolak', () {
    expect(IsiAktivasi.NormalkanAlamat('kasir.tokoabc.id').toString(), 'https://kasir.tokoabc.id/');
    expect(
      IsiAktivasi.NormalkanAlamat(' https://kasir.tokoabc.id/payoung ').toString(),
      'https://kasir.tokoabc.id/payoung/',
    );
    expect(IsiAktivasi.NormalkanAlamat(''), isNull);
    expect(IsiAktivasi.NormalkanAlamat('https://kasir.tokoabc.id/?a=1'), isNull);
    expect(IsiAktivasi.NormalkanAlamat('mailto:kasir@toko.id'), isNull);
  });
}
