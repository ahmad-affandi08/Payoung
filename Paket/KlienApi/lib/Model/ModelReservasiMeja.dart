import 'UraiJson.dart';

/// Ringkasan uang muka (DP) reservasi meja: string desimal ("30000.00").
class UangMukaReservasiMejaPos {
  const UangMukaReservasiMejaPos({required this.dicatat, required this.terpakai, required this.sisa});

  final String dicatat;
  final String terpakai;
  final String sisa;

  static UangMukaReservasiMejaPos DariJson(Map<String, Object?>? json) => UangMukaReservasiMejaPos(
    dicatat: UraiJson.AmbilDesimal(json?['Dicatat'], '0.00'),
    terpakai: UraiJson.AmbilDesimal(json?['Terpakai'], '0.00'),
    sisa: UraiJson.AmbilDesimal(json?['Sisa'], '0.00'),
  );
}

/// Reservasi meja FnB untuk antrian kasir (D-145, `GET /api/pos/v1/reservasi-meja?tanggal=`).
class ReservasiMejaPos {
  const ReservasiMejaPos({
    required this.uuid,
    required this.nomor,
    required this.mulaiPada,
    required this.selesaiPada,
    required this.namaPemesan,
    required this.noHp,
    required this.jumlahTamu,
    required this.uuidMeja,
    required this.namaMeja,
    required this.status,
    required this.labelStatus,
    required this.catatan,
    required this.uangMuka,
  });

  final String uuid;
  final String nomor;

  /// UTC.
  final DateTime mulaiPada;
  final DateTime selesaiPada;
  final String namaPemesan;
  final String noHp;
  final int jumlahTamu;

  /// Null = meja belum ditentukan (ditempatkan kasir saat tamu datang).
  final String? uuidMeja;
  final String? namaMeja;

  /// `Menunggu` / `Dikonfirmasi` / `Hadir` / `Selesai` / `Dibatalkan` / `TidakDatang`.
  final String status;
  final String labelStatus;
  final String? catatan;
  final UangMukaReservasiMejaPos uangMuka;

  /// Masih menahan meja dan bisa ditagih: belum berakhir.
  bool get BisaDilayani => status == 'Menunggu' || status == 'Dikonfirmasi' || status == 'Hadir';

  /// Tamu belum ditandai datang.
  bool get BelumDatang => status == 'Menunggu' || status == 'Dikonfirmasi';

  static DateTime _AmbilWaktu(Object? nilai) =>
      DateTime.tryParse(UraiJson.AmbilTeks(nilai))?.toUtc() ?? DateTime.utc(1970);

  static ReservasiMejaPos DariJson(Map<String, Object?> json) => ReservasiMejaPos(
    uuid: UraiJson.AmbilTeks(json['Uuid']),
    nomor: UraiJson.AmbilTeks(json['Nomor']),
    mulaiPada: _AmbilWaktu(json['MulaiPada']),
    selesaiPada: _AmbilWaktu(json['SelesaiPada']),
    namaPemesan: UraiJson.AmbilTeks(json['NamaPemesan']),
    noHp: UraiJson.AmbilTeks(json['NoHp']),
    jumlahTamu: UraiJson.AmbilBulat(json['JumlahTamu'], 1),
    uuidMeja: UraiJson.AmbilTeksAtauNull(json['UuidMeja']),
    namaMeja: UraiJson.AmbilTeksAtauNull(json['NamaMeja']),
    status: UraiJson.AmbilTeks(json['Status']),
    labelStatus: UraiJson.AmbilTeks(json['LabelStatus']),
    catatan: UraiJson.AmbilTeksAtauNull(json['Catatan']),
    uangMuka: UangMukaReservasiMejaPos.DariJson(UraiJson.AmbilPetaAtauNull(json['UangMuka'])),
  );
}

/// Jawaban `GET /api/pos/v1/reservasi-meja`: antrian beserta metode sistem "Uang muka (DP)" (null bila tidak ada
/// reservasi yang memegang uang muka).
class AntrianReservasiMejaPos {
  const AntrianReservasiMejaPos({
    required this.reservasi,
    required this.uuidMetodeUangMuka,
    required this.namaMetodeUangMuka,
  });

  final List<ReservasiMejaPos> reservasi;
  final String? uuidMetodeUangMuka;
  final String? namaMetodeUangMuka;

  static AntrianReservasiMejaPos DariJson(Map<String, Object?> json) {
    final metode = UraiJson.AmbilPetaAtauNull(json['MetodeUangMuka']);

    return AntrianReservasiMejaPos(
      reservasi: [for (final r in UraiJson.AmbilDaftarPeta(json['Reservasi'])) ReservasiMejaPos.DariJson(r)],
      uuidMetodeUangMuka: UraiJson.AmbilTeksAtauNull(metode?['Uuid']),
      namaMetodeUangMuka: UraiJson.AmbilTeksAtauNull(metode?['Nama']),
    );
  }
}

/// Jawaban `POST /api/pos/v1/reservasi-meja/{uuid}/hadir`: reservasi terbaru + metode uang muka.
class HasilHadirReservasiMejaPos {
  const HasilHadirReservasiMejaPos({
    required this.reservasi,
    required this.uuidMetodeUangMuka,
    required this.namaMetodeUangMuka,
  });

  final ReservasiMejaPos reservasi;
  final String? uuidMetodeUangMuka;
  final String? namaMetodeUangMuka;

  static HasilHadirReservasiMejaPos DariJson(Map<String, Object?> json) {
    final metode = UraiJson.AmbilPetaAtauNull(json['MetodeUangMuka']);

    return HasilHadirReservasiMejaPos(
      reservasi: ReservasiMejaPos.DariJson(UraiJson.AmbilPeta(json['Reservasi'])),
      uuidMetodeUangMuka: UraiJson.AmbilTeksAtauNull(metode?['Uuid']),
      namaMetodeUangMuka: UraiJson.AmbilTeksAtauNull(metode?['Nama']),
    );
  }
}
