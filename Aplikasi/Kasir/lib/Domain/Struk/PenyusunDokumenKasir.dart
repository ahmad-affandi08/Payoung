import 'package:adaptor_perangkat/AdaptorPerangkat.dart';
import 'package:inti/Inti.dart';

import '../../Data/BasisData/BasisDataKasir.dart';
import '../../Data/RepositoriPenjualan.dart';

import 'package:klien_api/KlienApi.dart';

import '../../Data/PesananMeja.dart';
import '../Penjualan/BagianTagihan.dart';
import '../Penjualan/Keranjang.dart';
import '../Penjualan/LayananPenjualan.dart';
import '../Pelanggan/LayananDeposit.dart';
import '../Penjualan/LayananPreOrder.dart';
import '../Shift/LayananTutupShift.dart';
import 'IdentitasStruk.dart';
import 'PenyusunStrukPenjualan.dart';

/// Dokumen cetak kasir selain struk penjualan (cetak struk bagian 3b): bukti void, nota retur, dan laporan shift X/Z;
/// bagian 4a: bukti uang muka pre-order; bagian 4c: tiket dapur per stasiun.
/// Kepala & kaki mengikuti pengaturan struk tenant; angka memakai format yang sama dengan struk penjualan. Bukti void
/// dan nota retur bisa membuka laci bila ada refund tunai dari laci.
abstract final class PenyusunDokumenKasir {
  static DokumenStruk SusunVoid(
    IdentitasStruk identitas,
    BarisPenjualan penjualan,
    BarisVoidPenjualan dokumen, {
    bool cetakUlang = false,
    bool bukaLaci = false,
  }) {
    final (tanggal, jam) = PenyusunStrukPenjualan.TanggalJam(dokumen.DivoidPada);
    final refundTunai = Uang.Dari(dokumen.RefundTunai);
    final refundNonTunai = Uang.Dari(dokumen.RefundNonTunai);
    return DokumenStruk([
      ...PenyusunStrukPenjualan.SusunKepala(identitas),
      const BarisGaris(),
      const BarisTeks('BUKTI VOID', rata: RataStruk.Tengah, tebal: true),
      if (cetakUlang) const BarisTeks('CETAK ULANG', rata: RataStruk.Tengah, tebal: true),
      BarisTeks(penjualan.Nomor),
      BarisDuaKolom(tanggal, jam),
      BarisTeks('Kasir: ${dokumen.NamaPengguna}'),
      BarisTeks('Disetujui: ${dokumen.NamaPenyetuju}'),
      BarisTeks('Alasan: ${dokumen.Alasan}'),
      const BarisGaris(),
      BarisDuaKolom('Total dibatalkan', Uang.Dari(dokumen.Nominal).FormatRupiah(), tebal: true),
      if (!refundTunai.BernilaiNol()) BarisDuaKolom('Refund tunai', PenyusunStrukPenjualan.Angka(refundTunai)),
      if (!refundNonTunai.BernilaiNol())
        BarisDuaKolom('Refund non-tunai', PenyusunStrukPenjualan.Angka(refundNonTunai)),
      const BarisGaris(),
      ...PenyusunStrukPenjualan.SusunKaki(identitas),
    ], bukaLaci: bukaLaci);
  }

  static DokumenStruk SusunRetur(
    IdentitasStruk identitas,
    BarisReturPenjualan retur,
    List<BarisReturPenjualanDetail> detail,
    List<BarisReturPenjualanPembayaran> pembayaran, {
    bool cetakUlang = false,
    bool bukaLaci = false,
  }) {
    final (tanggal, jam) = PenyusunStrukPenjualan.TanggalJam(retur.DibuatPada);
    return DokumenStruk([
      ...PenyusunStrukPenjualan.SusunKepala(identitas),
      const BarisGaris(),
      const BarisTeks('NOTA RETUR', rata: RataStruk.Tengah, tebal: true),
      if (cetakUlang) const BarisTeks('CETAK ULANG', rata: RataStruk.Tengah, tebal: true),
      BarisTeks(retur.Nomor),
      BarisTeks(retur.NomorPenjualanAsal.isEmpty ? 'Asal: tanpa struk' : 'Asal: ${retur.NomorPenjualanAsal}'),
      BarisDuaKolom(tanggal, jam),
      if (identitas.pengaturan.tampilkanKasir) BarisTeks('Kasir: ${retur.NamaKasir}'),
      const BarisGaris(),
      for (final d in detail) ...[
        BarisTeks(d.NamaProduk),
        BarisDuaKolom(
          '  ${PenyusunStrukPenjualan.Jumlah(d.Jumlah)} ${d.SimbolSatuan}${d.Kondisi == 'Rusak' ? ' (rusak)' : ''}'
              .trimRight(),
          PenyusunStrukPenjualan.Angka(Uang.Dari(d.NilaiBaris)),
        ),
      ],
      const BarisGaris(),
      BarisDuaKolom('TOTAL REFUND', Uang.Dari(retur.TotalRefund).FormatRupiah(), tebal: true),
      for (final b in pembayaran) BarisDuaKolom(b.NamaMetode, PenyusunStrukPenjualan.Angka(Uang.Dari(b.Jumlah))),
      BarisTeks('Alasan: ${retur.Alasan}'),
      const BarisGaris(),
      ...PenyusunStrukPenjualan.SusunKaki(identitas),
    ], bukaLaci: bukaLaci);
  }

  /// Bukti uang muka pre-order (cetak struk bagian 4a): nomor pre-order, pelanggan, tanggal ambil, barang pesanan,
  /// total pesanan, uang muka per metode, dan sisa yang dibayar saat diambil (perkiraan: harga dikunci, pajak & promo
  /// dihitung ulang saat pengambilan).
  static DokumenStruk SusunPreOrder(
    IdentitasStruk identitas,
    PreOrderTersimpan preOrder, {
    bool cetakUlang = false,
    bool bukaLaci = false,
  }) {
    final (tanggal, jam) = PenyusunStrukPenjualan.TanggalJam(preOrder.dibuatPada ?? DateTime.now().toUtc());
    final t = preOrder.tanggalAmbil;
    return DokumenStruk([
      ...PenyusunStrukPenjualan.SusunKepala(identitas),
      const BarisGaris(),
      const BarisTeks('BUKTI UANG MUKA', rata: RataStruk.Tengah, tebal: true),
      const BarisTeks('PRE-ORDER', rata: RataStruk.Tengah),
      if (cetakUlang) const BarisTeks('CETAK ULANG', rata: RataStruk.Tengah, tebal: true),
      BarisTeks(preOrder.nomor),
      BarisDuaKolom(tanggal, jam),
      if (identitas.pengaturan.tampilkanKasir && preOrder.namaKasir.isNotEmpty)
        BarisTeks('Kasir: ${preOrder.namaKasir}'),
      BarisTeks('Pelanggan: ${preOrder.namaPelanggan}'),
      BarisTeks('Diambil: ${t.substring(8, 10)}/${t.substring(5, 7)}/${t.substring(0, 4)}', tebal: true),
      const BarisGaris(),
      for (final b in preOrder.baris) ...[
        BarisTeks(b.nama),
        BarisDuaKolom(
          '  ${PenyusunStrukPenjualan.Jumlah(b.jumlah.KeString())} ${b.satuan ?? ''}'.trimRight(),
          PenyusunStrukPenjualan.Angka(b.nilai),
        ),
      ],
      const BarisGaris(),
      BarisDuaKolom('Total pesanan', PenyusunStrukPenjualan.Angka(preOrder.totalPesanan)),
      BarisDuaKolom('UANG MUKA', preOrder.uangMuka.FormatRupiah(), tebal: true),
      BarisDuaKolom(preOrder.namaMetode, PenyusunStrukPenjualan.Angka(preOrder.uangMuka)),
      BarisDuaKolom(
        'Sisa saat diambil',
        PenyusunStrukPenjualan.Angka(preOrder.totalPesanan.Kurangi(preOrder.uangMuka)),
      ),
      const BarisTeks('Sisa dapat berubah bila pajak atau promo berubah saat diambil.'),
      if (preOrder.catatan != null) BarisTeks('Catatan: ${preOrder.catatan}'),
      const BarisTeks('Simpan bukti ini untuk mengambil pesanan.'),
      const BarisGaris(),
      ...PenyusunStrukPenjualan.SusunKaki(identitas),
    ], bukaLaci: bukaLaci);
  }

  /// Struk satu tamu pada tagihan yang dibagi (K-14, BR-08.2, v5.68): total tagihan, bagian tamu ini, metode dan
  /// kembaliannya. Rincian barang tidak dicetak (pembagian per orang/nominal tidak memisahkan barang); struk utama
  /// memuatnya. [tamuKe] mulai dari 1.
  static DokumenStruk SusunStrukTamu(
    IdentitasStruk identitas,
    BarisPenjualan penjualan,
    BagianTagihan bagian,
    int tamuKe, {
    bool cetakUlang = false,
  }) {
    final tamu = bagian.tamu[tamuKe - 1];
    final (tanggal, jam) = PenyusunStrukPenjualan.TanggalJam(penjualan.DibuatPada);
    final angka = PenyusunStrukPenjualan.Angka;
    final tunaiLebih = tamu.diterima.Bandingkan(tamu.porsi) > 0;
    return DokumenStruk([
      ...PenyusunStrukPenjualan.SusunKepala(identitas),
      const BarisGaris(),
      const BarisTeks('STRUK TAMU', rata: RataStruk.Tengah, tebal: true),
      if (cetakUlang) const BarisTeks('CETAK ULANG', rata: RataStruk.Tengah, tebal: true),
      if (penjualan.Status == StatusPenjualanLokal.divoid)
        const BarisTeks('DIBATALKAN', rata: RataStruk.Tengah, tebal: true),
      BarisTeks('Tamu $tamuKe dari ${bagian.tamu.length}', rata: RataStruk.Tengah, tebal: true, besar: true),
      BarisTeks(bagian.rata ? 'Tagihan dibagi rata' : 'Tagihan dibagi per nominal', rata: RataStruk.Tengah),
      BarisTeks(penjualan.Nomor),
      BarisDuaKolom(tanggal, jam),
      if (identitas.pengaturan.tampilkanKasir) BarisTeks('Kasir: ${penjualan.NamaKasir}'),
      const BarisGaris(),
      BarisDuaKolom('Total tagihan', Uang.Dari(penjualan.TotalAkhir).FormatRupiah()),
      BarisDuaKolom('Bagian tamu $tamuKe', tamu.porsi.FormatRupiah(), tebal: true),
      const BarisGaris(),
      BarisDuaKolom(tunaiLebih ? '${tamu.namaMetode} diterima' : tamu.namaMetode, angka(tamu.diterima)),
      if (tunaiLebih) BarisDuaKolom('Kembalian', angka(tamu.kembalian)),
      const BarisGaris(),
      const BarisTeks('Rincian pesanan ada di struk utama.', rata: RataStruk.Tengah),
      ...PenyusunStrukPenjualan.SusunKaki(identitas),
    ]);
  }

  /// Tagihan sementara (pre-bill, §9.1, v3.53) pesanan meja sebelum dibayar: item, subtotal, diskon, biaya layanan,
  /// pajak per jenis, dan TOTAL dari mesin kalkulasi yang sama dengan layar Bayar, ditandai jelas "BELUM LUNAS" dan
  /// "bukan bukti pembayaran". Promo metode bayar & pembulatan tunai belum diketahui, jadi total bisa berubah saat bayar.
  static DokumenStruk SusunTagihanSementara(
    IdentitasStruk identitas, {
    required String judul,
    required String nomor,
    required Keranjang keranjang,
    required HitunganKeranjang hitungan,
    required DateTime waktu,
    String? namaKasir,
    int jumlahTamu = 0,
  }) {
    final (tanggal, jam) = PenyusunStrukPenjualan.TanggalJam(waktu);
    final hasil = hitungan.hasil;
    final angka = PenyusunStrukPenjualan.Angka;
    return DokumenStruk([
      ...PenyusunStrukPenjualan.SusunKepala(identitas),
      const BarisGaris(),
      const BarisTeks('TAGIHAN SEMENTARA', rata: RataStruk.Tengah, tebal: true),
      const BarisTeks('BELUM LUNAS', rata: RataStruk.Tengah),
      BarisTeks(judul, rata: RataStruk.Tengah, tebal: true, besar: true),
      BarisTeks(nomor),
      BarisDuaKolom(tanggal, jam),
      if (identitas.pengaturan.tampilkanKasir && namaKasir != null && namaKasir.isNotEmpty)
        BarisTeks('Kasir: $namaKasir'),
      if (jumlahTamu > 0) BarisTeks('Tamu: $jumlahTamu orang'),
      const BarisGaris(),
      for (var i = 0; i < keranjang.baris.length; i++) ...[
        BarisTeks(keranjang.baris[i].nama),
        for (final p in keranjang.baris[i].pilihan) BarisTeks('  + ${p.nama}'),
        BarisDuaKolom(
          '  ${PenyusunStrukPenjualan.Jumlah(keranjang.baris[i].jumlah.KeString())} x '
          '${angka(keranjang.baris[i].hargaSatuan)}',
          angka(hasil.baris[i].bruto),
        ),
        if (!hasil.baris[i].diskon.BernilaiNol()) BarisDuaKolom('  Diskon', '-${angka(hasil.baris[i].diskon)}'),
      ],
      const BarisGaris(),
      BarisDuaKolom('Subtotal', angka(hasil.subtotal)),
      if (!hasil.diskonPesanan.BernilaiNol()) BarisDuaKolom('Diskon', '-${angka(hasil.diskonPesanan)}'),
      if (!hasil.biayaLayanan.BernilaiNol()) BarisDuaKolom('Biaya layanan', angka(hasil.biayaLayanan)),
      for (final p in hitungan.pajakDokumen)
        if (hasil.pajak[p.kode] case final pajak? when !pajak.jumlah.BernilaiNol())
          BarisDuaKolom(hitungan.labelPajak[p.kode] ?? p.kode, angka(pajak.jumlah)),
      BarisDuaKolom('TOTAL', hasil.totalAkhir.FormatRupiah(), tebal: true),
      const BarisGaris(),
      const BarisTeks('Bukan bukti pembayaran.', rata: RataStruk.Tengah),
      const BarisTeks('Total dapat berubah karena promo metode bayar atau pembulatan tunai.', rata: RataStruk.Tengah),
      ...PenyusunStrukPenjualan.SusunKaki(identitas),
    ]);
  }

  /// Nota/label laundry (§9.9) dari daftar cucian (online): nomor nota besar, pemilik, layanan, isi, parfum, perkiraan
  /// selesai, dan QR lacak `/s/{kode}` agar mudah ditempel di kantong cucian.
  static DokumenStruk SusunNotaLaundry(IdentitasStruk identitas, TiketLaundryPos tiket, {String? awalanLacak}) {
    final (tanggalSelesai, jamSelesai) = PenyusunStrukPenjualan.TanggalJam(tiket.estimasiSelesaiPada);
    return DokumenStruk([
      ...PenyusunStrukPenjualan.SusunKepala(identitas),
      const BarisGaris(),
      const BarisTeks('NOTA LAUNDRY', rata: RataStruk.Tengah, tebal: true),
      BarisTeks(tiket.nomor, rata: RataStruk.Tengah, tebal: true),
      BarisTeks('Pemilik: ${tiket.namaPelanggan}'),
      BarisDuaKolom('Layanan', tiket.jenisLayanan),
      if (tiket.berat != null)
        BarisDuaKolom('Berat', '${PenyusunStrukPenjualan.Jumlah(tiket.berat!).replaceAll('.', ',')} kg'),
      for (final i in tiket.item) BarisDuaKolom(i.nama, '${i.jumlah}'),
      if (tiket.parfum != null) BarisDuaKolom('Parfum', tiket.parfum!),
      if (tiket.catatan != null) BarisTeks('Catatan: ${tiket.catatan}'),
      BarisDuaKolom('Selesai', '$tanggalSelesai $jamSelesai', tebal: true),
      BarisDuaKolom('Status', tiket.labelStatus),
      const BarisGaris(),
      if (awalanLacak != null && awalanLacak.isNotEmpty) ...[
        BarisQr(PenyusunStrukPenjualan.TautanStrukDigital(awalanLacak, tiket.uuid)),
        const BarisTeks('Lacak cucian:', rata: RataStruk.Tengah),
        BarisTeks(PenyusunStrukPenjualan.TautanStrukDigital(awalanLacak, tiket.uuid), rata: RataStruk.Tengah),
      ],
      ...PenyusunStrukPenjualan.SusunKaki(identitas),
    ]);
  }

  /// Bukti isi deposit pelanggan (F-16d bagian 1): nomor, pelanggan, jumlah per metode, dan saldo sesudah bila saldo
  /// sebelumnya sempat dibaca online. Bukan struk penjualan (tanpa pajak): deposit adalah titipan pelanggan.
  static DokumenStruk SusunIsiDeposit(
    IdentitasStruk identitas,
    IsiDepositTersimpan isi, {
    bool cetakUlang = false,
    bool bukaLaci = false,
  }) {
    final (tanggal, jam) = PenyusunStrukPenjualan.TanggalJam(isi.dibuatPada);
    final saldo = isi.saldoSesudah;
    return DokumenStruk([
      ...PenyusunStrukPenjualan.SusunKepala(identitas),
      const BarisGaris(),
      const BarisTeks('BUKTI ISI DEPOSIT', rata: RataStruk.Tengah, tebal: true),
      if (cetakUlang) const BarisTeks('CETAK ULANG', rata: RataStruk.Tengah, tebal: true),
      BarisTeks(isi.nomor),
      BarisDuaKolom(tanggal, jam),
      if (identitas.pengaturan.tampilkanKasir && isi.namaKasir.isNotEmpty) BarisTeks('Kasir: ${isi.namaKasir}'),
      BarisTeks('Pelanggan: ${isi.namaPelanggan}'),
      const BarisGaris(),
      BarisDuaKolom('ISI DEPOSIT', isi.jumlah.FormatRupiah(), tebal: true),
      BarisDuaKolom(isi.namaMetode, PenyusunStrukPenjualan.Angka(isi.jumlah)),
      if (isi.referensi != null) BarisTeks('Ref: ${isi.referensi}'),
      if (saldo != null) BarisDuaKolom('Saldo deposit', PenyusunStrukPenjualan.Angka(saldo)),
      const BarisTeks('Saldo deposit bisa dipakai untuk belanja di toko ini.'),
      const BarisGaris(),
      ...PenyusunStrukPenjualan.SusunKaki(identitas),
    ], bukaLaci: bukaLaci);
  }

  /// Tiket dapur satu stasiun untuk satu kiriman (cetak struk bagian 4c): tanpa harga, nama meja & jumlah dicetak
  /// besar agar terbaca dari jauh, pilihan & catatan per item di bawahnya.
  static DokumenStruk SusunTiketDapur({
    required String namaStasiun,
    required PesananMeja pesanan,
    required List<BarisPesananMeja> baris,
    required DateTime waktu,
    String? namaKasir,
    bool cetakUlang = false,
  }) {
    final (tanggal, jam) = PenyusunStrukPenjualan.TanggalJam(waktu);
    final ronde = baris.fold(0, (maks, b) => b.ronde > maks ? b.ronde : maks);
    return DokumenStruk([
      BarisTeks('TIKET ${namaStasiun.toUpperCase()}', rata: RataStruk.Tengah, tebal: true),
      if (cetakUlang) const BarisTeks('CETAK ULANG', rata: RataStruk.Tengah, tebal: true),
      BarisTeks(pesanan.AmbilJudul(), rata: RataStruk.Tengah, tebal: true, besar: true),
      BarisTeks(pesanan.nomor),
      BarisDuaKolom('Ronde $ronde', '$tanggal $jam'),
      if (namaKasir != null && namaKasir.isNotEmpty) BarisTeks('Kasir: $namaKasir'),
      const BarisGaris(),
      for (final b in baris) ...[
        BarisTeks('${PenyusunStrukPenjualan.Jumlah(b.jumlah)} x ${b.namaProduk}', tebal: true, besar: true),
        for (final p in b.pilihan)
          if (p['Nama'] case final String nama when nama.isNotEmpty) BarisTeks('  + $nama'),
        if (b.catatan case final String catatan when catatan.trim().isNotEmpty)
          BarisTeks('  Catatan: ${catatan.trim()}'),
      ],
      const BarisGaris(),
      BarisTeks('${baris.length} item', rata: RataStruk.Kanan),
    ]);
  }

  /// Laporan X (shift berjalan) atau Z (shift tertutup). [tampilkanKasSeharusnya] = false untuk tutup buta.
  static DokumenStruk SusunLaporanShift(
    IdentitasStruk identitas,
    LaporanShift laporan, {
    bool tampilkanKasSeharusnya = true,
  }) {
    final s = laporan.shift;
    String Nilai(Uang nilai) =>
        nilai.BernilaiNegatif() ? '-${PenyusunStrukPenjualan.Angka(nilai)}' : PenyusunStrukPenjualan.Angka(nilai);
    final (tanggalBuka, jamBuka) = PenyusunStrukPenjualan.TanggalJam(s.DibukaPada);
    final ditutup = s.DitutupPada;
    return DokumenStruk([
      ...PenyusunStrukPenjualan.SusunKepala(identitas),
      const BarisGaris(),
      BarisTeks(laporan.tertutup ? 'LAPORAN Z' : 'LAPORAN X', rata: RataStruk.Tengah, tebal: true),
      BarisTeks('Kasir: ${s.NamaKasir}'),
      BarisDuaKolom('Dibuka', '$tanggalBuka $jamBuka'),
      if (ditutup != null)
        BarisDuaKolom('Ditutup', () {
          final (tanggal, jam) = PenyusunStrukPenjualan.TanggalJam(ditutup);
          return '$tanggal $jam';
        }()),
      const BarisGaris(),
      BarisDuaKolom('Jumlah transaksi', '${laporan.jumlahTransaksi}'),
      BarisDuaKolom('Penjualan kotor', Nilai(laporan.penjualanKotor)),
      BarisDuaKolom('Diskon', Nilai(laporan.totalDiskon)),
      BarisDuaKolom('Penjualan bersih', Nilai(laporan.penjualanBersih), tebal: true),
      BarisDuaKolom('Pajak', Nilai(laporan.totalPajak)),
      if (!laporan.biayaLayanan.BernilaiNol()) BarisDuaKolom('Biaya layanan', Nilai(laporan.biayaLayanan)),
      if (!laporan.pembulatan.BernilaiNol()) BarisDuaKolom('Pembulatan', Nilai(laporan.pembulatan)),
      BarisDuaKolom('Total', laporan.totalAkhir.FormatRupiah(), tebal: true),
      BarisDuaKolom('Void ${laporan.jumlahVoid}x', Nilai(laporan.nominalVoid)),
      BarisDuaKolom('Retur ${laporan.jumlahRetur}x', Nilai(laporan.nominalRetur)),
      if (laporan.jumlahIsiDeposit > 0)
        BarisDuaKolom('Isi deposit ${laporan.jumlahIsiDeposit}x', Nilai(laporan.nominalIsiDeposit ?? Uang.Nol())),
      const BarisGaris(),
      const BarisTeks('Per metode bayar', tebal: true),
      for (final m in laporan.perMetode) BarisDuaKolom(m.nama, Nilai(m.jumlah)),
      const BarisGaris(),
      const BarisTeks('Kas laci', tebal: true),
      BarisDuaKolom('Kas awal', Nilai(laporan.kasAwal)),
      BarisDuaKolom('Tunai bersih', Nilai(laporan.tunaiMasukBersih)),
      BarisDuaKolom('Kas masuk', Nilai(laporan.kasMasuk)),
      BarisDuaKolom('Kas keluar', Nilai(Uang.Nol().Kurangi(laporan.kasKeluar))),
      BarisDuaKolom('Setoran', Nilai(Uang.Nol().Kurangi(laporan.setoran))),
      BarisDuaKolom('Refund tunai', Nilai(Uang.Nol().Kurangi(laporan.refundTunai))),
      if (tampilkanKasSeharusnya)
        BarisDuaKolom(
          'Kas seharusnya',
          Nilai(s.KasSeharusnya == null ? laporan.kasSeharusnya : Uang.Dari(s.KasSeharusnya!)),
          tebal: true,
        ),
      if (s.KasAktual != null) BarisDuaKolom('Kas aktual', Nilai(Uang.Dari(s.KasAktual!)), tebal: true),
      if (s.Selisih != null) BarisDuaKolom('Selisih', Nilai(Uang.Dari(s.Selisih!)), tebal: true),
      if (s.AlasanSelisih != null) BarisTeks('Alasan selisih: ${s.AlasanSelisih}'),
      const BarisGaris(),
      const BarisKosong(),
      const BarisTeks('Tanda tangan kasir', rata: RataStruk.Tengah),
      const BarisKosong(),
      const BarisKosong(),
      const BarisGaris(),
    ]);
  }
}
