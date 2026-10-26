import 'package:flutter_test/flutter_test.dart';
import 'package:kasir/Tampilan/LayarStatusSinkron.dart';

/// Audit kemudahan pakai #30: layar Sinkron memakai bahasa sehari-hari untuk setiap jenis kiriman; kode teknis
/// (`Penjualan.Void`, `PesananTerbuka.KirimDapur`, …) tidak pernah tampil.
void main() {
  test('semua jenis outbox kasir berlabel bahasa sehari-hari. Jenis tak dikenal jadi "Data lain"', () {
    const jenis = [
      'Shift.Buka',
      'Shift.Tutup',
      'Shift.BukaUlang',
      'Penjualan.Buat',
      'Penjualan.Void',
      'ReturPenjualan.Buat',
      'ReturPenjualan.TanpaStruk',
      'MutasiKas.Catat',
      'Laci.Buka',
      'Absensi.Masuk',
      'Absensi.Keluar',
      'Pelanggan.Buat',
      'Deposit.Isi',
      'Sesi.Pakai',
      'PesananPenjualan.Buat',
      'PesananTerbuka.Buka',
      'PesananTerbuka.Tambah',
      'PesananTerbuka.Ubah',
      'PesananTerbuka.KirimDapur',
      'PesananTerbuka.BatalkanBaris',
      'PesananTerbuka.PindahBaris',
      'PesananTerbuka.Batal',
      'Meja.Bersih',
      'BahanTerbuang.Catat',
      'PesananGrosir.Buat',
      'Kunjungan.Catat',
    ];
    for (final j in jenis) {
      final label = LayarStatusSinkron.AmbilLabelJenis(j);
      expect(label, isNot('Data lain'), reason: '$j belum berlabel');
      expect(label.contains('.'), isFalse, reason: '$j masih tampil sebagai kode');
    }
    expect(LayarStatusSinkron.AmbilLabelJenis('Penjualan.Void'), 'Pembatalan transaksi');
    expect(LayarStatusSinkron.AmbilLabelJenis('Sesuatu.Baru'), 'Data lain');
  });
}
