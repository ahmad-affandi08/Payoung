import 'dart:convert';

import 'package:inti/Inti.dart';
import 'package:klien_api/KlienApi.dart';

import '../../Data/RepositoriKasir.dart';
import '../GalatKasir.dart';
import '../Katalog/KatalogLokal.dart';
import '../Sesi/StafLokal.dart';

/// Jenis pekerjaan modul Gudang (POS-25).
enum JenisGudang {
  Penerimaan('Terima barang'),
  Transfer('Transfer masuk'),
  Opname('Hitung stok opname');

  const JenisGudang(this.judul);

  final String judul;
}

/// Isian batch/nomor seri satu baris penerimaan barang.
class PelacakanDraf {
  PelacakanDraf({this.nomorBatch, this.tanggalKedaluwarsa, List<String>? nomorSeri}) : nomorSeri = nomorSeri ?? [];

  String? nomorBatch;

  /// `YYYY-MM-DD`.
  String? tanggalKedaluwarsa;
  final List<String> nomorSeri;

  Map<String, Object?> KeJson() => {
    'NomorBatch': nomorBatch,
    'TanggalKedaluwarsa': tanggalKedaluwarsa,
    'NomorSeri': nomorSeri,
  };

  static PelacakanDraf DariJson(Map<String, Object?> json) => PelacakanDraf(
    nomorBatch: UraiJson.AmbilTeksAtauNull(json['NomorBatch']),
    tanggalKedaluwarsa: UraiJson.AmbilTeksAtauNull(json['TanggalKedaluwarsa']),
    nomorSeri: UraiJson.AmbilDaftarTeks(json['NomorSeri']),
  );
}

/// Draf kerja satu dokumen gudang di perangkat (PRD §13.5: online-first dengan draf lokal). Disimpan di `Pengaturan`
/// lokal agar hitungan tidak hilang bila aplikasi tertutup; [kunciIdempotensi] tetap sampai terkirim sehingga kirim
/// ulang setelah koneksi putus tidak menggandakan dokumen di server.
class DrafGudang {
  DrafGudang({
    required this.jenis,
    required this.uuidDokumen,
    required this.kunciIdempotensi,
    Map<int, String>? jumlah,
    Map<int, PelacakanDraf>? pelacakan,
    Map<String, String>? produkBaru,
    this.nomorSuratJalan,
  }) : jumlah = jumlah ?? {},
       pelacakan = pelacakan ?? {},
       produkBaru = produkBaru ?? {};

  final JenisGudang jenis;
  final String uuidDokumen;
  String kunciIdempotensi;

  /// Jumlah isian per `urutan` baris (string desimal; satuan PO untuk penerimaan, satuan dasar lainnya).
  final Map<int, String> jumlah;
  final Map<int, PelacakanDraf> pelacakan;

  /// Opname: produk hasil pindai yang belum ada di lembar hitung (Uuid produk → jumlah fisik).
  final Map<String, String> produkBaru;
  String? nomorSuratJalan;

  bool get kosong => jumlah.isEmpty && produkBaru.isEmpty && pelacakan.isEmpty;

  static String Kunci(JenisGudang jenis, String uuidDokumen) => 'DrafGudang:${jenis.name}:$uuidDokumen';

  String KeJson() => jsonEncode({
    'Jenis': jenis.name,
    'UuidDokumen': uuidDokumen,
    'KunciIdempotensi': kunciIdempotensi,
    'Jumlah': {for (final e in jumlah.entries) '${e.key}': e.value},
    'Pelacakan': {for (final e in pelacakan.entries) '${e.key}': e.value.KeJson()},
    'ProdukBaru': produkBaru,
    'NomorSuratJalan': nomorSuratJalan,
  });

  static DrafGudang? DariJson(String teks) {
    Object? mentah;
    try {
      mentah = jsonDecode(teks);
    } on FormatException {
      return null;
    }
    final json = mentah is Map<String, Object?> ? mentah : null;
    if (json == null) {
      return null;
    }
    final jenis = JenisGudang.values.where((j) => j.name == json['Jenis']).firstOrNull;
    if (jenis == null) {
      return null;
    }
    return DrafGudang(
      jenis: jenis,
      uuidDokumen: UraiJson.AmbilTeks(json['UuidDokumen']),
      kunciIdempotensi: UraiJson.AmbilTeks(json['KunciIdempotensi']),
      jumlah: {
        for (final e in UraiJson.AmbilPeta(json['Jumlah']).entries)
          if (int.tryParse(e.key) != null && e.value is String) int.parse(e.key): e.value! as String,
      },
      pelacakan: {
        for (final e in UraiJson.AmbilPeta(json['Pelacakan']).entries)
          if (int.tryParse(e.key) != null) int.parse(e.key): PelacakanDraf.DariJson(UraiJson.AmbilPeta(e.value)),
      },
      produkBaru: {
        for (final e in UraiJson.AmbilPeta(json['ProdukBaru']).entries)
          if (e.value is String) e.key: e.value! as String,
      },
      nomorSuratJalan: UraiJson.AmbilTeksAtauNull(json['NomorSuratJalan']),
    );
  }
}

/// Modul Gudang di aplikasi (POS-25, PRD §13.5 "mode Gudang"): terima barang dari PO, terima transfer masuk, dan hitung
/// stok opname untuk lokasi stok outlet perangkat. Online-first: daftar dokumen & posting lewat `/api/pos/v1/gudang/*`
/// (staf & izin diperiksa lagi di server); isian dikerjakan pada [DrafGudang] lokal. Barcode/SKU dari pemindai (atau
/// diketik) dicocokkan ke baris lewat katalog perangkat.
class LayananGudang {
  LayananGudang({required this.klien, required this.repositori, PembuatUlid? ulid}) : _ulid = ulid ?? PembuatUlid();

  static const String pesanPerluOnline =
      'Modul gudang perlu online. Isian tetap tersimpan di perangkat. Coba lagi saat terhubung internet.';

  final KlienPos klien;
  final RepositoriKasir repositori;
  final PembuatUlid _ulid;

  /// Boleh membuka pekerjaan [jenis]? Terima barang: `pembelian.kelola` atau `persediaan.kelola`; lainnya
  /// `persediaan.kelola`.
  static bool CekBoleh(StafLokal staf, JenisGudang jenis) => switch (jenis) {
    JenisGudang.Penerimaan => staf.PunyaIzin(IzinKasir.pembelianKelola) || staf.PunyaIzin(IzinKasir.persediaanKelola),
    _ => staf.PunyaIzin(IzinKasir.persediaanKelola),
  };

  Future<T> _Online<T>(Future<T> Function() kerja) async {
    try {
      return await kerja();
    } on GalatJaringan {
      throw const GalatKasir('PerluOnline', pesanPerluOnline);
    } on GalatApi catch (galat) {
      throw GalatKasir(galat.kode, galat.pesan);
    }
  }

  /// Kirim isi draf. Penolakan server (4xx) tersimpan 24 jam per kunci idempotensi: kirim ulang dengan isi yang sudah
  /// dikoreksi memakai kunci yang sama akan mentok `409 KunciIdempotensiBentrok`. Jadi setelah ditolak, draf diberi
  /// kunci baru supaya kasir bisa memperbaiki isian dan mengirim lagi. Gagal jaringan memakai kunci yang sama (kirim
  /// ulang yang sama tidak menggandakan dokumen).
  Future<T> _OnlineDraf<T>(DrafGudang draf, Future<T> Function() kerja) async {
    try {
      return await kerja();
    } on GalatJaringan {
      throw const GalatKasir('PerluOnline', pesanPerluOnline);
    } on GalatApi catch (galat) {
      final status = galat.statusHttp;
      if (status >= 400 && status < 500) {
        draf.kunciIdempotensi = 'gudang-${_ulid.Buat()}';
        await SimpanDraf(draf);
      }
      throw GalatKasir(galat.kode, galat.pesan);
    }
  }

  Future<List<PesananGudangPos>> AmbilPesanan({String kata = ''}) =>
      _Online(() => klien.AmbilPesananGudang(kata: kata));

  Future<List<TransferGudangPos>> AmbilTransfer() => _Online(klien.AmbilTransferMasuk);

  Future<List<OpnameGudangPos>> AmbilOpname() => _Online(klien.AmbilOpname);

  /// Draf tersimpan untuk dokumen ini, atau draf baru ber-kunci idempotensi baru.
  Future<DrafGudang> MuatDraf(JenisGudang jenis, String uuidDokumen) async {
    final teks = await repositori.AmbilPengaturan(DrafGudang.Kunci(jenis, uuidDokumen));
    final lama = teks == null ? null : DrafGudang.DariJson(teks);
    return lama != null && lama.jenis == jenis && lama.uuidDokumen == uuidDokumen
        ? lama
        : DrafGudang(jenis: jenis, uuidDokumen: uuidDokumen, kunciIdempotensi: 'gudang-${_ulid.Buat()}');
  }

  Future<void> SimpanDraf(DrafGudang draf) =>
      repositori.SimpanPengaturan(DrafGudang.Kunci(draf.jenis, draf.uuidDokumen), draf.KeJson());

  Future<void> HapusDraf(DrafGudang draf) => repositori.HapusPengaturan(DrafGudang.Kunci(draf.jenis, draf.uuidDokumen));

  /// Uuid produk dari barcode/SKU [kode] (katalog perangkat), atau null bila tidak dikenal.
  static String? CariProdukDariKode(String kode, KatalogLokal katalog) => katalog.CariKode(kode)?.produk.uuid;

  /// Baca teks jumlah (koma/titik desimal) menjadi [Kuantitas]; null bila kosong. [nolBoleh] untuk hitung opname.
  static Kuantitas? BacaJumlah(String? teks, {required bool bolehDesimal, bool nolBoleh = false}) {
    final rapi = (teks ?? '').trim().replaceAll(',', '.');
    if (rapi.isEmpty) {
      return null;
    }
    final nilai = RegExp(r'^\d{1,14}(\.\d{1,4})?$').hasMatch(rapi) ? Kuantitas.Dari(rapi) : null;
    if (nilai == null) {
      throw GalatKasir('JumlahTidakValid', '"$teks" bukan jumlah yang benar (maksimal 4 angka di belakang koma).');
    }
    if (!bolehDesimal && !nilai.KeDesimal().isInteger) {
      throw GalatKasir('JumlahTidakValid', 'Jumlah "$teks" harus bilangan bulat.');
    }
    if (!nolBoleh && nilai.BernilaiNol()) {
      return null;
    }
    return nilai;
  }

  /// Tambah [langkah] (bawaan 1) ke teks jumlah [teks]; hasil teks tanpa nol di belakang koma. Tidak pernah < 0.
  static String TambahJumlah(String? teks, {int langkah = 1}) {
    final lama = Kuantitas.Dari(_Rapikan(teks) ?? '0');
    final baru = lama.Tambah(Kuantitas.DariBulat(langkah));
    return (baru.BernilaiNegatif() ? Kuantitas.Nol() : baru).KeDesimal().toString();
  }

  static String? _Rapikan(String? teks) {
    final rapi = (teks ?? '').trim().replaceAll(',', '.');
    return RegExp(r'^\d{1,14}(\.\d{1,4})?$').hasMatch(rapi) ? rapi : null;
  }

  /// Posting penerimaan barang dari [pesanan] sesuai [draf] oleh [staf]. Draf dihapus setelah berhasil.
  Future<HasilPenerimaanGudang> KirimPenerimaan(PesananGudangPos pesanan, DrafGudang draf, StafLokal staf) async {
    _PastikanBoleh(staf, JenisGudang.Penerimaan);
    final baris = <Map<String, Object?>>[];
    for (final b in pesanan.baris) {
      final p = draf.pelacakan[b.urutan];
      final seri = b.pelacakan == 'Seri' ? (p?.nomorSeri ?? const <String>[]) : const <String>[];
      final Kuantitas? jumlah;
      if (b.pelacakan == 'Seri') {
        // Jumlah satuan PO = banyaknya nomor seri ÷ isi satuan (harus pas 4 desimal).
        final konversi = Decimal.tryParse(b.konversi) ?? Decimal.one;
        final skalaKonversi = (konversi * Decimal.fromInt(10000)).toBigInt();
        final pembilang = BigInt.from(seri.length) * BigInt.from(100000000);
        if (seri.isNotEmpty && (skalaKonversi <= BigInt.zero || pembilang % skalaKonversi != BigInt.zero)) {
          throw GalatKasir(
            'NomorSeriKurang',
            '${b.namaProduk}: ${seri.length} nomor seri tidak pas dengan isi ${_Format(b.konversi)} per ${b.simbolSatuan}.',
          );
        }
        final skalaJumlah = seri.isEmpty ? null : pembilang ~/ skalaKonversi;
        jumlah = skalaJumlah == null
            ? null
            : Kuantitas.Dari(
                '${skalaJumlah ~/ BigInt.from(10000)}.${(skalaJumlah % BigInt.from(10000)).toString().padLeft(4, '0')}',
              );
      } else {
        jumlah = BacaJumlah(draf.jumlah[b.urutan], bolehDesimal: true);
      }
      if (jumlah == null) {
        continue;
      }
      final q = jumlah;
      if (q.Bandingkan(Kuantitas.Dari(b.sisa)) > 0) {
        throw GalatKasir(
          'MelebihiSisa',
          '${b.namaProduk}: sisa yang belum diterima ${_Format(b.sisa)} ${b.simbolSatuan}.',
        );
      }
      if (b.pelacakan == 'Batch' && (p?.nomorBatch ?? '').trim().isEmpty) {
        throw GalatKasir('NomorBatchWajib', '${b.namaProduk}: isi nomor batch dari kemasan.');
      }
      final kedaluwarsa = p?.tanggalKedaluwarsa ?? '';
      if (b.pelacakan == 'Batch' &&
          kedaluwarsa.isNotEmpty &&
          (!RegExp(r'^\d{4}-\d{2}-\d{2}$').hasMatch(kedaluwarsa) || DateTime.tryParse(kedaluwarsa) == null)) {
        throw GalatKasir(
          'TanggalTidakValid',
          '${b.namaProduk}: tanggal kedaluwarsa ditulis TTTT-BB-HH, misal 2027-03-31.',
        );
      }
      baris.add({
        'Urutan': b.urutan,
        'Jumlah': q.KeString(),
        if (b.pelacakan == 'Batch') 'NomorBatch': p!.nomorBatch!.trim(),
        if (b.pelacakan == 'Batch' && (p?.tanggalKedaluwarsa ?? '').isNotEmpty)
          'TanggalKedaluwarsa': p!.tanggalKedaluwarsa,
        if (seri.isNotEmpty) 'NomorSeri': seri,
      });
    }
    if (baris.isEmpty) {
      throw const GalatKasir('TidakAdaJumlah', 'Isi jumlah barang yang diterima dulu.');
    }
    final surat = draf.nomorSuratJalan?.trim();
    final hasil = await _OnlineDraf(
      draf,
      () => klien.TerimaBarangGudang(
        uuidPesanan: pesanan.uuid,
        uuidPengguna: staf.uuid,
        kunciIdempotensi: draf.kunciIdempotensi,
        baris: baris,
        nomorSuratJalan: surat == null || surat.isEmpty ? null : surat,
      ),
    );
    await HapusDraf(draf);
    return hasil;
  }

  /// Terima transfer [transfer] sesuai [draf] oleh [staf]. Draf dihapus setelah berhasil.
  Future<TransferGudangPos> KirimTerimaTransfer(TransferGudangPos transfer, DrafGudang draf, StafLokal staf) async {
    _PastikanBoleh(staf, JenisGudang.Transfer);
    final baris = <Map<String, Object?>>[];
    for (final b in transfer.baris) {
      final jumlah = BacaJumlah(draf.jumlah[b.urutan], bolehDesimal: b.bolehDesimal);
      if (jumlah == null) {
        continue;
      }
      if (jumlah.Bandingkan(Kuantitas.Dari(b.sisa)) > 0) {
        throw GalatKasir(
          'MelebihiSisa',
          '${b.namaProduk}: sisa dalam perjalanan ${_Format(b.sisa)} ${b.simbolSatuan}.',
        );
      }
      baris.add({'Urutan': b.urutan, 'Jumlah': jumlah.KeString()});
    }
    if (baris.isEmpty) {
      throw const GalatKasir('TidakAdaJumlah', 'Isi jumlah barang yang diterima dulu.');
    }
    final hasil = await _OnlineDraf(
      draf,
      () => klien.TerimaTransfer(
        transfer.uuid,
        uuidPengguna: staf.uuid,
        kunciIdempotensi: draf.kunciIdempotensi,
        baris: baris,
      ),
    );
    await HapusDraf(draf);
    return hasil;
  }

  /// Simpan lembar hitung [opname] dari [draf] oleh [staf] (baris yang diisi saja; boleh 0). Draf dihapus setelah
  /// berhasil (jumlah fisik sudah tersimpan di server).
  Future<OpnameGudangPos> KirimHitung(OpnameGudangPos opname, DrafGudang draf, StafLokal staf) async {
    _PastikanBoleh(staf, JenisGudang.Opname);
    final hitung = <Map<String, Object?>>[];
    for (final b in opname.baris) {
      if (!draf.jumlah.containsKey(b.urutan)) {
        continue;
      }
      final jumlah = BacaJumlah(draf.jumlah[b.urutan], bolehDesimal: b.bolehDesimal, nolBoleh: true);
      if (jumlah == null) {
        continue;
      }
      if (b.nomorSeri != null && jumlah.Bandingkan(Kuantitas.DariBulat(1)) > 0) {
        throw GalatKasir('JumlahTidakValid', '${b.namaProduk} (${b.nomorSeri}): nomor seri hanya 0 atau 1.');
      }
      hitung.add({'Urutan': b.urutan, 'JumlahFisik': jumlah.KeString()});
    }
    for (final e in draf.produkBaru.entries) {
      final jumlah = BacaJumlah(e.value, bolehDesimal: true, nolBoleh: true);
      if (jumlah != null) {
        hitung.add({'UuidProduk': e.key, 'JumlahFisik': jumlah.KeString()});
      }
    }
    if (hitung.isEmpty) {
      throw const GalatKasir('TidakAdaJumlah', 'Belum ada hasil hitung yang diisi.');
    }
    final hasil = await _OnlineDraf(
      draf,
      () => klien.SimpanHitungOpname(
        opname.uuid,
        uuidPengguna: staf.uuid,
        kunciIdempotensi: draf.kunciIdempotensi,
        hitung: hitung,
      ),
    );
    await HapusDraf(draf);
    return hasil;
  }

  static void _PastikanBoleh(StafLokal staf, JenisGudang jenis) {
    if (!CekBoleh(staf, jenis)) {
      throw GalatKasir('TanpaIzin', '${staf.nama} tidak punya izin untuk ${jenis.judul.toLowerCase()}.');
    }
  }

  /// `24.0000` → `24`, `0.5000` → `0,5`.
  static String _Format(String jumlah) => FormatJumlah(jumlah);

  static String FormatJumlah(String jumlah) {
    final nilai = _Rapikan(jumlah);
    return nilai == null ? jumlah : Kuantitas.Dari(nilai).KeDesimal().toString().replaceAll('.', ',');
  }
}
