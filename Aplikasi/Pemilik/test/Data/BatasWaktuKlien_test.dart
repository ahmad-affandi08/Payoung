import 'dart:async';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:klien_api/KlienApi.dart';

import 'package:pemilik/Data/KlienPemilik.dart';

/// Klien yang menjawab header lebih dulu, lalu tidak pernah menyelesaikan isi respons.
class _KlienIsiMacet extends http.BaseClient {
  @override
  Future<http.StreamedResponse> send(http.BaseRequest request) async {
    final macet = StreamController<List<int>>();
    macet.add(const [123]);

    return http.StreamedResponse(macet.stream, 200, headers: const {'content-type': 'application/json'});
  }
}

/// Layar yang menunggu server tidak boleh berputar tanpa akhir: bila isi respons berhenti mengalir, klien harus
/// menyerah pada batas waktu dan melaporkan galat jaringan (layar menampilkan pesan dan tombol "Coba lagi").
void main() {
  test('isi respons yang macet berakhir sebagai GalatJaringan pada batas waktu, bukan menggantung', () async {
    final klien = KlienPemilik(
      alamatDasar: Uri.parse('https://contoh.test/'),
      versiAplikasi: '1.0.0',
      ambilToken: () => 'token',
      ambilTenant: () => 'T1',
      klien: _KlienIsiMacet(),
      batasWaktu: const Duration(milliseconds: 200),
    );

    await expectLater(klien.AmbilAntiFraud(dari: '2026-10-24', sampai: '2026-10-30'), throwsA(isA<GalatJaringan>()));
  });
}
