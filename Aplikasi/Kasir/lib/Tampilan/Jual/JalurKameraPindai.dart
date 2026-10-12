import 'dart:async';

import 'package:flutter/material.dart';
import 'package:sistem_desain/SistemDesain.dart';

import '../../Domain/Perangkat/PemindaiKamera.dart';

/// Hasil pemrosesan satu kode oleh layar Jual, untuk ditampilkan di strip kamera: [nomor] naik tiap kode baru,
/// [teks] kalimat hasilnya ("Ditambahkan: Air Mineral 600 ml"), [galat] true bila kode tidak dikenal/tidak bisa dijual.
typedef HasilPindaiKamera = ({int nomor, String teks, bool galat});

/// Strip kamera pemindai barcode di layar Jual. Kamera **tetap terbuka** sampai kasir menutupnya, jadi banyak barang
/// bisa dipindai berturut-turut; tiap kode diteruskan ke [saatKode] yang menjalankan jalur yang sama dengan pemindai
/// USB/Bluetooth. Kasir bisa pindah kamera depan/belakang dan menyalakan senter di strip ini.
///
/// Satu barang yang tetap terlihat kamera hanya dihitung sekali: kode yang sama diterima lagi hanya setelah sempat
/// hilang dari pandangan selama [jedaHilang] (barang diangkat lalu barang berikutnya yang sama didekatkan).
class JalurKameraPindai extends StatefulWidget {
  const JalurKameraPindai({
    super.key,
    required this.pabrik,
    required this.arahAwal,
    required this.jam,
    required this.saatKode,
    required this.saatArahBerubah,
    required this.saatTutup,
    this.hasil,
    this.tinggi = 184,
  });

  final PabrikPemindaiKamera pabrik;
  final ArahKamera arahAwal;
  final DateTime Function() jam;
  final ValueChanged<String> saatKode;

  /// Dipanggil setelah kamera benar-benar berpindah, untuk mengingat pilihan kasir.
  final ValueChanged<ArahKamera> saatArahBerubah;
  final VoidCallback saatTutup;
  final HasilPindaiKamera? hasil;
  final double tinggi;

  /// Lama sebuah kode harus tidak terlihat sebelum kode yang sama dihitung sebagai barang baru.
  static const Duration jedaHilang = Duration(milliseconds: 800);

  /// Lama bingkai strip berkedip hijau/merah setelah kode terbaca.
  static const Duration lamaKedip = Duration(milliseconds: 600);

  @override
  State<JalurKameraPindai> createState() => _JalurKameraPindaiState();
}

class _JalurKameraPindaiState extends State<JalurKameraPindai> {
  late final SesiPemindaiKamera _sesi;
  StreamSubscription<String>? _langganan;
  String? _kodeTerakhir;
  DateTime? _terlihatPada;
  ArahKamera? _arahDilaporkan;
  bool _berpindah = false;
  int _nomorKedip = 0;
  Timer? _pewaktuKedip;
  bool _kedip = false;

  @override
  void initState() {
    super.initState();
    _sesi = widget.pabrik.Buka(arah: widget.arahAwal);
    _arahDilaporkan = widget.arahAwal;
    _langganan = _sesi.kodeTerbaca.listen(_SaatKode);
    _sesi.keadaan.addListener(_SaatKeadaan);
  }

  @override
  void didUpdateWidget(JalurKameraPindai lama) {
    super.didUpdateWidget(lama);
    final hasil = widget.hasil;
    if (hasil != null && hasil.nomor != _nomorKedip) {
      _nomorKedip = hasil.nomor;
      _pewaktuKedip?.cancel();
      setState(() => _kedip = true);
      _pewaktuKedip = Timer(JalurKameraPindai.lamaKedip, () {
        if (mounted) {
          setState(() => _kedip = false);
        }
      });
    }
  }

  @override
  void dispose() {
    _pewaktuKedip?.cancel();
    unawaited(_langganan?.cancel());
    _sesi.keadaan.removeListener(_SaatKeadaan);
    unawaited(_sesi.Tutup());
    super.dispose();
  }

  void _SaatKode(String kode) {
    final sekarang = widget.jam();
    final terlihatPada = _terlihatPada;
    final baru =
        _kodeTerakhir != kode ||
        terlihatPada == null ||
        sekarang.difference(terlihatPada) > JalurKameraPindai.jedaHilang;
    // Selama barang masih di depan kamera, kode yang sama terus dipancarkan: dicatat terlihat, tidak diproses lagi.
    _kodeTerakhir = kode;
    _terlihatPada = sekarang;
    if (baru && mounted) {
      widget.saatKode(kode);
    }
  }

  void _SaatKeadaan() {
    final keadaan = _sesi.keadaan.value;
    if (keadaan.siap && keadaan.arah != _arahDilaporkan) {
      _arahDilaporkan = keadaan.arah;
      widget.saatArahBerubah(keadaan.arah);
    }
    if (_berpindah && keadaan.siap && mounted) {
      setState(() => _berpindah = false);
    }
  }

  Future<void> _Ganti() async {
    setState(() => _berpindah = true);
    await _sesi.GantiKamera();
    if (mounted && _berpindah && _sesi.keadaan.value.siap) {
      setState(() => _berpindah = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final warna = TokenWarna.AmbilDari(context);
    final teks = Theme.of(context).textTheme;
    final hasil = widget.hasil;
    final warnaBingkai = _kedip && hasil != null ? (hasil.galat ? warna.bahaya : warna.sukses) : warna.garis;
    final scrim = warna.teksUtama.withValues(alpha: 0.62);

    return Semantics(
      container: true,
      label: 'Pemindai kamera',
      child: AnimatedContainer(
        key: const ValueKey('JalurKameraPindai'),
        duration: const Duration(milliseconds: 120),
        height: widget.tinggi,
        clipBehavior: Clip.antiAlias,
        decoration: BoxDecoration(
          color: warna.teksUtama,
          borderRadius: BorderRadius.circular(TokenJarak.radiusPanel),
          border: Border.all(color: warnaBingkai, width: _kedip ? 3 : TokenJarak.tebalGaris),
        ),
        child: ValueListenableBuilder<KeadaanPemindaiKamera>(
          valueListenable: _sesi.keadaan,
          builder: (context, keadaan, _) {
            final galat = keadaan.galat;
            return Stack(
              fit: StackFit.expand,
              children: [
                if (galat == null)
                  _sesi.BangunPratinjau(context)
                else
                  Center(
                    child: Padding(
                      padding: const EdgeInsets.all(TokenJarak.jarak16),
                      child: Text(
                        galat,
                        key: const ValueKey('GalatKameraPindai'),
                        textAlign: TextAlign.center,
                        style: teks.bodyMedium?.copyWith(color: warna.permukaan),
                      ),
                    ),
                  ),
                Positioned(
                  left: 0,
                  right: 0,
                  top: 0,
                  child: Container(
                    color: scrim,
                    padding: const EdgeInsets.only(left: TokenJarak.jarak12),
                    child: Row(
                      children: [
                        Expanded(
                          child: Text(
                            keadaan.arah.label,
                            key: const ValueKey('ArahKameraPindai'),
                            style: teks.labelLarge?.copyWith(color: warna.permukaan),
                          ),
                        ),
                        if (keadaan.senterTersedia)
                          IconButton(
                            key: const ValueKey('SenterKameraPindai'),
                            tooltip: keadaan.senterNyala ? 'Matikan senter' : 'Nyalakan senter',
                            color: warna.permukaan,
                            onPressed: () => unawaited(_sesi.UbahSenter()),
                            icon: Icon(keadaan.senterNyala ? Icons.flash_on : Icons.flash_off),
                          ),
                        IconButton(
                          key: const ValueKey('GantiKameraPindai'),
                          tooltip: !keadaan.bisaGantiKamera
                              ? 'Perangkat ini hanya punya satu kamera'
                              : keadaan.arah == ArahKamera.Belakang
                              ? 'Pakai kamera depan'
                              : 'Pakai kamera belakang',
                          color: warna.permukaan,
                          onPressed: keadaan.bisaGantiKamera && !_berpindah ? () => unawaited(_Ganti()) : null,
                          icon: const Icon(Icons.cameraswitch_outlined),
                        ),
                        IconButton(
                          key: const ValueKey('TutupKameraPindai'),
                          tooltip: 'Tutup kamera',
                          color: warna.permukaan,
                          onPressed: widget.saatTutup,
                          icon: const Icon(Icons.close),
                        ),
                      ],
                    ),
                  ),
                ),
                Positioned(
                  left: 0,
                  right: 0,
                  bottom: 0,
                  child: Container(
                    color: scrim,
                    padding: const EdgeInsets.symmetric(horizontal: TokenJarak.jarak12, vertical: TokenJarak.jarak8),
                    child: Semantics(
                      liveRegion: true,
                      child: Text(
                        hasil?.teks ?? 'Arahkan kamera ke barcode barang. Barang masuk keranjang otomatis.',
                        key: const ValueKey('HasilKameraPindai'),
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                        style: teks.bodyMedium?.copyWith(color: warna.permukaan),
                      ),
                    ),
                  ),
                ),
              ],
            );
          },
        ),
      ),
    );
  }
}
