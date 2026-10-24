import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:klien_api/KlienApi.dart';
import 'package:mesin_kasir/MesinKasir.dart';
import 'package:sistem_desain/SistemDesain.dart';

import '../Komponen/FormatAngka.dart';
import '../Komponen/MasukanUang.dart';
import '../../Aplikasi/Penyedia.dart';
import '../../Data/BasisData/BasisDataKasir.dart';
import '../../Domain/GalatKasir.dart';
import '../../Domain/Pelanggan/LayananGiftCard.dart';
import '../../Domain/Penjualan/AturanApotek.dart';
import '../../Domain/Penjualan/BagianTagihan.dart';
import '../../Domain/Penjualan/Keranjang.dart';
import '../../Domain/Penjualan/KonteksPenjualan.dart';
import '../../Domain/Penjualan/LayananPenjualan.dart';
import '../../Domain/Penjualan/LayananPreOrder.dart';
import '../../Domain/Sesi/StafLokal.dart';
import '../LembarMutasiKas.dart';
import '../Struk/BagianCetakStruk.dart';
import '../Struk/BagianPrinter.dart';
import '../Struk/BagianTiketDapur.dart';
import '../Struk/TombolKirimStruk.dart';
import '../Struk/TombolStrukTamu.dart';
import 'DialogQrisDinamis.dart';
import 'DialogResep.dart';
import 'GambarQr.dart';
import 'PanelKeranjang.dart';
import 'PanelPelanggan.dart';
import 'SuaraPembayaranBerhasil.dart';

/// Label jenis metode pembayaran untuk kasir.
String AmbilLabelJenisMetode(String jenis) => switch (jenis) {
  JenisMetodeBayar.tunai => 'Tunai',
  JenisMetodeBayar.qrisStatis => 'QRIS',
  JenisMetodeBayar.qrisDinamis => 'QRIS dinamis',
  JenisMetodeBayar.edc => 'Kartu (EDC)',
  JenisMetodeBayar.transfer => 'Transfer',
  JenisMetodeBayar.ewallet => 'E-wallet',
  JenisMetodeBayar.tempo => 'Tempo (piutang)',
  JenisMetodeBayar.deposit => 'Deposit pelanggan',
  JenisMetodeBayar.giftCard => 'Gift card',
  JenisMetodeBayar.marketplace => 'Platform ojol / marketplace',
  _ => jenis,
};

/// Pecahan cepat untuk tagihan tunai: uang pas lalu pembulatan ke atas ke Rp 5.000, 10.000, 20.000, 50.000, 100.000
/// (unik, maksimal [batas] tombol selain uang pas).
List<Uang> HitungPecahanCepat(Uang tagihan, {int batas = 4}) {
  final hasil = <Uang>[];
  for (final kelipatan in const [5000, 10000, 20000, 50000, 100000]) {
    final atas = tagihan.BulatkanKeKelipatan(kelipatan, ModePembulatan.KeAtas);
    if (atas.Bandingkan(tagihan) > 0 && !hasil.contains(atas)) {
      hasil.add(atas);
    }
  }
  return hasil.take(batas).toList();
}

/// K-14 (BR-08.2): bagi tagihan per orang (rata) atau per nominal. Rata: porsi tiap tamu = total ÷ jumlah orang
/// dibulatkan ke bawah ke rupiah bulat, tamu terakhir membayar sisanya. Nominal: tiap tamu membayar jumlah yang
/// diketik. Semua bagian tetap satu penjualan dengan beberapa pembayaran; bagian tunai digabung menjadi satu baris
/// tunai (server hanya menerima satu pembayaran tunai per penjualan).
class BagiTagihan {
  const BagiTagihan.rata(this.jumlahOrang, this.porsi) : perNominal = false;
  const BagiTagihan.nominal() : jumlahOrang = null, porsi = null, perNominal = true;

  final int? jumlahOrang;
  final Uang? porsi;
  final bool perNominal;

  /// Porsi rata: [total] ÷ [orang] dibulatkan ke bawah ke rupiah bulat (sisa pembulatan dibayar tamu terakhir).
  static Uang HitungPorsi(Uang total, int orang) =>
      Uang.Dari((total.KeDesimal() / Decimal.fromInt(orang)).floor().toString());
}

/// Panel Bayar (F-08 fase 1, Rincian F-07c): tunai (pecahan cepat & uang pas), QRIS statis (gambar + konfirmasi
/// kasir), EDC (bank & nomor approval), transfer & e-wallet (referensi), split pembayaran (BR-08.1). Pembulatan tunai
/// hanya untuk bagian tunai (BR-08.6). F-12: Tempo (piutang) hanya bila pelanggan dipilih; di luar limit kredit atau
/// ada piutang lewat jatuh tempo (BR-12.1) butuh PIN penyetuju. F-16d: Deposit hanya bila pelanggan dipilih dan paket
/// berlaku; saldo dibaca online saat dipilih dan jumlah tidak boleh melebihinya. Setelah tersimpan memanggil [saatSelesai].
class PanelBayar extends ConsumerStatefulWidget {
  const PanelBayar({
    super.key,
    required this.kasir,
    required this.saatSelesai,
    this.saatPreOrder,
    this.saatPesananGrosir,
  });

  /// Lebar area kerja minimal untuk dua kolom (ringkasan & metode | rincian metode & papan angka).
  static const double lebarDuaKolom = 960;
  static const double lebarMaksDuaKolom = 1120;
  static const double lebarMaksSatuKolom = 720;

  final StafLokal kasir;
  final ValueChanged<PenjualanTersimpan> saatSelesai;

  /// F-12 bagian 2: jadikan keranjang ber-pelanggan sebagai pre-order dengan uang muka. Null = tidak ditampilkan.
  final VoidCallback? saatPreOrder;

  /// POS-05: jadikan keranjang ber-pelanggan pesanan grosir (SO cepat, tanpa bayar). Null = tidak ditampilkan.
  final VoidCallback? saatPesananGrosir;

  @override
  ConsumerState<PanelBayar> createState() => PanelBayarState();
}

/// Publik agar pintasan F9 (uang pas, PRD §17.2.3) di layar Jual bisa memicu pembayaran tunai uang pas.
class PanelBayarState extends ConsumerState<PanelBayar> {
  final List<PembayaranMasukan> _entri = [];
  BarisMetodePembayaran? _metode;
  final _nominal = TextEditingController();
  final _referensi = TextEditingController();
  final _bank = TextEditingController();
  bool _qrisDikonfirmasi = false;
  bool _sibuk = false;

  /// F-12: staf yang menyetujui tempo lewat PIN (BR-12.1).
  StafLokal? _penyetujuTempo;

  /// Apotek bagian 2 (§9.5): resep dokter untuk obat wajib resep dan apoteker yang lolos PIN untuk obat keras/
  /// psikotropika/narkotika. Hanya hidup selama panel Bayar terbuka (tidak ikut draf keranjang).
  ResepPenjualan? _resep;
  StafLokal? _apoteker;

  /// F-16d: saldo deposit pelanggan terakhir dibaca online (null = belum/ gagal dibaca).
  Uang? _saldoDeposit;
  bool _memuatSaldoDeposit = false;
  String? _pesanSaldoDeposit;

  /// CRM-06: gift card hasil cek online terakhir (null = belum dicek atau kode diubah setelah dicek).
  KartuGiftCard? _kartuGiftCard;
  bool _memuatGiftCard = false;
  String? _pesanGiftCard;
  String? _galat;

  /// K-14: rencana bagi tagihan (null = tidak dibagi) dan tamu yang sedang membayar (mulai 1).
  BagiTagihan? _bagi;
  int _tamuKe = 1;

  /// K-14: kembalian tunai tamu sebelumnya (bagian tunai yang uangnya lebih dari porsinya).
  String? _infoBagi;

  @override
  void initState() {
    super.initState();
    // F-12 bagian 2 & F-17 bagian 2: menagih pre-order / pesanan online → uang muka tersisa langsung menjadi
    // pembayaran pertama. Nol (mis. pesanan online COD) tidak menambah baris apa pun.
    final keranjang = ref.read(penyediaKeranjangEfektif);
    final praPesan = keranjang.praPesan;
    final k = ref.read(penyediaKonteksPenjualan).value;
    if (praPesan != null && k != null && !keranjang.CekKosong) {
      final total = ref.read(penyediaLayananPenjualan).Hitung(keranjang, k).hasil.totalAkhir;
      final dipakai = praPesan.sisaUangMuka.Bandingkan(total) > 0 ? total : praPesan.sisaUangMuka;
      if (dipakai.Bandingkan(Uang.Nol()) > 0) {
        _entri.add(PembayaranMasukan(metode: LayananPreOrder.MetodeUangMuka(praPesan), jumlah: dipakai));
      }
    }
    // K-11: tukar barang → nilai barang yang diretur langsung membayar barang pengganti (paling banyak total belanja;
    // kelebihannya dikembalikan tunai lewat refund retur).
    final tukar = keranjang.tukar;
    if (tukar != null && k != null && !keranjang.CekKosong) {
      final total = ref.read(penyediaLayananPenjualan).Hitung(keranjang, k).hasil.totalAkhir;
      final dipakai = tukar.HitungDipakai(total);
      if (dipakai.Bandingkan(Uang.Nol()) > 0) {
        _entri.add(
          PembayaranMasukan(
            metode: BarisMetodePembayaran(
              Uuid: tukar.uuidMetode,
              Jenis: JenisMetodeBayar.tukar,
              Nama: tukar.namaMetode,
              AdaGambarQris: false,
              Urutan: 0,
            ),
            jumlah: dipakai,
          ),
        );
      }
    }
    // X8: pesanan platform (GoFood, …) langsung memilih metode platform yang sama; kasir tinggal menyelesaikan.
    final kanal = LayananPenjualan.AmbilKanal(keranjang);
    final platform = k == null || !LayananPenjualan.kanalPlatform.contains(kanal)
        ? null
        : k.metodePembayaran.where((m) => m.Jenis == JenisMetodeBayar.marketplace && m.Kanal == kanal.name).firstOrNull;
    if (platform != null && _entri.isEmpty) {
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (mounted && _metode == null) {
          _PilihMetode(k!, platform);
        }
      });
      return;
    }
    // Audit kemudahan pakai: Tunai langsung terpilih (kasus paling sering), jadi kasir cukup mengetuk "Uang pas" atau
    // nominal pecahan; metode lain tetap satu ketukan di chip metode.
    final tunai = k?.metodePembayaran.where((m) => m.Jenis == JenisMetodeBayar.tunai).firstOrNull;
    if (tunai != null && !_entri.any((p) => p.CekTunai())) {
      WidgetsBinding.instance.addPostFrameCallback((_) {
        // Hanya bila masih ada yang harus dibayar (tukar barang/uang muka bisa sudah melunasi semuanya).
        if (mounted && _metode == null && _HitungSisa(k!, tunai).Bandingkan(Uang.Nol()) > 0) {
          _PilihMetode(k, tunai);
        }
      });
    }
  }

  /// QRIS statis bernominal juga tampil di layar pelanggan (sama seperti QRIS dinamis gerbang). Dikirim hanya saat isinya
  /// berubah, dan dikosongkan saat panel ditutup atau metode diganti.
  String? _qrLayarPelanggan;
  PengaturQrisLayarPelanggan? _pengaturQrLayar;

  void _AturQrLayarPelanggan(String? isiQr) {
    if (isiQr == _qrLayarPelanggan) {
      return;
    }
    _qrLayarPelanggan = isiQr;
    final PengaturQrisLayarPelanggan pengatur = _pengaturQrLayar ?? ref.read(penyediaQrisLayarPelanggan.notifier);
    _pengaturQrLayar = pengatur;
    // Ditunda satu frame: Notifier tidak boleh diubah saat pohon widget sedang dibangun/dibongkar.
    WidgetsBinding.instance.addPostFrameCallback((_) => pengatur.Atur(isiQr));
  }

  @override
  void dispose() {
    _AturQrLayarPelanggan(null);
    _nominal.dispose();
    _referensi.dispose();
    _bank.dispose();
    super.dispose();
  }

  Uang _AmbilDibayar() => _entri.fold(Uang.Nol(), (t, p) => t.Tambah(p.jumlah));

  /// K-14: tamu terakhir pada bagi rata (atau tidak sedang membagi rata) membayar seluruh sisa.
  bool get _tamuTerakhir => _bagi?.jumlahOrang == null || _tamuKe >= _bagi!.jumlahOrang!;

  /// K-14: tagihan tamu saat ini = porsi rata (paling banyak [sisa]) atau seluruh [sisa].
  Uang _AmbilTagihanTamu(Uang sisa) {
    final porsi = _bagi?.porsi;
    if (porsi == null || _tamuTerakhir || porsi.Bandingkan(sisa) >= 0) {
      return sisa;
    }
    return porsi;
  }

  /// K-14: bagian tunai digabung menjadi satu baris tunai.
  static List<PembayaranMasukan> _GabungTunai(List<PembayaranMasukan> pembayaran) {
    final tunai = pembayaran.where((p) => p.CekTunai()).toList();
    if (tunai.length < 2) {
      return pembayaran;
    }
    final total = tunai.fold(Uang.Nol(), (t, p) => t.Tambah(p.jumlah));
    return [...pembayaran.where((p) => !p.CekTunai()), PembayaranMasukan(metode: tunai.first.metode, jumlah: total)];
  }

  /// Sisa tagihan bila sisa dibayar dengan [metode] (tunai memakai pembulatan tunai).
  Uang _HitungSisa(KonteksPenjualan k, BarisMetodePembayaran? metode) {
    final layanan = ref.read(penyediaLayananPenjualan);
    final keranjang = ref.read(penyediaKeranjangEfektif);
    if (metode?.Jenis == JenisMetodeBayar.tunai) {
      // K-14: bagian tunai tamu sebelumnya sudah mengurangi tagihan tunai.
      final tunaiSebelum = _entri.where((p) => p.CekTunai()).fold(Uang.Nol(), (t, p) => t.Tambah(p.jumlah));
      return layanan.HitungTagihanTunai(keranjang, k, _entri).Kurangi(tunaiSebelum);
    }
    final hasil = layanan.Hitung(
      keranjang,
      k,
      pembayaran: [for (final p in _entri) p.KeKalkulasi()],
      metodeBayar: LayananPenjualan.AmbilMetodeBayar(k, [for (final p in _entri) p.metode, ?metode]),
    ).hasil;
    return hasil.totalAkhir.Kurangi(_AmbilDibayar());
  }

  void _PilihMetode(KonteksPenjualan k, BarisMetodePembayaran metode) {
    setState(() {
      _metode = metode;
      _galat = null;
      _qrisDikonfirmasi = false;
      _referensi.clear();
      _bank.clear();
      _kartuGiftCard = null;
      _pesanGiftCard = null;
      final sisa = _HitungSisa(k, metode);
      final porsiRata = _bagi?.porsi != null && !_tamuTerakhir;
      MasukanUang.Isi(
        _nominal,
        (metode.Jenis == JenisMetodeBayar.tunai && !porsiRata) ||
                _bagi?.perNominal == true ||
                sisa.Bandingkan(Uang.Nol()) <= 0
            ? null
            : Uang.Dari(_AmbilTagihanTamu(sisa).KeDesimal().ceil().toString()),
      );
    });
    if (metode.Jenis == JenisMetodeBayar.deposit) {
      unawaited(_MuatSaldoDeposit(k));
    }
  }

  /// F-16d: baca saldo deposit pelanggan (wajib online); jumlah bawaan = sisa tagihan atau saldo bila lebih kecil.
  Future<void> _MuatSaldoDeposit(KonteksPenjualan k) async {
    final pelanggan = ref.read(penyediaKeranjangEfektif).pelanggan;
    if (pelanggan == null) {
      return;
    }
    setState(() {
      _memuatSaldoDeposit = true;
      _pesanSaldoDeposit = null;
      _saldoDeposit = null;
    });
    try {
      final saldo = await ref.read(penyediaLayananDeposit).AmbilSaldo(pelanggan.uuid);
      if (!mounted) {
        return;
      }
      setState(() {
        _saldoDeposit = saldo;
        if (_metode?.Jenis == JenisMetodeBayar.deposit) {
          final sisa = _HitungSisa(k, _metode);
          final pakai = saldo.Bandingkan(sisa) < 0 ? saldo : sisa;
          MasukanUang.Isi(
            _nominal,
            pakai.Bandingkan(Uang.Nol()) <= 0 ? null : Uang.Dari(pakai.KeDesimal().floor().toString()),
          );
        }
      });
    } on GalatKasir catch (galat) {
      if (mounted) {
        setState(() => _pesanSaldoDeposit = galat.pesan);
      }
    } finally {
      if (mounted) {
        setState(() => _memuatSaldoDeposit = false);
      }
    }
  }

  /// CRM-06: cek kode gift card online; jumlah bawaan = sisa tagihan atau saldo kartu bila lebih kecil.
  Future<void> _CekGiftCard(KonteksPenjualan k) async {
    if (_entri.any((p) => p.metode.Jenis == JenisMetodeBayar.giftCard)) {
      setState(() => _pesanGiftCard = 'Gift card hanya boleh satu kartu per transaksi.');
      return;
    }
    setState(() {
      _memuatGiftCard = true;
      _pesanGiftCard = null;
      _kartuGiftCard = null;
      _galat = null;
    });
    try {
      final kartu = await ref.read(penyediaLayananGiftCard).Cek(_referensi.text);
      if (!mounted) {
        return;
      }
      setState(() {
        _kartuGiftCard = kartu;
        _referensi.text = kartu.kode;
        if (kartu.bisaDipakai && _metode?.Jenis == JenisMetodeBayar.giftCard) {
          final sisa = _HitungSisa(k, _metode);
          final pakai = kartu.saldo.Bandingkan(sisa) < 0 ? kartu.saldo : sisa;
          MasukanUang.Isi(
            _nominal,
            pakai.Bandingkan(Uang.Nol()) <= 0 ? null : Uang.Dari(pakai.KeDesimal().floor().toString()),
          );
        }
      });
    } on GalatKasir catch (galat) {
      if (mounted) {
        setState(() => _pesanGiftCard = galat.pesan);
      }
    } finally {
      if (mounted) {
        setState(() => _memuatGiftCard = false);
      }
    }
  }

  Uang? _AmbilNominal() {
    return MasukanUang.AmbilNilai(_nominal);
  }

  String? _SusunReferensi(BarisMetodePembayaran metode) {
    if (metode.Jenis == JenisMetodeBayar.edc) {
      return LayananPenjualan.SusunReferensiEdc(_bank.text, _referensi.text);
    }
    if (metode.Jenis == JenisMetodeBayar.giftCard) {
      return _kartuGiftCard?.kode;
    }
    final teks = _referensi.text.trim();
    return teks.isEmpty ? null : teks;
  }

  Future<void> _Terapkan(KonteksPenjualan k) async {
    final metode = _metode;
    if (metode == null) {
      setState(() => _galat = 'Pilih metode pembayaran dulu.');
      return;
    }
    final nominal = _AmbilNominal();
    if (nominal == null || nominal.Bandingkan(Uang.Nol()) <= 0) {
      setState(() => _galat = 'Isi jumlah pembayaran.');
      return;
    }
    if (metode.Jenis == JenisMetodeBayar.qrisStatis && !_qrisDikonfirmasi) {
      setState(() => _galat = 'Pastikan dana QRIS sudah masuk, lalu centang konfirmasi.');
      return;
    }
    if (metode.Jenis == JenisMetodeBayar.edc && _referensi.text.trim().isEmpty) {
      setState(() => _galat = 'Isi nomor approval dari struk EDC.');
      return;
    }
    if (metode.Jenis == JenisMetodeBayar.tempo && !await _PastikanTempoDisetujui(k, nominal)) {
      return;
    }
    if (metode.Jenis == JenisMetodeBayar.deposit) {
      final saldo = _saldoDeposit;
      if (saldo == null) {
        setState(() => _galat = _pesanSaldoDeposit ?? 'Tunggu saldo deposit selesai dicek.');
        return;
      }
      if (nominal.Bandingkan(saldo) > 0) {
        setState(() => _galat = 'Saldo deposit tinggal ${saldo.FormatRupiah()}. Bayar sisanya dengan metode lain.');
        return;
      }
    }
    if (metode.Jenis == JenisMetodeBayar.giftCard) {
      final kartu = _kartuGiftCard;
      if (kartu == null) {
        setState(() => _galat = _pesanGiftCard ?? 'Cek kode gift card dulu dengan tombol Cek saldo.');
        return;
      }
      if (!kartu.bisaDipakai) {
        setState(() => _galat = kartu.alasan);
        return;
      }
      if (nominal.Bandingkan(kartu.saldo) > 0) {
        setState(
          () => _galat = 'Saldo gift card tinggal ${kartu.saldo.FormatRupiah()}. Bayar sisanya dengan metode lain.',
        );
        return;
      }
    }
    final sisa = _HitungSisa(k, metode);
    if (metode.Jenis != JenisMetodeBayar.tunai && nominal.Bandingkan(sisa) > 0) {
      setState(() => _galat = 'Pembayaran ${metode.Nama} tidak boleh melebihi sisa ${sisa.FormatRupiah()}.');
      return;
    }
    if (metode.Jenis == JenisMetodeBayar.qrisDinamis) {
      await _BayarQrisDinamis(k, metode, nominal, sisa);
      return;
    }
    // K-14 bagi rata: tamu (bukan terakhir) membayar porsinya; uang tunai lebih → kembalian tamu itu.
    if (_bagi?.porsi != null && !_tamuTerakhir) {
      final porsi = _AmbilTagihanTamu(sisa);
      if (nominal.Bandingkan(porsi) < 0) {
        setState(() => _galat = 'Porsi tamu $_tamuKe ${porsi.FormatRupiah()}. Isi paling sedikit sebesar porsinya.');
        return;
      }
      if (metode.Jenis != JenisMetodeBayar.tunai && nominal.Bandingkan(porsi) > 0) {
        setState(() => _galat = 'Pembayaran ${metode.Nama} tamu $_tamuKe harus ${porsi.FormatRupiah()}.');
        return;
      }
      final kembalian = nominal.Kurangi(porsi);
      setState(() {
        _entri.add(
          PembayaranMasukan(
            metode: metode,
            jumlah: porsi,
            referensi: _SusunReferensi(metode),
            tamu: BagianTamu(namaMetode: metode.Nama, porsi: porsi, diterima: nominal),
          ),
        );
        _infoBagi = kembalian.BernilaiNol()
            ? 'Tamu $_tamuKe lunas (${metode.Nama}).'
            : 'Tamu $_tamuKe lunas. Kembalian ${kembalian.FormatRupiah()}.';
        _tamuKe++;
        _metode = null;
        _nominal.clear();
        _galat = null;
      });
      return;
    }
    final entri = PembayaranMasukan(
      metode: metode,
      jumlah: nominal,
      referensi: _SusunReferensi(metode),
      // K-14: bagian tamu ini (tamu terakhir menanggung sisa; tunai boleh menyerahkan lebih dari bagiannya).
      tamu: _bagi == null
          ? null
          : BagianTamu(
              namaMetode: metode.Nama,
              porsi: nominal.Bandingkan(sisa) < 0 ? nominal : sisa,
              diterima: nominal,
            ),
    );
    if (nominal.Bandingkan(sisa) < 0) {
      // Split (BR-08.1): simpan bagian ini, lanjutkan dengan metode lain.
      setState(() {
        _entri.add(entri);
        if (_bagi != null) {
          _infoBagi = 'Tamu $_tamuKe membayar ${nominal.FormatRupiah()} (${metode.Nama}).';
          _tamuKe++;
        }
        _metode = null;
        _nominal.clear();
        _galat = null;
      });
      return;
    }
    await _Selesaikan(k, [..._entri, entri]);
  }

  /// v2.05: QRIS dinamis. Pembayaran baru dicatat setelah server menyatakan tagihan lunas; Uuid tagihan menjadi
  /// referensi. Dana sudah masuk sehingga entri langsung disimpan di daftar (tidak bisa dihapus kasir) sebelum
  /// penjualan diselesaikan: bila penyimpanan gagal, kasir cukup menekan "Selesaikan pembayaran" lagi tanpa QRIS baru.
  Future<void> _BayarQrisDinamis(KonteksPenjualan k, BarisMetodePembayaran metode, Uang nominal, Uang sisa) async {
    // Tanpa keterangan: nama pelanggan/label meja tidak dikirim ke gerbang pembayaran (data pribadi).
    final tagihan = await showDialog<TagihanQrisPos>(
      context: context,
      barrierDismissible: false,
      builder: (_) => DialogQrisDinamis(metode: metode, jumlah: nominal),
    );
    if (tagihan == null || !mounted) {
      return;
    }
    setState(() {
      _entri.add(
        PembayaranMasukan(
          metode: metode,
          jumlah: nominal,
          referensi: tagihan.uuid,
          tamu: _bagi == null ? null : BagianTamu(namaMetode: metode.Nama, porsi: nominal, diterima: nominal),
        ),
      );
      if (_bagi != null && nominal.Bandingkan(sisa) < 0) {
        _infoBagi = 'Tamu $_tamuKe membayar ${nominal.FormatRupiah()} (${metode.Nama}).';
        _tamuKe++;
      }
      _metode = null;
      _nominal.clear();
      _galat = null;
    });
    if (nominal.Bandingkan(sisa) >= 0) {
      await _Selesaikan(k, List.of(_entri));
    }
  }

  /// BR-12.1: tempo di luar limit/lewat jatuh tempo → PIN penyetuju ber-izin `penjualan.tempo.setujui` (kasir
  /// ber-izin cukup dirinya). False = dibatalkan.
  Future<bool> _PastikanTempoDisetujui(KonteksPenjualan k, Uang nominal) async {
    final pelanggan = ref.read(penyediaKeranjangEfektif).pelanggan;
    if (pelanggan == null) {
      setState(() => _galat = 'Pilih pelanggan dulu untuk pembayaran tempo.');
      return false;
    }
    final alasan = LayananPenjualan.PeriksaTempo(
      pelanggan: pelanggan,
      jumlah: nominal,
      batasHariLewat: k.batasHariLewatJatuhTempo,
    );
    if (alasan.isEmpty || _penyetujuTempo != null || widget.kasir.PunyaIzin(IzinKasir.penjualanTempoSetujui)) {
      return true;
    }
    final staf = await MintaPenyetujuSementara(
      context,
      DialogPinSupervisor(
        izin: IzinKasir.penjualanTempoSetujui,
        pesan: 'Tempo ${pelanggan.nama} perlu persetujuan: ${alasan.join('; ')}. Pilih penyetuju.',
        judul: 'Penjualan tempo ${pelanggan.nama}',
        nilai: nominal,
      ),
    );
    if (staf == null) {
      return false;
    }
    setState(() => _penyetujuTempo = staf);
    return true;
  }

  /// K-14: pilih cara bagi tagihan (rata per orang atau per nominal).
  Future<void> _MulaiBagi(Uang total) async {
    final hasil = await showDialog<BagiTagihan>(
      context: context,
      builder: (_) => DialogBagiTagihan(total: total),
    );
    if (hasil == null || !mounted) {
      return;
    }
    setState(() {
      _bagi = hasil;
      _tamuKe = 1;
      _infoBagi = null;
      _metode = null;
      _nominal.clear();
      _galat = null;
    });
  }

  /// F9: bayar sisa tagihan dengan tunai uang pas (pembulatan tunai BR-08.6 ikut dihitung) lalu simpan.
  Future<void> BayarUangPas() async {
    if (_sibuk) {
      return;
    }
    final k = await ref.read(penyediaKonteksPenjualan.future);
    final tunai = k.metodePembayaran.where((m) => m.Jenis == JenisMetodeBayar.tunai).firstOrNull;
    if (tunai == null || _entri.any((p) => p.CekTunai())) {
      if (mounted) {
        setState(() => _galat = 'Uang pas tidak bisa dipakai: metode tunai tidak tersedia atau sudah dipakai.');
      }
      return;
    }
    final tagihan = ref
        .read(penyediaLayananPenjualan)
        .HitungTagihanTunai(ref.read(penyediaKeranjangEfektif), k, _entri);
    if (tagihan.Bandingkan(Uang.Nol()) <= 0) {
      await _Selesaikan(k, List.of(_entri));
      return;
    }
    await _Selesaikan(k, [..._entri, PembayaranMasukan(metode: tunai, jumlah: tagihan)]);
  }

  /// Apotek bagian 2: syarat penyerahan obat keranjang saat ini (kosong = tidak ada obat yang diatur).
  SyaratApotek _AmbilSyaratApotek() =>
      AturanApotek.Periksa(ref.read(penyediaKeranjangEfektif), ref.read(penyediaKatalog).value);

  bool get _kasirApoteker => widget.kasir.PunyaIzin(IzinKasir.apotekObatKerasJual);

  /// Isi/ubah resep dokter (PMK 73/2016). Batal = resep lama tetap.
  Future<void> _IsiResep(KonteksPenjualan k, SyaratApotek syarat) async {
    final resep = await showDialog<ResepPenjualan>(
      context: context,
      builder: (_) => DialogResep(
        hariIni: AturanApotek.HitungHariIni(ref.read(penyediaJam)(), k.zonaWaktu),
        wajibAlamat: syarat.wajibAlamat,
        namaObat: SyaratApotek.SebutNama(syarat.barisWajibResep),
        awal: _resep,
      ),
    );
    if (resep != null && mounted) {
      setState(() {
        _resep = resep;
        _galat = null;
      });
    }
  }

  /// Obat keras/psikotropika/narkotika diserahkan apoteker: PIN staf ber-izin `apotek.obat-keras.jual` dari data staf
  /// tersimpan (bisa offline). Tanpa persetujuan jarak jauh: apoteker harus ada di tempat.
  Future<void> _MintaApoteker(SyaratApotek syarat) async {
    final staf = await showDialog<StafLokal>(
      context: context,
      builder: (_) => DialogPinSupervisor(
        izin: IzinKasir.apotekObatKerasJual,
        judulDialog: 'PIN apoteker',
        pesan:
            '${SyaratApotek.SebutNama(syarat.barisWajibApoteker)} hanya boleh diserahkan apoteker. Pilih apoteker '
            'yang menyerahkan obat.',
        pesanKosong:
            'Belum ada apoteker berizin di perangkat ini. Obat ini tidak bisa diserahkan; keluarkan dari keranjang '
            'atau minta pemilik memberi izin "apotek.obat-keras.jual" lalu sinkronkan perangkat.',
        bolehJarakJauh: false,
      ),
    );
    if (staf != null && mounted) {
      setState(() {
        _apoteker = staf;
        _galat = null;
      });
    }
  }

  /// Tukar barang: persetujuan retur diminta di sini, satu kali, setelah barang pengganti dipilih dan nilainya pasti.
  /// Dibatalkan = pembayaran tidak dilanjutkan, keranjang tukar tetap utuh.
  Future<bool> _PastikanPenyetujuTukar(KonteksPenjualan k) async {
    final keranjang = ref.read(penyediaKeranjangEfektif);
    final tukar = keranjang.tukar;
    final persetujuan = tukar?.penyetuju;
    if (tukar == null || persetujuan == null) {
      return true;
    }
    // Kewenangan dinilai sekarang dengan kasir yang membayar (bisa berbeda dari yang memulai tukar).
    persetujuan.kasir = widget.kasir;
    if (persetujuan.bolehSendiri && widget.kasir.PunyaIzin(persetujuan.izin)) {
      persetujuan.staf = widget.kasir;
      return true;
    }
    if (persetujuan.siap && persetujuan.staf!.PunyaIzin(persetujuan.izin)) {
      return true;
    }
    // Barang pengganti kurang dari nilai retur tanpa struk akan ditolak layanan dengan pesan yang jelas; PIN tidak
    // perlu diketik untuk transaksi yang pasti gagal.
    final total = ref.read(penyediaLayananPenjualan).Hitung(keranjang, k).hasil.totalAkhir;
    if (tukar.tanpaStruk && tukar.HitungDipakai(total).Bandingkan(tukar.nilai) < 0) {
      return true;
    }
    final staf = await showDialog<StafLokal>(
      context: context,
      builder: (_) => DialogPinSupervisor(
        izin: persetujuan.izin,
        judulDialog: 'Persetujuan retur tukar barang',
        pesan: persetujuan.pesan,
        judul: 'Retur tukar barang',
        nilai: tukar.nilai,
      ),
    );
    if (staf == null || !mounted) {
      return false;
    }
    persetujuan.staf = staf;
    return true;
  }

  Future<void> _Selesaikan(KonteksPenjualan k, List<PembayaranMasukan> pembayaran) async {
    // Penjaga ketuk ganda: `_sibuk` dipasang sebelum `await` pertama, supaya dua ketukan di frame yang sama tidak
    // sama-sama lolos dan menyimpan dua penjualan.
    if (_sibuk) {
      return;
    }
    setState(() {
      _sibuk = true;
      _galat = null;
    });
    final syarat = _AmbilSyaratApotek();
    try {
      if (!await _PastikanPenyetujuTukar(k)) {
        return;
      }
      final tamu = [for (final p in pembayaran) ?p.tamu];
      final hasil = await ref
          .read(penyediaLayananPenjualan)
          .Bayar(
            // K-14: struk per tamu hanya bila tagihan benar-benar dibayar oleh dua tamu atau lebih.
            bagianTagihan: _bagi != null && tamu.length >= 2
                ? BagianTagihan(rata: !_bagi!.perNominal, tamu: tamu)
                : null,
            keranjang: ref.read(penyediaKeranjangEfektif),
            pembayaran: _GabungTunai(pembayaran),
            kasir: widget.kasir,
            k: k,
            uuidPenyetujuTempo: pembayaran.any((p) => p.metode.Jenis == JenisMetodeBayar.tempo)
                ? _penyetujuTempo?.uuid
                : null,
            saldoDeposit: _saldoDeposit,
            katalog: ref.read(penyediaKatalog).value,
            latihan: ref.read(penyediaModeLatihan),
            resep: syarat.CekWajibResep ? _resep : null,
            uuidApoteker: syarat.CekWajibApoteker && !_kasirApoteker ? _apoteker?.uuid : null,
          );
      ref.read(penyediaKeranjang.notifier).Kosongkan();
      final sesi = ref.read(penyediaSesi.notifier);
      // Dibaca sebelum [widget.saatSelesai]: panel ini bisa dilepas dari pohon widget sesudahnya.
      final suara = ref.read(penyediaSuaraPembayaran);
      widget.saatSelesai(hasil);
      if (!hasil.latihan) {
        // Bunyi tanda berhasil, tidak ditunggu supaya layar selesai tidak tertunda oleh pemutaran.
        unawaited(suara.Mainkan());
        await sesi.Sinkronkan();
      }
    } on GalatKasir catch (galat) {
      if (mounted) {
        setState(() => _galat = galat.pesan);
      }
    } finally {
      if (mounted) {
        setState(() => _sibuk = false);
      }
    }
  }

  Widget _BangunTunai(BuildContext context, KonteksPenjualan k) {
    final tagihan = _AmbilTagihanTamu(_HitungSisa(k, _metode));
    final nominal = _AmbilNominal();
    final kembalian = nominal?.Kurangi(tagihan);
    final teks = Theme.of(context).textTheme;
    Widget Tombol(String label, Uang nilai) => SizedBox(
      height: TokenJarak.targetSentuh,
      child: OutlinedButton(
        onPressed: () => setState(() {
          MasukanUang.Isi(_nominal, Uang.Dari(nilai.KeDesimal().ceil().toString()));
          _galat = null;
        }),
        child: Text(label),
      ),
    );
    final papanAngka = PapanAngka(
      saatTekan: (tombol) => setState(() {
        final digit = PapanAngka.Terapkan(_nominal.text.replaceAll('.', ''), tombol);
        MasukanUang.Isi(_nominal, digit.isEmpty ? null : Uang.Dari(digit));
        _galat = null;
      }),
    );
    final kembalianBaris = kembalian != null && !kembalian.BernilaiNegatif()
        ? Row(
            children: [
              Expanded(child: Text('Kembalian', style: teks.titleMedium)),
              TeksUang(kembalian, gaya: teks.titleMedium),
            ],
          )
        : null;
    final ringkasan = <Widget>[
      Row(
        children: [
          Expanded(
            child: Text(
              _bagi?.porsi != null && !_tamuTerakhir ? 'Porsi tamu $_tamuKe' : 'Tagihan tunai',
              style: teks.bodyMedium,
            ),
          ),
          TeksUang(tagihan, gaya: teks.titleMedium),
        ],
      ),
      const SizedBox(height: TokenJarak.jarak8),
      Wrap(
        spacing: TokenJarak.jarak8,
        runSpacing: TokenJarak.jarak8,
        children: [
          // Audit kemudahan pakai: "Uang pas" langsung menyelesaikan pembayaran (Bayar → Uang pas = 2 ketukan),
          // kecuali sedang membagi tagihan per orang (porsi tamu diisi dulu).
          if (_bagi?.porsi == null || _tamuTerakhir)
            SizedBox(
              height: TokenJarak.targetSentuh,
              child: FilledButton(
                onPressed: _sibuk
                    ? null
                    : () {
                        // Uang diterima = tagihan dibulatkan ke rupiah utuh, lalu langsung diterapkan.
                        MasukanUang.Isi(_nominal, Uang.Dari(tagihan.KeDesimal().ceil().toString()));
                        _galat = null;
                        unawaited(_Terapkan(k));
                      },
                child: const Text('Uang pas'),
              ),
            )
          else
            Tombol('Uang pas', tagihan),
          for (final p in HitungPecahanCepat(tagihan)) Tombol(p.FormatRupiah(), p),
        ],
      ),
      const SizedBox(height: TokenJarak.jarak12),
      TextField(
        controller: _nominal,
        keyboardType: TextInputType.number,
        inputFormatters: [MasukanUang.pemformat],
        textAlign: TextAlign.right,
        style: teks.titleMedium?.copyWith(fontFeatures: const [FontFeature.tabularFigures()]),
        onChanged: (_) => setState(() => _galat = null),
        onSubmitted: (_) => _Terapkan(k),
        decoration: const InputDecoration(labelText: 'Uang diterima', prefixText: 'Rp ', border: OutlineInputBorder()),
      ),
    ];
    return LayoutBuilder(
      builder: (context, batas) {
        // Panel lebar (D-40): papan angka berdiri di samping ringkasan supaya alur tunai muat tanpa gulir,
        // bukan menumpuk di bawahnya sementara sisi kanan panel menganggur.
        if (batas.maxWidth >= 560) {
          final lebarPapan = (batas.maxWidth * 0.5).clamp(300.0, 400.0).toDouble();
          return Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    ...ringkasan,
                    if (kembalianBaris != null) ...[const SizedBox(height: TokenJarak.jarak8), kembalianBaris],
                  ],
                ),
              ),
              const SizedBox(width: TokenJarak.jarak16),
              SizedBox(width: lebarPapan, child: papanAngka),
            ],
          );
        }
        return Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            ...ringkasan,
            const SizedBox(height: TokenJarak.jarak8),
            papanAngka,
            if (kembalianBaris != null) ...[const SizedBox(height: TokenJarak.jarak8), kembalianBaris],
          ],
        );
      },
    );
  }

  /// QRIS statis toko yang diberi nominal pembayaran ini (dihitung di perangkat, tanpa gerbang). Null bila toko belum
  /// mengaktifkan nominal otomatis, nominal belum diisi, atau teks QRIS tidak sah: kasir tetap memakai gambar QRIS statis.
  String? _BuatQrisBernominal(BarisMetodePembayaran metode) {
    final teksQris = metode.TeksQris;
    final nominal = _AmbilNominal();
    if (teksQris == null || nominal == null || nominal.Bandingkan(Uang.Nol()) <= 0) {
      return null;
    }
    try {
      return Qris.BuatDinamis(teksQris, nominal.KeString());
    } on GalatQris {
      return null;
    }
  }

  Widget _BangunNonTunai(BuildContext context, KonteksPenjualan k, BarisMetodePembayaran metode) {
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (metode.Jenis == JenisMetodeBayar.qrisStatis) ...[
          if (_BuatQrisBernominal(metode) case final qrisDinamis?) ...[
            Center(
              child: GambarQr(
                key: const ValueKey('QrisNominalOtomatis'),
                data: qrisDinamis,
                label: 'Kode QRIS ${metode.Nama} ${(_AmbilNominal() ?? Uang.Nol()).FormatRupiah()}',
              ),
            ),
            const SizedBox(height: TokenJarak.jarak4),
            Text(
              'Nominal ${(_AmbilNominal() ?? Uang.Nol()).FormatRupiah()} sudah terisi di QRIS. Pelanggan tinggal memindai dan membayar.',
              textAlign: TextAlign.center,
              style: teks.bodySmall,
            ),
          ] else if (metode.AdaGambarQris)
            ref
                .watch(penyediaGambarQris(metode.Uuid))
                .when(
                  loading: () => const SizedBox(height: 240, child: Center(child: CircularProgressIndicator())),
                  error: (_, _) => Text(
                    'Gambar QRIS tidak bisa dimuat. Minta pelanggan memindai QRIS yang tercetak di meja kasir.',
                    style: teks.bodySmall?.copyWith(color: warna.peringatan),
                  ),
                  data: (bait) => Center(
                    child: Semantics(
                      label: 'Kode QRIS ${metode.Nama}',
                      image: true,
                      child: Image.memory(
                        bait,
                        height: 240,
                        fit: BoxFit.contain,
                        errorBuilder: (_, _, _) => Text('Gambar QRIS rusak. Pakai QRIS cetak.', style: teks.bodySmall),
                      ),
                    ),
                  ),
                )
          else
            Text('Minta pelanggan memindai QRIS yang tercetak di meja kasir.', style: teks.bodySmall),
          const SizedBox(height: TokenJarak.jarak8),
          Material(
            type: MaterialType.transparency,
            child: CheckboxListTile(
              contentPadding: EdgeInsets.zero,
              controlAffinity: ListTileControlAffinity.leading,
              value: _qrisDikonfirmasi,
              onChanged: (v) => setState(() {
                _qrisDikonfirmasi = v ?? false;
                _galat = null;
              }),
              title: const Text('Dana sudah masuk (cek notifikasi atau aplikasi bank)'),
            ),
          ),
        ],
        if (metode.Jenis == JenisMetodeBayar.transfer && metode.NomorRekening != null) ...[
          Text(
            'Rekening ${metode.NomorRekening}${metode.NamaPemilikRekening == null ? '' : ' a.n. ${metode.NamaPemilikRekening}'}',
            style: teks.bodyMedium,
          ),
          const SizedBox(height: TokenJarak.jarak8),
        ],
        if (metode.Jenis == JenisMetodeBayar.tempo) ...[
          _BangunInfoTempo(context, k),
          const SizedBox(height: TokenJarak.jarak8),
        ],
        if (metode.Jenis == JenisMetodeBayar.edc) ...[
          TextField(
            controller: _bank,
            maxLength: LayananPenjualan.panjangMaksBankEdc,
            decoration: const InputDecoration(
              labelText: 'Bank penerbit kartu (opsional)',
              border: OutlineInputBorder(),
            ),
          ),
          const SizedBox(height: TokenJarak.jarak4),
        ],
        if (metode.Jenis == JenisMetodeBayar.deposit) ...[
          _BangunInfoDeposit(context),
          const SizedBox(height: TokenJarak.jarak8),
        ],
        if (metode.Jenis == JenisMetodeBayar.giftCard) ...[
          _BangunKodeGiftCard(context, k),
          const SizedBox(height: TokenJarak.jarak8),
        ],
        if (metode.Jenis != JenisMetodeBayar.tempo &&
            metode.Jenis != JenisMetodeBayar.deposit &&
            metode.Jenis != JenisMetodeBayar.giftCard)
          TextField(
            controller: _referensi,
            maxLength: metode.Jenis == JenisMetodeBayar.edc ? LayananPenjualan.panjangMaksApprovalEdc : 60,
            onChanged: (_) => setState(() => _galat = null),
            decoration: InputDecoration(
              labelText: switch (metode.Jenis) {
                JenisMetodeBayar.edc => 'Nomor approval (di struk EDC)',
                JenisMetodeBayar.marketplace => 'Nomor pesanan ${metode.Nama} (opsional)',
                _ => 'Referensi (opsional)',
              },
              border: const OutlineInputBorder(),
            ),
          ),
        const SizedBox(height: TokenJarak.jarak4),
        TextField(
          controller: _nominal,
          keyboardType: TextInputType.number,
          inputFormatters: [MasukanUang.pemformat],
          textAlign: TextAlign.right,
          style: const TextStyle(fontFeatures: [FontFeature.tabularFigures()]),
          onChanged: (_) => setState(() => _galat = null),
          decoration: const InputDecoration(labelText: 'Jumlah', prefixText: 'Rp ', border: OutlineInputBorder()),
        ),
      ],
    );
  }

  /// Apotek bagian 2: ringkasan syarat penyerahan obat (resep dokter, apoteker) dan tombol untuk melengkapinya. Status
  /// selalu berteks + ikon, bukan warna saja.
  Widget _BangunApotek(BuildContext context, KonteksPenjualan k, SyaratApotek syarat) {
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    final resep = _resep;
    final apoteker = _kasirApoteker ? widget.kasir : _apoteker;
    Widget Status(bool lengkap, String isi) => Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Icon(
          lengkap ? Icons.check_circle_outline : Icons.error_outline,
          size: TokenJarak.ikonKecil,
          color: lengkap ? warna.sukses : warna.peringatan,
        ),
        const SizedBox(width: TokenJarak.jarak8),
        Expanded(child: Text(isi, style: teks.bodyMedium)),
      ],
    );
    return Container(
      key: const ValueKey('SyaratApotek'),
      padding: const EdgeInsets.all(TokenJarak.jarak12),
      decoration: BoxDecoration(
        border: Border.all(color: warna.garis, width: TokenJarak.tebalGaris),
        borderRadius: BorderRadius.circular(TokenJarak.radiusPanel),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text('Penyerahan obat', style: teks.titleSmall),
          if (syarat.CekWajibResep) ...[
            const SizedBox(height: TokenJarak.jarak8),
            Status(
              resep != null,
              resep == null
                  ? 'Wajib resep dokter: ${SyaratApotek.SebutNama(syarat.barisWajibResep)}.'
                  : AturanApotek.SusunBarisStruk(resep.nomorResep, resep.namaDokter),
            ),
            const SizedBox(height: TokenJarak.jarak4),
            Align(
              alignment: Alignment.centerLeft,
              child: SizedBox(
                height: TokenJarak.targetSentuh,
                child: resep == null
                    ? FilledButton.icon(
                        onPressed: _sibuk ? null : () => unawaited(_IsiResep(k, syarat)),
                        icon: const Icon(Icons.description_outlined),
                        label: const Text('Isi resep dokter'),
                      )
                    : OutlinedButton(
                        onPressed: _sibuk ? null : () => unawaited(_IsiResep(k, syarat)),
                        child: const Text('Ubah resep'),
                      ),
              ),
            ),
          ],
          if (syarat.CekWajibApoteker) ...[
            const SizedBox(height: TokenJarak.jarak8),
            Status(
              apoteker != null,
              apoteker == null
                  ? 'Hanya diserahkan apoteker: ${SyaratApotek.SebutNama(syarat.barisWajibApoteker)}.'
                  : 'Diserahkan apoteker ${apoteker.nama}.',
            ),
            if (apoteker == null) ...[
              const SizedBox(height: TokenJarak.jarak4),
              Align(
                alignment: Alignment.centerLeft,
                child: SizedBox(
                  height: TokenJarak.targetSentuh,
                  child: FilledButton.tonalIcon(
                    onPressed: _sibuk ? null : () => unawaited(_MintaApoteker(syarat)),
                    icon: const Icon(Icons.pin_outlined),
                    label: const Text('PIN apoteker'),
                  ),
                ),
              ),
            ],
          ],
        ],
      ),
    );
  }

  /// CRM-06: isian kode gift card + tombol Cek saldo, lalu saldo/masa berlaku atau alasan kartu tak bisa dipakai. Mengubah
  /// kode setelah dicek membatalkan hasil cek (kode yang dibayar harus kode yang dicek).
  Widget _BangunKodeGiftCard(BuildContext context, KonteksPenjualan k) {
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    final kartu = _kartuGiftCard;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Expanded(
              child: TextField(
                controller: _referensi,
                maxLength: 20,
                textCapitalization: TextCapitalization.characters,
                autocorrect: false,
                enableSuggestions: false,
                onChanged: (_) => setState(() {
                  _galat = null;
                  _kartuGiftCard = null;
                  _pesanGiftCard = null;
                }),
                onSubmitted: (_) => unawaited(_CekGiftCard(k)),
                decoration: const InputDecoration(labelText: 'Kode gift card', border: OutlineInputBorder()),
              ),
            ),
            const SizedBox(width: TokenJarak.jarak8),
            SizedBox(
              height: TokenJarak.targetSentuh,
              child: OutlinedButton(
                onPressed: _memuatGiftCard || _sibuk ? null : () => unawaited(_CekGiftCard(k)),
                child: const Text('Cek saldo'),
              ),
            ),
          ],
        ),
        if (_memuatGiftCard)
          const LinearProgressIndicator()
        else if (_pesanGiftCard != null)
          Semantics(
            liveRegion: true,
            child: Text(_pesanGiftCard!, style: teks.bodyMedium?.copyWith(color: warna.bahaya)),
          )
        else if (kartu != null) ...[
          Row(
            children: [
              Expanded(child: Text('Saldo ${kartu.samaran}', style: teks.bodyMedium)),
              TeksUang(kartu.saldo, gaya: teks.titleMedium),
            ],
          ),
          if (kartu.alasan != null)
            Row(
              children: [
                Icon(Icons.error_outline, size: TokenJarak.ikonKecil, color: warna.peringatan),
                const SizedBox(width: TokenJarak.jarak4),
                Expanded(child: Text(kartu.alasan!, style: teks.bodyMedium)),
              ],
            )
          else if (kartu.berlakuSampai != null)
            Text('Berlaku sampai ${kartu.berlakuSampai}', style: teks.bodySmall?.copyWith(color: warna.teksSekunder)),
        ],
      ],
    );
  }

  /// F-16d: saldo deposit pelanggan (online) atau alasan belum bisa dipakai.
  Widget _BangunInfoDeposit(BuildContext context) {
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    final saldo = _saldoDeposit;
    if (_memuatSaldoDeposit) {
      return const LinearProgressIndicator();
    }
    if (saldo == null) {
      return Text(
        _pesanSaldoDeposit ?? 'Saldo deposit belum dicek.',
        style: teks.bodyMedium?.copyWith(color: warna.bahaya),
      );
    }
    return Row(
      children: [
        Expanded(child: Text('Saldo deposit', style: teks.bodyMedium)),
        TeksUang(saldo, gaya: teks.titleMedium),
      ],
    );
  }

  /// F-12: posisi kredit pelanggan (terakhir diketahui perangkat) dan alasan butuh penyetuju untuk jumlah saat ini.
  Widget _BangunInfoTempo(BuildContext context, KonteksPenjualan k) {
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    final pelanggan = ref.watch(penyediaKeranjangEfektif).pelanggan;
    if (pelanggan == null) {
      return Text('Pilih pelanggan dulu untuk pembayaran tempo.', style: TextStyle(color: warna.bahaya));
    }
    final alasan = LayananPenjualan.PeriksaTempo(
      pelanggan: pelanggan,
      jumlah: _AmbilNominal() ?? Uang.Nol(),
      batasHariLewat: k.batasHariLewatJatuhTempo,
    );
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text(PelangganTerpilihKredit.Ringkas(pelanggan), style: teks.bodyMedium),
        if (alasan.isNotEmpty)
          Padding(
            padding: const EdgeInsets.only(top: TokenJarak.jarak4),
            child: Text(
              _penyetujuTempo != null
                  ? 'Disetujui ${_penyetujuTempo!.nama}.'
                  : widget.kasir.PunyaIzin(IzinKasir.penjualanTempoSetujui)
                  ? 'Perhatian: ${alasan.join('; ')}.'
                  : 'Perlu PIN penyetuju: ${alasan.join('; ')}.',
              style: teks.bodySmall?.copyWith(color: _penyetujuTempo != null ? warna.teksSekunder : warna.peringatan),
            ),
          ),
      ],
    );
  }

  /// Konteks pesanan (pelanggan, jenis pesanan, catatan) masih bisa diubah selama belum ada pembayaran tercatat dan
  /// tagihan belum dibagi: mengubahnya setelah itu dapat mengubah tempo, deposit, atau pembagian yang sudah dihitung.
  bool get _KonteksTerkunci => _sibuk || _entri.isNotEmpty || _bagi != null;

  Future<void> _BukaPelanggan() async {
    if (_KonteksTerkunci) {
      return;
    }
    await showDialog<void>(
      context: context,
      builder: (konteks) {
        final tinggi = MediaQuery.sizeOf(konteks).height * 0.86;
        return Dialog(
          insetPadding: const EdgeInsets.all(TokenJarak.jarak16),
          child: ConstrainedBox(
            constraints: BoxConstraints(maxWidth: 560, maxHeight: tinggi > 760 ? 760 : tinggi),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                Padding(
                  padding: const EdgeInsets.fromLTRB(TokenJarak.jarak16, TokenJarak.jarak8, TokenJarak.jarak8, 0),
                  child: Row(
                    children: [
                      Expanded(child: Text('Pelanggan', style: Theme.of(konteks).textTheme.titleMedium)),
                      IconButton(
                        tooltip: 'Tutup',
                        onPressed: () => Navigator.of(konteks).pop(),
                        icon: const Icon(Icons.close),
                      ),
                    ],
                  ),
                ),
                Flexible(
                  child: PanelPelanggan(kasir: widget.kasir, saatSelesai: () => Navigator.of(konteks).pop()),
                ),
              ],
            ),
          ),
        );
      },
    );
  }

  /// X8: ganti kanal sekaligus harga baris. Sama dengan pemilih kanal di keranjang, tetapi bisa dikoreksi dari layar
  /// Bayar sebelum uang diterima.
  Future<void> _PilihKanal(KonteksPenjualan k) async {
    if (_KonteksTerkunci) {
      return;
    }
    final katalog = ref.read(penyediaKatalog).value;
    if (katalog == null) {
      return;
    }
    final pilihan = LayananPenjualan.AmbilPilihanKanal(k, katalog);
    final keranjang = ref.read(penyediaKeranjang);
    final sekarang = LayananPenjualan.AmbilKanal(keranjang);
    final kanal = await showDialog<KanalPenjualan>(
      context: context,
      builder: (konteks) => SimpleDialog(
        title: const Text('Jenis pesanan'),
        children: [
          for (final p in pilihan)
            ListTile(
              key: ValueKey('KanalBayar-${p.name}'),
              leading: Icon(
                LayananPenjualan.kanalPlatform.contains(p) ? Icons.delivery_dining : Icons.storefront_outlined,
              ),
              title: Text(LayananPenjualan.AmbilLabelKanal(p)),
              trailing: p == sekarang ? const Icon(Icons.check) : null,
              onTap: () => Navigator.of(konteks).pop(p),
            ),
        ],
      ),
    );
    if (kanal == null || kanal == sekarang || !mounted) {
      return;
    }
    ref
        .read(penyediaKeranjang.notifier)
        .Ganti(ref.read(penyediaLayananPenjualan).GantiKanal(keranjang, kanal, katalog, k));
  }

  Future<void> _UbahCatatan() async {
    if (_KonteksTerkunci) {
      return;
    }
    final keranjang = ref.read(penyediaKeranjang);
    final hasil = await showDialog<String>(
      context: context,
      builder: (_) => _DialogCatatan(awal: keranjang.catatan ?? ''),
    );
    if (hasil == null || !mounted) {
      return;
    }
    final rapi = hasil.trim();
    ref.read(penyediaKeranjang.notifier).Ganti(keranjang.Salin(catatan: () => rapi.isEmpty ? null : rapi));
  }

  /// Satu baris konteks yang bisa diketuk (pelanggan, jenis pesanan, catatan). Tanpa aksi = tampil sebagai keterangan.
  Widget _BangunBarisKonteks(
    BuildContext context, {
    required IconData ikon,
    required String label,
    required String nilai,
    String? keterangan,
    VoidCallback? saatKetuk,
    String? kunci,
  }) {
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    return Semantics(
      button: saatKetuk != null,
      label: '$label: $nilai',
      excludeSemantics: true,
      // Material transparan agar riak sentuh InkWell terlihat di atas latar panel yang opak.
      child: Material(
        type: MaterialType.transparency,
        child: InkWell(
          key: kunci == null ? null : ValueKey(kunci),
          onTap: saatKetuk,
          borderRadius: BorderRadius.circular(TokenJarak.radiusKontrol),
          child: ConstrainedBox(
            constraints: const BoxConstraints(minHeight: TokenJarak.targetSentuh),
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: TokenJarak.jarak12, vertical: TokenJarak.jarak8),
              child: Row(
                children: [
                  Icon(ikon, size: TokenJarak.ikonKecil, color: warna.teksSekunder),
                  const SizedBox(width: TokenJarak.jarak12),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(label, style: teks.bodySmall?.copyWith(color: warna.teksSekunder)),
                        Text(nilai, style: teks.bodyMedium?.copyWith(fontWeight: FontWeight.w600)),
                        if (keterangan != null)
                          Text(keterangan, style: teks.bodySmall?.copyWith(color: warna.teksSekunder)),
                      ],
                    ),
                  ),
                  if (saatKetuk != null)
                    Icon(Icons.chevron_right, size: TokenJarak.ikonKecil, color: warna.teksSekunder),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }

  Widget _BangunBarisPesanan(BuildContext context, ItemKeranjang item, Uang nilai) {
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    // Jumlah sudah tampil sebagai lencana di kiri, jadi rincian hanya memuat satuan, pilihan, dan catatan.
    final rincian = [
      if (item.namaSatuan != null) item.namaSatuan!,
      ...item.pilihan.map((p) => p.nama),
      if (item.catatan != null) item.catatan!,
    ].join(' | ');
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: TokenJarak.jarak16, vertical: TokenJarak.jarak8),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Container(
            constraints: const BoxConstraints(minWidth: 28),
            padding: const EdgeInsets.symmetric(horizontal: TokenJarak.jarak4, vertical: 2),
            decoration: BoxDecoration(
              color: warna.latar,
              borderRadius: BorderRadius.circular(TokenJarak.radiusKontrol),
            ),
            child: Text(
              FormatAngka.FormatJumlah(item.jumlah),
              textAlign: TextAlign.center,
              style: teks.bodySmall?.copyWith(fontWeight: FontWeight.w700),
            ),
          ),
          const SizedBox(width: TokenJarak.jarak8),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(item.nama, style: teks.bodyMedium?.copyWith(fontWeight: FontWeight.w600)),
                if (rincian.isNotEmpty) Text(rincian, style: teks.bodySmall?.copyWith(color: warna.teksSekunder)),
              ],
            ),
          ),
          const SizedBox(width: TokenJarak.jarak8),
          TeksUang(nilai, gaya: teks.bodyMedium?.copyWith(fontWeight: FontWeight.w600)),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    final konteks = ref.watch(penyediaKonteksPenjualan);
    final keranjang = ref.watch(penyediaKeranjangEfektif);
    final k = konteks.value;
    if (k == null) {
      return const Padding(padding: EdgeInsets.all(TokenJarak.jarak24), child: LinearProgressIndicator());
    }
    final layanan = ref.read(penyediaLayananPenjualan);
    final metode = _metode;
    _AturQrLayarPelanggan(metode?.Jenis == JenisMetodeBayar.qrisStatis ? _BuatQrisBernominal(metode!) : null);
    final pembayaranHitung = [
      for (final p in _entri) p.KeKalkulasi(),
      if (metode?.Jenis == JenisMetodeBayar.tunai) const DataPembayaranKalkulasi(metode: 'Tunai'),
    ];
    // F-16c bagian 3: metode yang sedang dipilih ikut dinilai agar promo metode bayar langsung tampil di total.
    final hitungan = layanan.Hitung(
      keranjang,
      k,
      pembayaran: pembayaranHitung,
      metodeBayar: LayananPenjualan.AmbilMetodeBayar(k, [for (final p in _entri) p.metode, ?metode]),
    );
    final sisa = _HitungSisa(k, metode);
    final tunaiDipakai = _entri.any((p) => p.CekTunai());
    final tempoDipakai = _entri.any((p) => p.metode.Jenis == JenisMetodeBayar.tempo);
    final depositDipakai = _entri.any((p) => p.metode.Jenis == JenisMetodeBayar.deposit);
    final giftCardDipakai = _entri.any((p) => p.metode.Jenis == JenisMetodeBayar.giftCard);
    final nominal = _AmbilNominal();
    final melunasi = nominal != null && nominal.Bandingkan(sisa) >= 0;
    // Semua tagihan sudah tertutup (misal QRIS dinamis lunas tetapi penyimpanan gagal): cukup selesaikan lagi.
    final lunasTanpaMetode = metode == null && _entri.isNotEmpty && sisa.Bandingkan(Uang.Nol()) <= 0;
    // Apotek bagian 2: obat wajib resep / hanya apoteker → metode bayar baru tampil setelah syaratnya lengkap.
    final syaratApotek = AturanApotek.Periksa(keranjang, ref.watch(penyediaKatalog).value);
    final gerbangApotek =
        (syaratApotek.CekWajibResep && _resep == null) ||
        (syaratApotek.CekWajibApoteker && _apoteker == null && !_kasirApoteker);

    final katalog = ref.watch(penyediaKatalog).value;
    final pilihanKanal = katalog == null ? const <KanalPenjualan>[] : LayananPenjualan.AmbilPilihanKanal(k, katalog);
    final kanal = LayananPenjualan.AmbilKanal(keranjang);
    final bisaPilihPelanggan = !_KonteksTerkunci;
    // Tukar barang & laundry ikut dihitung ulang harganya saat kanal berganti, jadi kanalnya tidak boleh diubah di sini.
    final kanalTerikat =
        keranjang.pesananMeja != null ||
        keranjang.praPesan != null ||
        keranjang.reservasi != null ||
        keranjang.perintahKerja != null ||
        keranjang.tukar != null ||
        keranjang.laundry != null;
    final bisaGantiJenis = !_KonteksTerkunci && !kanalTerikat && pilihanKanal.length > 1;
    // Alasan kanal tidak bisa diubah mengikuti dokumen yang sedang ditagih, bukan selalu soal pembayaran tercatat.
    final alasanJenisTerkunci = keranjang.pesananMeja != null
        ? 'Mengikuti pesanan meja'
        : keranjang.praPesan != null
        ? 'Mengikuti ${keranjang.praPesan!.sumber.Sebutan} ${keranjang.praPesan!.nomor}'
        : keranjang.reservasi != null
        ? 'Mengikuti reservasi ${keranjang.reservasi!.nomor}'
        : keranjang.perintahKerja != null
        ? 'Mengikuti perintah kerja ${keranjang.perintahKerja!.nomor}'
        : keranjang.tukar != null
        ? 'Tukar barang memakai kanal penjualan asal'
        : keranjang.laundry != null
        ? 'Mengikuti tiket laundry'
        : 'Tidak bisa diubah setelah pembayaran tercatat';
    final pelanggan = keranjang.pelanggan;

    // Konteks pesanan yang bisa dikoreksi dari layar Bayar (pelanggan, jenis pesanan, catatan).
    final konteksPesanan = <Widget>[
      _BangunBarisKonteks(
        context,
        ikon: Icons.person_outline,
        label: 'Pelanggan',
        nilai: pelanggan?.nama ?? 'Pelanggan umum',
        keterangan: pelanggan == null ? 'Belum dipilih | ketuk untuk memilih' : pelanggan.noHpSamar,
        saatKetuk: bisaPilihPelanggan ? () => unawaited(_BukaPelanggan()) : null,
        kunci: 'PelangganBayar',
      ),
      if (pilihanKanal.length > 1)
        _BangunBarisKonteks(
          context,
          ikon: switch (kanal) {
            KanalPenjualan.MakanDiTempat => Icons.restaurant_outlined,
            KanalPenjualan.Antar => Icons.delivery_dining_outlined,
            _ => Icons.shopping_bag_outlined,
          },
          label: 'Jenis pesanan',
          nilai: LayananPenjualan.AmbilLabelKanal(kanal),
          keterangan: bisaGantiJenis ? null : alasanJenisTerkunci,
          saatKetuk: bisaGantiJenis ? () => unawaited(_PilihKanal(k)) : null,
          kunci: 'JenisPesananBayar',
        ),
      _BangunBarisKonteks(
        context,
        ikon: Icons.edit_note,
        label: 'Catatan',
        nilai: keranjang.catatan ?? 'Tambah catatan',
        keterangan: keranjang.catatan != null
            ? null
            : _KonteksTerkunci
            ? 'Terkunci setelah tagihan dibagi atau pembayaran dicatat'
            : 'Untuk dapur & struk',
        saatKetuk: _KonteksTerkunci ? null : () => unawaited(_UbahCatatan()),
        kunci: 'CatatanPesananBayar',
      ),
      if (keranjang.pesananMeja case final meja?)
        _BangunBarisKonteks(
          context,
          ikon: Icons.table_restaurant_outlined,
          label: 'Meja',
          nilai: meja.AmbilJudul(),
          keterangan: 'Pesanan meja dibayar di meja ini',
        ),
    ];

    final infoDokumen = <Widget>[
      if (keranjang.praPesan case final praPesan?)
        Padding(
          padding: const EdgeInsets.only(bottom: TokenJarak.jarak8),
          child: Text(switch (praPesan.sumber) {
            SumberUangMuka.pesananOnline => 'Menagih pesanan online ${praPesan.nomor}',
            SumberUangMuka.reservasiMeja => 'Menagih reservasi meja ${praPesan.nomor}',
            SumberUangMuka.praPesan => 'Mengambil pre-order ${praPesan.nomor}',
          }, style: teks.bodySmall),
        ),
      if (keranjang.laundry case final laundry?)
        Padding(
          padding: const EdgeInsets.only(bottom: TokenJarak.jarak8),
          child: Text('Tiket laundry ${laundry.jenisLayanan} | ${laundry.RingkasIsi()}', style: teks.bodySmall),
        ),
      if (keranjang.reservasi case final reservasi?)
        Padding(
          padding: const EdgeInsets.only(bottom: TokenJarak.jarak8),
          child: Text('Melayani reservasi ${reservasi.nomor}', style: teks.bodySmall),
        ),
      if (keranjang.perintahKerja case final pk?)
        Padding(
          padding: const EdgeInsets.only(bottom: TokenJarak.jarak8),
          child: Text(['Menagih perintah kerja ${pk.nomor}', ?pk.pengenalUnit].join(' | '), style: teks.bodySmall),
        ),
      if (keranjang.tukar case final tukar?)
        Padding(
          padding: const EdgeInsets.only(bottom: TokenJarak.jarak8),
          child: Text(
            'Tukar barang ${tukar.tanpaStruk ? 'tanpa struk' : 'dari ${tukar.nomorPenjualanAsal}'} | nilai retur ${tukar.nilai.FormatRupiah()}'
            '${tukar.nilai.Bandingkan(hitungan.hasil.totalAkhir) <= 0
                ? ''
                : tukar.tanpaStruk
                ? ' | tambah barang pengganti ${tukar.nilai.Kurangi(hitungan.hasil.totalAkhir).FormatRupiah()} lagi (tanpa struk tidak dikembalikan tunai)'
                : ' | kembalikan ${tukar.nilai.Kurangi(hitungan.hasil.totalAkhir).FormatRupiah()} tunai'}',
            style: teks.bodySmall,
          ),
        ),
    ];

    final ringkasan = RingkasanTotal.BangunBaris(context, hitungan, keranjang, tampilPembulatan: true);

    // Baris pesanan di panel kiri: jumlah, nama, satuan/pilihan/catatan, lalu nilai bruto baris. Total yang sudah
    // dipotong diskon & pajak tetap di ringkasan bawah agar kasir bisa mencocokkan keduanya.
    final barisPesanan = <Widget>[
      for (var i = 0; i < keranjang.baris.length; i++)
        _BangunBarisPesanan(
          context,
          keranjang.baris[i],
          i < hitungan.hasil.baris.length
              ? hitungan.hasil.baris[i].bruto
              : keranjang.baris[i].hargaSatuan.Kali(keranjang.baris[i].jumlah.KeDesimal()),
        ),
    ];

    final bagiEntri = <Widget>[
      if (_bagi case final bagi?) ...[
        const SizedBox(height: TokenJarak.jarak8),
        Container(
          key: const ValueKey('InfoBagiTagihan'),
          padding: const EdgeInsets.all(TokenJarak.jarak12),
          decoration: BoxDecoration(
            border: Border.all(color: warna.garis, width: TokenJarak.tebalGaris),
            borderRadius: BorderRadius.circular(TokenJarak.radiusPanel),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  const Icon(Icons.call_split, size: TokenJarak.ikonKecil),
                  const SizedBox(width: TokenJarak.jarak8),
                  Expanded(
                    child: Text(
                      bagi.perNominal
                          ? 'Bagi per nominal | tamu $_tamuKe'
                          : 'Bagi rata ${bagi.jumlahOrang} orang | tamu $_tamuKe dari ${bagi.jumlahOrang}',
                      style: teks.titleSmall,
                    ),
                  ),
                ],
              ),
              if (!bagi.perNominal) ...[
                const SizedBox(height: TokenJarak.jarak4),
                Text(
                  _tamuTerakhir
                      ? 'Tamu terakhir membayar sisa ${sisa.FormatRupiah()}'
                      : 'Porsi per orang ${bagi.porsi!.FormatRupiah()}',
                  style: teks.bodyMedium,
                ),
              ],
              if (_infoBagi != null) ...[
                const SizedBox(height: TokenJarak.jarak4),
                Semantics(liveRegion: true, child: Text(_infoBagi!, style: teks.bodyMedium)),
              ],
            ],
          ),
        ),
      ],
      for (final p in _entri)
        Row(
          children: [
            Expanded(child: Text('${p.metode.Nama}${p.referensi == null ? '' : ' | ${p.referensi}'}')),
            TeksUang(p.jumlah),
            if (p.metode.Jenis == JenisMetodeBayar.uangMuka ||
                p.metode.Jenis == JenisMetodeBayar.qrisDinamis ||
                p.metode.Jenis == JenisMetodeBayar.tukar)
              const SizedBox(width: TokenJarak.targetSentuh)
            else
              IconButton(
                tooltip: 'Hapus pembayaran ${p.metode.Nama}',
                onPressed: () => setState(() {
                  _entri.remove(p);
                  if (p.metode.Jenis == JenisMetodeBayar.tempo) {
                    _penyetujuTempo = null;
                  }
                }),
                icon: const Icon(Icons.close),
              ),
          ],
        ),
      if (_entri.isNotEmpty)
        Row(
          children: [
            Expanded(child: Text('Sisa', style: teks.titleMedium)),
            TeksUang(sisa, gaya: teks.titleMedium),
          ],
        ),
    ];

    final bagianApotek = <Widget>[
      if (!syaratApotek.CekKosong) ...[
        const SizedBox(height: TokenJarak.jarak12),
        _BangunApotek(context, k, syaratApotek),
      ],
    ];

    // Metode pembayaran: chip yang sama dengan sebelumnya supaya jalur bayar (termasuk QRIS, EDC, transfer, tempo,
    // deposit, gift card, dan kanal ojol) tidak berubah; hanya tempatnya pindah ke panel kanan.
    final metodePembayaran = <Widget>[
      const SizedBox(height: TokenJarak.jarak16),
      if (gerbangApotek)
        Text(
          'Lengkapi syarat obat di atas sebelum menerima pembayaran.',
          key: const ValueKey('GerbangApotek'),
          style: teks.bodyMedium?.copyWith(color: warna.peringatan),
        )
      else if (k.metodePembayaran.isEmpty)
        Text(
          'Metode pembayaran belum tersedia di perangkat ini. Sambungkan ke internet agar data terbaru terunduh.',
          style: TextStyle(color: warna.bahaya),
        )
      else
        Wrap(
          spacing: TokenJarak.jarak8,
          runSpacing: TokenJarak.jarak8,
          children: [
            for (final m in LayananPenjualan.SaringMetodeKanal(k.metodePembayaran, kanal))
              if (!(tunaiDipakai && _bagi == null && m.Jenis == JenisMetodeBayar.tunai) &&
                  !(m.Jenis == JenisMetodeBayar.tempo && (keranjang.pelanggan == null || tempoDipakai)) &&
                  !(m.Jenis == JenisMetodeBayar.deposit &&
                      (keranjang.pelanggan == null || depositDipakai || !k.deposit.berlaku)) &&
                  !(m.Jenis == JenisMetodeBayar.giftCard && giftCardDipakai))
                ChoiceChip(
                  label: Text(m.Nama),
                  tooltip: AmbilLabelJenisMetode(m.Jenis),
                  selected: metode?.Uuid == m.Uuid,
                  onSelected: _sibuk ? null : (_) => _PilihMetode(k, m),
                ),
          ],
        ),
    ];

    // Layar sempit memakai satu kolom dengan panel yang sama, tetap dari atas ke bawah.
    // Rincian metode terpilih (tagihan, tombol uang cepat, isian, papan angka / QR / referensi).
    final rincian = <Widget>[
      if (metode != null && !gerbangApotek)
        if (metode.Jenis == JenisMetodeBayar.tunai) _BangunTunai(context, k) else _BangunNonTunai(context, k, metode),
    ];
    // Peringatan & galat selalu terlihat tepat di atas tombol bayar.
    final peringatanAksi = <Widget>[
      // Audit kemudahan pakai #32: peringatan tepat di atas tombol bayar supaya penjualan sungguhan tidak hilang.
      if (ref.watch(penyediaModeLatihan))
        Padding(
          key: const ValueKey('PeringatanLatihanBayar'),
          padding: const EdgeInsets.only(bottom: TokenJarak.jarak8),
          child: Row(
            children: [
              Icon(Icons.school_outlined, color: warna.peringatan, size: 20),
              const SizedBox(width: TokenJarak.jarak8),
              Expanded(
                child: Text(
                  'Mode latihan: pembayaran ini tidak disimpan. Matikan mode latihan untuk penjualan sungguhan.',
                  style: teks.bodyMedium?.copyWith(color: warna.peringatan, fontWeight: FontWeight.w600),
                ),
              ),
            ],
          ),
        ),
      if (_galat != null)
        Padding(
          padding: const EdgeInsets.only(bottom: TokenJarak.jarak8),
          child: Text(_galat!, style: TextStyle(color: warna.bahaya)),
        ),
    ];

    final tombolUtama = SizedBox(
      height: 56,
      child: FilledButton(
        onPressed: _sibuk || gerbangApotek
            ? null
            : metode != null
            ? () => _Terapkan(k)
            : lunasTanpaMetode
            ? () => _Selesaikan(k, List.of(_entri))
            : null,
        child: Text(
          _sibuk
              ? 'Menyimpan…'
              : _bagi?.porsi != null && !_tamuTerakhir && metode != null
              ? 'Bayar porsi tamu $_tamuKe'
              : melunasi || metode == null
              ? 'Selesaikan pembayaran'
              : 'Tambah pembayaran ${metode.Nama}',
        ),
      ),
    );

    final tombolBagi = switch ((
      _bagi == null &&
          _entri.isEmpty &&
          keranjang.tukar == null &&
          keranjang.praPesan == null &&
          hitungan.hasil.totalAkhir.Bandingkan(Uang.DariBulat(1)) > 0,
      _bagi != null && _entri.isEmpty,
    )) {
      (true, _) => SizedBox(
        height: TokenJarak.targetSentuh,
        child: OutlinedButton.icon(
          onPressed: _sibuk ? null : () => unawaited(_MulaiBagi(hitungan.hasil.totalAkhir)),
          icon: const Icon(Icons.call_split),
          label: const Text('Bagi tagihan'),
        ),
      ),
      (_, true) => SizedBox(
        height: TokenJarak.targetSentuh,
        child: TextButton(
          onPressed: _sibuk ? null : () => setState(() => _bagi = null),
          child: const Text('Batal bagi tagihan'),
        ),
      ),
      _ => null,
    };

    // Aksi dokumen (pre-order & pesanan grosir) hanya masuk akal sebelum ada pembayaran tercatat.
    final aksiDokumen = <Widget>[
      if (widget.saatPreOrder != null &&
          keranjang.pelanggan != null &&
          keranjang.praPesan == null &&
          keranjang.reservasi == null &&
          keranjang.perintahKerja == null &&
          keranjang.laundry == null &&
          keranjang.pesananMeja == null &&
          _entri.isEmpty) ...[
        const SizedBox(height: TokenJarak.jarak8),
        SizedBox(
          height: TokenJarak.targetSentuh,
          child: OutlinedButton.icon(
            onPressed: _sibuk ? null : widget.saatPreOrder,
            icon: const Icon(Icons.event_available_outlined),
            label: const Text('Jadikan pre-order (bayar DP)'),
          ),
        ),
      ],
      if (widget.saatPesananGrosir != null &&
          keranjang.pelanggan != null &&
          keranjang.praPesan == null &&
          keranjang.reservasi == null &&
          keranjang.perintahKerja == null &&
          keranjang.laundry == null &&
          keranjang.pesananMeja == null &&
          _entri.isEmpty) ...[
        const SizedBox(height: TokenJarak.jarak8),
        SizedBox(
          height: TokenJarak.targetSentuh,
          child: OutlinedButton.icon(
            onPressed: _sibuk ? null : widget.saatPesananGrosir,
            icon: const Icon(Icons.local_shipping_outlined),
            label: const Text('Jadikan pesanan grosir (SO)'),
          ),
        ),
      ],
    ];

    final isiPembayaran = <Widget>[
      ...bagianApotek,
      ...bagiEntri,
      ...metodePembayaran,
      if (rincian.isNotEmpty)
        ...rincian
      else if (!gerbangApotek)
        Padding(
          padding: const EdgeInsets.only(top: TokenJarak.jarak16),
          child: Text(
            'Pilih metode pembayaran untuk melihat rinciannya.',
            style: teks.bodyMedium?.copyWith(color: warna.teksSekunder),
          ),
        ),
      ...aksiDokumen,
    ];

    return LayoutBuilder(
      builder: (context, batas) {
        final duaKolom = batas.maxWidth >= PanelBayar.lebarDuaKolom;
        final lebarIsi = duaKolom ? PanelBayar.lebarMaksDuaKolom : PanelBayar.lebarMaksSatuKolom;
        final lebarPesanan = batas.maxWidth >= 1200 ? 400.0 : 340.0;
        final lebarTombolUtama = batas.maxWidth >= 1120 ? 380.0 : 300.0;

        Widget Batasi(Widget anak) => Align(
          alignment: Alignment.topCenter,
          child: ConstrainedBox(
            constraints: BoxConstraints(maxWidth: lebarIsi),
            child: anak,
          ),
        );

        final panelPesanan = Container(
          key: const ValueKey('PanelPesananBayar'),
          decoration: BoxDecoration(
            color: warna.permukaan,
            border: Border.all(color: warna.garis, width: TokenJarak.tebalGaris),
            borderRadius: BorderRadius.circular(TokenJarak.radiusPanel),
          ),
          child: Column(
            mainAxisSize: duaKolom ? MainAxisSize.max : MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Padding(
                padding: const EdgeInsets.fromLTRB(
                  TokenJarak.jarak16,
                  TokenJarak.jarak12,
                  TokenJarak.jarak16,
                  TokenJarak.jarak8,
                ),
                child: Row(
                  children: [
                    Expanded(
                      child: Text(
                        'Pesanan | ${FormatAngka.FormatJumlah(keranjang.HitungJumlahItem())} item',
                        style: teks.titleMedium,
                      ),
                    ),
                    if (keranjang.pesananMeja case final meja?)
                      Container(
                        padding: const EdgeInsets.symmetric(horizontal: TokenJarak.jarak8, vertical: TokenJarak.jarak4),
                        decoration: BoxDecoration(
                          color: warna.latar,
                          borderRadius: BorderRadius.circular(TokenJarak.radiusKontrol),
                        ),
                        child: Text(meja.AmbilJudul(), style: teks.labelMedium),
                      ),
                  ],
                ),
              ),
              Divider(height: TokenJarak.tebalGaris, thickness: TokenJarak.tebalGaris, color: warna.garis),
              if (duaKolom)
                Expanded(
                  child: ListView(
                    key: const ValueKey('DaftarPesananBayar'),
                    padding: const EdgeInsets.symmetric(vertical: TokenJarak.jarak8),
                    children: [
                      ...konteksPesanan,
                      if (infoDokumen.isNotEmpty)
                        Padding(
                          padding: const EdgeInsets.fromLTRB(
                            TokenJarak.jarak16,
                            TokenJarak.jarak4,
                            TokenJarak.jarak16,
                            0,
                          ),
                          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: infoDokumen),
                        ),
                      ...barisPesanan,
                    ],
                  ),
                )
              else ...[
                ...konteksPesanan,
                if (infoDokumen.isNotEmpty)
                  Padding(
                    padding: const EdgeInsets.fromLTRB(TokenJarak.jarak16, TokenJarak.jarak4, TokenJarak.jarak16, 0),
                    child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: infoDokumen),
                  ),
                ...barisPesanan,
              ],
              Divider(height: TokenJarak.tebalGaris, thickness: TokenJarak.tebalGaris, color: warna.garis),
              Padding(
                padding: const EdgeInsets.all(TokenJarak.jarak16),
                child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: ringkasan),
              ),
            ],
          ),
        );

        final panelPembayaran = Container(
          key: const ValueKey('PanelPembayaranBayar'),
          decoration: BoxDecoration(
            color: warna.permukaan,
            border: Border.all(color: warna.garis, width: TokenJarak.tebalGaris),
            borderRadius: BorderRadius.circular(TokenJarak.radiusPanel),
          ),
          child: Column(
            mainAxisSize: duaKolom ? MainAxisSize.max : MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Padding(
                padding: const EdgeInsets.fromLTRB(
                  TokenJarak.jarak24,
                  TokenJarak.jarak12,
                  TokenJarak.jarak24,
                  TokenJarak.jarak8,
                ),
                child: Text(
                  'METODE PEMBAYARAN',
                  style: teks.labelSmall?.copyWith(
                    color: warna.teksSekunder,
                    fontWeight: FontWeight.w700,
                    letterSpacing: 0.8,
                  ),
                ),
              ),
              Divider(height: TokenJarak.tebalGaris, thickness: TokenJarak.tebalGaris, color: warna.garis),
              if (duaKolom)
                Expanded(
                  child: SingleChildScrollView(
                    key: const ValueKey('GulirPembayaranBayar'),
                    padding: const EdgeInsets.fromLTRB(
                      TokenJarak.jarak24,
                      TokenJarak.jarak4,
                      TokenJarak.jarak24,
                      TokenJarak.jarak24,
                    ),
                    child: Align(
                      alignment: Alignment.topCenter,
                      child: ConstrainedBox(
                        constraints: const BoxConstraints(maxWidth: 760),
                        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: isiPembayaran),
                      ),
                    ),
                  ),
                )
              else
                Padding(
                  padding: const EdgeInsets.fromLTRB(
                    TokenJarak.jarak24,
                    TokenJarak.jarak4,
                    TokenJarak.jarak24,
                    TokenJarak.jarak24,
                  ),
                  child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: isiPembayaran),
                ),
            ],
          ),
        );

        final isi = duaKolom
            ? Padding(
                padding: const EdgeInsets.fromLTRB(
                  TokenJarak.jarak24,
                  TokenJarak.jarak24,
                  TokenJarak.jarak24,
                  TokenJarak.jarak16,
                ),
                child: LayoutBuilder(
                  builder: (context, dalam) => Center(
                    child: ConstrainedBox(
                      constraints: BoxConstraints(maxWidth: lebarIsi),
                      child: SizedBox(
                        height: dalam.maxHeight,
                        child: Row(
                          crossAxisAlignment: CrossAxisAlignment.stretch,
                          children: [
                            SizedBox(width: lebarPesanan, child: panelPesanan),
                            const SizedBox(width: TokenJarak.jarak24),
                            Expanded(child: panelPembayaran),
                          ],
                        ),
                      ),
                    ),
                  ),
                ),
              )
            : SingleChildScrollView(
                key: const ValueKey('GulirBayar'),
                padding: const EdgeInsets.fromLTRB(
                  TokenJarak.jarak24,
                  TokenJarak.jarak24,
                  TokenJarak.jarak24,
                  TokenJarak.jarak16,
                ),
                child: Batasi(
                  Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      panelPesanan,
                      const SizedBox(height: TokenJarak.jarak16),
                      panelPembayaran,
                    ],
                  ),
                ),
              );

        return Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Expanded(child: isi),
            DecoratedBox(
              key: const ValueKey('BilahAksiBayar'),
              decoration: BoxDecoration(
                color: warna.permukaan,
                border: Border(
                  top: BorderSide(color: warna.garis, width: TokenJarak.tebalGaris),
                ),
              ),
              child: SafeArea(
                top: false,
                child: Padding(
                  padding: const EdgeInsets.symmetric(horizontal: TokenJarak.jarak24, vertical: TokenJarak.jarak12),
                  child: Batasi(
                    Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        ...peringatanAksi,
                        if (duaKolom)
                          Row(
                            children: [
                              if (tombolBagi != null) ...[tombolBagi, const SizedBox(width: TokenJarak.jarak16)],
                              if (batas.maxWidth >= 960)
                                Expanded(
                                  child: Row(
                                    children: [
                                      Icon(
                                        Icons.cloud_done_outlined,
                                        size: TokenJarak.ikonKecil,
                                        color: warna.teksSekunder,
                                      ),
                                      const SizedBox(width: TokenJarak.jarak8),
                                      Flexible(
                                        child: Text(
                                          'Keranjang tersimpan di perangkat',
                                          style: teks.bodySmall?.copyWith(color: warna.teksSekunder),
                                        ),
                                      ),
                                    ],
                                  ),
                                )
                              else
                                const Spacer(),
                              SizedBox(width: lebarTombolUtama, child: tombolUtama),
                            ],
                          )
                        else ...[
                          tombolUtama,
                          if (tombolBagi != null) ...[const SizedBox(height: TokenJarak.jarak8), tombolBagi],
                        ],
                      ],
                    ),
                  ),
                ),
              ),
            ),
          ],
        );
      },
    );
  }
}

/// Dialog catatan pesanan: pengendali teks dimiliki dialog supaya tidak dipakai setelah dibuang saat animasi tutup.
class _DialogCatatan extends StatefulWidget {
  const _DialogCatatan({required this.awal});

  final String awal;

  @override
  State<_DialogCatatan> createState() => _DialogCatatanState();
}

class _DialogCatatanState extends State<_DialogCatatan> {
  late final TextEditingController _pengendali = TextEditingController(text: widget.awal);

  @override
  void dispose() {
    _pengendali.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => AlertDialog(
    title: const Text('Catatan pesanan'),
    content: TextField(
      key: const ValueKey('CatatanBayar'),
      controller: _pengendali,
      autofocus: true,
      minLines: 2,
      maxLines: 4,
      maxLength: 500,
      textCapitalization: TextCapitalization.sentences,
      decoration: const InputDecoration(hintText: 'Mis. tanpa es, saus terpisah', counterText: ''),
    ),
    actions: [
      TextButton(onPressed: () => Navigator.of(context).pop(), child: const Text('Batal')),
      FilledButton(onPressed: () => Navigator.of(context).pop(_pengendali.text), child: const Text('Simpan')),
    ],
  );
}

/// Layar selesai setelah pembayaran tersimpan (D-66): satu halaman berlatar warna merek, tanda centang besar,
/// nomor, kembalian, rincian bayar, dan aksi struk. Kasir harus bisa membacanya dari jarak berdiri.
class TampilanSelesai extends ConsumerWidget {
  const TampilanSelesai({super.key, required this.hasil, required this.saatTransaksiBaru});

  final PenjualanTersimpan hasil;
  final VoidCallback saatTransaksiBaru;

  Future<void> _BukaAturPrinter(BuildContext context) => showDialog<void>(
    context: context,
    builder: (konteks) => Dialog(
      insetPadding: const EdgeInsets.all(TokenJarak.jarak16),
      child: ConstrainedBox(
        constraints: BoxConstraints(maxWidth: 720, maxHeight: MediaQuery.sizeOf(konteks).height * 0.86),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Padding(
              padding: const EdgeInsets.fromLTRB(
                TokenJarak.jarak16,
                TokenJarak.jarak8,
                TokenJarak.jarak8,
                TokenJarak.jarak8,
              ),
              child: Row(
                children: [
                  Expanded(child: Text('Atur printer', style: Theme.of(konteks).textTheme.titleMedium)),
                  IconButton(
                    tooltip: 'Tutup',
                    onPressed: () => Navigator.of(konteks).pop(),
                    icon: const Icon(Icons.close),
                  ),
                ],
              ),
            ),
            const Divider(height: TokenJarak.tebalGaris),
            const Flexible(
              child: SingleChildScrollView(padding: EdgeInsets.all(TokenJarak.jarak16), child: BagianPrinter()),
            ),
          ],
        ),
      ),
    ),
  );

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    final putih = warna.permukaan;
    final putihRedup = putih.withValues(alpha: 0.78);
    final printer = ref.watch(penyediaPrinter);
    final belumDiaturPrinter = printer.profil == null;

    final ringkasan = <Widget>[
      Row(
        children: [
          Expanded(
            child: Text(
              'Total',
              style: teks.titleSmall?.copyWith(color: putih, fontWeight: FontWeight.w700),
            ),
          ),
          TeksUang(
            hasil.totalAkhir,
            gaya: teks.titleSmall?.copyWith(color: putih, fontWeight: FontWeight.w700),
          ),
        ],
      ),
      for (final p in hasil.pembayaran)
        Padding(
          padding: const EdgeInsets.only(top: 2),
          child: Row(
            children: [
              Expanded(
                child: Text(p.metode.Nama, style: teks.bodySmall?.copyWith(color: putihRedup)),
              ),
              TeksUang(p.jumlah, gaya: teks.bodySmall?.copyWith(color: putihRedup)),
            ],
          ),
        ),
    ];

    final aksiStruk = <Widget>[
      if (belumDiaturPrinter)
        Padding(
          padding: const EdgeInsets.only(top: TokenJarak.jarak16),
          child: Row(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              Icon(Icons.print_outlined, size: TokenJarak.ikonKecil, color: warna.aksen),
              const SizedBox(width: TokenJarak.jarak8),
              Flexible(
                child: Text(
                  'Printer belum diatur | struk belum tercetak. Ketuk Atur printer untuk mencetak.',
                  style: teks.bodySmall?.copyWith(color: putih.withValues(alpha: 0.86)),
                  textAlign: TextAlign.center,
                ),
              ),
            ],
          ),
        ),
      Padding(
        padding: const EdgeInsets.only(top: TokenJarak.jarak12),
        child: LayoutBuilder(
          builder: (context, batas) {
            final atur = SizedBox(
              height: TokenJarak.targetSentuh,
              child: OutlinedButton.icon(
                key: const ValueKey('AturPrinterSelesai'),
                onPressed: () => unawaited(_BukaAturPrinter(context)),
                style: OutlinedButton.styleFrom(
                  foregroundColor: putih,
                  side: BorderSide(color: warna.aksen, width: 2),
                ),
                icon: Icon(Icons.print_outlined, color: warna.aksen),
                label: const Text('Atur printer'),
              ),
            );
            final kirim = TombolKirimStruk(uuidPenjualan: hasil.uuid, diAtasMerek: true);
            // HP: dua tombol bertumpuk supaya label tidak berdesakan dalam satu baris sempit.
            if (batas.maxWidth < 420) {
              return Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  atur,
                  const SizedBox(height: TokenJarak.jarak8),
                  kirim,
                ],
              );
            }
            return Row(
              children: [
                Expanded(child: atur),
                const SizedBox(width: TokenJarak.jarak8),
                Expanded(child: kirim),
              ],
            );
          },
        ),
      ),
      Padding(
        padding: const EdgeInsets.only(top: TokenJarak.jarak12),
        child: BagianCetakStruk(
          uuidPenjualan: hasil.uuid,
          namaPelanggan: hasil.namaPelanggan,
          labelPoin: hasil.labelPoin,
          diAtasMerek: true,
        ),
      ),
      if (hasil.bagianTagihan != null) ...[
        const SizedBox(height: TokenJarak.jarak8),
        TombolStrukTamu(uuidPenjualan: hasil.uuid, diAtasMerek: true),
      ],
      BagianTiketDapur(uuidPenjualan: hasil.uuid, namaPelanggan: hasil.namaPelanggan, diAtasMerek: true),
    ];

    return DecoratedBox(
      key: const ValueKey('LayarBerhasilBayar'),
      decoration: BoxDecoration(color: warna.brand),
      child: Material(
        color: warna.brand,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            // Kepala halaman senada dengan merek: logo, nama halaman, dan tombol mulai transaksi baru.
            Container(
              height: 56,
              padding: const EdgeInsets.only(left: TokenJarak.jarak16, right: TokenJarak.jarak4),
              decoration: BoxDecoration(
                border: Border(
                  bottom: BorderSide(color: putih.withValues(alpha: 0.14), width: TokenJarak.tebalGaris),
                ),
              ),
              child: Row(
                children: [
                  // D-61: wordmark memakai aset merek, bukan huruf yang dirakit ulang.
                  const LogoMerek.lengkapPutih(tinggi: 32),
                  Container(
                    width: TokenJarak.tebalGaris,
                    height: 18,
                    margin: const EdgeInsets.symmetric(horizontal: TokenJarak.jarak12),
                    color: putih.withValues(alpha: 0.25),
                  ),
                  Expanded(
                    child: Text(
                      'Transaksi selesai',
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: teks.bodySmall?.copyWith(color: putih.withValues(alpha: 0.75)),
                    ),
                  ),
                  IconButton(
                    tooltip: 'Transaksi baru',
                    onPressed: saatTransaksiBaru,
                    color: putih,
                    icon: const Icon(Icons.close),
                  ),
                ],
              ),
            ),
            Expanded(
              child: LayoutBuilder(
                builder: (context, batas) => SingleChildScrollView(
                  padding: const EdgeInsets.fromLTRB(
                    TokenJarak.jarak24,
                    TokenJarak.jarak12,
                    TokenJarak.jarak24,
                    TokenJarak.jarak24,
                  ),
                  child: ConstrainedBox(
                    constraints: BoxConstraints(minHeight: batas.maxHeight - TokenJarak.jarak12 - TokenJarak.jarak24),
                    child: Center(
                      child: ConstrainedBox(
                        constraints: const BoxConstraints(maxWidth: 520),
                        child: Column(
                          mainAxisSize: MainAxisSize.min,
                          crossAxisAlignment: CrossAxisAlignment.stretch,
                          children: [
                            Center(
                              child: Container(
                                width: 76,
                                height: 76,
                                decoration: BoxDecoration(shape: BoxShape.circle, color: putih),
                                child: Icon(
                                  hasil.latihan ? Icons.school_outlined : Icons.check_rounded,
                                  color: hasil.latihan ? warna.peringatan : warna.brand,
                                  size: 40,
                                ),
                              ),
                            ),
                            const SizedBox(height: TokenJarak.jarak16),
                            FittedBox(
                              fit: BoxFit.scaleDown,
                              child: Text(
                                hasil.latihan ? 'Latihan selesai' : 'Pembayaran berhasil',
                                style: teks.displaySmall?.copyWith(color: putih, fontWeight: FontWeight.w700),
                                textAlign: TextAlign.center,
                              ),
                            ),
                            if (hasil.latihan)
                              Padding(
                                padding: const EdgeInsets.only(top: TokenJarak.jarak4),
                                child: Text(
                                  'Mode latihan: transaksi ini tidak disimpan, tidak dikirim, dan tidak dicetak.',
                                  style: teks.bodyMedium?.copyWith(color: putih),
                                  textAlign: TextAlign.center,
                                ),
                              ),
                            const SizedBox(height: TokenJarak.jarak8),
                            Center(
                              child: DecoratedBox(
                                decoration: BoxDecoration(
                                  color: putih.withValues(alpha: 0.13),
                                  borderRadius: BorderRadius.circular(99),
                                ),
                                child: Padding(
                                  padding: const EdgeInsets.symmetric(horizontal: TokenJarak.jarak12, vertical: 3),
                                  child: TeksKode(hasil.nomor, gaya: teks.bodySmall?.copyWith(color: putih)),
                                ),
                              ),
                            ),
                            // v3.52 (§9.2): nomor panggil besar supaya kasir bisa menyebutkannya ke pembeli.
                            if (hasil.nomorAntrian case final antrian?) ...[
                              const SizedBox(height: TokenJarak.jarak16),
                              Semantics(
                                label:
                                    'Nomor antrian $antrian${hasil.namaPemesan == null ? '' : ', ${hasil.namaPemesan}'}',
                                excludeSemantics: true,
                                child: Column(
                                  children: [
                                    Text(
                                      'Nomor antrian',
                                      style: teks.titleSmall?.copyWith(color: putihRedup),
                                      textAlign: TextAlign.center,
                                    ),
                                    TeksKode(antrian, gaya: teks.displaySmall?.copyWith(color: warna.aksen)),
                                    if (hasil.namaPemesan case final nama?)
                                      Text(
                                        nama,
                                        style: teks.titleSmall?.copyWith(color: putih),
                                        textAlign: TextAlign.center,
                                      ),
                                  ],
                                ),
                              ),
                            ],
                            const SizedBox(height: TokenJarak.jarak24),
                            Text(
                              'Kembalian',
                              style: teks.titleSmall?.copyWith(color: putihRedup, fontWeight: FontWeight.w600),
                              textAlign: TextAlign.center,
                            ),
                            FittedBox(
                              fit: BoxFit.scaleDown,
                              child: TeksUang(
                                hasil.kembalian,
                                rataKanan: false,
                                gaya: teks.displaySmall?.copyWith(color: putih, fontWeight: FontWeight.w700),
                              ),
                            ),
                            Text(
                              hasil.kembalian.Bandingkan(Uang.Nol()) > 0
                                  ? 'Serahkan kembalian ke pelanggan'
                                  : 'Uang pas diterima',
                              style: teks.bodySmall?.copyWith(color: putihRedup),
                              textAlign: TextAlign.center,
                            ),
                            // K-11: selisih tukar barang (barang pengganti lebih murah) dikembalikan tunai lewat refund retur.
                            if (hasil.kembalianTukar case final selisih? when !selisih.BernilaiNol()) ...[
                              const SizedBox(height: TokenJarak.jarak8),
                              Text(
                                'Kembalikan selisih tukar',
                                style: teks.titleSmall?.copyWith(color: putihRedup),
                                textAlign: TextAlign.center,
                              ),
                              Center(
                                child: TeksUang(
                                  selisih,
                                  rataKanan: false,
                                  gaya: teks.headlineMedium?.copyWith(color: putih),
                                ),
                              ),
                            ],
                            if (hasil.nomorReturTukar case final nomorRetur?)
                              Text(
                                'Retur tukar barang $nomorRetur',
                                style: teks.bodySmall?.copyWith(color: putihRedup),
                                textAlign: TextAlign.center,
                              ),
                            Padding(
                              padding: const EdgeInsets.only(top: TokenJarak.jarak24),
                              child: DecoratedBox(
                                decoration: BoxDecoration(
                                  color: putih.withValues(alpha: 0.08),
                                  borderRadius: BorderRadius.circular(TokenJarak.radiusKontrol),
                                ),
                                child: Padding(
                                  padding: const EdgeInsets.symmetric(
                                    horizontal: TokenJarak.jarak16,
                                    vertical: TokenJarak.jarak12,
                                  ),
                                  child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: ringkasan),
                                ),
                              ),
                            ),
                            if (!hasil.latihan) ...aksiStruk,
                            const SizedBox(height: TokenJarak.jarak12),
                            SizedBox(
                              height: 54,
                              child: FilledButton(
                                autofocus: true,
                                onPressed: saatTransaksiBaru,
                                style: FilledButton.styleFrom(
                                  backgroundColor: putih,
                                  foregroundColor: warna.brand,
                                  shape: RoundedRectangleBorder(
                                    borderRadius: BorderRadius.circular(TokenJarak.radiusKontrol),
                                  ),
                                  textStyle: teks.titleMedium?.copyWith(fontWeight: FontWeight.w700),
                                ),
                                child: FittedBox(
                                  fit: BoxFit.scaleDown,
                                  child: Row(
                                    mainAxisSize: MainAxisSize.min,
                                    children: [
                                      const Text('Transaksi baru'),
                                      const SizedBox(width: TokenJarak.jarak8),
                                      Container(
                                        padding: const EdgeInsets.symmetric(horizontal: 5, vertical: 1),
                                        decoration: BoxDecoration(
                                          border: Border.all(color: warna.brand.withValues(alpha: 0.35)),
                                          borderRadius: BorderRadius.circular(TokenJarak.radiusKontrol),
                                        ),
                                        child: Text(
                                          'Enter',
                                          style: teks.bodySmall?.copyWith(
                                            color: warna.brand.withValues(alpha: 0.7),
                                            fontWeight: FontWeight.w600,
                                          ),
                                        ),
                                      ),
                                    ],
                                  ),
                                ),
                              ),
                            ),
                            const SizedBox(height: TokenJarak.jarak8),
                            Text(
                              'Atau pindai barang berikutnya untuk mulai transaksi baru',
                              style: teks.bodySmall?.copyWith(color: putihRedup),
                              textAlign: TextAlign.center,
                            ),
                          ],
                        ),
                      ),
                    ),
                  ),
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// Ringkasan posisi kredit pelanggan untuk kasir (F-12).
abstract final class PelangganTerpilihKredit {
  static String Ringkas(PelangganTerpilih p) {
    if (p.sisaPiutang == null) {
      return '${p.nama}: posisi kredit belum diketahui perangkat ini.';
    }
    final limit = p.limitKredit == null ? 'tanpa limit kredit' : 'limit ${Uang.Dari(p.limitKredit!).FormatRupiah()}';
    final lewat = (p.hariLewatJatuhTempo ?? 0) > 0 ? ', lewat jatuh tempo ${p.hariLewatJatuhTempo} hari' : '';
    return '${p.nama}: $limit, piutang ${Uang.Dari(p.sisaPiutang!).FormatRupiah()}$lewat.';
  }
}

/// K-14: pilih bagi rata (jumlah orang 2–20, pratinjau porsi) atau per nominal.
class DialogBagiTagihan extends StatefulWidget {
  const DialogBagiTagihan({super.key, required this.total});

  static const int orangMinimal = 2;
  static const int orangMaksimal = 20;

  final Uang total;

  @override
  State<DialogBagiTagihan> createState() => _DialogBagiTagihanState();
}

class _DialogBagiTagihanState extends State<DialogBagiTagihan> {
  bool _rata = true;
  int _orang = DialogBagiTagihan.orangMinimal;

  @override
  Widget build(BuildContext context) {
    final teks = Theme.of(context).textTheme;
    final porsi = BagiTagihan.HitungPorsi(widget.total, _orang);
    final terakhir = widget.total.Kurangi(porsi.Kali(Decimal.fromInt(_orang - 1)));
    return AlertDialog(
      title: const Text('Bagi tagihan'),
      content: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text('Total ${widget.total.FormatRupiah()}', style: teks.titleMedium),
          const SizedBox(height: TokenJarak.jarak12),
          SegmentedButton<bool>(
            showSelectedIcon: false,
            segments: const [
              ButtonSegment(value: true, label: Text('Rata per orang')),
              ButtonSegment(value: false, label: Text('Per nominal')),
            ],
            selected: {_rata},
            onSelectionChanged: (pilih) => setState(() => _rata = pilih.first),
          ),
          const SizedBox(height: TokenJarak.jarak12),
          if (_rata) ...[
            Row(
              children: [
                Expanded(child: Text('Jumlah orang', style: teks.bodyMedium)),
                IconButton(
                  tooltip: 'Kurangi orang',
                  onPressed: _orang > DialogBagiTagihan.orangMinimal ? () => setState(() => _orang--) : null,
                  icon: const Icon(Icons.remove),
                ),
                SizedBox(
                  width: 40,
                  child: Text('$_orang', textAlign: TextAlign.center, style: teks.titleMedium),
                ),
                IconButton(
                  tooltip: 'Tambah orang',
                  onPressed: _orang < DialogBagiTagihan.orangMaksimal ? () => setState(() => _orang++) : null,
                  icon: const Icon(Icons.add),
                ),
              ],
            ),
            Text(
              porsi.SamaDengan(terakhir)
                  ? '$_orang × ${porsi.FormatRupiah()}'
                  : '${_orang - 1} × ${porsi.FormatRupiah()} + tamu terakhir ${terakhir.FormatRupiah()}',
              style: teks.bodyMedium,
            ),
          ] else
            Text('Tiap tamu membayar jumlah yang diketik, sampai tagihan lunas.', style: teks.bodyMedium),
        ],
      ),
      actions: [
        TextButton(onPressed: () => Navigator.of(context).pop(), child: const Text('Batal')),
        FilledButton(
          onPressed: () =>
              Navigator.of(context).pop(_rata ? BagiTagihan.rata(_orang, porsi) : const BagiTagihan.nominal()),
          child: const Text('Mulai bagi'),
        ),
      ],
    );
  }
}
