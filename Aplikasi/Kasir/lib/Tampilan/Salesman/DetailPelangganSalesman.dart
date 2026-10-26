import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:inti/Inti.dart';
import 'package:klien_api/KlienApi.dart';
import 'package:sistem_desain/SistemDesain.dart';

import '../../Aplikasi/PenyediaSalesman.dart';
import '../../Data/BasisData/BasisDataKasir.dart';
import '../../Domain/GalatKasir.dart';
import '../../Domain/Salesman/LayananSalesman.dart';
import '../../Domain/Sesi/StafLokal.dart';
import '../Komponen/FormatWaktu.dart';
import 'LayarSalesman.dart';

/// Rincian satu pelanggan untuk salesman: kontak & alamat (teks yang bisa dipilih/disalin), posisi kredit, kunjungan
/// terakhir, aksi kunjungan & pesanan, dan daftar piutang terbuka (dibaca online; offline diberi keterangan jelas).
class DetailPelangganSalesman extends StatelessWidget {
  const DetailPelangganSalesman({
    super.key,
    required this.pelanggan,
    required this.staf,
    required this.kunjunganBerjalan,
    required this.mencariLokasi,
    required this.sibuk,
    required this.tepi,
    required this.saatMulaiKunjungan,
    required this.saatSelesaiKunjungan,
    required this.saatAmbilPesanan,
    this.saatKembali,
  });

  final PelangganSalesman pelanggan;
  final StafLokal staf;
  final BarisKunjunganSalesLokal? kunjunganBerjalan;
  final bool mencariLokasi;
  final bool sibuk;
  final double tepi;
  final VoidCallback saatMulaiKunjungan;
  final VoidCallback? saatSelesaiKunjungan;
  final VoidCallback saatAmbilPesanan;

  /// Kembali ke daftar (layar sempit); null = daftar berdampingan.
  final VoidCallback? saatKembali;

  @override
  Widget build(BuildContext context) {
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    final p = pelanggan;
    final berjalan = kunjunganBerjalan;
    final diSini = berjalan != null && berjalan.UuidPelanggan == p.uuid;
    final diTempatLain = berjalan != null && !diSini;
    final sisaLimit = p.sisaLimit;

    // Rincian pendek: dibangun utuh (bukan daftar malas) supaya pembaca layar & pencarian menemukan semua isinya.
    return SingleChildScrollView(
      padding: EdgeInsets.all(tepi),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (saatKembali != null)
            Align(
              alignment: Alignment.centerLeft,
              child: SizedBox(
                height: TokenJarak.targetSentuh,
                child: TextButton.icon(
                  onPressed: saatKembali,
                  icon: const Icon(Icons.arrow_back),
                  label: const Text('Daftar pelanggan'),
                ),
              ),
            ),
          Semantics(header: true, child: Text(p.nama, style: teks.titleLarge)),
          if (p.namaTier != null)
            Text('Tier ${p.namaTier}', style: teks.bodyMedium?.copyWith(color: warna.teksSekunder)),
          const SizedBox(height: TokenJarak.jarak12),
          _BarisInfo(
            label: 'Nomor HP',
            isi: SelectableText(p.noHp ?? '—', style: teks.bodyLarge),
          ),
          _BarisInfo(
            label: 'Alamat',
            isi: SelectableText(p.alamat ?? '—', style: teks.bodyMedium),
          ),
          _BarisInfo(
            label: 'Kunjungan terakhir',
            isi: Text(
              diSini
                  ? 'Sedang dikunjungi sejak ${FormatWaktu.FormatJam(berjalan.MasukPada)}'
                  : p.terakhirDikunjungiPada == null
                  ? 'Belum pernah dikunjungi'
                  : FormatWaktu.FormatTanggalJam(p.terakhirDikunjungiPada!),
              style: teks.bodyMedium,
            ),
          ),
          const SizedBox(height: TokenJarak.jarak8),
          KotakPanel(
            anak: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                _BarisAngka(label: 'Sisa piutang', nilai: p.sisaPiutang),
                if (p.limitKredit != null) ...[
                  _BarisAngka(label: 'Limit kredit', nilai: p.limitKredit!),
                  _BarisAngka(label: 'Sisa limit', nilai: sisaLimit!, bahaya: sisaLimit.BernilaiNegatif()),
                ] else
                  const _BarisTeks(label: 'Limit kredit', nilai: 'Tanpa limit'),
                _BarisTeks(label: 'Termin', nilai: p.terminHari == 0 ? 'Tunai' : '${p.terminHari} hari'),
                if (p.adaLewatJatuhTempo) ...[
                  const SizedBox(height: TokenJarak.jarak8),
                  Row(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Icon(Icons.warning_amber_outlined, size: TokenJarak.ikonSedang, color: warna.bahaya),
                      const SizedBox(width: TokenJarak.jarak8),
                      Expanded(
                        child: Text(
                          '${p.jumlahPiutangJatuhTempo.FormatRupiah()} lewat jatuh tempo, paling lama '
                          '${p.hariLewatJatuhTempo} hari. Ingatkan pelanggan untuk melunasi.',
                          style: teks.bodyMedium?.copyWith(color: warna.bahaya),
                        ),
                      ),
                    ],
                  ),
                ],
              ],
            ),
          ),
          const SizedBox(height: TokenJarak.jarak16),
          if (diSini)
            Text(
              'Sedang berkunjung | ${TeksLokasiKunjungan(berjalan, mencari: mencariLokasi)}',
              style: teks.bodyMedium?.copyWith(color: warna.info),
            ),
          if (diTempatLain)
            Text(
              'Masih berkunjung ke ${berjalan.NamaPelanggan}. Selesaikan kunjungan itu sebelum memulai yang baru.',
              style: teks.bodyMedium?.copyWith(color: warna.peringatan),
            ),
          const SizedBox(height: TokenJarak.jarak8),
          Wrap(
            spacing: TokenJarak.jarak12,
            runSpacing: TokenJarak.jarak8,
            children: [
              if (diSini) ...[
                SizedBox(
                  height: TokenJarak.targetSentuh,
                  child: FilledButton.icon(
                    onPressed: saatAmbilPesanan,
                    icon: const Icon(Icons.add_shopping_cart),
                    label: const Text('Ambil pesanan'),
                  ),
                ),
                SizedBox(
                  height: TokenJarak.targetSentuh,
                  child: OutlinedButton.icon(
                    onPressed: saatSelesaiKunjungan,
                    icon: const Icon(Icons.check_circle_outline),
                    label: const Text('Selesai kunjungan'),
                  ),
                ),
              ] else ...[
                SizedBox(
                  height: TokenJarak.targetSentuh,
                  child: FilledButton.icon(
                    onPressed: diTempatLain || sibuk ? null : saatMulaiKunjungan,
                    icon: const Icon(Icons.directions_walk),
                    label: Text(sibuk ? 'Memulai…' : 'Mulai kunjungan'),
                  ),
                ),
                SizedBox(
                  height: TokenJarak.targetSentuh,
                  child: OutlinedButton.icon(
                    onPressed: saatAmbilPesanan,
                    icon: const Icon(Icons.add_shopping_cart),
                    label: const Text('Ambil pesanan'),
                  ),
                ),
              ],
            ],
          ),
          const SizedBox(height: TokenJarak.jarak24),
          _BagianPiutang(key: ValueKey('Piutang-${p.uuid}'), uuidPelanggan: p.uuid, staf: staf),
        ],
      ),
    );
  }
}

class _BarisInfo extends StatelessWidget {
  const _BarisInfo({required this.label, required this.isi});

  final String label;
  final Widget isi;

  @override
  Widget build(BuildContext context) {
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    return Padding(
      padding: const EdgeInsets.only(bottom: TokenJarak.jarak8),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(label, style: teks.labelMedium?.copyWith(color: warna.teksSekunder)),
          isi,
        ],
      ),
    );
  }
}

class _BarisAngka extends StatelessWidget {
  const _BarisAngka({required this.label, required this.nilai, this.bahaya = false});

  final String label;
  final Uang nilai;
  final bool bahaya;

  @override
  Widget build(BuildContext context) {
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: TokenJarak.jarak4),
      child: Row(
        children: [
          Expanded(child: Text(label, style: teks.bodyMedium)),
          TeksUang(nilai, gaya: teks.bodyMedium?.copyWith(color: bahaya ? warna.bahaya : warna.teksUtama)),
          if (bahaya) Text(' (melebihi limit)', style: teks.bodySmall?.copyWith(color: warna.bahaya)),
        ],
      ),
    );
  }
}

class _BarisTeks extends StatelessWidget {
  const _BarisTeks({required this.label, required this.nilai});

  final String label;
  final String nilai;

  @override
  Widget build(BuildContext context) {
    final teks = Theme.of(context).textTheme;
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: TokenJarak.jarak4),
      child: Row(
        children: [
          Expanded(child: Text(label, style: teks.bodyMedium)),
          Text(nilai, style: teks.bodyMedium),
        ],
      ),
    );
  }
}

/// Piutang terbuka pelanggan, dibaca online saat rincian dibuka; offline = keterangan + tombol coba lagi.
class _BagianPiutang extends ConsumerStatefulWidget {
  const _BagianPiutang({super.key, required this.uuidPelanggan, required this.staf});

  final String uuidPelanggan;
  final StafLokal staf;

  @override
  ConsumerState<_BagianPiutang> createState() => _BagianPiutangState();
}

class _BagianPiutangState extends ConsumerState<_BagianPiutang> {
  List<PiutangSalesmanPos>? _piutang;
  String? _galat;
  bool _offline = false;
  bool _memuat = true;

  @override
  void initState() {
    super.initState();
    unawaited(_Muat());
  }

  Future<void> _Muat() async {
    setState(() {
      _memuat = true;
      _galat = null;
      _offline = false;
    });
    try {
      final hasil = await ref.read(penyediaLayananSalesman).AmbilPiutang(widget.uuidPelanggan, widget.staf);
      if (mounted) {
        setState(() {
          _piutang = hasil;
          _memuat = false;
        });
      }
    } on GalatKasir catch (galat) {
      if (mounted) {
        setState(() {
          _memuat = false;
          _offline = galat.kode == 'PerluOnline';
          _galat = galat.pesan;
        });
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    final piutang = _piutang;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Row(
          children: [
            Expanded(child: Text('Piutang terbuka', style: teks.titleMedium)),
            SizedBox(
              height: TokenJarak.targetSentuh,
              child: TextButton.icon(
                onPressed: _memuat ? null : _Muat,
                icon: const Icon(Icons.refresh),
                label: const Text('Muat ulang'),
              ),
            ),
          ],
        ),
        if (_memuat) const LinearProgressIndicator(),
        if (!_memuat && _galat != null)
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Icon(
                _offline ? Icons.wifi_off : Icons.error_outline,
                size: TokenJarak.ikonSedang,
                color: _offline ? warna.peringatan : warna.bahaya,
              ),
              const SizedBox(width: TokenJarak.jarak8),
              Expanded(
                child: Text(
                  _offline
                      ? 'Offline. Rincian piutang hanya bisa dilihat saat online. Ringkasan di atas dari data '
                            'terakhir yang diunduh.'
                      : _galat!,
                  style: teks.bodyMedium,
                ),
              ),
            ],
          ),
        if (!_memuat && _galat == null && piutang != null && piutang.isEmpty)
          Text('Tidak ada piutang terbuka.', style: teks.bodyMedium),
        if (!_memuat && _galat == null && piutang != null && piutang.isNotEmpty)
          Material(
            color: warna.permukaan,
            shape: RoundedRectangleBorder(
              side: BorderSide(color: warna.garis, width: TokenJarak.tebalGaris),
              borderRadius: BorderRadius.circular(TokenJarak.radiusPanel),
            ),
            clipBehavior: Clip.antiAlias,
            child: Column(
              children: [
                for (var i = 0; i < piutang.length; i++) ...[
                  if (i > 0) Divider(height: TokenJarak.tebalGaris, color: warna.garis),
                  _BarisPiutang(piutang: piutang[i]),
                ],
              ],
            ),
          ),
      ],
    );
  }
}

class _BarisPiutang extends StatelessWidget {
  const _BarisPiutang({required this.piutang});

  final PiutangSalesmanPos piutang;

  @override
  Widget build(BuildContext context) {
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    final p = piutang;
    final lewat = p.lewatJatuhTempo;
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: TokenJarak.jarak16, vertical: TokenJarak.jarak12),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              Expanded(child: TeksKode(p.nomor)),
              TeksUang(p.sisa, gaya: teks.labelLarge),
            ],
          ),
          Row(
            children: [
              if (lewat) ...[
                Icon(Icons.warning_amber_outlined, size: TokenJarak.ikonKecil, color: warna.bahaya),
                const SizedBox(width: TokenJarak.jarak4),
              ],
              Expanded(
                child: Text(
                  'Jatuh tempo ${p.jatuhTempo}'
                  '${lewat
                      ? ' | lewat ${p.umurHari} hari'
                      : p.umurHari == 0
                      ? ' | hari ini'
                      : ''}'
                  ' | dari ${p.jumlah.FormatRupiah()}',
                  style: teks.bodySmall?.copyWith(color: lewat ? warna.bahaya : warna.teksSekunder),
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }
}
