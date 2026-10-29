import 'dart:convert';

import 'package:drift/drift.dart' show Value;
import 'package:klien_api/KlienApi.dart';
import 'package:mesin_kasir/MesinKasir.dart';

import '../../Data/BasisData/BasisDataKasir.dart';
import '../../Data/RepositoriKasir.dart';
import '../../Data/RepositoriPelanggan.dart';
import '../../Data/RepositoriPenjualan.dart';
import '../GalatKasir.dart';
import '../Katalog/KatalogLokal.dart';
import '../Katalog/LayananStokTersedia.dart';
import '../Sesi/StafLokal.dart';
import 'AturanApotek.dart';
import 'BagianTagihan.dart';
import 'Keranjang.dart';
import 'KonteksPenjualan.dart';
import 'Racikan.dart';

/// Label jenis pajak untuk kasir menurut kategorinya (`Ppn`/`Pbjt`, PRD v1.46); kategori lain memakai kodenya.
abstract final class KodePajak {
  static String AmbilLabel(PajakProduk pajak) => switch (pajak.AmbilKategori()) {
    PajakKelompokPos.kategoriPpn => 'PPN',
    PajakKelompokPos.kategoriPbjt => 'PBJT',
    _ => pajak.kode,
  };
}

/// Hasil hitung keranjang: keluaran `MesinKalkulasi` beserta pajak dokumen yang dipakai (snapshot outbox) dan
/// peringatan pajak (tarif belum tersedia). F-16c: promo otomatis yang diterapkan dan hasil tanpa promo (dasar batas
/// diskon manual, sama dengan server).
class HitunganKeranjang {
  const HitunganKeranjang({
    required this.hasil,
    this.hasilDasar,
    this.promoTerpakai = const [],
    this.namaPromo = const {},
    required this.pajakDokumen,
    required this.tarifDipakai,
    required this.kodePajakBaris,
    required this.peringatan,
    required this.tanggalBisnis,
    this.labelPajak = const {},
    this.poinBerlipat,
  });

  HasilKalkulasi get hasilTanpaPromo => hasilDasar ?? hasil;

  String AmbilNamaPromo(PromoTerpakai p) => namaPromo[p.uuid] ?? p.kode;

  /// Σ potongan promo pesanan (bagian `hasil.diskonPesanan`).
  Uang HitungDiskonPromoPesanan() => promoTerpakai.fold(Uang.Nol(), (t, p) => t.Tambah(p.diskonPesanan));

  final HasilKalkulasi hasil;

  /// Hasil tanpa promo; null = sama dengan [hasil].
  final HasilKalkulasi? hasilDasar;
  final List<PromoTerpakai> promoTerpakai;

  /// Nama promo per Uuid untuk tampilan.
  final Map<String, String> namaPromo;
  final List<DataPajakKalkulasi> pajakDokumen;
  final Map<String, TarifPajakLokal> tarifDipakai;
  final List<List<String>> kodePajakBaris;
  final List<String> peringatan;

  /// `YYYY-MM-DD`.
  final String tanggalBisnis;

  /// Label tampilan per kode pajak dokumen (`PPN`/`PBJT` menurut kategori jenis pajak).
  final Map<String, String> labelPajak;

  /// F-16c bagian 4a: promo poin berlipat yang berlaku (hanya bila pelanggan dipilih); poin dihitung server.
  final DefinisiPromo? poinBerlipat;

  /// `2×` / `1,5×` untuk tampilan.
  String? AmbilLabelPoinBerlipat() {
    final p = poinBerlipat;
    return p == null ? null : 'Poin ${p.pengali.toString().replaceAll('.', ',')}× | ${namaPromo[p.uuid] ?? p.kode}';
  }
}

/// Satu pembayaran yang dimasukkan kasir. Tunai: [jumlah] = uang diterima.
class PembayaranMasukan {
  const PembayaranMasukan({required this.metode, required this.jumlah, this.referensi, this.tamu});

  final BarisMetodePembayaran metode;
  final Uang jumlah;
  final String? referensi;

  /// K-14: bagian tamu bila pembayaran ini bagian dari tagihan yang dibagi (untuk struk per tamu).
  final BagianTamu? tamu;

  bool CekTunai() => metode.Jenis == JenisMetodeBayar.tunai;

  DataPembayaranKalkulasi KeKalkulasi() =>
      DataPembayaranKalkulasi(metode: CekTunai() ? DataPembayaranKalkulasi.metodeTunai : metode.Jenis, jumlah: jumlah);
}

class PenjualanTersimpan {
  const PenjualanTersimpan({
    required this.uuid,
    required this.nomor,
    required this.totalAkhir,
    required this.totalDibayar,
    required this.kembalian,
    required this.pembayaran,
    this.namaPelanggan,
    this.labelPoin,
    this.nomorAntrian,
    this.namaPemesan,
    this.nomorReturTukar,
    this.kembalianTukar,
    this.bagianTagihan,
    this.latihan = false,
  });

  final String uuid;
  final String nomor;

  /// K-23 (POS-18): transaksi mode latihan — tidak disimpan, tidak masuk outbox, tidak dicetak.
  final bool latihan;

  /// K-11: retur tukar barang yang dibuat bersama penjualan ini, dan selisih tunai yang dikembalikan ke pelanggan
  /// (barang pengganti lebih murah; null bila bukan tukar barang).
  final String? nomorReturTukar;
  final Uang? kembalianTukar;

  /// K-14: rincian bagian tiap tamu bila tagihan dibagi (null = tidak dibagi atau hanya satu tamu).
  final BagianTagihan? bagianTagihan;

  /// v3.52: nomor panggil (null = tidak bernomor antrian) & nama pemesan, ditampilkan besar setelah bayar.
  final String? nomorAntrian;
  final String? namaPemesan;
  final Uang totalAkhir;
  final Uang totalDibayar;
  final Uang kembalian;
  final List<PembayaranMasukan> pembayaran;

  /// Untuk struk yang dicetak langsung setelah bayar (nama pelanggan tidak disimpan di tabel penjualan lokal).
  final String? namaPelanggan;

  /// F-16c bagian 4a: "Poin 2× | nama promo" bila promo poin berlipat berlaku (dicetak di struk setelah bayar).
  final String? labelPoin;
}

/// Keputusan diskon manual terhadap batas BR-07.3.
enum StatusDiskon { Boleh, ButuhPenyetuju, MelebihiBatas }

/// Keranjang, harga, pajak, diskon, dan simpan penjualan di perangkat (F-07 mode retail, Rincian F-07c). Aturan sama
/// dengan server (Rincian F-07b) agar kasir langsung tahu bila ditolak:
/// - produk `IndukVarian`/`BahanBaku` tidak bisa dijual (konsinyasi bisa sejak F-05i);
/// - harga satuan dari `PenentuHarga` (outlet, kanal `BawaPulang`), total dari `MesinKalkulasi` (paket MesinKasir);
/// - pajak per produk dari kelompok pajaknya: PPN hanya bila PKP, PBJT makanan & minuman hanya bila memungut PBJT, tarif
///   dari `TarifPajak` yang berlaku pada tanggal bisnis (tanpa tarif → tidak dihitung + peringatan, CLAUDE.md #12);
/// - BR-07.3 diskon manual sampai `BatasDiskonManual` oleh kasir ber-izin `penjualan.diskon.manual`; kasir tanpa izin
///   itu atau di atas batas butuh penyetuju ber-izin `penjualan.diskon.setujui` sampai `BatasDiskonPenyetuju`;
///   Pemilik tanpa batas. Persen = diskon hasil mesin ÷ bruto baris (pesanan: ÷ subtotal);
/// - BR-05.2: bila [stokTersedia] ada, menambah jumlah produk berstok melebihi sisa stok Toko ditolak
///   (`StokTidakCukup`, lihat [PastikanStokCukup]);
/// - BR-07.4 butuh shift terbuka; BR-07.1 nomor `INV/{KodeOutlet}/{YYMMDD}/{KodePerangkat}-{SEQ4}`;
/// - penjualan + detail + pembayaran + outbox `Penjualan.Buat` dalam satu transaksi SQLite (PRD §18.3 no. 3).
class LayananPenjualan {
  LayananPenjualan({
    required this.repositori,
    required this.repositoriPenjualan,
    this.repositoriPelanggan,
    this.stokTersedia,
    PembuatUlid? ulid,
    DateTime Function()? jam,
  }) : _ulid = ulid ?? PembuatUlid(),
       _jam = jam ?? DateTime.now;

  static const String jenisOutbox = 'Penjualan.Buat';

  /// X8: kanal platform (dibayar lewat pencairan platform, metode `Marketplace` berkanal sama).
  static const List<KanalPenjualan> kanalPlatform = [
    KanalPenjualan.GoFood,
    KanalPenjualan.GrabFood,
    KanalPenjualan.ShopeeFood,
    KanalPenjualan.Marketplace,
  ];
  static const String statusLunas = 'Lunas';

  final RepositoriKasir repositori;
  final RepositoriPenjualan repositoriPenjualan;

  /// F-12: cache posisi kredit pelanggan (sisa piutang bertambah setelah penjualan tempo); null = tidak diperbarui.
  final RepositoriPelanggan? repositoriPelanggan;

  /// BR-05.2: salinan sisa stok Toko untuk pencegah di keranjang; null = tanpa pencegah (stok tidak dibatasi).
  final LayananStokTersedia? stokTersedia;
  final PembuatUlid _ulid;
  final DateTime Function() _jam;
  final MesinKalkulasi _mesin = const MesinKalkulasi();

  String BuatUuid() => _ulid.Buat();

  // Harga & keranjang --------------------------------------------------------------------------------------------------

  /// F-16b: harga ulang semua baris keranjang menurut kanal & tier pelanggan saat ini (dipanggil setelah pelanggan
  /// dipilih/dilepas). Tanpa harga yang cocok = harga lama.
  Keranjang HitungUlangHarga(Keranjang keranjang, KatalogLokal katalog, KonteksPenjualan k) => keranjang.Salin(
    baris: [
      for (final b in keranjang.baris)
        b.uuidProdukSatuan == null || b.CekHargaDikunci
            ? b
            : b.Salin(
                hargaSatuan:
                    TentukanHarga(
                      katalog,
                      k,
                      b.uuidProduk,
                      b.uuidProdukSatuan!,
                      b.jumlah,
                      kanal: AmbilKanal(keranjang),
                      tierPelanggan: keranjang.pelanggan?.kodeTier,
                    ) ??
                    b.hargaSatuan,
              ),
    ],
  );

  /// Kanal harga keranjang: pesanan di meja = `MakanDiTempat` (daftar harga dine-in); selain itu kanal pilihan kasir
  /// (X8: GoFood, GrabFood, …) atau `BawaPulang`.
  static KanalPenjualan AmbilKanal(Keranjang keranjang) => keranjang.pesananMeja?.uuidMeja != null
      ? KanalPenjualan.MakanDiTempat
      : keranjang.kanal ?? KanalPenjualan.BawaPulang;

  static String AmbilLabelKanal(KanalPenjualan kanal) => switch (kanal) {
    KanalPenjualan.MakanDiTempat => 'Makan di tempat',
    KanalPenjualan.BawaPulang => 'Bawa pulang',
    KanalPenjualan.Antar => 'Antar',
    KanalPenjualan.Online => 'Online',
    KanalPenjualan.PesanSendiri => 'Pesan sendiri',
    KanalPenjualan.Marketplace => 'Marketplace',
    KanalPenjualan.GoFood => 'GoFood',
    KanalPenjualan.GrabFood => 'GrabFood',
    KanalPenjualan.ShopeeFood => 'ShopeeFood',
  };

  /// X8: pilihan kanal keranjang. Bawa pulang, makan di tempat, dan antar selalu ada; kanal platform ditawarkan bila
  /// punya metode pembayaran platform atau daftar harga aktif. Kosong = tidak ada kanal platform/harga berkanal, jadi
  /// pilihan kanal tidak perlu ditampilkan (toko biasa tetap sederhana).
  static List<KanalPenjualan> AmbilPilihanKanal(KonteksPenjualan k, KatalogLokal katalog) {
    final berharga = katalog.AmbilKanalBerharga();
    final platform = [
      for (final kanal in kanalPlatform)
        if (berharga.contains(kanal) ||
            k.metodePembayaran.any((m) => m.Jenis == JenisMetodeBayar.marketplace && m.Kanal == kanal.name))
          kanal,
    ];
    const kanalToko = [KanalPenjualan.BawaPulang, KanalPenjualan.MakanDiTempat, KanalPenjualan.Antar];
    // v3.29: toko yang punya promo gratis ongkir mengantar sendiri, jadi kanal Antar (dengan ongkir) ikut ditawarkan.
    final adaGratisOngkir = k.promo.any((p) => p.aksi == JenisAksiPromo.GratisOngkir);
    // v3.51: jenis pesanan outlet (FnB) yang diatur pemilik selalu ditawarkan, walau tanpa harga berkanal/ojol.
    if (k.jenisPesanan.isNotEmpty) {
      return [
        ...k.jenisPesanan,
        if (!k.jenisPesanan.contains(KanalPenjualan.Antar) &&
            (adaGratisOngkir || berharga.contains(KanalPenjualan.Antar)))
          KanalPenjualan.Antar,
        ...platform,
      ];
    }
    if (platform.isEmpty && !adaGratisOngkir && !berharga.any(kanalToko.contains)) {
      return const [];
    }
    // D-183: "Makan di tempat" milik F&B (jenis pesanan outlet); toko lain hanya menawarkannya bila punya harga berkanal itu.
    return [
      ...kanalToko.where((kanal) => kanal != KanalPenjualan.MakanDiTempat || berharga.contains(kanal)),
      ...platform,
    ];
  }

  /// X8: ganti kanal keranjang lalu harga ulang semua baris menurut daftar harga kanal baru. Baris tanpa harga di kanal
  /// baru memakai harga dasar (sama seperti `HitungUlangHarga`). Ongkir hanya milik kanal Antar (v3.29): keluar dari
  /// kanal Antar menghapus ongkir yang diisi kasir.
  Keranjang GantiKanal(Keranjang keranjang, KanalPenjualan kanal, KatalogLokal katalog, KonteksPenjualan k) =>
      HitungUlangHarga(
        keranjang.Salin(
          kanal: () => kanal == KanalPenjualan.BawaPulang ? null : kanal,
          biayaKirim: kanal == KanalPenjualan.Antar ? keranjang.biayaKirim : Uang.Nol(),
          diskonKirim: kanal == KanalPenjualan.Antar ? keranjang.diskonKirim : Uang.Nol(),
        ),
        katalog,
        k,
      );

  /// Batas wajar ongkir yang diisi kasir (salah ketik nol berlebih tertangkap sebelum masuk dokumen).
  static final Uang ongkirMaksimal = Uang.DariBulat(5000000);

  /// v3.29 (F-17 bagian 3, F-16c gratis ongkir): ongkir penjualan kasir yang diantar toko sendiri (kanal Antar, bukan
  /// pesanan online). Yang diisi kasir hanya ongkir **kotor**; potongannya (`DiskonKirim`) selalu dari promo gratis
  /// ongkir yang dihitung mesin promo, bukan isian manual, supaya biaya promo tercatat dan diperiksa ulang server.
  Keranjang AturOngkir(Keranjang keranjang, Uang ongkir) {
    if (LayananPenjualan.AmbilKanal(keranjang) != KanalPenjualan.Antar || keranjang.praPesan != null) {
      throw const GalatKasir(
        'OngkirHanyaAntar',
        'Ongkir hanya untuk penjualan yang diantar toko. Pilih kanal Antar dulu.',
      );
    }
    if (ongkir.Bandingkan(Uang.Nol()) < 0 || ongkir.Bandingkan(ongkirMaksimal) > 0) {
      throw GalatKasir('OngkirTidakWajar', 'Ongkir 0 sampai ${ongkirMaksimal.FormatRupiah()}.');
    }
    if (ongkir.KeDesimal() != ongkir.KeDesimal().truncate()) {
      throw const GalatKasir('OngkirTidakWajar', 'Ongkir dalam rupiah bulat, tanpa sen.');
    }
    return keranjang.Salin(biayaKirim: ongkir, diskonKirim: Uang.Nol());
  }

  /// X8: metode yang boleh dipakai untuk [kanal]: metode platform hanya untuk kanal miliknya.
  static List<BarisMetodePembayaran> SaringMetodeKanal(List<BarisMetodePembayaran> metode, KanalPenjualan kanal) => [
    for (final m in metode)
      if (m.Jenis != JenisMetodeBayar.marketplace || m.Kanal == kanal.name) m,
  ];

  Uang? TentukanHarga(
    KatalogLokal katalog,
    KonteksPenjualan k,
    String uuidProduk,
    String uuidSatuan,
    Kuantitas jumlah, {
    KanalPenjualan kanal = KanalPenjualan.BawaPulang,
    String? tierPelanggan,
  }) => const PenentuHarga()
      .Tentukan(
        katalog.AmbilKatalogHarga(uuidProduk),
        PermintaanHarga(
          uuidProduk: uuidProduk,
          uuidProdukSatuan: uuidSatuan,
          jumlah: jumlah,
          uuidOutlet: k.uuidOutlet,
          kanal: kanal,
          tierPelanggan: tierPelanggan,
          waktu: _jam().toUtc(),
        ),
      )
      ?.harga;

  /// Buat baris baru untuk [produk]. Menolak produk yang belum bisa dijual, pilihan yang tidak lengkap, dan produk
  /// tanpa harga.
  ItemKeranjang BuatBaris(
    KatalogLokal katalog,
    KonteksPenjualan k,
    ProdukJual produk, {
    SatuanJual? satuan,
    List<PilihanTerpilih> pilihan = const [],
    Kuantitas? jumlah,
    String? catatan,
    KanalPenjualan kanal = KanalPenjualan.BawaPulang,
    String? tierPelanggan,
    List<String> nomorSeri = const [],
    Uang? hargaManual,
    Uang? hargaDokumen,
  }) {
    final alasan = produk.AmbilAlasanTidakBisaDijual();
    if (alasan != null) {
      throw GalatKasir(alasan.kode, alasan.pesan);
    }
    final satuanJual = satuan ?? produk.AmbilSatuanBawaan();
    if (satuanJual == null) {
      throw GalatKasir('SatuanTidakAda', '"${produk.nama}" belum punya satuan jual. Atur di back-office menu Produk.');
    }
    ValidasiPilihan(produk, pilihan);
    // F-05h: produk bernomor seri = satu unit per nomor; jumlahnya ditentukan banyaknya nomor.
    final qty = produk.bernomorSeri && nomorSeri.isNotEmpty
        ? Kuantitas.DariBulat(nomorSeri.length)
        : jumlah ?? Kuantitas.DariBulat(1);
    if (hargaManual != null) {
      ValidasiHargaTerbuka(produk, hargaManual);
    }
    // K-25: harga terbuka = harga ketikan kasir; tanpa ketikan (panel pilihan/nomor seri) harga daftar jadi bawaan.
    // Bengkel bagian 2: harga dokumen sumber yang sudah disepakati pelanggan (perintah kerja) dipakai apa adanya, seperti
    // harga pre-order saat diambil; server menerima `HargaSatuan` perangkat dan menghitung ulang totalnya.
    final harga =
        hargaManual ??
        hargaDokumen ??
        TentukanHarga(katalog, k, produk.uuid, satuanJual.uuid, qty, kanal: kanal, tierPelanggan: tierPelanggan);
    if (harga == null) {
      if (produk.hargaTerbuka) {
        throw GalatKasir('HargaTerbukaWajib', 'Ketik harga "${produk.nama}" dulu.');
      }
      throw GalatKasir(
        'HargaTidakDitemukan',
        'Harga "${produk.nama}" (${satuanJual.nama}) belum diatur. Atur di back-office menu Harga.',
      );
    }
    final rapi = catatan?.trim();
    return ItemKeranjang(
      uuid: BuatUuid(),
      uuidProduk: produk.uuid,
      nama: produk.nama,
      uuidProdukSatuan: satuanJual.uuid,
      namaSatuan: satuanJual.nama,
      // F-16d bagian 2: paket sesi selalu dijual per paket utuh.
      bolehDesimal: satuanJual.bolehDesimal && !produk.paketSesi,
      jumlah: qty,
      hargaSatuan: harga,
      pilihan: pilihan,
      catatan: rapi == null || rapi.isEmpty ? null : rapi,
      hargaTermasukPajak: produk.hargaTermasukPajak,
      pajak: produk.pajak,
      nomorSeri: produk.bernomorSeri ? nomorSeri : const [],
      hargaTerbuka: produk.hargaTerbuka,
    );
  }

  /// K-25: harga ketikan hanya untuk produk harga terbuka, lebih dari nol, dan tanpa pecahan rupiah.
  static void ValidasiHargaTerbuka(ProdukJual produk, Uang harga) {
    if (!produk.hargaTerbuka) {
      throw GalatKasir('HargaBukanTerbuka', 'Harga "${produk.nama}" mengikuti daftar harga dan tidak bisa diketik.');
    }
    if (harga.BernilaiNol() || harga.BernilaiNegatif()) {
      throw GalatKasir('HargaTidakValid', 'Harga "${produk.nama}" harus lebih dari Rp0.');
    }
    if (!harga.SamaDengan(Uang.Dari(harga.KeDesimal().truncate().toString()))) {
      throw GalatKasir('HargaTidakValid', 'Harga "${produk.nama}" harus rupiah bulat.');
    }
  }

  /// Pilihan wajib/opsional sesuai `MinimalPilih`/`MaksimalPilih` tiap kelompok produk.
  static void ValidasiPilihan(ProdukJual produk, List<PilihanTerpilih> pilihan) {
    final dipilih = pilihan.map((p) => p.uuid).toSet();
    for (final k in produk.kelompokPilihan) {
      final jumlah = k.pilihan.where((p) => dipilih.contains(p.uuid)).length;
      if (jumlah < k.minimal) {
        throw GalatKasir('PilihanBelumLengkap', 'Pilih minimal ${k.minimal} untuk "${k.nama}".');
      }
      if (k.maksimal != null && jumlah > k.maksimal!) {
        throw GalatKasir('PilihanTerlaluBanyak', 'Pilih maksimal ${k.maksimal} untuk "${k.nama}".');
      }
    }
  }

  /// Tambah baris; produk + satuan + pilihan yang sama (tanpa catatan & diskon) → jumlah bertambah.
  /// Apotek bagian 4: produk yang bisa menjadi bahan racikan (berstok, tanpa nomor seri), urut nama.
  static List<ProdukJual> AmbilBahanRacikan(KatalogLokal katalog) => [
    for (final p in katalog.produk)
      if (p.aktif &&
          !p.bernomorSeri &&
          (p.jenis == 'Stok' || p.jenis == 'Produksi' || p.jenis == JenisProdukKasir.bahanBaku))
        p,
  ]..sort((a, b) => a.nama.toLowerCase().compareTo(b.nama.toLowerCase()));

  /// Produk jasa racik (pembawa baris racikan), urut nama.
  static List<ProdukJual> AmbilJasaRacik(KatalogLokal katalog) => [
    for (final p in katalog.produk)
      if (p.aktif && p.jenis == JenisProdukKasir.jasa) p,
  ]..sort((a, b) => a.nama.toLowerCase().compareTo(b.nama.toLowerCase()));

  /// Harga saran racikan = harga jasa racik + Σ harga jual komponen × jumlah (harga daftar & tier pelanggan, seperti
  /// menjual obatnya satuan). Komponen tanpa harga dihitung 0 dan dilaporkan di [tanpaHarga].
  ({Uang harga, List<String> tanpaHarga}) HitungHargaSaranRacikan(
    KatalogLokal katalog,
    KonteksPenjualan k,
    ProdukJual jasa,
    List<KomponenRacikan> komponen, {
    String? tierPelanggan,
  }) {
    final satuanJasa = jasa.AmbilSatuanBawaan();
    var total = satuanJasa == null
        ? Uang.Nol()
        : TentukanHarga(katalog, k, jasa.uuid, satuanJasa.uuid, Kuantitas.DariBulat(1), tierPelanggan: tierPelanggan) ??
              Uang.Nol();
    final tanpaHarga = <String>[];
    for (final c in komponen) {
      final produk = katalog.CariProduk(c.uuidProduk);
      final satuan =
          produk?.satuan.where((s) => s.uuid == c.uuidProdukSatuan).firstOrNull ?? produk?.AmbilSatuanBawaan();
      final harga = produk == null || satuan == null
          ? null
          : TentukanHarga(katalog, k, produk.uuid, satuan.uuid, c.jumlah, tierPelanggan: tierPelanggan);
      if (harga == null) {
        tanpaHarga.add(c.nama);
        continue;
      }
      total = total.Tambah(harga.Kali(c.jumlah.KeDesimal()));
    }
    return (harga: total, tanpaHarga: tanpaHarga);
  }

  /// Baris jasa racik pembawa [racikan] dengan [harga] racikan yang disepakati (server menerima harga perangkat).
  ItemKeranjang BuatBarisRacikan(
    KatalogLokal katalog,
    KonteksPenjualan k,
    ProdukJual jasa,
    RacikanBaris racikan,
    Uang harga,
  ) {
    if (jasa.jenis != JenisProdukKasir.jasa) {
      throw GalatKasir('RacikanBukanJasa', 'Racikan dicatat pada produk jasa racik; "${jasa.nama}" bukan jasa.');
    }
    if (harga.BernilaiNegatif()) {
      throw const GalatKasir('HargaTidakValid', 'Harga racikan tidak boleh minus.');
    }
    return BuatBaris(katalog, k, jasa, hargaDokumen: harga).Salin(racikan: racikan);
  }

  /// FnB minimum charge (§9.1, D-144): pada pesanan meja yang punya minimum, selisih antara minimum dan nilai belanja
  /// (subtotal setelah diskon, sebelum biaya layanan & pajak, persis pemeriksaan server) ditagih sebagai SATU baris produk
  /// "Selisih minimum charge" (harga terbuka). Baris itu dibuang lalu dihitung ulang setiap kali, jadi idempoten: belanja
  /// bertambah = selisih mengecil atau baris hilang. Uuid baris dipertahankan supaya tampilan tidak berkedip. Tanpa
  /// minimum, tanpa produk penampung di katalog, atau keranjang kosong = keranjang apa adanya.
  Keranjang TerapkanMinimumCharge(Keranjang keranjang, KatalogLokal katalog, KonteksPenjualan k) {
    final minimum = k.minimumCharge.Ambil(keranjang.pesananMeja?.uuidMeja);
    final uuidProduk = k.minimumCharge.uuidProduk;
    final produk = uuidProduk == null ? null : katalog.CariProduk(uuidProduk);
    if (minimum == null || uuidProduk == null || produk == null) {
      return keranjang;
    }
    final lama = keranjang.baris.where((b) => b.uuidProduk == uuidProduk).firstOrNull;
    final tanpa = keranjang.Salin(
      baris: [
        for (final b in keranjang.baris)
          if (b.uuidProduk != uuidProduk) b,
      ],
    );
    if (tanpa.baris.isEmpty) {
      return tanpa;
    }
    try {
      final hasil = Hitung(tanpa, k).hasil;
      final belanja = hasil.subtotal.Kurangi(hasil.totalDiskon);
      if (belanja.Bandingkan(minimum) >= 0) {
        return tanpa;
      }
      final selisih = minimum.Kurangi(belanja);
      final baris = lama != null
          ? lama.Salin(hargaSatuan: selisih, jumlah: Kuantitas.DariBulat(1))
          : BuatBaris(katalog, k, produk, hargaManual: selisih);
      return tanpa.Salin(baris: [...tanpa.baris, baris]);
    } on GalatKasir {
      // Keranjang yang tidak bisa dihitung ditampilkan apa adanya; kesalahannya muncul di tempat asalnya.
      return keranjang;
    }
  }

  Keranjang TambahBaris(Keranjang keranjang, ItemKeranjang baru, KatalogLokal katalog, KonteksPenjualan k) {
    final indeks = keranjang.baris.indexWhere((b) => b.CekBisaDigabung(baru));
    if (indeks < 0) {
      PastikanStokCukup(
        keranjang,
        katalog,
        uuidProduk: baru.uuidProduk,
        nama: baru.nama,
        jumlahDasarBaru: LayananStokTersedia.HitungJumlahDasar(
          baru.jumlah,
          baru.uuidProduk,
          baru.uuidProdukSatuan,
          katalog,
        ),
      );
      return keranjang.Salin(baris: [...keranjang.baris, baru]);
    }
    final lama = keranjang.baris[indeks];
    return UbahJumlah(keranjang, lama.uuid, lama.jumlah.Tambah(baru.jumlah), katalog, k);
  }

  /// Ubah jumlah baris (≤ 0 = hapus). Harga ditentukan ulang karena harga bertingkat bergantung jumlah.
  Keranjang UbahJumlah(
    Keranjang keranjang,
    String uuidBaris,
    Kuantitas jumlah,
    KatalogLokal katalog,
    KonteksPenjualan k,
  ) {
    if (jumlah.Bandingkan(Kuantitas.Nol()) <= 0) {
      return HapusBaris(keranjang, uuidBaris);
    }
    return _UbahBaris(keranjang, uuidBaris, (b) {
      if (b.nomorSeri.isNotEmpty && jumlah != Kuantitas.DariBulat(b.nomorSeri.length)) {
        throw GalatKasir(
          'NomorSeriTidakSesuai',
          'Jumlah "${b.nama}" mengikuti banyaknya nomor seri. Ketuk item untuk menambah atau mengurangi nomor seri.',
        );
      }
      if (!b.bolehDesimal && jumlah.KeDesimal() != jumlah.KeDesimal().truncate()) {
        throw GalatKasir('JumlahTidakValid', 'Jumlah ${b.namaSatuan ?? 'satuan ini'} harus bilangan bulat.');
      }
      PastikanStokCukup(
        keranjang,
        katalog,
        uuidProduk: b.uuidProduk,
        nama: b.nama,
        jumlahDasarBaru: LayananStokTersedia.HitungJumlahDasar(jumlah, b.uuidProduk, b.uuidProdukSatuan, katalog),
        jumlahDasarLama: LayananStokTersedia.HitungJumlahDasar(b.jumlah, b.uuidProduk, b.uuidProdukSatuan, katalog),
        kecualiBaris: b.uuid,
      );
      final harga = b.uuidProdukSatuan == null || b.CekHargaDikunci
          ? null
          : TentukanHarga(
              katalog,
              k,
              b.uuidProduk,
              b.uuidProdukSatuan!,
              jumlah,
              kanal: AmbilKanal(keranjang),
              tierPelanggan: keranjang.pelanggan?.kodeTier,
            );
      return b.Salin(jumlah: jumlah, hargaSatuan: harga ?? b.hargaSatuan);
    });
  }

  /// Ganti satuan jual baris. [jumlahBaru] (bila diisi) adalah jumlah yang akan dipakai baris itu setelah ini
  /// (misal dari panel item yang mengganti satuan dan jumlah sekaligus), supaya pencegah stok menilai hasil akhirnya
  /// dan bukan jumlah lama dalam satuan baru.
  Keranjang GantiSatuan(
    Keranjang keranjang,
    String uuidBaris,
    SatuanJual satuan,
    KatalogLokal katalog,
    KonteksPenjualan k, {
    Kuantitas? jumlahBaru,
  }) => _UbahBaris(keranjang, uuidBaris, (b) {
    final asal = jumlahBaru ?? b.jumlah;
    final jumlah = satuan.bolehDesimal ? asal : Kuantitas.DariDesimal(asal.KeDesimal().ceil());
    if (b.CekHargaDikunci) {
      throw GalatKasir(
        'HargaTerbukaSatuan',
        'Harga "${b.nama}" ditentukan kasir. Kembalikan harga aslinya atau hapus baris, lalu tambahkan lagi dengan satuan lain.',
      );
    }
    PastikanStokCukup(
      keranjang,
      katalog,
      uuidProduk: b.uuidProduk,
      nama: b.nama,
      jumlahDasarBaru: LayananStokTersedia.HitungJumlahDasar(jumlah, b.uuidProduk, satuan.uuid, katalog),
      jumlahDasarLama: LayananStokTersedia.HitungJumlahDasar(b.jumlah, b.uuidProduk, b.uuidProdukSatuan, katalog),
      kecualiBaris: b.uuid,
    );
    final harga = TentukanHarga(
      katalog,
      k,
      b.uuidProduk,
      satuan.uuid,
      jumlah,
      kanal: AmbilKanal(keranjang),
      tierPelanggan: keranjang.pelanggan?.kodeTier,
    );
    if (harga == null) {
      throw GalatKasir('HargaTidakDitemukan', 'Harga "${b.nama}" per ${satuan.nama} belum diatur.');
    }
    return b.Salin(
      uuidProdukSatuan: satuan.uuid,
      namaSatuan: satuan.nama,
      bolehDesimal: satuan.bolehDesimal,
      jumlah: jumlah,
      hargaSatuan: harga,
    );
  });

  /// BR-05.2: pencegah stok berlaku untuk [keranjang] ini. Tidak berlaku bila keranjang menagih dokumen yang sudah
  /// ada (pengambilan pre-order/uang muka, pesanan online, reservasi, perintah kerja) atau bila [stokTersedia] tidak
  /// dipasang. (Tanpa salinan stok, [LayananStokTersedia] sendiri menganggap semua produk tanpa batas.)
  bool CekPencegahStokBerlaku(Keranjang keranjang) =>
      stokTersedia != null &&
      keranjang.praPesan == null &&
      keranjang.reservasi == null &&
      keranjang.perintahKerja == null;

  /// BR-05.2: produk [uuidProduk] tidak bisa ditambah ke [keranjang] karena sisa stoknya nol atau kurang (tampil
  /// "Habis" di katalog kasir).
  bool CekStokHabis(Keranjang keranjang, KatalogLokal katalog, String uuidProduk) =>
      CekPencegahStokBerlaku(keranjang) && stokTersedia!.CekHabis(uuidProduk, keranjang, katalog);

  /// BR-05.2: tolak penambahan jumlah produk berstok yang melebihi sisa stok Toko (`StokTidakCukup`). Sisa = salinan
  /// stok dari server − penjualan lokal yang belum tercakup − isi [keranjang] (lihat [LayananStokTersedia]);
  /// [kecualiBaris] (baris yang sedang diubah) tidak ikut dihitung. Jumlah dalam satuan dasar.
  ///
  /// Sengaja **tidak** memblokir:
  /// - mengurangi/mempertahankan jumlah ([jumlahDasarLama] ≥ [jumlahDasarBaru]): salinan bisa turun setelah baris
  ///   masuk, dan kasir harus tetap bisa merapikan keranjangnya;
  /// - produk di luar daftar server (boleh minus, jasa, paket, resep) dan perangkat tanpa salinan (belum online);
  /// - keranjang yang menagih dokumen yang sudah ada (pengambilan pre-order/uang muka, pesanan online, reservasi,
  ///   perintah kerja): barangnya sudah dijanjikan ke pelanggan, jadi kasir tidak boleh terhenti di kasir;
  /// - retur, tukar-masuk, void, pembayaran, pesanan meja yang barisnya sudah tersimpan, dan pesanan self-order/online:
  ///   jalur ini tidak lewat [TambahBaris]/[UbahJumlah]/[GantiSatuan], dan server tetap menerima penjualan yang sudah
  ///   terjadi.
  void PastikanStokCukup(
    Keranjang keranjang,
    KatalogLokal katalog, {
    required String uuidProduk,
    required String nama,
    required Kuantitas jumlahDasarBaru,
    Kuantitas? jumlahDasarLama,
    String? kecualiBaris,
  }) {
    final stok = stokTersedia;
    if (stok == null || !CekPencegahStokBerlaku(keranjang)) {
      return;
    }
    if (jumlahDasarLama != null && jumlahDasarBaru.Bandingkan(jumlahDasarLama) <= 0) {
      return;
    }
    final sisa = stok.HitungTersediaEfektif(uuidProduk, keranjang, katalog, kecualiBaris: kecualiBaris);
    if (sisa == null || jumlahDasarBaru.Bandingkan(sisa) <= 0) {
      return;
    }
    final total = stok.HitungTersediaSalinan(uuidProduk) ?? Kuantitas.Nol();
    final namaSatuan = katalog.CariProduk(uuidProduk)?.satuanDasar?.nama.trim() ?? '';
    final satuan = namaSatuan.isEmpty ? '' : ' $namaSatuan';
    if (total.Bandingkan(Kuantitas.Nol()) <= 0) {
      throw GalatKasir('StokTidakCukup', 'Stok $nama sudah habis.');
    }
    final hanya = 'Stok $nama hanya ${LayananStokTersedia.FormatJumlah(total)}$satuan';
    throw GalatKasir(
      'StokTidakCukup',
      sisa.Bandingkan(Kuantitas.Nol()) <= 0 ? '$hanya, semuanya sudah ada di keranjang.' : '$hanya.',
    );
  }

  /// F-05h: ganti nomor seri baris produk bernomor seri; jumlah mengikuti banyaknya nomor (kosong = hapus baris).
  Keranjang AturNomorSeri(
    Keranjang keranjang,
    String uuidBaris,
    List<String> nomorSeri,
    KatalogLokal katalog,
    KonteksPenjualan k,
  ) {
    final rapi = [for (final n in nomorSeri) n.trim()].where((n) => n.isNotEmpty).toList();
    if (rapi.isEmpty) {
      return HapusBaris(keranjang, uuidBaris);
    }
    final asal = keranjang.baris.firstWhere((b) => b.uuid == uuidBaris);
    final sementara = _UbahBaris(keranjang, uuidBaris, (b) => b.Salin(nomorSeri: const []));
    return _UbahBaris(
      UbahJumlah(sementara, asal.uuid, Kuantitas.DariBulat(rapi.length), katalog, k),
      uuidBaris,
      (b) => b.Salin(nomorSeri: rapi),
    );
  }

  Keranjang AturPilihan(Keranjang keranjang, String uuidBaris, ProdukJual produk, List<PilihanTerpilih> pilihan) {
    ValidasiPilihan(produk, pilihan);
    return _UbahBaris(keranjang, uuidBaris, (b) => b.Salin(pilihan: pilihan));
  }

  Keranjang AturCatatan(Keranjang keranjang, String uuidBaris, String? catatan) {
    final rapi = catatan?.trim();
    return _UbahBaris(keranjang, uuidBaris, (b) => b.Salin(catatan: () => rapi == null || rapi.isEmpty ? null : rapi));
  }

  /// Harga manual (lapis 1 price engine, `pos.harga.timpa`): kasir ber-izin itu, atau penyetuju ber-PIN dengan izin
  /// itu, mengubah harga satu baris dari harga price engine. Alasan wajib. Harga asal disimpan di `hargaAsli` supaya
  /// bisa dipulihkan dan server bisa menandai perubahannya; server memeriksa ulang izinnya (pelanggaran = tinjauan,
  /// bukan penolakan, karena penjualan offline sudah terjadi). Harga yang sama dengan harga asal = memulihkan.
  Keranjang UbahHargaItem(
    Keranjang keranjang,
    String uuidBaris,
    Uang hargaBaru,
    String alasan, {
    required StafLokal kasir,
    PenyetujuDiskon? penyetuju,
  }) {
    if (keranjang.pesananMeja != null ||
        keranjang.praPesan != null ||
        keranjang.perintahKerja != null ||
        keranjang.tukar != null) {
      throw const GalatKasir(
        'HargaDokumenTerikat',
        'Harga pesanan ini sudah disepakati dan tidak bisa diubah dari keranjang.',
      );
    }
    return _UbahBaris(keranjang, uuidBaris, (b) {
      if (b.hargaTerbuka || b.racikan != null) {
        throw GalatKasir(
          'HargaTidakBisaDiubah',
          'Harga "${b.nama}" ditentukan saat ditambahkan, bukan dari daftar harga.',
        );
      }
      final asal = b.hargaAsli ?? b.hargaSatuan;
      if (hargaBaru.SamaDengan(asal)) {
        return PulihkanHarga(b);
      }
      if (hargaBaru.BernilaiNol() || hargaBaru.BernilaiNegatif()) {
        throw GalatKasir('HargaTidakValid', 'Harga "${b.nama}" harus lebih dari Rp0. Untuk gratis, pakai diskon.');
      }
      final rapi = alasan.trim();
      if (rapi.isEmpty) {
        throw const GalatKasir('AlasanHargaWajib', 'Isi alasan mengubah harga.');
      }
      if (rapi.length > 100) {
        throw const GalatKasir('AlasanHargaTerlaluPanjang', 'Alasan mengubah harga maksimal 100 karakter.');
      }
      if (!kasir.PunyaIzin(IzinKasir.posHargaTimpa) && penyetuju == null) {
        throw const GalatKasir('HargaTanpaIzin', 'Mengubah harga perlu persetujuan supervisor dengan PIN.');
      }
      return b.Salin(
        hargaSatuan: hargaBaru,
        hargaAsli: () => asal,
        alasanHarga: () => rapi,
        penyetujuHarga: () => kasir.PunyaIzin(IzinKasir.posHargaTimpa) ? null : penyetuju,
      );
    });
  }

  /// Kembalikan harga baris ke harga price engine yang disimpan saat diubah ([ItemKeranjang.hargaAsli]).
  static ItemKeranjang PulihkanHarga(ItemKeranjang baris) => baris.hargaAsli == null
      ? baris
      : baris.Salin(
          hargaSatuan: baris.hargaAsli,
          hargaAsli: () => null,
          alasanHarga: () => null,
          penyetujuHarga: () => null,
        );

  Keranjang PulihkanHargaItem(Keranjang keranjang, String uuidBaris) => _UbahBaris(keranjang, uuidBaris, PulihkanHarga);

  Keranjang HapusBaris(Keranjang keranjang, String uuidBaris) {
    final baris = keranjang.baris.where((b) => b.uuid != uuidBaris).toList();
    // Keranjang yang terikat dokumen (pesanan meja, tukar barang, pre-order, reservasi, perintah kerja) tetap terikat
    // walau barisnya habis; kalau tidak, konteksnya hilang diam-diam dan kasir menjual biasa.
    final terikatDokumen =
        keranjang.pesananMeja != null ||
        keranjang.tukar != null ||
        keranjang.praPesan != null ||
        keranjang.reservasi != null ||
        keranjang.perintahKerja != null;
    return baris.isEmpty && !terikatDokumen ? Keranjang.kosong : keranjang.Salin(baris: baris);
  }

  Keranjang _UbahBaris(Keranjang keranjang, String uuidBaris, ItemKeranjang Function(ItemKeranjang) ubah) =>
      keranjang.Salin(baris: [for (final b in keranjang.baris) b.uuid == uuidBaris ? ubah(b) : b]);

  // Hitung & pajak -----------------------------------------------------------------------------------------------------

  /// [metodeBayar]: Uuid metode semua pembayaran transaksi (F-16c bagian 3, promo metode bayar); null = belum memilih
  /// pembayaran (promo metode bayar tidak berlaku, seperti di server). [polos] (K28 retur tanpa struk): tanpa biaya
  /// layanan, pembulatan tunai, dan promo — sama dengan `PenghitungGrosir` server.
  HitunganKeranjang Hitung(
    Keranjang keranjang,
    KonteksPenjualan k, {
    List<DataPembayaranKalkulasi> pembayaran = const [],
    List<String>? metodeBayar,
    bool polos = false,
  }) {
    final tanggal = k.HitungTanggalBisnis(_jam());
    final pajakDokumen = <String, DataPajakKalkulasi>{};
    final tarifDipakai = <String, TarifPajakLokal>{};
    final peringatan = <String>{};
    final labelPajak = <String, String>{};
    final kodeBaris = <List<String>>[];

    for (final b in keranjang.baris) {
      final kode = <String>[];
      for (final p in b.pajak) {
        // Syarat profil pajak outlet menurut kategori jenis pajak (PRD v1.46), bukan string kode tetap.
        final kategori = p.AmbilKategori();
        if (kategori == PajakKelompokPos.kategoriPpn && !k.profilPajak.pkp) {
          continue;
        }
        if (kategori == PajakKelompokPos.kategoriPbjt && !k.profilPajak.pungutPbjt) {
          continue;
        }
        final tarif = k.CariTarif(p.kode, tanggal);
        if (tarif == null) {
          peringatan.add(
            'Tarif ${KodePajak.AmbilLabel(p)} belum tersedia di perangkat, jadi pajak ini tidak dihitung. '
            'Sambungkan ke internet agar tarif terbaru terunduh.',
          );
          continue;
        }
        if (!kode.contains(p.kode)) {
          kode.add(p.kode);
        }
        tarifDipakai.putIfAbsent(p.kode, () => tarif);
        labelPajak.putIfAbsent(p.kode, () => KodePajak.AmbilLabel(p));
        pajakDokumen.putIfAbsent(
          p.kode,
          () => DataPajakKalkulasi(
            kode: p.kode,
            tarif: Decimal.parse(tarif.tarif),
            pengaliDpp: Rational(BigInt.from(tarif.pembilang), BigInt.from(tarif.penyebut)),
            dasarPengenaan: p.dasarPengenaan == DasarPengenaanPajak.SubtotalPlusLayanan.name
                ? DasarPengenaanPajak.SubtotalPlusLayanan
                : DasarPengenaanPajak.Subtotal,
            kenaBiayaKirim: p.kenaBiayaKirim,
          ),
        );
      }
      kodeBaris.add(kode);
    }

    final dasar = DataKalkulasi(
      hargaTermasukPajak: k.profilPajak.hargaTermasukPajak,
      persenBiayaLayanan: polos ? null : k.AmbilPersenBiayaLayanan(),
      pembulatanTunai: polos ? null : k.pembulatanTunai,
      pajak: pajakDokumen.values.toList(),
      baris: [
        for (var i = 0; i < keranjang.baris.length; i++)
          DataBarisKalkulasi(
            jumlah: keranjang.baris[i].jumlah,
            hargaSatuan: keranjang.baris[i].hargaSatuan,
            hargaPilihan: keranjang.baris[i].AmbilHargaPilihan(),
            hargaTermasukPajak: keranjang.baris[i].hargaTermasukPajak,
            kodePajak: kodeBaris[i],
            potongan: [if (keranjang.baris[i].diskon != null) keranjang.baris[i].diskon!.KePotongan()],
          ),
      ],
      potonganPesanan: [if (keranjang.diskonPesanan != null) keranjang.diskonPesanan!.KePotongan()],
      tukarPoin: keranjang.tukarPoin?.nilai,
      pembayaran: pembayaran,
      biayaKirim: keranjang.biayaKirim,
      diskonKirim: keranjang.diskonKirim,
    );
    final hasilTanpaPromo = _mesin.Hitung(dasar);
    var hasil = hasilTanpaPromo;
    var promoTerpakai = const <PromoTerpakai>[];
    DefinisiPromo? poinBerlipat;
    // F-16c bagian 2: promo voucher ikut dievaluasi walau daftar promo tersimpan belum memuatnya.
    final voucher = keranjang.voucher;
    final promoVoucher = voucher == null || k.promo.any((p) => p.uuid == voucher.uuidPromo)
        ? null
        : KonteksPenjualan.UraiPromo(PromoPos.DariJson(voucher.promo));
    final daftarPromo = [...k.promo, ?promoVoucher];
    if (!polos && daftarPromo.isNotEmpty && keranjang.baris.isNotEmpty) {
      final waktu = _jam().toUtc();
      final hasilPromo = const MesinPromo().Terapkan(
        dasar,
        [
          for (final b in keranjang.baris)
            BarisPromo(uuidProduk: b.uuidProduk, uuidKategori: k.kategoriProduk[b.uuidProduk]),
        ],
        daftarPromo,
        KonteksPromo(
          waktu: waktu,
          waktuLokal: ZonaWaktuOutlet.KeWaktuOutlet(waktu, k.zonaWaktu),
          uuidOutlet: k.uuidOutlet,
          kanal: AmbilKanal(keranjang),
          tier: keranjang.pelanggan?.kodeTier,
          voucher: [?voucher?.uuidPromo],
          // F-16c bagian 3: data pelanggan terakhir yang diketahui perangkat; server menilai ulang saat sinkron.
          metodeBayar: metodeBayar,
          berpelanggan: keranjang.pelanggan != null,
          tanggalLahir: keranjang.pelanggan?.hariLahir == null ? null : '2000-${keranjang.pelanggan!.hariLahir}',
          jumlahTransaksiPelanggan: keranjang.pelanggan?.jumlahTransaksi,
          pemakaianPelanggan: keranjang.pelanggan?.AmbilPemakaianPada(tanggal) ?? const {},
        ),
        mode: k.modeResolusiPromo,
      );
      hasil = hasilPromo.hasil;
      promoTerpakai = hasilPromo.terpakai;
      poinBerlipat = keranjang.pelanggan == null ? null : hasilPromo.poinBerlipat;
    }

    return HitunganKeranjang(
      hasil: hasil,
      hasilDasar: hasilTanpaPromo,
      promoTerpakai: promoTerpakai,
      namaPromo: voucher == null ? k.namaPromo : {...k.namaPromo, voucher.uuidPromo: voucher.namaPromo},
      pajakDokumen: pajakDokumen.values.toList(),
      tarifDipakai: tarifDipakai,
      kodePajakBaris: kodeBaris,
      peringatan: peringatan.toList(),
      tanggalBisnis: tanggal,
      labelPajak: labelPajak,
      poinBerlipat: poinBerlipat,
    );
  }

  /// Tagihan untuk bagian tunai bila sisa dibayar tunai: total (dengan pembulatan tunai, BR-08.6) − yang sudah dibayar.
  Uang HitungTagihanTunai(Keranjang keranjang, KonteksPenjualan k, List<PembayaranMasukan> pembayaran) {
    final nonTunai = pembayaran.where((p) => !p.CekTunai()).toList();
    final hitungan = Hitung(
      keranjang,
      k,
      pembayaran: [
        for (final p in nonTunai) p.KeKalkulasi(),
        const DataPembayaranKalkulasi(metode: 'Tunai'),
      ],
      metodeBayar: AmbilMetodeBayar(k, [for (final p in nonTunai) p.metode], tambahanTunai: true),
    );
    final dibayar = nonTunai.fold(Uang.Nol(), (total, p) => total.Tambah(p.jumlah));
    return hitungan.hasil.totalAkhir.Kurangi(dibayar);
  }

  /// Uuid metode pembayaran untuk promo metode bayar (F-16c bagian 3): metode [dipakai] + metode tunai outlet bila
  /// [tambahanTunai] (sisa akan dibayar tunai). Kosong = null (belum memilih pembayaran).
  static List<String>? AmbilMetodeBayar(
    KonteksPenjualan k,
    Iterable<BarisMetodePembayaran> dipakai, {
    bool tambahanTunai = false,
  }) {
    final hasil = <String>{
      for (final m in dipakai) m.Uuid,
      if (tambahanTunai)
        for (final m in k.metodePembayaran.where((m) => m.Jenis == JenisMetodeBayar.tunai)) m.Uuid,
    };
    return hasil.isEmpty ? null : hasil.toList();
  }

  // Diskon (BR-07.3) ---------------------------------------------------------------------------------------------------

  /// Persen efektif diskon (PRD v1.46 tindak lanjut (c), sama dengan server): nilai diskon hasil `MesinKalkulasi`
  /// (sudah dibulatkan ke sen) ÷ [dasar] × 100. Dasar = bruto baris untuk diskon baris, subtotal untuk diskon
  /// pesanan. Bukan persen masukan: diskon 30% dari bruto Rp 4.995,45 dibulatkan menjadi Rp 1.498,64 = 30,0001%.
  static Rational HitungPersenEfektif(Uang dasar, Uang diskon) {
    if (diskon.Bandingkan(Uang.Nol()) <= 0) {
      return Rational.zero;
    }
    if (dasar.Bandingkan(Uang.Nol()) <= 0) {
      return Rational.fromInt(100);
    }
    return diskon.KeDesimal().toRational() * Rational.fromInt(100) / dasar.KeDesimal().toRational();
  }

  /// Validasi bentuk diskon sebelum diterapkan: persen 0–100, nominal tidak melebihi [dasar].
  static void ValidasiBentukDiskon(Uang dasar, DiskonManual diskon) {
    final persen = diskon.persen;
    if (persen != null && (persen <= Decimal.zero || persen > Decimal.fromInt(100))) {
      throw const GalatKasir('DiskonTidakValid', 'Persen diskon harus lebih dari 0 dan paling besar 100.');
    }
    final jumlah = diskon.jumlah;
    if (jumlah != null && (jumlah.Bandingkan(Uang.Nol()) <= 0 || jumlah.Bandingkan(dasar) > 0)) {
      throw GalatKasir('DiskonTidakValid', 'Diskon harus lebih dari Rp 0 dan paling besar ${dasar.FormatRupiah()}.');
    }
  }

  /// Nilai diskon baris [uuidBaris] bila [diskon] diterapkan: bruto (dasar) dan diskon hasil mesin.
  ({Uang dasar, Uang diskon}) HitungDiskonBaris(
    Keranjang keranjang,
    String uuidBaris,
    DiskonManual diskon,
    KonteksPenjualan k,
  ) {
    final indeks = keranjang.baris.indexWhere((b) => b.uuid == uuidBaris);
    final dengan = keranjang.Salin(
      baris: [for (final b in keranjang.baris) b.uuid == uuidBaris ? b.Salin(diskon: () => diskon) : b],
    );
    final baris = Hitung(dengan, k).hasilTanpaPromo.baris[indeks];
    return (dasar: baris.bruto, diskon: baris.diskon);
  }

  /// Nilai diskon pesanan bila [diskon] diterapkan: subtotal (dasar) dan diskon pesanan hasil mesin.
  ({Uang dasar, Uang diskon}) HitungDiskonPesanan(Keranjang keranjang, DiskonManual diskon, KonteksPenjualan k) {
    final hasil = Hitung(keranjang.Salin(diskonPesanan: () => diskon), k).hasilTanpaPromo;
    return (dasar: hasil.subtotal, diskon: hasil.diskonPesanan.Kurangi(hasil.diskonPoin));
  }

  /// Penyetuju efektif: penyetuju yang lolos PIN, atau kasir sendiri bila ia punya izin menyetujui.
  static PenyetujuDiskon? AmbilPenyetujuEfektif(StafLokal kasir, PenyetujuDiskon? penyetuju) =>
      penyetuju ??
      (kasir.PunyaIzin(IzinKasir.penjualanDiskonSetujui)
          ? PenyetujuDiskon(uuid: kasir.uuid, nama: kasir.nama, pemilik: kasir.pemilik)
          : null);

  /// Apakah diskon ini wajib dicatat penyetujunya: kasir tanpa `penjualan.diskon.manual` (PRD v1.46 (f): diarahkan
  /// ke PIN penyetuju, bukan ditolak), atau persen efektif di atas `BatasDiskonManual`. Pemilik tidak pernah.
  static bool CekButuhPenyetuju({required Rational persen, required StafLokal kasir, required KonteksPenjualan k}) =>
      !kasir.pemilik &&
      (!kasir.PunyaIzin(IzinKasir.penjualanDiskonManual) || persen > k.batasDiskonManual.toRational());

  /// BR-07.3 terhadap persen efektif [diskon] (hasil mesin) dari [dasar].
  static StatusDiskon PeriksaDiskon({
    required Uang dasar,
    required Uang diskon,
    required StafLokal kasir,
    required KonteksPenjualan k,
    PenyetujuDiskon? penyetuju,
  }) {
    final persen = HitungPersenEfektif(dasar, diskon);
    if (!CekButuhPenyetuju(persen: persen, kasir: kasir, k: k)) {
      return StatusDiskon.Boleh;
    }
    final penyetujuEfektif = AmbilPenyetujuEfektif(kasir, penyetuju);
    if (penyetujuEfektif?.pemilik ?? false) {
      return StatusDiskon.Boleh;
    }
    if (persen > k.batasDiskonPenyetuju.toRational()) {
      return StatusDiskon.MelebihiBatas;
    }
    return penyetujuEfektif == null ? StatusDiskon.ButuhPenyetuju : StatusDiskon.Boleh;
  }

  /// Periksa semua diskon keranjang terhadap batas memakai hasil mesin di [hitungan]. Kembalikan penyetuju yang
  /// dicatat (`UuidPenyetujuDiskon`), atau null bila tidak ada diskon yang butuh penyetuju.
  static PenyetujuDiskon? ValidasiDiskon(
    Keranjang keranjang,
    HitunganKeranjang hitungan,
    StafLokal kasir,
    KonteksPenjualan k,
  ) {
    var butuhPenyetuju = false;
    void Periksa(String nama, Uang dasar, Uang diskon) {
      final status = PeriksaDiskon(dasar: dasar, diskon: diskon, kasir: kasir, k: k, penyetuju: keranjang.penyetuju);
      if (status == StatusDiskon.MelebihiBatas) {
        throw GalatKasir(
          'DiskonMelebihiBatas',
          'Diskon $nama melebihi batas ${k.batasDiskonPenyetuju}%. Hanya Pemilik yang bisa menyetujuinya.',
        );
      }
      if (status == StatusDiskon.ButuhPenyetuju) {
        throw GalatKasir(
          'PersetujuanDiperlukan',
          kasir.PunyaIzin(IzinKasir.penjualanDiskonManual)
              ? 'Diskon $nama di atas ${k.batasDiskonManual}% wajib disetujui supervisor dengan PIN.'
              : 'Diskon $nama wajib disetujui supervisor dengan PIN.',
        );
      }
      if (CekButuhPenyetuju(persen: HitungPersenEfektif(dasar, diskon), kasir: kasir, k: k)) {
        butuhPenyetuju = true;
      }
    }

    final hasil = hitungan.hasilTanpaPromo;
    for (var i = 0; i < keranjang.baris.length; i++) {
      if (keranjang.baris[i].diskon != null) {
        Periksa('"${keranjang.baris[i].nama}"', hasil.baris[i].bruto, hasil.baris[i].diskon);
      }
    }
    if (keranjang.diskonPesanan != null) {
      // Diskon poin (F-16b) bukan diskon manual: tidak ikut batas diskon kasir.
      Periksa('pesanan', hasil.subtotal, hasil.diskonPesanan.Kurangi(hasil.diskonPoin));
    }
    return butuhPenyetuju ? AmbilPenyetujuEfektif(kasir, keranjang.penyetuju) : null;
  }

  /// F-16b: poin hanya ditukar untuk pelanggan terpilih, dan nilainya tidak boleh terpotong batas sisa subtotal (server
  /// menolak dokumen yang nilainya berbeda dengan hitungan mesin).
  static void ValidasiTukarPoin(Keranjang keranjang, HasilKalkulasi hasil) {
    final tukar = keranjang.tukarPoin;
    if (tukar == null) {
      return;
    }
    if (keranjang.pelanggan == null) {
      throw const GalatKasir('TukarPoinTanpaPelanggan', 'Pilih pelanggan dulu sebelum menukar poin.');
    }
    if (!hasil.diskonPoin.SamaDengan(tukar.nilai)) {
      throw const GalatKasir(
        'TukarPoinMelebihiTotal',
        'Potongan poin melebihi total belanja. Kurangi poin yang ditukar di panel Pelanggan (F2).',
      );
    }
  }

  /// F-12 BR-12.1: alasan penjualan tempo [jumlah] butuh PIN penyetuju ber-izin `penjualan.tempo.setujui` menurut
  /// posisi kredit terakhir yang diketahui perangkat (kosong = boleh tanpa penyetuju). Sama dengan server
  /// `KreditPelanggan::Periksa`; bila cache basi, server tetap menerima penjualan dan menandainya untuk ditinjau.
  static List<String> PeriksaTempo({
    required PelangganTerpilih pelanggan,
    required Uang jumlah,
    required int batasHariLewat,
  }) {
    final alasan = <String>[];
    final limit = pelanggan.limitKredit == null ? null : Uang.Dari(pelanggan.limitKredit!);
    final setelah = Uang.Dari(pelanggan.sisaPiutang ?? '0').Tambah(jumlah);
    if (limit == null || limit.Bandingkan(Uang.Nol()) <= 0) {
      alasan.add('pelanggan belum punya limit kredit');
    } else if (setelah.Bandingkan(limit) > 0) {
      alasan.add('piutang ${setelah.FormatRupiah()} melebihi limit ${limit.FormatRupiah()}');
    }
    final hari = pelanggan.hariLewatJatuhTempo ?? 0;
    if (hari > batasHariLewat) {
      alasan.add('ada piutang lewat jatuh tempo $hari hari');
    }
    return alasan;
  }

  /// F-12: aturan pembayaran tempo: paling banyak satu per transaksi, wajib pelanggan, dan bila BR-12.1 tidak lolos
  /// wajib penyetuju (kasir sendiri bila ber-izin, atau [uuidPenyetujuTempo] hasil PIN).
  static void ValidasiTempo(
    Keranjang keranjang,
    List<PembayaranMasukan> pembayaran,
    StafLokal kasir,
    KonteksPenjualan k,
    String? uuidPenyetujuTempo,
  ) {
    final tempo = pembayaran.where((p) => p.metode.Jenis == JenisMetodeBayar.tempo).toList();
    if (tempo.isEmpty) {
      return;
    }
    if (tempo.length > 1) {
      throw const GalatKasir('TempoGanda', 'Pembayaran tempo hanya boleh satu kali per transaksi.');
    }
    final pelanggan = keranjang.pelanggan;
    if (pelanggan == null) {
      throw const GalatKasir('TempoTanpaPelanggan', 'Pilih pelanggan dulu untuk pembayaran tempo.');
    }
    final alasan = PeriksaTempo(
      pelanggan: pelanggan,
      jumlah: tempo.single.jumlah,
      batasHariLewat: k.batasHariLewatJatuhTempo,
    );
    if (alasan.isNotEmpty && uuidPenyetujuTempo == null && !kasir.PunyaIzin(IzinKasir.penjualanTempoSetujui)) {
      throw GalatKasir('PersetujuanTempoDiperlukan', 'Tempo perlu persetujuan: ${alasan.join('; ')}.');
    }
  }

  /// F-16d bagian 1: pembayaran deposit paling banyak satu per transaksi, wajib pelanggan, dan tidak melebihi saldo
  /// terakhir yang dibaca online ([saldoDeposit], null = belum dicek). Server tetap memotong saldo; saldo yang berubah di
  /// perangkat lain ditandai tinjauan.
  static void ValidasiDeposit(Keranjang keranjang, List<PembayaranMasukan> pembayaran, Uang? saldoDeposit) {
    final deposit = pembayaran.where((p) => p.metode.Jenis == JenisMetodeBayar.deposit).toList();
    if (deposit.isEmpty) {
      return;
    }
    if (deposit.length > 1) {
      throw const GalatKasir('DepositGanda', 'Pembayaran deposit hanya boleh satu kali per transaksi.');
    }
    final pelanggan = keranjang.pelanggan;
    if (pelanggan == null) {
      throw const GalatKasir('DepositTanpaPelanggan', 'Pilih pelanggan dulu untuk membayar dengan deposit.');
    }
    if (saldoDeposit == null) {
      throw const GalatKasir('PerluOnline', 'Saldo deposit harus dicek online dulu sebelum dipakai.');
    }
    if (deposit.single.jumlah.Bandingkan(saldoDeposit) > 0) {
      throw GalatKasir('SaldoDepositKurang', 'Saldo deposit ${pelanggan.nama} tinggal ${saldoDeposit.FormatRupiah()}.');
    }
  }

  /// CRM-06: gift card paling banyak satu per transaksi dan wajib membawa kode kartu (`Referensi`). Saldo dicek kasir
  /// online di panel Bayar; server memotong saldo dan menandai tinjauan bila kartu bermasalah.
  static void ValidasiGiftCard(List<PembayaranMasukan> pembayaran) {
    final kartu = pembayaran.where((p) => p.metode.Jenis == JenisMetodeBayar.giftCard).toList();
    if (kartu.isEmpty) {
      return;
    }
    if (kartu.length > 1) {
      throw const GalatKasir('GiftCardGanda', 'Gift card hanya boleh satu kartu per transaksi.');
    }
    if ((kartu.single.referensi ?? '').trim().isEmpty) {
      throw const GalatKasir('GiftCardTanpaKode', 'Cek kode gift card dulu sebelum dipakai membayar.');
    }
  }

  /// F-12 bagian 2 & F-17 bagian 2: uang muka hanya dipakai saat menagih dokumen yang punya DP, sekali, dan tidak
  /// melebihi sisanya.
  static void ValidasiUangMuka(Keranjang keranjang, List<PembayaranMasukan> pembayaran) {
    final dp = pembayaran.where((p) => p.metode.Jenis == JenisMetodeBayar.uangMuka).toList();
    if (dp.isEmpty) {
      return;
    }
    final praPesan = keranjang.praPesan;
    if (praPesan == null || dp.length > 1) {
      throw const GalatKasir(
        'UangMukaTanpaPesanan',
        'Uang muka hanya dipakai sekali saat menagih pre-order, pesanan online, atau reservasi meja.',
      );
    }
    if (dp.single.jumlah.Bandingkan(praPesan.sisaUangMuka) > 0) {
      throw GalatKasir(
        'UangMukaMelebihiSisa',
        'Uang muka ${praPesan.nomor} tinggal ${praPesan.sisaUangMuka.FormatRupiah()}.',
      );
    }
  }

  /// F-05h: baris produk bernomor seri wajib membawa tepat satu nomor per unit (jumlah bulat) tanpa nomor ganda di
  /// keranjang, sama dengan validasi server (`NomorSeriTidakSesuai`/`NomorSeriGanda`).
  static void ValidasiNomorSeri(Keranjang keranjang, KatalogLokal? katalog) {
    if (katalog == null) {
      return;
    }
    final dipakai = <String>{};
    for (final b in keranjang.baris) {
      if (katalog.CariProduk(b.uuidProduk)?.bernomorSeri != true) {
        continue;
      }
      if (b.jumlah.KeDesimal() != b.jumlah.KeDesimal().truncate() ||
          b.nomorSeri.length != b.jumlah.KeDesimal().toBigInt().toInt()) {
        throw GalatKasir(
          'NomorSeriTidakSesuai',
          '"${b.nama}" memakai nomor seri: isi ${b.jumlah.KeDesimal().truncate()} nomor seri (satu per unit), baru ${b.nomorSeri.length}.',
        );
      }
      for (final no in b.nomorSeri) {
        if (!dipakai.add('${b.uuidProduk}|${no.toUpperCase()}')) {
          throw GalatKasir('NomorSeriGanda', 'Nomor seri $no dipakai dua kali di keranjang ini.');
        }
      }
    }
  }

  /// F-16d bagian 2: baris paket sesi wajib berpelanggan (saldo sesinya milik pelanggan itu) dan jumlahnya bulat.
  static void ValidasiPaketSesi(Keranjang keranjang, KatalogLokal? katalog) {
    if (katalog == null) {
      return;
    }
    for (final b in keranjang.baris) {
      final produk = katalog.CariProduk(b.uuidProduk);
      if (produk == null || !produk.paketSesi) {
        continue;
      }
      if (keranjang.pelanggan == null) {
        throw GalatKasir(
          'PaketSesiTanpaPelanggan',
          '"${b.nama}" adalah paket sesi. Pilih pelanggan dulu agar sesinya tercatat atas namanya.',
        );
      }
      if (b.jumlah.KeDesimal() != b.jumlah.KeDesimal().truncate()) {
        throw GalatKasir('JumlahPaketSesiTidakValid', 'Jumlah paket "${b.nama}" harus bilangan bulat.');
      }
    }
  }

  /// Apotek bagian 2 (§9.5): obat wajib resep butuh [resep] yang lengkap (tanggal tidak setelah [hariIni], alamat pasien
  /// untuk psikotropika/narkotika), dan obat keras/psikotropika/narkotika hanya diserahkan pemegang izin
  /// `apotek.obat-keras.jual`: kasir sendiri, atau apoteker [uuidApoteker] yang lolos PIN. Barang bukan obat tidak
  /// pernah diblokir.
  static void ValidasiApotek({
    required Keranjang keranjang,
    required KatalogLokal? katalog,
    required StafLokal kasir,
    required String hariIni,
    ResepPenjualan? resep,
    String? uuidApoteker,
  }) {
    final syarat = AturanApotek.Periksa(keranjang, katalog);
    if (syarat.CekWajibResep) {
      if (resep == null) {
        throw GalatKasir(
          'ResepWajib',
          'Wajib resep dokter untuk ${SyaratApotek.SebutNama(syarat.barisWajibResep)}. Isi resep dulu.',
        );
      }
      final cek = AturanApotek.Susun(
        nomorResep: resep.nomorResep,
        tanggalResep: resep.tanggalResep,
        namaDokter: resep.namaDokter,
        noSipDokter: resep.noSipDokter ?? '',
        namaPasien: resep.namaPasien,
        umurPasien: resep.umurPasien ?? '',
        alamatPasien: resep.alamatPasien ?? '',
        hariIni: hariIni,
        wajibAlamat: syarat.wajibAlamat,
      );
      if (cek.galat != null) {
        throw GalatKasir('ResepTidakLengkap', cek.galat!);
      }
    }
    if (syarat.CekWajibApoteker && uuidApoteker == null && !kasir.PunyaIzin(IzinKasir.apotekObatKerasJual)) {
      throw GalatKasir(
        'ApotekerWajib',
        '${SyaratApotek.SebutNama(syarat.barisWajibApoteker)} hanya boleh diserahkan apoteker. Minta PIN apoteker.',
      );
    }
  }

  // Bayar & simpan -----------------------------------------------------------------------------------------------------

  Future<PenjualanTersimpan> Bayar({
    required Keranjang keranjang,
    required List<PembayaranMasukan> pembayaran,
    required StafLokal kasir,
    required KonteksPenjualan k,
    String? uuidPenyetujuTempo,
    Uang? saldoDeposit,
    KatalogLokal? katalog,
    bool latihan = false,
    ResepPenjualan? resep,
    String? uuidApoteker,
    BagianTagihan? bagianTagihan,
  }) async {
    final shift = await repositori.AmbilShiftAktif();
    if (shift == null) {
      throw const GalatKasir('ShiftTidakDitemukan', 'Belum ada shift terbuka. Buka shift dulu sebelum berjualan.');
    }
    if (!kasir.PunyaIzin(IzinKasir.penjualanBuat)) {
      throw GalatKasir('TanpaIzin', '${kasir.nama} tidak punya izin berjualan.');
    }
    if (keranjang.CekKosong) {
      throw const GalatKasir('KeranjangKosong', 'Keranjang masih kosong. Tambahkan produk dulu.');
    }
    final kodeOutlet = k.kodeOutlet ?? '';
    final kodePerangkat = k.kodePerangkat ?? '';
    if (kodeOutlet.isEmpty || kodePerangkat.isEmpty) {
      throw const GalatKasir(
        'DataAwalBelumLengkap',
        'Kode outlet atau perangkat belum ada di perangkat ini. Sambungkan ke internet agar data terbaru terunduh.',
      );
    }

    ValidasiPembayaran(pembayaran);
    final hitungan = Hitung(
      keranjang,
      k,
      pembayaran: [for (final p in pembayaran) p.KeKalkulasi()],
      metodeBayar: AmbilMetodeBayar(k, [for (final p in pembayaran) p.metode]),
    );
    final hasil = hitungan.hasil;
    final nonTunai = pembayaran.where((p) => !p.CekTunai()).fold(Uang.Nol(), (t, p) => t.Tambah(p.jumlah));
    if (nonTunai.Bandingkan(hasil.totalAkhir) > 0) {
      throw const GalatKasir('PembayaranMelebihiTotal', 'Pembayaran non-tunai melebihi total belanja.');
    }
    final dibayar = pembayaran.fold(Uang.Nol(), (t, p) => t.Tambah(p.jumlah));
    if (dibayar.Bandingkan(hasil.totalAkhir) < 0) {
      throw GalatKasir('PembayaranKurang', 'Pembayaran kurang ${hasil.totalAkhir.Kurangi(dibayar).FormatRupiah()}.');
    }
    final penyetuju = ValidasiDiskon(keranjang, hitungan, kasir, k);
    ValidasiTukarPoin(keranjang, hasil);
    ValidasiTempo(keranjang, pembayaran, kasir, k, uuidPenyetujuTempo);
    ValidasiUangMuka(keranjang, pembayaran);
    ValidasiDeposit(keranjang, pembayaran, saldoDeposit);
    ValidasiGiftCard(pembayaran);
    ValidasiPaketSesi(keranjang, katalog);
    ValidasiNomorSeri(keranjang, katalog);
    ValidasiApotek(
      keranjang: keranjang,
      katalog: katalog,
      kasir: kasir,
      hariIni: AturanApotek.HitungHariIni(_jam(), k.zonaWaktu),
      resep: resep,
      uuidApoteker: uuidApoteker,
    );
    if (latihan) {
      return _SelesaikanLatihan(keranjang, pembayaran, hitungan, dibayar);
    }

    final sekarang = _jam().toUtc();
    final t = hitungan.tanggalBisnis;
    final yymmdd = '${t.substring(2, 4)}${t.substring(5, 7)}${t.substring(8, 10)}';
    // F-16c bagian 2: voucher dipesan server untuk Uuid penjualan ini.
    final uuid = keranjang.voucher?.uuidPenjualan ?? BuatUuid();
    final uuidPembayaran = [for (final _ in pembayaran) BuatUuid()];
    final kembalian = hasil.kembalian ?? Uang.Nol();

    // K-11: tukar barang = retur & penjualan pengganti tersimpan atomik, retur lebih dulu (urutan outbox = urutan
    // diterima server, sehingga penjualan pengganti selalu menemukan returnya).
    final tukar = keranjang.tukar;
    final dipakaiTukar = tukar?.HitungDipakai(hasil.totalAkhir);
    if (tukar != null) {
      final bayarTukar = pembayaran.where((p) => p.metode.Jenis == JenisMetodeBayar.tukar).toList();
      if (tukar.tanpaStruk && dipakaiTukar!.Bandingkan(tukar.nilai) < 0) {
        throw GalatKasir(
          'TukarKurang',
          'Retur tanpa struk tidak bisa dikembalikan tunai. Tambah barang pengganti sampai minimal '
              '${tukar.nilai.FormatRupiah()}.',
        );
      }
      if (bayarTukar.length != 1 || !bayarTukar.single.jumlah.SamaDengan(dipakaiTukar!)) {
        throw GalatKasir(
          'TukarTidakSesuai',
          'Nilai tukar barang yang dipakai harus ${dipakaiTukar!.FormatRupiah()}. Buka ulang pembayaran.',
        );
      }
    } else if (pembayaran.any((p) => p.metode.Jenis == JenisMetodeBayar.tukar)) {
      throw const GalatKasir('TukarTanpaRetur', 'Pembayaran tukar barang hanya dari layar retur.');
    }
    late final DokumenPenjualan dokumen;
    String? nomorReturTukar;
    await repositoriPenjualan.db.transaction(() async {
      if (tukar != null) {
        nomorReturTukar = await tukar.simpanRetur(tukar: dipakaiTukar!, tunai: tukar.nilai.Kurangi(dipakaiTukar));
      }
      dokumen = await repositoriPenjualan.SimpanPenjualan(
        kodePerangkat: kodePerangkat,
        tanggal: yymmdd,
        sekarang: sekarang,
        susun: (urut) {
          final nomor = 'INV/$kodeOutlet/$yymmdd/$kodePerangkat-${urut.toString().padLeft(4, '0')}';
          return SusunDokumen(
            uuid: uuid,
            nomor: nomor,
            shift: shift,
            kasir: kasir,
            keranjang: keranjang,
            hitungan: hitungan,
            pembayaran: pembayaran,
            uuidPembayaran: uuidPembayaran,
            penyetuju: penyetuju,
            k: k,
            sekarang: sekarang,
            uuidPenyetujuTempo: uuidPenyetujuTempo,
            katalog: katalog,
            resep: resep,
            uuidApoteker: uuidApoteker,
            bagianTagihan: bagianTagihan,
          );
        },
      );
    });
    final tempo = pembayaran.where((p) => p.metode.Jenis == JenisMetodeBayar.tempo).firstOrNull;
    final uuidPelanggan = keranjang.pelanggan?.uuid;
    if (tempo != null && uuidPelanggan != null) {
      await repositoriPelanggan?.TambahSisaPiutang(uuidPelanggan, tempo.jumlah.KeString());
    }
    // F-16c bagian 3: jumlah transaksi & pemakaian promo pelanggan di cache ikut bertambah (promo offline berikutnya).
    if (uuidPelanggan != null) {
      await repositoriPelanggan?.CatatTransaksi(uuidPelanggan, hitungan.tanggalBisnis, [
        for (final p in hitungan.promoTerpakai) p.uuid,
      ]);
    }

    return PenjualanTersimpan(
      uuid: uuid,
      nomor: dokumen.penjualan.Nomor.value,
      totalAkhir: hasil.totalAkhir,
      totalDibayar: dibayar,
      kembalian: kembalian,
      pembayaran: pembayaran,
      namaPelanggan: keranjang.pelanggan?.nama,
      labelPoin: hitungan.AmbilLabelPoinBerlipat(),
      nomorAntrian: dokumen.penjualan.NomorAntrian.value,
      namaPemesan: dokumen.penjualan.NamaPemesan.value,
      nomorReturTukar: nomorReturTukar,
      kembalianTukar: tukar?.nilai.Kurangi(dipakaiTukar!),
      bagianTagihan: bagianTagihan,
    );
  }

  /// Metode bayar yang boleh dipakai di mode latihan: yang tidak menyentuh server, piutang, atau saldo pelanggan.
  static const Set<String> metodeLatihan = {
    JenisMetodeBayar.tunai,
    JenisMetodeBayar.qrisStatis,
    JenisMetodeBayar.edc,
    JenisMetodeBayar.transfer,
    JenisMetodeBayar.ewallet,
  };

  /// K-23 (POS-18): hasil transaksi latihan setelah semua validasi kasir lolos. Tidak ada yang ditulis ke basis data,
  /// outbox, kas shift, stok, piutang, poin, atau server; nomor bertanda `LATIHAN`. Dokumen yang terkait server
  /// (pesanan meja, voucher, reservasi, pre-order, laundry, tukar barang) dan metode tempo/deposit/QRIS dinamis/
  /// marketplace ditolak agar latihan tidak meninggalkan jejak di data nyata.
  PenjualanTersimpan _SelesaikanLatihan(
    Keranjang keranjang,
    List<PembayaranMasukan> pembayaran,
    HitunganKeranjang hitungan,
    Uang dibayar,
  ) {
    if (keranjang.pesananMeja != null ||
        keranjang.voucher != null ||
        keranjang.reservasi != null ||
        keranjang.perintahKerja != null ||
        keranjang.praPesan != null ||
        keranjang.laundry != null ||
        keranjang.tukar != null) {
      throw const GalatKasir(
        'TidakUntukLatihan',
        'Mode latihan hanya untuk penjualan biasa. Hapus voucher atau pesanan meja/reservasi/servis/pre-order/laundry/tukar.',
      );
    }
    final metode = pembayaran.where((p) => !metodeLatihan.contains(p.metode.Jenis)).firstOrNull;
    if (metode != null) {
      throw GalatKasir('TidakUntukLatihan', 'Metode ${metode.metode.Nama} tidak bisa dipakai di mode latihan.');
    }
    final hasil = hitungan.hasil;
    return PenjualanTersimpan(
      uuid: BuatUuid(),
      nomor: 'LATIHAN-${_jam().toLocal().millisecondsSinceEpoch % 100000}',
      totalAkhir: hasil.totalAkhir,
      totalDibayar: dibayar,
      kembalian: hasil.kembalian ?? Uang.Nol(),
      pembayaran: pembayaran,
      namaPelanggan: keranjang.pelanggan?.nama,
      latihan: true,
    );
  }

  /// Server membatasi `Pembayaran.*.Referensi` paling panjang 100 karakter. EDC menggabungkan bank & nomor approval
  /// (`bank | approval`), jadi masing-masing dibatasi agar gabungannya ≤ 100 (40 + 3 + 56 = 99).
  static const int panjangMaksReferensi = 100;
  static const int panjangMaksBankEdc = 40;
  static const int panjangMaksApprovalEdc = 56;

  /// Referensi EDC `bank | approval` (bank opsional); tiap bagian dipangkas ke batasnya.
  static String SusunReferensiEdc(String bank, String approval) {
    String Pangkas(String teks, int maks) {
      final rapi = teks.trim();
      return rapi.runes.length <= maks ? rapi : String.fromCharCodes(rapi.runes.take(maks)).trim();
    }

    return [
      Pangkas(bank, panjangMaksBankEdc),
      Pangkas(approval, panjangMaksApprovalEdc),
    ].where((t) => t.isNotEmpty).join(' | ');
  }

  /// Aturan pembayaran fase 1 (Rincian F-07b langkah 9) yang bisa diperiksa sebelum dihitung.
  static void ValidasiPembayaran(List<PembayaranMasukan> pembayaran) {
    if (pembayaran.isEmpty) {
      throw const GalatKasir('PembayaranKurang', 'Pilih metode pembayaran dulu.');
    }
    if (pembayaran.where((p) => p.CekTunai()).length > 1) {
      throw const GalatKasir('TunaiGanda', 'Pembayaran tunai hanya boleh satu kali per transaksi.');
    }
    for (final p in pembayaran) {
      if (!JenisMetodeBayar.fase1.contains(p.metode.Jenis) &&
          p.metode.Jenis != JenisMetodeBayar.uangMuka &&
          p.metode.Jenis != JenisMetodeBayar.tukar) {
        throw GalatKasir('MetodeBayarBelumDidukung', 'Metode ${p.metode.Nama} belum didukung aplikasi kasir.');
      }
      if (p.jumlah.Bandingkan(Uang.Nol()) <= 0) {
        throw GalatKasir('JumlahTidakValid', 'Jumlah pembayaran ${p.metode.Nama} harus lebih dari Rp 0.');
      }
      if ((p.referensi?.runes.length ?? 0) > panjangMaksReferensi) {
        throw GalatKasir(
          'ReferensiTerlaluPanjang',
          'Referensi ${p.metode.Nama} paling panjang $panjangMaksReferensi karakter.',
        );
      }
    }
  }

  /// v3.52 (§9.2): nomor antrian penjualan bayar-dulu di outlet yang menawarkan jenis pesanan (FnB) = urut harian
  /// perangkat dari nomor BR-07.1 (`…-0042` → `042`), jadi tidak butuh sekuens baru dan tetap unik per perangkat per
  /// hari offline. Pesanan meja, pengambilan pre-order, dan reservasi tidak bernomor antrian (sudah punya nama/meja).
  /// K26: bila perangkat punya awalan huruf (diatur di back-office), nomor jadi `A-042` supaya dua kasir di satu outlet
  /// tidak memanggil nomor yang sama (maks. 10 karakter di server).
  static String? AmbilNomorAntrian(String nomor, Keranjang keranjang, KonteksPenjualan k) {
    if (k.jenisPesanan.isEmpty ||
        keranjang.pesananMeja != null ||
        keranjang.praPesan != null ||
        keranjang.reservasi != null ||
        keranjang.perintahKerja != null) {
      return null;
    }
    final urut = int.tryParse(nomor.split('-').last)?.toString().padLeft(3, '0');
    final awalan = k.awalanAntrian;
    return urut == null || awalan == null || awalan.isEmpty ? urut : '$awalan-$urut';
  }

  /// Susun penjualan lokal + payload outbox `Penjualan.Buat` persis kontrak Rincian F-07b (kunci PascalCase, uang
  /// string desimal, jumlah string desimal).
  static DokumenPenjualan SusunDokumen({
    required String uuid,
    required String nomor,
    required BarisShift shift,
    required StafLokal kasir,
    required Keranjang keranjang,
    required HitunganKeranjang hitungan,
    required List<PembayaranMasukan> pembayaran,
    required List<String> uuidPembayaran,
    required PenyetujuDiskon? penyetuju,
    required KonteksPenjualan k,
    required DateTime sekarang,
    String? uuidPenyetujuTempo,
    KatalogLokal? katalog,
    ResepPenjualan? resep,
    String? uuidApoteker,
    BagianTagihan? bagianTagihan,
  }) {
    final hasil = hitungan.hasil;
    // Apotek (§9.5): baris wajib resep yang ditutup resep ini ditandai `DenganResep` (server memakai tanda perangkat).
    final denganResep = resep == null
        ? const <String>{}
        : {for (final b in AturanApotek.Periksa(keranjang, katalog).barisWajibResep) b.uuid};
    final perintahKerja = keranjang.perintahKerja;
    final kembalian = hasil.kembalian ?? Uang.Nol();
    final dibayar = pembayaran.fold(Uang.Nol(), (t, p) => t.Tambah(p.jumlah));
    final pembulatan = k.pembulatanTunai;
    final catatan = keranjang.catatan?.trim();
    final pesananMeja = keranjang.pesananMeja;
    final kanal = AmbilKanal(keranjang).name;
    final nomorAntrian = AmbilNomorAntrian(nomor, keranjang, k);
    final namaPemesan = keranjang.namaPemesan?.trim();

    final data = <String, Object?>{
      'UuidShift': shift.Uuid,
      'UuidPengguna': kasir.uuid,
      'Nomor': nomor,
      'Kanal': kanal,
      'DibuatPada': sekarang.toIso8601String(),
      'HargaTermasukPajak': k.profilPajak.hargaTermasukPajak,
      'PersenBiayaLayanan': k.AmbilPersenBiayaLayanan().toString(),
      'PembulatanTunai': pembulatan == null ? null : {'Kelipatan': pembulatan.kelipatan, 'Arah': pembulatan.arah.name},
      // F-17 bagian 3: dikirim hanya bila ada ongkirnya, supaya muatan penjualan biasa tidak berubah sama sekali. Yang
      // dikirim hasil hitungan (bukan isi keranjang): `DiskonKirim` sudah memuat potongan promo gratis ongkir.
      if (!hasil.biayaKirim.BernilaiNol()) 'BiayaKirim': hasil.biayaKirim.KeString(),
      if (!hasil.diskonKirim.BernilaiNol()) 'DiskonKirim': hasil.diskonKirim.KeString(),
      'Pajak': [
        for (final p in hitungan.pajakDokumen)
          {
            'Kode': p.kode,
            'Tarif': hitungan.tarifDipakai[p.kode]!.tarif,
            'PengaliDppPembilang': hitungan.tarifDipakai[p.kode]!.pembilang,
            'PengaliDppPenyebut': hitungan.tarifDipakai[p.kode]!.penyebut,
            'DasarPengenaan': p.dasarPengenaan.name,
            'KenaBiayaKirim': p.kenaBiayaKirim,
          },
      ],
      'Baris': [
        for (var i = 0; i < keranjang.baris.length; i++)
          {
            'Uuid': keranjang.baris[i].uuid,
            'UuidProduk': keranjang.baris[i].uuidProduk,
            'UuidProdukSatuan': keranjang.baris[i].uuidProdukSatuan,
            'Jumlah': keranjang.baris[i].jumlah.KeString(),
            'HargaSatuan': keranjang.baris[i].hargaSatuan.KeString(),
            'HargaPilihan': keranjang.baris[i].AmbilHargaPilihan().KeString(),
            'Pilihan': [for (final p in keranjang.baris[i].pilihan) p.KeJson()],
            'HargaTermasukPajak': keranjang.baris[i].hargaTermasukPajak,
            'KodePajak': hitungan.kodePajakBaris[i],
            'DiskonManual': keranjang.baris[i].diskon?.KeJson(),
            'Catatan': keranjang.baris[i].catatan,
            // Harga manual (`pos.harga.timpa`): hanya dikirim bila kasir mengubah harga dari price engine.
            if (keranjang.baris[i].hargaAsli != null)
              'HargaManual': {
                'HargaAsli': keranjang.baris[i].hargaAsli!.KeString(),
                'Alasan': keranjang.baris[i].alasanHarga ?? '',
              },
            if (keranjang.baris[i].staf.isNotEmpty) 'Staf': keranjang.baris[i].staf,
            if (keranjang.baris[i].nomorSeri.isNotEmpty) 'NomorSeri': keranjang.baris[i].nomorSeri,
            if (denganResep.contains(keranjang.baris[i].uuid)) 'DenganResep': true,
            if (keranjang.baris[i].racikan != null) 'Racikan': keranjang.baris[i].racikan!.KeMuatan(),
          },
      ],
      'DiskonManualPesanan': keranjang.diskonPesanan?.KeJson(),
      'UuidPenyetujuDiskon': penyetuju?.uuid,
      'UuidPenyetujuHarga': ?keranjang.baris.map((b) => b.penyetujuHarga?.uuid).nonNulls.firstOrNull,
      'Pembayaran': [
        for (var i = 0; i < pembayaran.length; i++)
          {
            'Uuid': uuidPembayaran[i],
            'UuidMetodePembayaran': pembayaran[i].metode.Uuid,
            'Jumlah': pembayaran[i].jumlah.KeString(),
            'Referensi': pembayaran[i].referensi,
          },
      ],
      'Ringkasan': {
        'Subtotal': hasil.subtotal.KeString(),
        'TotalPajak': hasil.totalPajak.KeString(),
        'Pembulatan': hasil.pembulatan.KeString(),
        'TotalAkhir': hasil.totalAkhir.KeString(),
        'Kembalian': kembalian.KeString(),
      },
      'Catatan': catatan == null || catatan.isEmpty ? null : catatan,
      // v3.52 (§9.2): nomor panggil & nama pemesan; hanya dikirim bila ada (muatan penjualan retail tidak berubah).
      'NomorAntrian': ?nomorAntrian,
      if (namaPemesan != null && namaPemesan.isNotEmpty) 'NamaPemesan': namaPemesan,
      'UuidPesananTerbuka': ?pesananMeja?.uuid,
      'UuidPelanggan': ?keranjang.pelanggan?.uuid,
      'TukarPoin': ?keranjang.tukarPoin?.KeJson(),
      'UuidPenyetujuTempo': ?uuidPenyetujuTempo,
      'Voucher': ?keranjang.voucher?.kode,
      if (keranjang.praPesan case final praPesan?) praPesan.sumber.KunciOutbox: praPesan.uuid,
      'UuidReservasi': ?keranjang.reservasi?.uuid,
      // Bengkel bagian 2: perintah kerja yang ditagih (server menandainya Ditagih; mekanik = `Baris[].Staf`).
      'UuidPerintahKerja': ?perintahKerja?.uuid,
      // Apotek bagian 2: resep dokter & apoteker yang menyerahkan (hanya bila bukan kasirnya sendiri).
      'Resep': ?resep?.KeJson(),
      'UuidApoteker': ?uuidApoteker,
      // K-11: retur tukar barang yang nilainya membayar penjualan ini.
      'UuidReturTukar': ?keranjang.tukar?.uuidRetur,
      'Laundry': ?keranjang.laundry?.KeJson(),
      // Cetak struk bagian 4c: penjualan langsung di outlet berstasiun dapur dikirim ke dapur (mode cepat, bayar dulu).
      if (k.kirimDapurLangsung && pesananMeja == null && keranjang.praPesan == null) 'KirimDapur': true,
      if (hitungan.promoTerpakai.isNotEmpty)
        'Promo': [
          for (final p in hitungan.promoTerpakai)
            {
              'UuidPromo': p.uuid,
              'Kode': p.kode,
              'DiskonBaris': [
                for (final MapEntry(:key, :value) in p.diskonBaris.entries)
                  {'UuidBaris': keranjang.baris[key].uuid, 'Jumlah': value.KeString()},
              ],
              'DiskonPesanan': p.diskonPesanan.KeString(),
            },
        ],
    };

    return DokumenPenjualan(
      penjualan: PenjualanCompanion.insert(
        Uuid: uuid,
        Nomor: nomor,
        UuidShift: shift.Uuid,
        UuidPengguna: kasir.uuid,
        NamaKasir: kasir.nama,
        Kanal: kanal,
        DibuatPada: sekarang,
        TanggalBisnis: hitungan.tanggalBisnis,
        Status: statusLunas,
        Subtotal: hasil.subtotal.KeString(),
        TotalDiskon: hasil.totalDiskon.KeString(),
        BiayaLayanan: hasil.biayaLayanan.KeString(),
        BiayaKirim: Value(hasil.biayaKirim.KeString()),
        DiskonKirim: Value(hasil.diskonKirim.KeString()),
        TotalPajak: hasil.totalPajak.KeString(),
        Pembulatan: hasil.pembulatan.KeString(),
        TotalAkhir: hasil.totalAkhir.KeString(),
        TotalDibayar: dibayar.KeString(),
        Kembalian: kembalian.KeString(),
        UuidPenyetujuDiskon: Value(penyetuju?.uuid),
        Catatan: Value(data['Catatan'] as String?),
        Laundry: Value(keranjang.laundry == null ? null : jsonEncode(keranjang.laundry!.KeJson())),
        NomorAntrian: Value(nomorAntrian),
        NamaPemesan: Value(data['NamaPemesan'] as String?),
        PerintahKerja: Value(
          perintahKerja == null
              ? null
              : jsonEncode({
                  'Uuid': perintahKerja.uuid,
                  'Nomor': perintahKerja.nomor,
                  'NomorPolisi': perintahKerja.nomorPolisi,
                  if (perintahKerja.unitPerangkat) ...{
                    'JenisUnit': perintahKerja.jenisUnit,
                    'LabelUnit': perintahKerja.labelKendaraan,
                  },
                }),
        ),
        Resep: Value(resep == null ? null : jsonEncode(resep.KeRingkasanStruk())),
        BagianTagihan: Value(bagianTagihan == null ? null : jsonEncode(bagianTagihan.KeJson())),
      ),
      detail: [
        for (var i = 0; i < keranjang.baris.length; i++)
          PenjualanDetailCompanion.insert(
            Uuid: keranjang.baris[i].uuid,
            UuidPenjualan: uuid,
            Urutan: i + 1,
            UuidProduk: keranjang.baris[i].uuidProduk,
            UuidProdukSatuan: Value(keranjang.baris[i].uuidProdukSatuan),
            NamaProduk: keranjang.baris[i].nama,
            NamaSatuan: Value(keranjang.baris[i].namaSatuan),
            Jumlah: keranjang.baris[i].jumlah.KeString(),
            HargaSatuan: keranjang.baris[i].hargaSatuan.KeString(),
            HargaPilihan: keranjang.baris[i].AmbilHargaPilihan().KeString(),
            Pilihan: jsonEncode([for (final p in keranjang.baris[i].pilihan) p.KeJson()]),
            Bruto: hasil.baris[i].bruto.KeString(),
            Diskon: hasil.baris[i].diskon.KeString(),
            DiskonPesanan: hasil.baris[i].diskonPesanan.KeString(),
            BiayaLayanan: hasil.baris[i].biayaLayanan.KeString(),
            JumlahPajak: hasil.baris[i].pajak.KeString(),
            PajakEksklusif: hasil.baris[i].pajakEksklusif.KeString(),
            TotalBaris: hasil.baris[i].totalBaris.KeString(),
            Catatan: Value(keranjang.baris[i].catatan),
            NomorSeri: Value(keranjang.baris[i].nomorSeri.isEmpty ? null : jsonEncode(keranjang.baris[i].nomorSeri)),
            MasaGaransiBulan: Value(
              keranjang.baris[i].nomorSeri.isEmpty
                  ? null
                  : katalog?.CariProduk(keranjang.baris[i].uuidProduk)?.masaGaransiBulan,
            ),
            Racikan: Value(
              keranjang.baris[i].racikan == null ? null : jsonEncode(keranjang.baris[i].racikan!.KeJson()),
            ),
          ),
      ],
      pembayaran: [
        for (var i = 0; i < pembayaran.length; i++)
          PenjualanPembayaranCompanion.insert(
            Uuid: uuidPembayaran[i],
            UuidPenjualan: uuid,
            UuidMetodePembayaran: pembayaran[i].metode.Uuid,
            Jenis: pembayaran[i].metode.Jenis,
            NamaMetode: pembayaran[i].metode.Nama,
            Jumlah: pembayaran[i].jumlah.KeString(),
            Referensi: Value(pembayaran[i].referensi),
          ),
      ],
      outbox: ItemOutbox(jenis: jenisOutbox, uuid: uuid, data: data),
      uuidPesananTerbuka: pesananMeja?.uuid,
    );
  }

  // Pesanan tertahan ---------------------------------------------------------------------------------------------------

  /// Simpan keranjang sebagai pesanan tertahan lokal (tidak dikirim ke server, Rincian F-07b).
  Future<void> TahanPesanan(Keranjang keranjang, StafLokal kasir, Uang total) async {
    if (keranjang.CekKosong) {
      throw const GalatKasir('KeranjangKosong', 'Keranjang masih kosong.');
    }
    final sekarang = _jam().toUtc();
    final lokal = sekarang.toLocal();
    final jam = '${lokal.hour.toString().padLeft(2, '0')}.${lokal.minute.toString().padLeft(2, '0')}';
    await repositoriPenjualan.SimpanPesananTertahan(
      PesananTertahanCompanion.insert(
        Uuid: BuatUuid(),
        Label:
            '${keranjang.baris.first.nama}${keranjang.baris.length > 1 ? ' +${keranjang.baris.length - 1}' : ''} | $jam',
        Data: jsonEncode(keranjang.KeJson()),
        Total: total.KeString(),
        JumlahItem: keranjang.baris.length,
        UuidPengguna: kasir.uuid,
        DibuatPada: sekarang,
      ),
    );
  }

  /// Buka pesanan tertahan: kembalikan keranjangnya dan hapus dari daftar tertahan.
  Future<Keranjang> BukaPesanan(String uuid) async {
    final baris = await repositoriPenjualan.CariPesananTertahan(uuid);
    if (baris == null) {
      throw const GalatKasir('PesananTidakDitemukan', 'Pesanan tertahan ini sudah dibuka atau dibatalkan.');
    }
    final keranjang = Keranjang.DariJson(jsonDecode(baris.Data) as Map<String, Object?>);
    await repositoriPenjualan.HapusPesananTertahan(uuid);
    return keranjang;
  }
}
