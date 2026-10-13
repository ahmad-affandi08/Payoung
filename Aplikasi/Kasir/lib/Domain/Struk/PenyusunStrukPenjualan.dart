import 'dart:convert';

import 'package:adaptor_perangkat/AdaptorPerangkat.dart';
import 'package:mesin_kasir/MesinKasir.dart';

import '../../Data/BasisData/BasisDataKasir.dart';
import '../../Data/RepositoriPenjualan.dart';
import '../Penjualan/AturanApotek.dart';
import '../Penjualan/Keranjang.dart';
import '../Penjualan/KonteksPenjualan.dart';
import '../Penjualan/LayananPenjualan.dart';
import '../Penjualan/Racikan.dart';
import 'IdentitasStruk.dart';

/// Isi satu penjualan untuk dicetak (dari tabel lokal, jadi bisa offline dan dicetak ulang).
class DataStrukPenjualan {
  const DataStrukPenjualan({
    required this.penjualan,
    required this.detail,
    required this.pembayaran,
    this.namaPelanggan,
    this.labelPoin,
    this.laundry,
    this.awalanLacakLaundry,
  });

  final BarisPenjualan penjualan;
  final List<BarisPenjualanDetail> detail;
  final List<BarisPenjualanPembayaran> pembayaran;

  /// Diketahui saat struk dicetak langsung setelah bayar; tidak disimpan lokal, jadi cetak ulang tanpa nama pelanggan.
  final String? namaPelanggan;

  /// F-16c bagian 4a: promo poin berlipat yang berlaku (seperti nama pelanggan, hanya saat dicetak setelah bayar).
  final String? labelPoin;

  /// Laundry (§9.9): tiket laundry penjualan ini (tersimpan lokal, jadi ikut cetak ulang) dan awalan tautan lacak.
  final LaundryKeranjang? laundry;
  final String? awalanLacakLaundry;
}

/// Menyusun struk penjualan (POS-11, PRD v1.79) sesuai pengaturan struk tenant. Angka memakai format Indonesia tanpa
/// "Rp" di baris rincian agar muat di kertas 58 mm; total memakai "Rp". Cetak ulang diberi tanda "CETAK ULANG"
/// (anti-fraud) dan penjualan void diberi tanda "DIBATALKAN". QR struk digital dicetak bila diaktifkan tenant.
abstract final class PenyusunStrukPenjualan {
  static const String penutupBawaan = 'Terima kasih atas kunjungan Anda';
  static const String tandaAir = 'Dibuat dengan Payoung';

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

  static DokumenStruk Susun(
    IdentitasStruk identitas,
    DataStrukPenjualan data, {
    bool cetakUlang = false,
    bool bukaLaci = false,
  }) {
    final p = identitas.pengaturan;
    final jual = data.penjualan;
    final baris = <BarisStruk>[...SusunKepala(identitas)];

    if (cetakUlang) {
      baris.add(const BarisTeks('CETAK ULANG', rata: RataStruk.Tengah, tebal: true));
    }
    if (jual.Status == StatusPenjualanLokal.divoid) {
      baris.add(const BarisTeks('DIBATALKAN', rata: RataStruk.Tengah, tebal: true));
    }
    final waktu = jual.DibuatPada.toLocal();
    baris
      ..add(const BarisGaris())
      ..add(BarisTeks(jual.Nomor))
      ..add(
        BarisDuaKolom(
          '${waktu.day} ${_bulan[waktu.month - 1]} ${waktu.year}',
          '${_Dua(waktu.hour)}.${_Dua(waktu.minute)}',
        ),
      );
    if (p.tampilkanKasir) {
      baris.add(BarisTeks('Kasir: ${jual.NamaKasir}'));
    }
    final pelanggan = data.namaPelanggan?.trim();
    if (p.tampilkanPelanggan && pelanggan != null && pelanggan.isNotEmpty) {
      baris.add(BarisTeks('Pelanggan: $pelanggan'));
    }
    // Bengkel bagian 2: perintah kerja yang ditagih & nomor polisi kendaraannya (atau perangkat yang diservis, D-142) (tersimpan lokal, ikut cetak ulang).
    if (_UraiPeta(jual.PerintahKerja) case final pk?) {
      baris.add(BarisTeks('Perintah kerja ${pk['Nomor'] ?? ''}'));
      if (pk['JenisUnit'] == 'Perangkat') {
        if (pk['LabelUnit'] case final String unit when unit.trim().isNotEmpty) {
          baris.add(BarisTeks('Perangkat $unit'));
        }
      } else if (pk['NomorPolisi'] case final String polisi when polisi.trim().isNotEmpty) {
        baris.add(BarisTeks('Kendaraan $polisi'));
      }
    }
    // Apotek bagian 2: nomor resep & dokter penulis. Data pasien sengaja tidak dicetak (struk bisa berpindah tangan).
    if (_UraiPeta(jual.Resep) case final resep?) {
      baris.add(
        BarisTeks(AturanApotek.SusunBarisStruk('${resep['NomorResep'] ?? ''}', '${resep['NamaDokter'] ?? ''}')),
      );
    }
    // X8: pesanan platform ojol dicetak jelas agar mudah dicocokkan dengan pengemudi; nomor pesanan dari referensi
    // pembayaran platform bila diisi kasir.
    final kanal = KanalPenjualan.values.where((k) => k.name == jual.Kanal).firstOrNull;
    if (kanal != null && LayananPenjualan.kanalPlatform.contains(kanal)) {
      final nomorPesanan = data.pembayaran
          .where((b) => b.Jenis == JenisMetodeBayar.marketplace)
          .map((b) => b.Referensi?.trim())
          .where((r) => r != null && r.isNotEmpty)
          .firstOrNull;
      baris.add(
        BarisTeks(
          'Pesanan ${LayananPenjualan.AmbilLabelKanal(kanal)}${nomorPesanan == null ? '' : ' #$nomorPesanan'}',
          tebal: true,
        ),
      );
    } else if (kanal case KanalPenjualan.MakanDiTempat || KanalPenjualan.Antar) {
      // v3.51: jenis pesanan FnB dicetak supaya pramusaji/kurir tahu pesanan disajikan atau diantar.
      baris.add(BarisTeks(LayananPenjualan.AmbilLabelKanal(kanal!), tebal: true));
    }
    // v3.52 (§9.2): nomor antrian besar di struk bayar-dulu supaya pembeli tahu nomor yang dipanggil.
    if (jual.NomorAntrian case final antrian?) {
      baris.add(BarisTeks('ANTRIAN $antrian', rata: RataStruk.Tengah, tebal: true, besar: true));
      if (jual.NamaPemesan case final nama?) {
        baris.add(BarisTeks(nama, rata: RataStruk.Tengah, tebal: true));
      }
    }
    baris.add(const BarisGaris());

    for (final d in data.detail) {
      baris.add(BarisTeks(d.NamaSatuan == null ? d.NamaProduk : '${d.NamaProduk} (${d.NamaSatuan})'));
      for (final nama in _NamaPilihan(d.Pilihan)) {
        baris.add(BarisTeks('  + $nama'));
      }
      final harga = Uang.Dari(d.HargaSatuan).Tambah(Uang.Dari(d.HargaPilihan));
      baris.add(BarisDuaKolom('  ${_Jumlah(d.Jumlah)} x ${_Angka(harga)}', _Angka(Uang.Dari(d.Bruto))));
      final diskon = Uang.Dari(d.Diskon);
      if (!diskon.BernilaiNol()) {
        baris.add(BarisDuaKolom('  Diskon', '-${_Angka(diskon)}'));
      }
      final nomorSeri = d.NomorSeri == null
          ? const <String>[]
          : (jsonDecode(d.NomorSeri!) as List<Object?>).whereType<String>();
      if (nomorSeri.isNotEmpty) {
        baris.add(BarisTeks('  No. seri: ${nomorSeri.join(', ')}'));
        final garansi = d.MasaGaransiBulan;
        if (garansi != null) {
          baris.add(BarisTeks('  Garansi sampai ${_TanggalGaransi(jual.TanggalBisnis, garansi)}'));
        }
      }
      // Apotek bagian 4: nama racikan, jumlah kemasan, dan aturan pakai (komposisi tidak dicetak; ada di rincian).
      final racikan = d.Racikan == null ? null : RacikanBaris.DariJson(jsonDecode(d.Racikan!));
      if (racikan != null) {
        baris.add(BarisTeks('  Racikan: ${racikan.nama}'));
        baris.add(BarisTeks('  ${racikan.Ringkasan}'));
      }
      final catatan = d.Catatan?.trim();
      if (catatan != null && catatan.isNotEmpty) {
        baris.add(BarisTeks('  Catatan: $catatan'));
      }
    }

    final diskonPesanan = data.detail.fold(Uang.Nol(), (s, d) => s.Tambah(Uang.Dari(d.DiskonPesanan)));
    final pajakEksklusif = data.detail.fold(Uang.Nol(), (s, d) => s.Tambah(Uang.Dari(d.PajakEksklusif)));
    final totalPajak = Uang.Dari(jual.TotalPajak);
    final biayaLayanan = Uang.Dari(jual.BiayaLayanan);
    final pembulatan = Uang.Dari(jual.Pembulatan);
    final biayaKirim = Uang.Dari(jual.BiayaKirim);
    final diskonKirim = Uang.Dari(jual.DiskonKirim);
    baris
      ..add(const BarisGaris())
      ..add(BarisDuaKolom('Subtotal', _Angka(Uang.Dari(jual.Subtotal))));
    if (!diskonPesanan.BernilaiNol()) {
      baris.add(BarisDuaKolom('Diskon', '-${_Angka(diskonPesanan)}'));
    }
    if (!biayaLayanan.BernilaiNol()) {
      baris.add(BarisDuaKolom('Biaya layanan', _Angka(biayaLayanan)));
    }
    if (!biayaKirim.BernilaiNol()) {
      baris.add(BarisDuaKolom('Ongkir', _Angka(biayaKirim)));
    }
    if (!diskonKirim.BernilaiNol()) {
      baris.add(BarisDuaKolom('Diskon ongkir', '-${_Angka(diskonKirim)}'));
    }
    if (!pajakEksklusif.BernilaiNol()) {
      baris.add(BarisDuaKolom('Pajak', _Angka(pajakEksklusif)));
    }
    if (!pembulatan.BernilaiNol()) {
      baris.add(
        BarisDuaKolom('Pembulatan', pembulatan.BernilaiNegatif() ? '-${_Angka(pembulatan)}' : _Angka(pembulatan)),
      );
    }
    baris.add(BarisDuaKolom('TOTAL', Uang.Dari(jual.TotalAkhir).FormatRupiah(), tebal: true));
    for (final bayar in data.pembayaran) {
      baris.add(BarisDuaKolom(bayar.NamaMetode, _Angka(Uang.Dari(bayar.Jumlah))));
    }
    final kembalian = Uang.Dari(jual.Kembalian);
    if (!kembalian.BernilaiNol()) {
      baris.add(BarisDuaKolom('Kembalian', _Angka(kembalian)));
    }
    final pajakTermasuk = totalPajak.Kurangi(pajakEksklusif);
    if (_Positif(pajakTermasuk)) {
      baris.add(BarisTeks('Harga termasuk pajak ${pajakTermasuk.FormatRupiah()}', rata: RataStruk.Tengah));
    }
    final hemat = Uang.Dari(jual.TotalDiskon);
    if (p.tampilkanHemat && _Positif(hemat)) {
      baris.add(BarisTeks('Anda hemat ${hemat.FormatRupiah()}', rata: RataStruk.Tengah));
    }
    // F-16c bagian 4a: poin dihitung server saat transaksi tersinkron; struk hanya memberi tahu pengalinya.
    final labelPoin = data.labelPoin?.trim();
    if (labelPoin != null && labelPoin.isNotEmpty && jual.Status != StatusPenjualanLokal.divoid) {
      baris
        ..add(BarisTeks(labelPoin, rata: RataStruk.Tengah, tebal: true))
        ..add(const BarisTeks('Poin masuk setelah transaksi tersinkron', rata: RataStruk.Tengah));
    }

    baris.add(const BarisGaris());
    // Laundry (§9.9): rincian tiket + QR lacak cucian (tautan sama dengan struk digital, tetap dicetak walau struk
    // digital dimatikan).
    final laundry = data.laundry;
    final awalanLacak = data.awalanLacakLaundry;
    if (laundry != null) {
      baris
        ..add(const BarisTeks('TIKET LAUNDRY', rata: RataStruk.Tengah, tebal: true))
        ..add(BarisDuaKolom('Layanan', laundry.jenisLayanan));
      if (laundry.berat != null) {
        baris.add(BarisDuaKolom('Berat', '${laundry.berat.toString().replaceAll('.', ',')} kg'));
      }
      for (final i in laundry.item) {
        baris.add(BarisDuaKolom(i.nama, '${i.jumlah}'));
      }
      if (laundry.parfum != null) {
        baris.add(BarisDuaKolom('Parfum', laundry.parfum!));
      }
      if (laundry.catatan != null) {
        baris.add(BarisTeks('Catatan: ${laundry.catatan}'));
      }
      final selesai = laundry.estimasiSelesaiPada.toLocal();
      baris
        ..add(
          BarisDuaKolom(
            'Selesai',
            '${selesai.day} ${_bulan[selesai.month - 1]} ${_Dua(selesai.hour)}.${_Dua(selesai.minute)}',
          ),
        )
        ..add(const BarisGaris());
    }
    // POS-11: QR & tautan struk digital (bisa dibuat offline; halaman tersedia setelah penjualan terkirim).
    final awalanStruk = p.awalanStrukDigital;
    final awalan = laundry != null && awalanLacak != null && awalanLacak.isNotEmpty ? awalanLacak : awalanStruk;
    if (laundry != null && awalan != null && awalan.isNotEmpty) {
      final tautan = TautanStrukDigital(awalan, jual.Uuid);
      baris
        ..add(BarisQr(tautan))
        ..add(const BarisTeks('Lacak cucian:', rata: RataStruk.Tengah))
        ..add(BarisTeks(tautan, rata: RataStruk.Tengah));
    } else if (awalan != null && awalan.isNotEmpty) {
      final tautan = TautanStrukDigital(awalan, jual.Uuid);
      baris
        ..add(BarisQr(tautan))
        ..add(const BarisTeks('Struk digital:', rata: RataStruk.Tengah))
        ..add(BarisTeks(tautan, rata: RataStruk.Tengah));
    }
    baris.addAll(SusunKaki(identitas));
    return DokumenStruk(baris, bukaLaci: bukaLaci);
  }

  /// Tautan struk digital `/s/{kodeStruk}` = awalan dari server + Uuid penjualan (huruf besar, format ULID).
  static String TautanStrukDigital(String awalan, String uuidPenjualan) => '$awalan${uuidPenjualan.toUpperCase()}';

  static List<BarisStruk> SusunKepala(IdentitasStruk identitas) {
    final p = identitas.pengaturan;
    final nama = p.namaDicetak ?? identitas.namaUsaha;
    return [
      if (p.adaLogo && identitas.logo != null) BarisGambar(identitas.logo!),
      if (nama.isNotEmpty) BarisTeks(nama, rata: RataStruk.Tengah, tebal: true),
      for (final teks in p.teksKepala) BarisTeks(teks, rata: RataStruk.Tengah),
      if (identitas.namaOutlet != null && identitas.namaOutlet != nama)
        BarisTeks(identitas.namaOutlet!, rata: RataStruk.Tengah),
      if (p.tampilkanAlamat && identitas.alamat != null) BarisTeks(identitas.alamat!, rata: RataStruk.Tengah),
      if (p.tampilkanTelepon && identitas.telepon != null)
        BarisTeks('Telp. ${identitas.telepon}', rata: RataStruk.Tengah),
      if (p.tampilkanNpwp && p.npwp != null) BarisTeks('NPWP ${p.npwp}', rata: RataStruk.Tengah),
    ];
  }

  static List<BarisStruk> SusunKaki(IdentitasStruk identitas) {
    final p = identitas.pengaturan;
    return [
      if (p.catatanKaki != null) BarisTeks(p.catatanKaki!, rata: RataStruk.Tengah),
      BarisTeks(p.teksPenutup ?? penutupBawaan, rata: RataStruk.Tengah),
      if (p.tandaAir) const BarisTeks(tandaAir, rata: RataStruk.Tengah),
    ];
  }

  /// `Rp 18.000` → `18.000` (baris rincian; dipakai juga dokumen kasir lain).
  static String Angka(Uang nilai) => _Angka(nilai);

  /// `2.0000` → `2`; `1.5000` → `1,5`.
  static String Jumlah(String jumlah) => _Jumlah(jumlah);

  /// `20 Sep 2026` dan `10.15` waktu lokal perangkat.
  static (String, String) TanggalJam(DateTime waktu) {
    final lokal = waktu.toLocal();
    return ('${lokal.day} ${_bulan[lokal.month - 1]} ${lokal.year}', '${_Dua(lokal.hour)}.${_Dua(lokal.minute)}');
  }

  /// F-05h: tanggal garansi berakhir = tanggal bisnis (`YYYY-MM-DD`) + [bulan] bulan kalender, hari dipangkas ke akhir
  /// bulan bila perlu (31 Jan + 1 bulan = 28/29 Feb), sama dengan `addMonthsNoOverflow` di server. Format `20 Sep 2027`.
  static String _TanggalGaransi(String tanggalBisnis, int bulan) {
    final bagian = tanggalBisnis.split('-').map(int.parse).toList();
    final indeks = bagian[0] * 12 + (bagian[1] - 1) + bulan;
    final tahun = indeks ~/ 12;
    final bulanBaru = indeks % 12 + 1;
    final hariTerakhir = DateTime(tahun, bulanBaru + 1, 0).day;
    final hari = bagian[2] > hariTerakhir ? hariTerakhir : bagian[2];
    return '$hari ${_bulan[bulanBaru - 1]} $tahun';
  }

  /// `Rp 18.000` → `18.000` (baris rincian).
  static String _Angka(Uang nilai) => nilai.FormatRupiah().replaceFirst('Rp ', '').replaceFirst('−', '');

  /// `2.0000` → `2`; `1.5000` → `1,5`.
  static String _Jumlah(String jumlah) {
    var teks = (Decimal.tryParse(jumlah) ?? Decimal.zero).toString();
    if (teks.contains('.')) {
      teks = teks.replaceFirst(RegExp(r'0+$'), '').replaceFirst(RegExp(r'\.$'), '');
    }
    return teks.replaceAll('.', ',');
  }

  static bool _Positif(Uang nilai) => !nilai.BernilaiNol() && !nilai.BernilaiNegatif();

  static String _Dua(int n) => n.toString().padLeft(2, '0');

  static Map<String, Object?>? _UraiPeta(String? json) {
    if (json == null || json.isEmpty) {
      return null;
    }
    try {
      final isi = jsonDecode(json);
      return isi is Map<String, Object?> ? isi : null;
    } on FormatException {
      return null;
    }
  }

  static List<String> _NamaPilihan(String json) {
    try {
      final daftar = jsonDecode(json);
      return daftar is List
          ? [
              for (final p in daftar)
                if (p is Map && p['Nama'] is String) p['Nama'] as String,
            ]
          : const [];
    } on FormatException {
      return const [];
    }
  }
}
