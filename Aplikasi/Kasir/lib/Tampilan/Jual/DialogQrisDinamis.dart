import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:klien_api/KlienApi.dart';
import 'package:mesin_kasir/MesinKasir.dart';
import 'package:sistem_desain/SistemDesain.dart';

import '../../Aplikasi/Penyedia.dart';
import '../../Data/BasisData/BasisDataKasir.dart';
import '../../Domain/GalatKasir.dart';
import 'GambarQr.dart';

/// Dialog QRIS dinamis (v2.05): minta tagihan ke server, tampilkan QR (juga di layar pelanggan), pantau status tiap
/// [jedaCek] sampai lunas, kedaluwarsa, atau dibatalkan kasir. Hasil = tagihan yang **lunas** (Uuid-nya menjadi
/// `Referensi` pembayaran), atau null bila batal/gagal. Kasir tidak bisa menandai lunas sendiri: status hanya dari
/// server (notifikasi gerbang), sehingga tidak ada "sudah bayar" palsu.
class DialogQrisDinamis extends ConsumerStatefulWidget {
  const DialogQrisDinamis({
    super.key,
    required this.metode,
    required this.jumlah,
    this.keterangan,
    this.jedaCek = const Duration(seconds: 2),
  });

  final BarisMetodePembayaran metode;
  final Uang jumlah;
  final String? keterangan;
  final Duration jedaCek;

  @override
  ConsumerState<DialogQrisDinamis> createState() => _DialogQrisDinamisState();
}

class _DialogQrisDinamisState extends ConsumerState<DialogQrisDinamis> {
  /// Jarak dialog ke tepi layar (total kiri+kanan dan atas+bawah), dipakai juga untuk menghitung ruang yang tersedia.
  static const double _jarakTepiKiriKanan = 32;
  static const double _jarakTepiAtasBawah = 48;

  TagihanQrisPos? _tagihan;
  String? _galat;
  bool _terputus = false;
  bool _selesaiTanpaBayar = false;
  bool _membatalkan = false;
  Timer? _pemantau;
  Timer? _detik;
  bool _mengecek = false;
  bool _mengecekManual = false;

  /// Lama berlaku tagihan saat dibuat, untuk cincin hitung mundur.
  Duration? _durasiAwal;

  @override
  void initState() {
    super.initState();
    unawaited(_Buat());
  }

  @override
  void dispose() {
    _pemantau?.cancel();
    _detik?.cancel();
    super.dispose();
  }

  void _AturLayarPelanggan(String? isiQr) {
    // Ditunda satu frame: Notifier tidak boleh diubah saat pohon widget sedang dibangun/dibongkar.
    final pengatur = ref.read(penyediaQrisLayarPelanggan.notifier);
    WidgetsBinding.instance.addPostFrameCallback((_) => pengatur.Atur(isiQr));
  }

  Future<void> _Buat() async {
    setState(() {
      _galat = null;
      _tagihan = null;
      _durasiAwal = null;
      _selesaiTanpaBayar = false;
    });
    try {
      final tagihan = await ref
          .read(penyediaLayananQrisDinamis)
          .Buat(metode: widget.metode, jumlah: widget.jumlah, keterangan: widget.keterangan);
      if (!mounted) {
        return;
      }
      setState(() {
        _tagihan = tagihan;
        _durasiAwal = tagihan.kedaluwarsaPada?.difference(ref.read(penyediaJam)().toUtc());
      });
      _AturLayarPelanggan(tagihan.isiQr);
      _pemantau = Timer.periodic(widget.jedaCek, (_) => _Cek());
      _detik = Timer.periodic(const Duration(seconds: 1), (_) {
        if (mounted) {
          setState(() {});
        }
      });
    } on GalatKasir catch (galat) {
      if (mounted) {
        setState(() => _galat = galat.pesan);
      }
    }
  }

  Future<void> _Cek() async {
    final tagihan = _tagihan;
    if (tagihan == null || _mengecek || _membatalkan) {
      return;
    }
    _mengecek = true;
    try {
      final status = await ref.read(penyediaLayananQrisDinamis).AmbilStatus(tagihan.uuid);
      if (!mounted) {
        return;
      }
      if (status.lunas) {
        _Tutup(tagihan);
      } else if (status.selesaiTanpaBayar) {
        _Berhenti();
        setState(() {
          _selesaiTanpaBayar = true;
          _terputus = false;
          _galat = status.status == TagihanQrisPos.kedaluwarsa
              ? 'QRIS kedaluwarsa sebelum dibayar. Buat QRIS baru atau pakai metode lain.'
              : 'Pembayaran QRIS gagal. Buat QRIS baru atau pakai metode lain.';
        });
      } else if (_terputus) {
        setState(() => _terputus = false);
      }
    } on GalatKasir {
      // Jaringan putus sesaat: tetap memantau; tagihan tetap berlaku di server.
      if (mounted && !_terputus) {
        setState(() => _terputus = true);
      }
    } finally {
      _mengecek = false;
    }
  }

  /// Tombol "Cek status sekarang": menanyakan server tanpa menunggu putaran berikutnya.
  Future<void> _CekSekarang() async {
    if (_mengecek || _mengecekManual) {
      return;
    }
    setState(() => _mengecekManual = true);
    await _Cek();
    if (mounted) {
      setState(() => _mengecekManual = false);
    }
  }

  void _Berhenti() {
    _pemantau?.cancel();
    _detik?.cancel();
    _AturLayarPelanggan(null);
  }

  void _Tutup(TagihanQrisPos? hasil) {
    _Berhenti();
    Navigator.of(context).pop(hasil);
  }

  Future<void> _Batalkan() async {
    final tagihan = _tagihan;
    if (tagihan == null || _selesaiTanpaBayar) {
      _Tutup(null);
      return;
    }
    setState(() => _membatalkan = true);
    try {
      await ref.read(penyediaLayananQrisDinamis).Batalkan(tagihan.uuid);
      if (mounted) {
        _Tutup(null);
      }
    } on GalatKasir catch (galat) {
      if (!mounted) {
        return;
      }
      if (galat.kode == 'SudahLunas') {
        // Pelanggan sudah membayar tepat sebelum dibatalkan: pembayaran dipakai, bukan dibuang.
        _Tutup(tagihan);
        return;
      }
      setState(() {
        _membatalkan = false;
        _galat = 'QRIS belum bisa dibatalkan: ${galat.pesan}';
      });
    }
  }

  String _SisaWaktu(DateTime kedaluwarsa) {
    final sisa = kedaluwarsa.difference(ref.read(penyediaJam)().toUtc());
    if (sisa.isNegative) {
      return '00:00';
    }
    final menit = sisa.inMinutes.toString().padLeft(2, '0');
    final detik = (sisa.inSeconds % 60).toString().padLeft(2, '0');
    return '$menit:$detik';
  }

  double _SisaPecahan(DateTime kedaluwarsa) {
    final awal = _durasiAwal;
    if (awal == null || awal.inSeconds <= 0) {
      return 0;
    }
    final sisa = kedaluwarsa.difference(ref.read(penyediaJam)().toUtc());
    return (sisa.inMilliseconds / awal.inMilliseconds).clamp(0.0, 1.0);
  }

  @override
  Widget build(BuildContext context) {
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    final tagihan = _tagihan;
    final namaOutlet = ref.watch(penyediaIdentitas).value?.outlet;
    final menunggu = tagihan != null && !_selesaiTanpaBayar;
    final bisaUlang = _galat != null && (tagihan == null || _selesaiTanpaBayar);
    final ukuranLayar = MediaQuery.sizeOf(context);
    // Tinggi dan lebar yang benar-benar bisa dipakai dialog (setelah jarak tepi dan keyboard layar).
    final tinggiTersedia = ukuranLayar.height - MediaQuery.viewInsetsOf(context).bottom - _jarakTepiAtasBawah;
    final lebarTersedia = ukuranLayar.width - _jarakTepiKiriKanan;
    final tampilQr = tagihan != null && !_selesaiTanpaBayar;
    // Layar lebar tetapi pendek (tablet mendatar, jendela Windows): QR di kiri, rincian dan tombol di kanan,
    // supaya seluruh dialog muat tanpa digulir. Layar tinggi: satu kolom. HP pendek: satu kolom yang dipadatkan.
    final duaKolom = tampilQr && lebarTersedia >= 640 && tinggiTersedia < 820;
    final padat = tampilQr && !duaKolom && tinggiTersedia < 780;
    final ukuranQr = (duaKolom ? tinggiTersedia - 200 : tinggiTersedia - (padat ? 525 : 630))
        .clamp(150.0, 280.0)
        .toDouble();

    final Widget nominal = Column(
      mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        // Nominal jadi pusat perhatian: ini yang dicek pelanggan sebelum membayar.
        Text(
          'Total tagihan',
          textAlign: TextAlign.center,
          style: teks.bodyMedium?.copyWith(color: warna.teksSekunder),
        ),
        const SizedBox(height: TokenJarak.jarak4),
        Text(
          widget.jumlah.FormatRupiah(),
          textAlign: TextAlign.center,
          style: teks.displaySmall?.copyWith(
            fontWeight: FontWeight.w700,
            fontFeatures: const [FontFeature.tabularFigures()],
          ),
        ),
      ],
    );
    Widget? kartuQr;
    Widget? petunjuk;
    Widget? stripCaraBayar;
    Widget? sisaWaktu;
    if (tagihan != null && tampilQr) {
      kartuQr = _KartuQr(
        data: tagihan.isiQr,
        label: 'Kode QRIS ${widget.jumlah.FormatRupiah()}',
        judul: tagihan.halamanBayar ? 'Halaman bayar' : 'QRIS',
        namaOutlet: namaOutlet,
        ukuranMaks: ukuranQr,
      );
      petunjuk = Text(
        tagihan.halamanBayar
            ? 'Minta pelanggan memindai kode untuk membuka halaman bayar.'
            : 'Minta pelanggan memindai dengan aplikasi bank atau e-wallet mana pun.',
        textAlign: TextAlign.center,
        style: teks.bodyMedium,
      );
      if (!tagihan.halamanBayar && !padat) {
        stripCaraBayar = const _StripCaraBayar();
      }
      if (tagihan.kedaluwarsaPada case final kedaluwarsa?) {
        sisaWaktu = Row(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            _CincinWaktu(sisa: _SisaPecahan(kedaluwarsa), warnaBusur: warna.aksen, warnaLatar: warna.brandTint),
            const SizedBox(width: TokenJarak.jarak12),
            Flexible(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    'Berlaku ${_SisaWaktu(kedaluwarsa)} lagi',
                    style: teks.titleMedium?.copyWith(
                      fontWeight: FontWeight.w700,
                      fontFeatures: const [FontFeature.tabularFigures()],
                    ),
                  ),
                  Text(
                    'Setelah waktu habis, buat QRIS baru.',
                    style: teks.bodySmall?.copyWith(color: warna.teksSekunder),
                  ),
                ],
              ),
            ),
          ],
        );
      }
    }
    final Widget? teksGalat = _galat == null
        ? null
        : Padding(
            padding: const EdgeInsets.only(top: TokenJarak.jarak12),
            child: Text(
              _galat!,
              textAlign: TextAlign.center,
              style: TextStyle(color: warna.bahaya),
            ),
          );
    final tombolBatal = OutlinedButton(
      onPressed: _membatalkan ? null : _Batalkan,
      child: Text(_membatalkan ? 'Membatalkan…' : (tagihan == null || _selesaiTanpaBayar ? 'Tutup' : 'Batalkan QRIS')),
    );
    final Widget? tombolUtama = bisaUlang
        ? FilledButton(onPressed: _Buat, child: const Text('Buat QRIS lagi'))
        : (menunggu
              ? FilledButton(
                  onPressed: _membatalkan || _mengecekManual ? null : _CekSekarang,
                  child: Text(_mengecekManual ? 'Memeriksa…' : 'Cek status sekarang'),
                )
              : null);
    final Widget barisTombol = Row(
      children: [
        Expanded(
          flex: tombolUtama == null ? 1 : 2,
          child: SizedBox(height: 52, child: tombolBatal),
        ),
        if (tombolUtama != null) ...[
          const SizedBox(width: TokenJarak.jarak12),
          Expanded(flex: 3, child: SizedBox(height: 52, child: tombolUtama)),
        ],
      ],
    );
    final Widget? kodeReferensi = tagihan != null && !_selesaiTanpaBayar && !padat
        ? Center(
            child: TeksKode(tagihan.nomorPesanan, gaya: teks.bodySmall?.copyWith(color: warna.teksSekunder)),
          )
        : null;

    final Widget judulDialog = Row(
      crossAxisAlignment: CrossAxisAlignment.baseline,
      textBaseline: TextBaseline.alphabetic,
      children: [
        Expanded(child: Text('Bayar dengan ${widget.metode.Nama}', style: teks.titleLarge)),
        if (namaOutlet != null && namaOutlet.isNotEmpty)
          Flexible(
            child: Text(
              namaOutlet,
              overflow: TextOverflow.ellipsis,
              style: teks.bodySmall?.copyWith(color: warna.teksSekunder),
            ),
          ),
      ],
    );

    final Widget badan;
    if (duaKolom) {
      badan = Row(
        crossAxisAlignment: CrossAxisAlignment.center,
        children: [
          SizedBox(width: ukuranQr + 36, child: kartuQr),
          const SizedBox(width: TokenJarak.jarak24),
          Expanded(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                nominal,
                const SizedBox(height: TokenJarak.jarak12),
                Center(child: _ChipStatus(terputus: _terputus)),
                const SizedBox(height: TokenJarak.jarak12),
                ?petunjuk,
                if (stripCaraBayar != null) ...[const SizedBox(height: TokenJarak.jarak12), stripCaraBayar],
                if (sisaWaktu != null) ...[const SizedBox(height: TokenJarak.jarak12), sisaWaktu],
                ?teksGalat,
                const SizedBox(height: TokenJarak.jarak16),
                barisTombol,
                if (kodeReferensi != null) ...[const SizedBox(height: TokenJarak.jarak12), kodeReferensi],
              ],
            ),
          ),
        ],
      );
    } else if (tagihan == null && _galat == null) {
      badan = const Padding(
        padding: EdgeInsets.all(TokenJarak.jarak24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            CircularProgressIndicator(),
            SizedBox(height: TokenJarak.jarak12),
            Text('Membuat QRIS…'),
          ],
        ),
      );
    } else {
      final jarak = padat ? TokenJarak.jarak8 : TokenJarak.jarak12;
      badan = Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          nominal,
          if (kartuQr != null) ...[
            SizedBox(height: jarak),
            Center(child: _ChipStatus(terputus: _terputus)),
            SizedBox(height: padat ? TokenJarak.jarak12 : TokenJarak.jarak16),
            kartuQr,
            SizedBox(height: jarak),
            ?petunjuk,
            if (stripCaraBayar != null) ...[SizedBox(height: jarak), stripCaraBayar],
            if (sisaWaktu != null) ...[SizedBox(height: jarak), sisaWaktu],
          ],
          ?teksGalat,
        ],
      );
    }
    return PopScope(
      canPop: false,
      child: Dialog(
        backgroundColor: warna.permukaan,
        insetPadding: const EdgeInsets.symmetric(
          horizontal: _jarakTepiKiriKanan / 2,
          vertical: _jarakTepiAtasBawah / 2,
        ),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(TokenJarak.radiusKartu)),
        child: ConstrainedBox(
          constraints: BoxConstraints(maxWidth: duaKolom ? 760 : 520),
          child: SingleChildScrollView(
            padding: EdgeInsets.all(padat ? TokenJarak.jarak16 : TokenJarak.jarak24),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                judulDialog,
                SizedBox(height: padat ? TokenJarak.jarak8 : TokenJarak.jarak16),
                badan,
                if (!duaKolom) ...[
                  SizedBox(height: padat ? TokenJarak.jarak12 : TokenJarak.jarak16),
                  barisTombol,
                  if (kodeReferensi != null) ...[const SizedBox(height: TokenJarak.jarak12), kodeReferensi],
                ],
              ],
            ),
          ),
        ),
      ),
    );
  }
}

/// Status menunggu: kapsul beraksen dengan titik dan teks (status tidak hanya warna).
class _ChipStatus extends StatelessWidget {
  const _ChipStatus({required this.terputus});

  final bool terputus;

  @override
  Widget build(BuildContext context) {
    final warna = TokenWarna.AmbilDari(context);
    final teks = Theme.of(context).textTheme;
    final warnaTeks = terputus ? warna.bahaya : warna.peringatan;
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: TokenJarak.jarak12, vertical: TokenJarak.jarak8),
      decoration: BoxDecoration(
        color: (terputus ? warna.bahaya : warna.aksen).withValues(alpha: 0.16),
        borderRadius: BorderRadius.circular(TokenJarak.radiusKapsul),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Container(
            width: 16,
            height: 16,
            alignment: Alignment.center,
            decoration: BoxDecoration(color: warnaTeks.withValues(alpha: 0.25), shape: BoxShape.circle),
            child: Container(
              width: 8,
              height: 8,
              decoration: BoxDecoration(color: warnaTeks, shape: BoxShape.circle),
            ),
          ),
          const SizedBox(width: TokenJarak.jarak8),
          Flexible(
            child: Text(
              terputus ? 'Koneksi terputus, mencoba lagi…' : 'Menunggu pembayaran',
              style: teks.bodyMedium?.copyWith(color: warnaTeks, fontWeight: FontWeight.w700),
            ),
          ),
        ],
      ),
    );
  }
}

/// Kartu putih berpinggir berisi QR, label jenis kode, dan nama toko (identitas yang membuat QR terlihat resmi).
class _KartuQr extends StatelessWidget {
  const _KartuQr({
    required this.data,
    required this.label,
    required this.judul,
    this.namaOutlet,
    this.ukuranMaks = 280,
  });

  final String data;
  final String label;
  final String judul;
  final String? namaOutlet;

  /// Sisi terbesar QR; mengecil di layar pendek agar dialog muat tanpa digulir.
  final double ukuranMaks;

  @override
  Widget build(BuildContext context) {
    final warna = TokenWarna.AmbilDari(context);
    final teks = Theme.of(context).textTheme;
    return Container(
      padding: const EdgeInsets.all(TokenJarak.jarak16),
      decoration: BoxDecoration(
        color: warna.permukaan,
        border: Border.all(color: warna.garis, width: 2),
        borderRadius: BorderRadius.circular(TokenJarak.radiusKartu),
      ),
      child: LayoutBuilder(
        builder: (konteks, batas) {
          final ukuran = batas.maxWidth.clamp(120.0, ukuranMaks).toDouble();
          return Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Align(
                alignment: Alignment.centerLeft,
                child: judul == 'QRIS'
                    ? Image.asset('assets/logo/Qris.png', height: 28, semanticLabel: 'Logo QRIS')
                    : Text(judul, style: teks.titleMedium?.copyWith(fontWeight: FontWeight.w800, letterSpacing: 1)),
              ),
              const SizedBox(height: TokenJarak.jarak12),
              GambarQr(data: data, ukuran: ukuran, label: label),
              if (namaOutlet != null && namaOutlet!.isNotEmpty) ...[
                const SizedBox(height: TokenJarak.jarak12),
                Text(
                  namaOutlet!.toUpperCase(),
                  textAlign: TextAlign.center,
                  style: teks.titleSmall?.copyWith(fontWeight: FontWeight.w700),
                ),
              ],
            ],
          );
        },
      ),
    );
  }
}

/// Cincin sisa waktu: busur beraksen yang menyusut dari penuh ke kosong.
class _CincinWaktu extends StatelessWidget {
  const _CincinWaktu({required this.sisa, required this.warnaBusur, required this.warnaLatar});

  final double sisa;
  final Color warnaBusur;
  final Color warnaLatar;

  @override
  Widget build(BuildContext context) => SizedBox(
    width: 40,
    height: 40,
    child: CustomPaint(
      painter: _PelukisCincin(sisa: sisa, warnaBusur: warnaBusur, warnaLatar: warnaLatar),
    ),
  );
}

class _PelukisCincin extends CustomPainter {
  _PelukisCincin({required this.sisa, required this.warnaBusur, required this.warnaLatar});

  final double sisa;
  final Color warnaBusur;
  final Color warnaLatar;

  @override
  void paint(Canvas kanvas, Size ukuran) {
    const tebal = 4.0;
    final area = Rect.fromLTWH(tebal / 2, tebal / 2, ukuran.width - tebal, ukuran.height - tebal);
    final cat = Paint()
      ..style = PaintingStyle.stroke
      ..strokeWidth = tebal
      ..strokeCap = StrokeCap.round;
    kanvas.drawArc(area, 0, 2 * 3.141592653589793, false, cat..color = warnaLatar);
    kanvas.drawArc(area, -3.141592653589793 / 2, 2 * 3.141592653589793 * sisa, false, cat..color = warnaBusur);
  }

  @override
  bool shouldRepaint(_PelukisCincin lama) =>
      lama.sisa != sisa || lama.warnaBusur != warnaBusur || lama.warnaLatar != warnaLatar;
}

/// Logo bank dan e-wallet yang lazim memindai QRIS. Sama dengan logo di halaman bayar back-office (D-161): masih
/// logo komunitas CC BY-NC, ganti dengan logo resmi sebelum rilis komersial (lihat `assets/logo/SUMBER.txt`).
class _StripCaraBayar extends StatelessWidget {
  const _StripCaraBayar();

  static const List<String> _berkas = ['Dana', 'ShopeePay', 'Bca', 'Mandiri', 'Bri', 'Bni'];

  @override
  Widget build(BuildContext context) => Semantics(
    label: 'Bisa dibayar lewat DANA, ShopeePay, BCA, Mandiri, BRI, BNI, dan aplikasi lain',
    child: ExcludeSemantics(
      child: Wrap(
        alignment: WrapAlignment.center,
        crossAxisAlignment: WrapCrossAlignment.center,
        spacing: TokenJarak.jarak16,
        runSpacing: TokenJarak.jarak8,
        children: [for (final nama in _berkas) Image.asset('assets/logo/$nama.png', height: 20)],
      ),
    ),
  );
}
