import 'dart:async';

import 'DokumenStruk.dart';
import 'TransportPrinter.dart';

/// Antrean cetak per printer fisik (D-317): pekerjaan ke printer yang sama dijalankan satu per satu, berurutan sesuai
/// kedatangan. Tanpa ini struk, tiket dapur, salinan checker, dan buka laci yang dikirim hampir bersamaan membuka
/// soket/Bluetooth/USB yang sama berbarengan lalu bercampur atau salah satunya gagal. Printer berbeda tetap jalan
/// paralel. Pekerjaan yang gagal tidak menghentikan antrean, dan pekerjaan yang menggantung dilepas setelah
/// [batasPekerjaan] supaya printer macet tidak mengunci semua cetak berikutnya.
class AntreanCetak {
  AntreanCetak({
    this.jedaAntarPekerjaan = const Duration(milliseconds: 300),
    this.batasPekerjaan = const Duration(seconds: 90),
  });

  /// Antrean tunggal untuk seluruh aplikasi.
  static final AntreanCetak bersama = AntreanCetak();

  /// Napas bagi printer murah setelah satu pekerjaan sebelum sambungan berikutnya dibuka.
  final Duration jedaAntarPekerjaan;
  final Duration batasPekerjaan;

  final Map<String, Future<void>> _ekor = {};

  /// Jumlah printer yang sedang punya antrean (untuk test).
  int get jumlahAktif => _ekor.length;

  /// Menjalankan [pekerjaan] setelah semua pekerjaan sebelumnya untuk [kunci] selesai.
  Future<T> Jalankan<T>(String kunci, Future<T> Function() pekerjaan) {
    final sebelum = _ekor[kunci] ?? Future<void>.value();
    final hasil = sebelum.then((_) => pekerjaan());
    late final Future<void> ekor;
    ekor = hasil
        .then<void>((_) {}, onError: (Object _) {})
        .timeout(batasPekerjaan, onTimeout: () {})
        .then<void>((_) => Future<void>.delayed(jedaAntarPekerjaan))
        .whenComplete(() {
          if (identical(_ekor[kunci], ekor)) {
            _ekor.remove(kunci);
          }
        });
    _ekor[kunci] = ekor;
    return hasil;
  }

  /// Membungkus [dalam] supaya setiap kirimannya lewat antrean [kunci] (satu kunci = satu printer fisik).
  static TransportPrinter Bungkus(
    TransportPrinter dalam,
    String kunci, {
    AntreanCetak? antrean,
  }) {
    final dipakai = antrean ?? bersama;
    return dalam is TransportDokumen
        ? TransportDokumenBerantre(dalam, kunci, dipakai)
        : TransportBerantre(dalam, kunci, dipakai);
  }
}

/// Transport yang mengirim lewat [AntreanCetak].
class TransportBerantre implements TransportPrinter {
  TransportBerantre(this.dalam, this.kunci, this.antrean);

  final TransportPrinter dalam;
  final String kunci;
  final AntreanCetak antrean;

  @override
  Future<void> Kirim(List<int> data) =>
      antrean.Jalankan(kunci, () => dalam.Kirim(data));
}

/// Versi untuk printer sistem: tetap [TransportDokumen] supaya [PrinterStruk] mengirim dokumen utuh.
class TransportDokumenBerantre extends TransportBerantre
    implements TransportDokumen {
  TransportDokumenBerantre(
    TransportDokumen super.dalam,
    super.kunci,
    super.antrean,
  );

  @override
  Future<void> CetakDokumen(DokumenStruk dokumen, LebarKertas lebar) =>
      antrean.Jalankan(
        kunci,
        () => (dalam as TransportDokumen).CetakDokumen(dokumen, lebar),
      );
}
