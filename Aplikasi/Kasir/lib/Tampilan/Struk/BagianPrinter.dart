import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:sistem_desain/SistemDesain.dart';

import '../../Aplikasi/Penyedia.dart';
import '../../Domain/Dapur/LayananTiketDapur.dart';
import '../../Domain/Struk/DaftarPrinter.dart';
import '../../Domain/Struk/ProfilPrinter.dart';
import 'EditorProfilPrinter.dart';

/// Satu halaman pengaturan printer (D-67, gaya Majoo): daftar semua printer di perangkat ini. Printer tidak lagi
/// dibedakan "struk" atau "dapur" di menu; tiap printer dicentang kegunaannya: struk kasir dan/atau tiket pesanan
/// untuk stasiun dapur/bar tertentu. Sambungan (LAN, Bluetooth, USB, ...) diisi lewat [EditorProfilPrinter].
class BagianPrinter extends ConsumerStatefulWidget {
  const BagianPrinter({super.key});

  @override
  ConsumerState<BagianPrinter> createState() => _BagianPrinterState();
}

class _BagianPrinterState extends ConsumerState<BagianPrinter> {
  RuteDapur _rute = const RuteDapur();
  Map<String, PrinterDapur> _dapur = const {};

  /// null = tidak sedang mengisi; -1 = printer baru; selain itu indeks printer yang diubah.
  int? _mengubah;
  var _struk = false;
  Set<String> _stasiun = {};
  String? _galatKegunaan;
  var _sibuk = false;
  String? _pesan;
  var _pesanGalat = false;

  @override
  void initState() {
    super.initState();
    unawaited(_Muat());
  }

  Future<void> _Muat() async {
    final repositori = ref.read(penyediaRepositori);
    final rute = await RuteDapur.Muat(repositori);
    final dapur = await PrinterDapur.MuatSemua(repositori);
    if (mounted) {
      setState(() {
        _rute = rute;
        _dapur = dapur;
      });
    }
  }

  List<PrinterPerangkat> _Daftar() => DaftarPrinter.Gabung(ref.watch(penyediaPrinter).profil, _dapur);

  Future<void> _Terapkan(List<PrinterPerangkat> daftar, String pesan) async {
    final hasil = DaftarPrinter.Susun(daftar);
    final notifier = ref.read(penyediaPrinter.notifier);
    if (hasil.struk case final profil?) {
      await notifier.SimpanProfil(profil);
    } else {
      await notifier.HapusProfil();
    }
    await PrinterDapur.SimpanSemua(ref.read(penyediaRepositori), hasil.dapur);
    if (mounted) {
      setState(() {
        _dapur = hasil.dapur;
        _mengubah = null;
        _pesan = pesan;
        _pesanGalat = false;
      });
    }
  }

  void _MulaiIsi(int indeks, List<PrinterPerangkat> daftar) => setState(() {
    _pesan = null;
    _galatKegunaan = null;
    _mengubah = indeks;
    if (indeks >= 0) {
      _struk = daftar[indeks].struk;
      _stasiun = {...daftar[indeks].stasiun};
    } else {
      // Printer pertama = satu printer untuk semuanya (struk + semua stasiun dapur/bar); toko dengan banyak printer
      // tinggal mematikan yang tidak perlu. Printer berikutnya kosong: kegunaannya dipilih sendiri.
      final pertama = daftar.isEmpty;
      _struk = !daftar.any((p) => p.struk);
      // Checker tidak ikut otomatis: ia mencetak salinan semua tiket, dan menaruhnya di printer yang sama hanya mengganda.
      _stasiun = pertama ? {for (final s in _rute.memasak) s.uuid} : {};
    }
  });

  Future<void> _Simpan(ProfilPrinter profil, List<PrinterPerangkat> daftar) async {
    if (!_struk && _stasiun.isEmpty) {
      setState(() => _galatKegunaan = 'Pilih kegunaan printer: struk kasir atau tiket untuk satu stasiun.');
      return;
    }
    final indeks = _mengubah ?? -1;
    final baru = PrinterPerangkat(profil: profil, struk: _struk, stasiun: _stasiun);
    // Satu printer struk per perangkat dan satu printer per stasiun: yang dipilih sekarang menggantikan yang lama.
    final lain = [
      for (var i = 0; i < daftar.length; i++)
        if (i != indeks)
          daftar[i].copyWith(struk: _struk ? false : daftar[i].struk, stasiun: daftar[i].stasiun.difference(_stasiun)),
    ];
    // Printer yang kehilangan semua kegunaannya hilang dari daftar (tidak ada yang akan dicetak di sana).
    final hasil = [...lain.where((p) => p.struk || p.stasiun.isNotEmpty), baru];
    await _Terapkan(hasil, 'Printer disimpan.');
  }

  Future<void> _Hapus(int indeks, List<PrinterPerangkat> daftar) => _Terapkan([
    for (var i = 0; i < daftar.length; i++)
      if (i != indeks) daftar[i],
  ], 'Printer dihapus dari perangkat ini.');

  Future<void> _CetakUji(ProfilPrinter profil) async {
    setState(() {
      _sibuk = true;
      _pesan = null;
    });
    final galat = await ref.read(penyediaPrinter.notifier).CetakUji(profil);
    if (!mounted) {
      return;
    }
    setState(() {
      _sibuk = false;
      _pesan = galat ?? 'Halaman uji terkirim. Pastikan semua baris lurus dan tidak terpotong.';
      _pesanGalat = galat != null;
    });
  }

  String _NamaStasiun(String uuid) {
    final s = _rute.CariStasiun(uuid);

    return s == null ? 'Stasiun' : (s.salinan ? '${s.nama} (checker)' : s.nama);
  }

  Widget _Kegunaan(TextTheme teks, TokenWarna warna) => Column(
    crossAxisAlignment: CrossAxisAlignment.start,
    children: [
      Text('Kegunaan printer', style: teks.titleSmall),
      Text(
        'Satu printer boleh dipakai untuk semuanya: struk kasir, tiket dapur, dan tiket bar.',
        style: teks.bodySmall?.copyWith(color: warna.teksSekunder),
      ),
      SwitchListTile(
        key: const ValueKey('KegunaanStruk'),
        contentPadding: EdgeInsets.zero,
        title: const Text('Cetak struk kasir'),
        value: _struk,
        onChanged: (nilai) => setState(() {
          _struk = nilai;
          _galatKegunaan = null;
        }),
      ),
      if (_rute.stasiun.isNotEmpty) ...[
        Text('Cetak tiket pesanan untuk', style: teks.bodyMedium),
        const SizedBox(height: TokenJarak.jarak4),
        Wrap(
          spacing: TokenJarak.jarak8,
          runSpacing: TokenJarak.jarak8,
          children: [
            for (final s in _rute.stasiun)
              FilterChip(
                label: Text(s.salinan ? '${s.nama} (checker)' : s.nama),
                selected: _stasiun.contains(s.uuid),
                onSelected: (pilih) => setState(() {
                  _stasiun = pilih ? {..._stasiun, s.uuid} : ({..._stasiun}..remove(s.uuid));
                  _galatKegunaan = null;
                }),
              ),
          ],
        ),
        const SizedBox(height: TokenJarak.jarak4),
        Text(
          'Stasiun yang sudah dipakai printer lain dipindah ke printer ini. Checker mencetak salinan semua tiket untuk dicek sebelum disajikan.',
          style: teks.bodySmall?.copyWith(color: warna.teksSekunder),
        ),
      ] else
        Text(
          'Belum ada stasiun dapur/bar. Tambahkan di back-office menu Meja & dapur, lalu perbarui data kasir.',
          style: teks.bodySmall?.copyWith(color: warna.teksSekunder),
        ),
      if (_galatKegunaan != null)
        Padding(
          padding: const EdgeInsets.only(top: TokenJarak.jarak8),
          child: Text(_galatKegunaan!, style: teks.bodyMedium?.copyWith(color: warna.bahaya)),
        ),
      const SizedBox(height: TokenJarak.jarak16),
    ],
  );

  @override
  Widget build(BuildContext context) {
    final daftar = _Daftar();
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);

    if (_mengubah case final indeks?) {
      return Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          _Kegunaan(teks, warna),
          EditorProfilPrinter(
            profilAwal: indeks >= 0 ? daftar[indeks].profil : null,
            opsiStruk: _struk,
            saatSimpan: (p) => _Simpan(p, daftar),
            saatBatal: () => setState(() => _mengubah = null),
            saatCetakUji: (p) => ref.read(penyediaPrinter.notifier).CetakUji(p),
          ),
        ],
      );
    }

    Widget Tombol(String label, IconData ikon, VoidCallback? aksi, {bool utama = false}) => SizedBox(
      height: TokenJarak.targetSentuh,
      child: utama
          ? FilledButton.icon(onPressed: _sibuk ? null : aksi, icon: Icon(ikon), label: Text(label))
          : OutlinedButton.icon(onPressed: _sibuk ? null : aksi, icon: Icon(ikon), label: Text(label)),
    );

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        for (var i = 0; i < daftar.length; i++)
          _KartuPrinter(
            printer: daftar[i],
            namaStasiun: _NamaStasiun,
            tombol: [
              Tombol('Cetak uji', Icons.print_outlined, () => _CetakUji(daftar[i].profil)),
              Tombol('Ubah', Icons.edit_outlined, () => _MulaiIsi(i, daftar)),
              Tombol('Hapus printer', Icons.delete_outline, () => _Hapus(i, daftar)),
            ],
          ),
        Tombol(
          daftar.isEmpty ? 'Atur printer' : 'Tambah printer',
          daftar.isEmpty ? Icons.print_outlined : Icons.add,
          () => _MulaiIsi(-1, daftar),
          utama: daftar.isEmpty,
        ),
        if (_pesan != null)
          Padding(
            padding: const EdgeInsets.only(top: TokenJarak.jarak8),
            child: Text(
              _pesan!,
              style: teks.bodyMedium?.copyWith(color: _pesanGalat ? warna.bahaya : warna.teksSekunder),
            ),
          ),
      ],
    );
  }
}

/// Satu printer di daftar: sambungan, lencana kegunaan, dan aksinya.
class _KartuPrinter extends StatelessWidget {
  const _KartuPrinter({required this.printer, required this.namaStasiun, required this.tombol});

  final PrinterPerangkat printer;
  final String Function(String uuid) namaStasiun;
  final List<Widget> tombol;

  @override
  Widget build(BuildContext context) {
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    final profil = printer.profil;
    final lencana = [
      if (printer.struk) 'Struk kasir',
      for (final uuid in printer.stasiun) 'Tiket ${namaStasiun(uuid)}',
    ];
    return Container(
      margin: const EdgeInsets.only(bottom: TokenJarak.jarak12),
      padding: const EdgeInsets.all(TokenJarak.jarak12),
      decoration: BoxDecoration(
        color: warna.permukaan,
        borderRadius: BorderRadius.circular(TokenJarak.radiusPanel),
        border: Border.all(color: warna.garis, width: TokenJarak.tebalGaris),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text('Printer ${profil.label}', style: teks.bodyLarge),
          const SizedBox(height: TokenJarak.jarak8),
          Wrap(
            spacing: TokenJarak.jarak8,
            runSpacing: TokenJarak.jarak4,
            children: [
              for (final l in lencana)
                Chip(
                  label: Text(l),
                  visualDensity: VisualDensity.compact,
                  backgroundColor: warna.brand.withValues(alpha: 0.1),
                  side: BorderSide.none,
                ),
            ],
          ),
          if (printer.struk)
            Padding(
              padding: const EdgeInsets.only(top: TokenJarak.jarak4),
              child: Text(
                '${profil.cetakOtomatis ? 'Cetak otomatis setelah bayar' : 'Cetak manual'}'
                '${profil.bukaLaciTunai ? ' | buka laci untuk tunai' : ''}',
                style: teks.bodySmall,
              ),
            ),
          const SizedBox(height: TokenJarak.jarak12),
          Wrap(spacing: TokenJarak.jarak8, runSpacing: TokenJarak.jarak8, children: tombol),
        ],
      ),
    );
  }
}
