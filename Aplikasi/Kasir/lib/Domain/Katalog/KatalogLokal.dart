import 'dart:convert';

import 'package:klien_api/KlienApi.dart' show PajakKelompokPos;
import 'package:mesin_kasir/MesinKasir.dart';

import '../../Data/BasisData/BasisDataKasir.dart' hide BarisProdukHarga;
import '../../Data/RepositoriKatalog.dart';

/// Jenis & pelacakan produk. Induk varian dan bahan baku tidak bisa dijual di POS (Rincian F-07b langkah 5); barang
/// konsinyasi (titipan) dijual seperti produk berstok sejak F-05i (server menjurnal J-05.7);
/// produk ber-batch dijual tanpa pilihan batch (server memilih FEFO, F-05g) dan produk bernomor seri wajib membawa nomor
/// serinya (F-05h).
abstract final class JenisProdukKasir {
  static const String indukVarian = 'IndukVarian';
  static const String bahanBaku = 'BahanBaku';
  static const String konsinyasi = 'Konsinyasi';
  static const String jasa = 'Jasa';
  static const String pelacakanTidak = 'Tidak';
  static const String pelacakanSeri = 'Seri';
  static const String pelacakanBatch = 'Batch';
}

/// Satuan jual produk (baris `ProdukSatuan` + nama satuan).
class SatuanJual {
  const SatuanJual({
    required this.uuid,
    required this.nama,
    required this.konversiKeDasar,
    required this.defaultJual,
    required this.bolehDesimal,
  });

  /// Uuid `ProdukSatuan`.
  final String uuid;
  final String nama;
  final String konversiKeDasar;
  final bool defaultJual;
  final bool bolehDesimal;
}

class PilihanJual {
  const PilihanJual({required this.uuid, required this.nama, required this.harga});

  final String uuid;
  final String nama;
  final Uang harga;
}

/// Kelompok pilihan (modifier) produk. `minimal ≥ 1` = wajib; `maksimal` null = tanpa batas atas.
class KelompokPilihanJual {
  const KelompokPilihanJual({
    required this.uuid,
    required this.nama,
    required this.minimal,
    required this.maksimal,
    required this.pilihan,
  });

  final String uuid;
  final String nama;
  final int minimal;
  final int? maksimal;
  final List<PilihanJual> pilihan;

  bool CekWajib() => minimal > 0;

  bool CekSatuSaja() => maksimal == 1;
}

/// Jenis pajak kelompok pajak produk: kode jenis (`Ppn`, `PbjtMakananMinuman`, ...), dasar pengenaan, dan kategori
/// jenis pajak (`Ppn`/`Pbjt`/`Lainnya`; null = server lama, lihat [AmbilKategori]).
class PajakProduk {
  const PajakProduk({required this.kode, required this.dasarPengenaan, this.kategori, this.kenaBiayaKirim = false});

  final String kode;
  final String dasarPengenaan;
  final String? kategori;

  /// F-17 bagian 3: ongkir yang ditagih ke pembeli ikut DPP pajak ini.
  final bool kenaBiayaKirim;

  /// Kategori untuk syarat PKP/PBJT (PRD v1.46): dari atribut `JenisPajak` bila ada; bila absen, fallback ke kode lama
  /// (`Ppn` → `Ppn`, `PbjtMakananMinuman` → `Pbjt`, lainnya → `Lainnya`).
  String AmbilKategori() =>
      kategori ??
      switch (kode) {
        'Ppn' => PajakKelompokPos.kategoriPpn,
        'PbjtMakananMinuman' => PajakKelompokPos.kategoriPbjt,
        _ => PajakKelompokPos.kategoriLainnya,
      };

  Map<String, Object?> KeJson() => {
    'Kode': kode,
    'DasarPengenaan': dasarPengenaan,
    'Kategori': kategori,
    'KenaBiayaKirim': kenaBiayaKirim,
  };

  static PajakProduk DariJson(Map<String, Object?> json) => PajakProduk(
    kode: json['Kode'] is String ? json['Kode']! as String : '',
    dasarPengenaan: json['DasarPengenaan'] is String ? json['DasarPengenaan']! as String : 'Subtotal',
    kategori: json['Kategori'] is String ? json['Kategori']! as String : null,
    kenaBiayaKirim: json['KenaBiayaKirim'] == true,
  );
}

class ProdukJual {
  const ProdukJual({
    required this.uuid,
    required this.sku,
    required this.nama,
    required this.jenis,
    required this.uuidKategori,
    required this.pelacakan,
    required this.hargaTermasukPajak,
    required this.urlGambarKecil,
    required this.tampil,
    required this.satuan,
    required this.kelompokPilihan,
    required this.pajak,
    this.jumlahSesiPaket,
    this.masaGaransiBulan,
    this.hargaTerbuka = false,
    this.favorit = false,
    this.golonganObat,
    this.obatWajibApotek = false,
    this.prekursor = false,
    this.wajibResep = false,
    this.aktif = true,
    this.satuanDasar,
    this.uuidInduk,
    this.atributVarian = const {},
    this.definisiVarian = const [],
  });

  final String uuid;
  final String? sku;
  final String nama;
  final String jenis;

  /// K-9: induk bila produk ini anak varian.
  final String? uuidInduk;

  /// K-9 (anak varian): kombinasi atribut, misal `{Ukuran: M, Warna: Hitam}`.
  final Map<String, String> atributVarian;

  /// K-9 (induk varian): urutan atribut & nilainya untuk pemilih varian, misal Ukuran [S, M, L].
  final List<({String nama, List<String> nilai})> definisiVarian;

  bool get indukVarian => jenis == JenisProdukKasir.indukVarian;

  /// Urai JSON `Produk.AtributVarian` lokal (bentuk server `PenyusunAnakVarian`): induk = daftar `{Nama, Nilai: [..]}`
  /// (definisi), anak = daftar `{Nama, Nilai: "M"}` (kombinasi; bentuk peta `{Ukuran: M}` juga diterima). Bentuk lain
  /// diabaikan.
  static ({Map<String, String> atribut, List<({String nama, List<String> nilai})> definisi}) UraiAtributVarian(
    String? json,
  ) {
    final kosong = (atribut: const <String, String>{}, definisi: const <({String nama, List<String> nilai})>[]);
    if (json == null || json.isEmpty) {
      return kosong;
    }
    final Object? isi;
    try {
      isi = jsonDecode(json);
    } on FormatException {
      return kosong;
    }
    if (isi is Map) {
      return (
        atribut: {
          for (final e in isi.entries)
            if (e.value != null) '${e.key}': '${e.value}',
        },
        definisi: const [],
      );
    }
    if (isi is! List) {
      return kosong;
    }
    final atribut = <String, String>{};
    final definisi = <({String nama, List<String> nilai})>[];
    for (final a in isi.whereType<Map<Object?, Object?>>()) {
      final nama = a['Nama'];
      final nilai = a['Nilai'];
      if (nama is! String) {
        continue;
      }
      if (nilai is List) {
        definisi.add((nama: nama, nilai: [for (final n in nilai) '$n']));
      } else if (nilai != null) {
        atribut[nama] = '$nilai';
      }
    }
    return (atribut: atribut, definisi: definisi);
  }

  /// F-16d bagian 2: jumlah sesi bila produk paket sesi (wajib pelanggan, jumlah bulat, tidak bisa diretur).
  final int? jumlahSesiPaket;

  /// F-05h: masa garansi standar (bulan) produk bernomor seri; di-snapshot ke baris penjualan untuk struk.
  final int? masaGaransiBulan;

  /// K-25: harga diketik kasir saat produk ditambahkan (harga daftar, bila ada, jadi saran).
  final bool hargaTerbuka;

  /// K-16 (v5.68): favorit pilihan pemilik (kategori Favorit di layar jual).
  final bool favorit;

  /// Apotek (§9.5): golongan obat (`Bebas`/`BebasTerbatas`/`Keras`/`Psikotropika`/`Narkotika`; null = bukan obat),
  /// Obat Wajib Apotek, prekursor, dan wajib resep dari server. Aturan penyerahannya di `AturanApotek`.
  final String? golonganObat;
  final bool obatWajibApotek;
  final bool prekursor;
  final bool wajibResep;

  bool get paketSesi => jumlahSesiPaket != null;
  final String? uuidKategori;
  final String pelacakan;

  /// Null = ikut pengaturan pajak outlet.
  final bool? hargaTermasukPajak;
  final String? urlGambarKecil;

  /// Aktif & tampil di POS.
  final bool tampil;

  /// Aktif di katalog (termasuk yang tidak tampil di POS, mis. bahan baku; dipakai pencatatan bahan terbuang).
  final bool aktif;

  /// Satuan dasar produk (konversi 1; Uuid = Uuid `Satuan`, bukan `ProdukSatuan`). Null bila katalog tidak memuatnya.
  final SatuanJual? satuanDasar;
  final List<SatuanJual> satuan;
  final List<KelompokPilihanJual> kelompokPilihan;
  final List<PajakProduk> pajak;

  SatuanJual? AmbilSatuanBawaan() {
    for (final s in satuan) {
      if (s.defaultJual) {
        return s;
      }
    }
    return satuan.isEmpty ? null : satuan.first;
  }

  /// F-05h: produk ini dijual dengan nomor seri/IMEI per unit (kasir mengetik atau memindai).
  bool get bernomorSeri => pelacakan == JenisProdukKasir.pelacakanSeri;
  bool get berBatch => pelacakan == JenisProdukKasir.pelacakanBatch;

  /// Alasan produk tidak bisa dijual di POS fase 1 (null = bisa dijual) beserta kode galat server padanannya.
  ({String kode, String pesan})? AmbilAlasanTidakBisaDijual() {
    return switch (jenis) {
      JenisProdukKasir.indukVarian => (
        kode: 'ProdukTidakBisaDijual',
        pesan: '"$nama" adalah induk varian. Pilih salah satu variannya.',
      ),
      JenisProdukKasir.bahanBaku => (
        kode: 'ProdukTidakBisaDijual',
        pesan: '"$nama" adalah bahan baku dan tidak dijual ke pelanggan.',
      ),
      _ => null,
    };
  }
}

/// Hasil cari kode (barcode/SKU): produk beserta satuan barcode bila barcode terikat satuan tertentu.
typedef HasilCariKode = ({ProdukJual produk, SatuanJual? satuan});

/// Katalog lokal di memori untuk layar Jual: produk siap jual, kategori, barcode, kelompok pajak, dan data harga
/// untuk `PenentuHarga`. Dibangun ulang setiap katalog diperbarui.
class KatalogLokal {
  KatalogLokal._({
    required this.produk,
    required this.kategori,
    required this._petaProduk,
    required this._barcode,
    required this.daftarHarga,
    required this._hargaPerProduk,
  }) : _varianPerInduk = {
         for (final p in produk)
           if (p.uuidInduk != null && p.tampil) p.uuidInduk!: [],
       } {
    for (final p in produk) {
      if (p.uuidInduk != null && p.tampil) {
        _varianPerInduk[p.uuidInduk]!.add(p);
      }
    }
  }

  static final KatalogLokal kosong = KatalogLokal._(
    produk: const [],
    kategori: const [],
    petaProduk: const {},
    barcode: const {},
    daftarHarga: const [],
    hargaPerProduk: const {},
  );

  /// Semua produk (urut nama), termasuk yang tidak tampil (untuk pesanan tertahan & barcode).
  final List<ProdukJual> produk;
  final List<BarisKategori> kategori;
  final List<DaftarHargaResolusi> daftarHarga;

  /// X8: kanal yang punya daftar harga aktif (misal harga GoFood), untuk menawarkan pilihan kanal di keranjang.
  Set<KanalPenjualan> AmbilKanalBerharga() => {
    for (final d in daftarHarga)
      if (d.aktif && d.kanal != null) d.kanal!,
  };
  final Map<String, ProdukJual> _petaProduk;
  final Map<String, ({String uuidProduk, String? uuidProdukSatuan})> _barcode;
  final Map<String, List<BarisProdukHarga>> _hargaPerProduk;
  final Map<String, List<ProdukJual>> _varianPerInduk;

  bool get CekKosong => produk.isEmpty;

  /// K-9: anak varian induk ini yang aktif & tampil di POS (urut nama).
  List<ProdukJual> AmbilVarian(String uuidInduk) => _varianPerInduk[uuidInduk] ?? const [];

  ProdukJual? CariProduk(String uuid) => _petaProduk[uuid];

  /// Data harga satu produk untuk `PenentuHarga` (baris harga produk itu saja, agar cepat untuk katalog besar).
  KatalogHarga AmbilKatalogHarga(String uuidProduk) =>
      KatalogHarga(daftarHarga: daftarHarga, harga: _hargaPerProduk[uuidProduk] ?? const []);

  /// Produk tampil di grid: aktif & tampil di POS, disaring kategori (termasuk subkategori) dan kata kunci
  /// (nama/SKU/barcode).
  List<ProdukJual> AmbilTampil({String? uuidKategori, String kata = ''}) {
    final kunci = kata.trim().toLowerCase();
    final subKategori = uuidKategori == null
        ? const <String>{}
        : {uuidKategori, ...kategori.where((k) => k.UuidInduk == uuidKategori).map((k) => k.Uuid)};
    final uuidBarcode = kunci.isEmpty ? null : _barcode[kata.trim()]?.uuidProduk;
    return produk.where((p) {
      if (!p.tampil) {
        return false;
      }
      if (uuidKategori != null && !subKategori.contains(p.uuidKategori)) {
        return false;
      }
      if (kunci.isEmpty) {
        // K-9: anak varian dipilih lewat induknya; tanpa kata cari hanya induknya yang tampil.
        return p.uuidInduk == null || !(_petaProduk[p.uuidInduk]?.tampil ?? false);
      }
      return p.nama.toLowerCase().contains(kunci) ||
          (p.sku?.toLowerCase().contains(kunci) ?? false) ||
          p.uuid == uuidBarcode;
    }).toList();
  }

  /// K-16: produk favorit pilihan pemilik yang tampil di kasir (anak varian lewat induknya), urutan katalog.
  List<ProdukJual> AmbilFavorit({String kata = ''}) => AmbilTampil(kata: kata).where((p) => p.favorit).toList();

  /// K-16: ada favorit yang bisa ditampilkan (menentukan chip Favorit di bar kategori). Dihitung sekali per katalog.
  late final bool adaFavorit = AmbilFavorit().isNotEmpty;

  /// Cari persis berdasarkan barcode lalu SKU (tanpa beda huruf besar/kecil). Dipakai pemindai & Enter di kolom cari.
  HasilCariKode? CariKode(String kode) {
    final rapi = kode.trim();
    if (rapi.isEmpty) {
      return null;
    }
    final kodeBarcode = _barcode[rapi];
    if (kodeBarcode != null) {
      final p = _petaProduk[kodeBarcode.uuidProduk];
      if (p != null) {
        final satuan = p.satuan.where((s) => s.uuid == kodeBarcode.uuidProdukSatuan).firstOrNull;
        return (produk: p, satuan: satuan);
      }
    }
    final kecil = rapi.toLowerCase();
    for (final p in produk) {
      if (p.sku != null && p.sku!.toLowerCase() == kecil) {
        return (produk: p, satuan: null);
      }
    }
    return null;
  }

  static KatalogLokal Bangun(IsiKatalogLokal isi) {
    final satuan = {for (final s in isi.satuan) s.Uuid: s};
    final satuanProduk = <String, List<SatuanJual>>{};
    for (final ps in isi.produkSatuan) {
      final s = satuan[ps.UuidSatuan];
      satuanProduk
          .putIfAbsent(ps.UuidProduk, () => [])
          .add(
            SatuanJual(
              uuid: ps.Uuid,
              nama: s?.Nama ?? '',
              konversiKeDasar: ps.KonversiKeDasar,
              defaultJual: ps.DefaultJual,
              bolehDesimal: s?.BolehDesimal ?? false,
            ),
          );
    }

    final pilihanKelompok = <String, List<PilihanJual>>{};
    for (final p in isi.pilihan.where((p) => p.Aktif)) {
      pilihanKelompok
          .putIfAbsent(p.UuidKelompokPilihan, () => [])
          .add(PilihanJual(uuid: p.Uuid, nama: p.Nama, harga: Uang.Dari(p.Harga)));
    }
    final kelompok = {
      for (final k in isi.kelompokPilihan)
        k.Uuid: KelompokPilihanJual(
          uuid: k.Uuid,
          nama: k.Nama,
          minimal: k.MinimalPilih,
          maksimal: k.MaksimalPilih,
          pilihan: pilihanKelompok[k.Uuid] ?? const [],
        ),
    };
    final kelompokProduk = <String, List<KelompokPilihanJual>>{};
    for (final t in isi.produkKelompokPilihan) {
      final k = kelompok[t.UuidKelompokPilihan];
      if (k != null && k.pilihan.isNotEmpty) {
        kelompokProduk.putIfAbsent(t.UuidProduk, () => []).add(k);
      }
    }

    final pajakKelompok = <String, List<PajakProduk>>{};
    for (final d in isi.kelompokPajakDetail) {
      pajakKelompok
          .putIfAbsent(d.UuidKelompokPajak, () => [])
          .add(
            PajakProduk(
              kode: d.KodeJenisPajak,
              dasarPengenaan: d.DasarPengenaan,
              kategori: d.Kategori,
              kenaBiayaKirim: d.KenaBiayaKirim,
            ),
          );
    }

    final produk = [
      for (final p in isi.produk)
        ProdukJual(
          uuid: p.Uuid,
          sku: p.Sku,
          nama: p.Nama,
          jenis: p.Jenis,
          uuidKategori: p.UuidKategori,
          pelacakan: p.Pelacakan,
          hargaTermasukPajak: p.HargaTermasukPajak,
          urlGambarKecil: p.UrlGambarKecil,
          tampil: p.Aktif && p.TampilDiPos,
          satuan: satuanProduk[p.Uuid] ?? const [],
          kelompokPilihan: kelompokProduk[p.Uuid] ?? const [],
          pajak: p.UuidKelompokPajak == null ? const [] : pajakKelompok[p.UuidKelompokPajak] ?? const [],
          jumlahSesiPaket: p.JumlahSesiPaket,
          masaGaransiBulan: p.MasaGaransiBulan,
          hargaTerbuka: p.HargaTerbuka,
          favorit: p.Favorit,
          golonganObat: p.GolonganObat,
          obatWajibApotek: p.ObatWajibApotek,
          prekursor: p.Prekursor,
          wajibResep: p.WajibResep,
          aktif: p.Aktif,
          uuidInduk: p.UuidInduk,
          atributVarian: ProdukJual.UraiAtributVarian(p.AtributVarian).atribut,
          definisiVarian: ProdukJual.UraiAtributVarian(p.AtributVarian).definisi,
          satuanDasar: switch (satuan[p.UuidSatuanDasar]) {
            final s? => SatuanJual(
              uuid: s.Uuid,
              nama: s.Nama,
              konversiKeDasar: '1',
              defaultJual: false,
              bolehDesimal: s.BolehDesimal,
            ),
            null => null,
          },
        ),
    ];

    final hargaPerProduk = <String, List<BarisProdukHarga>>{};
    for (final h in isi.produkHarga) {
      hargaPerProduk
          .putIfAbsent(h.UuidProduk, () => [])
          .add(
            BarisProdukHarga(
              uuidProduk: h.UuidProduk,
              uuidProdukSatuan: h.UuidProdukSatuan,
              uuidDaftarHarga: h.UuidDaftarHarga,
              jumlahMinimum: Kuantitas.Dari(h.JumlahMinimum),
              harga: Uang.Dari(h.Harga),
            ),
          );
    }

    return KatalogLokal._(
      produk: produk,
      kategori: isi.kategori.where((k) => k.UuidInduk == null).toList(),
      petaProduk: {for (final p in produk) p.uuid: p},
      barcode: {
        for (final b in isi.produkBarcode) b.Barcode: (uuidProduk: b.UuidProduk, uuidProdukSatuan: b.UuidProdukSatuan),
      },
      daftarHarga: [for (final d in isi.daftarHarga) _KeResolusi(d)],
      hargaPerProduk: hargaPerProduk,
    );
  }

  static DaftarHargaResolusi _KeResolusi(BarisDaftarHarga d) {
    final outlet = d.UuidOutlet == null ? null : jsonDecode(d.UuidOutlet!);
    final kanal = KanalPenjualan.values.where((k) => k.name == d.Kanal).firstOrNull;
    return DaftarHargaResolusi(
      uuid: d.Uuid,
      // Kanal yang belum dikenal aplikasi versi ini tidak boleh dianggap "semua kanal".
      aktif: d.Aktif && (d.Kanal == null || kanal != null),
      uuidOutlet: outlet is List<Object?> ? outlet.whereType<String>().toList() : null,
      kanal: kanal,
      tierPelanggan: d.TierPelanggan,
      mulaiPada: d.MulaiPada?.toUtc(),
      selesaiPada: d.SelesaiPada?.toUtc(),
      prioritas: d.Prioritas,
    );
  }
}
