import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:mesin_kasir/MesinKasir.dart';
import 'package:sistem_desain/SistemDesain.dart';

import '../Komponen/MasukanUang.dart';
import '../../Aplikasi/Penyedia.dart';
import '../../Data/BasisData/BasisDataKasir.dart';
import '../../Domain/GalatKasir.dart';
import '../../Domain/Penjualan/KonteksPenjualan.dart';
import '../../Domain/Penjualan/LayananPreOrder.dart';
import '../Struk/BagianCetakDokumen.dart';
import '../../Domain/Sesi/StafLokal.dart';

/// F-12 bagian 2: jadikan keranjang ber-pelanggan sebagai pre-order dengan uang muka (bisa offline). Tanggal ambil, DP
/// (satu metode, tanpa kembalian), referensi non-tunai, dan catatan pesanan (misalnya tulisan di kue). Stok & pendapatan
/// baru tercatat saat pesanan diambil.
class PanelPreOrder extends ConsumerStatefulWidget {
  const PanelPreOrder({super.key, required this.kasir, required this.saatSelesai, required this.saatKembali});

  final StafLokal kasir;
  final ValueChanged<PreOrderTersimpan> saatSelesai;
  final VoidCallback saatKembali;

  @override
  ConsumerState<PanelPreOrder> createState() => _PanelPreOrderState();
}

class _PanelPreOrderState extends ConsumerState<PanelPreOrder> {
  final _uangMuka = TextEditingController();
  final _referensi = TextEditingController();
  final _catatan = TextEditingController();
  late DateTime _tanggalAmbil = DateTime.now().add(const Duration(days: 1));
  BarisMetodePembayaran? _metode;
  String? _galat;
  bool _sibuk = false;

  @override
  void dispose() {
    _uangMuka.dispose();
    _referensi.dispose();
    _catatan.dispose();
    super.dispose();
  }

  static String TulisTanggal(DateTime t) =>
      '${t.year.toString().padLeft(4, '0')}-${t.month.toString().padLeft(2, '0')}-${t.day.toString().padLeft(2, '0')}';

  static String TampilTanggal(DateTime t) =>
      '${t.day.toString().padLeft(2, '0')}/${t.month.toString().padLeft(2, '0')}/${t.year}';

  Future<void> _PilihTanggal() async {
    final hariIni = DateTime.now();
    final dipilih = await showDatePicker(
      context: context,
      initialDate: _tanggalAmbil,
      firstDate: DateTime(hariIni.year, hariIni.month, hariIni.day),
      lastDate: hariIni.add(const Duration(days: 365)),
      helpText: 'Tanggal ambil',
    );
    if (dipilih != null) {
      setState(() => _tanggalAmbil = dipilih);
    }
  }

  Future<void> _Simpan(KonteksPenjualan k) async {
    if (_sibuk) {
      return;
    }
    if (ref.read(penyediaModeLatihan)) {
      setState(() => _galat = 'Mode latihan hidup, jadi pre-order tidak disimpan. Matikan dulu di Pengaturan.');
      return;
    }
    final metode = _metode;
    final teks = _uangMuka.text.trim();
    if (metode == null) {
      setState(() => _galat = 'Pilih metode pembayaran uang muka.');
      return;
    }
    if (teks.isEmpty) {
      setState(() => _galat = 'Isi uang muka.');
      return;
    }
    setState(() {
      _sibuk = true;
      _galat = null;
    });
    try {
      final hasil = await ref
          .read(penyediaLayananPreOrder)
          .Buat(
            keranjang: ref.read(penyediaKeranjangEfektif),
            k: k,
            kasir: widget.kasir,
            tanggalAmbil: TulisTanggal(_tanggalAmbil),
            uangMuka: MasukanUang.UraiTeks(teks) ?? Uang.Nol(),
            metode: metode,
            referensi: _referensi.text,
            catatan: _catatan.text,
          );
      ref.read(penyediaKeranjang.notifier).Kosongkan();
      widget.saatSelesai(hasil);
      unawaited(ref.read(penyediaSesi.notifier).Sinkronkan());
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

  @override
  Widget build(BuildContext context) {
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    final k = ref.watch(penyediaKonteksPenjualan).value;
    final keranjang = ref.watch(penyediaKeranjangEfektif);
    if (k == null) {
      return const Padding(padding: EdgeInsets.all(TokenJarak.jarak24), child: LinearProgressIndicator());
    }
    final total = keranjang.CekKosong
        ? Uang.Nol()
        : ref.read(penyediaLayananPenjualan).Hitung(keranjang, k).hasil.totalAkhir;
    final metodeBoleh = k.metodePembayaran.where((m) => JenisMetodeBayar.bolehUangMuka.contains(m.Jenis)).toList();
    final metode = _metode;

    return Padding(
      padding: const EdgeInsets.all(TokenJarak.jarak24),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(
            'Pesanan atas nama ${keranjang.pelanggan?.nama ?? '-'}. Uang muka dicatat sekarang. Stok berkurang dan '
            'penjualan tercatat saat pesanan diambil.',
            style: teks.bodySmall,
          ),
          const SizedBox(height: TokenJarak.jarak12),
          Row(
            children: [
              Expanded(child: Text('Perkiraan total', style: teks.bodyMedium)),
              TeksUang(total, gaya: teks.titleMedium),
            ],
          ),
          const SizedBox(height: TokenJarak.jarak12),
          SizedBox(
            height: TokenJarak.targetSentuh,
            child: OutlinedButton.icon(
              onPressed: _sibuk ? null : () => unawaited(_PilihTanggal()),
              icon: const Icon(Icons.event_outlined),
              label: Text('Tanggal ambil: ${TampilTanggal(_tanggalAmbil)}'),
            ),
          ),
          const SizedBox(height: TokenJarak.jarak12),
          Wrap(
            spacing: TokenJarak.jarak8,
            runSpacing: TokenJarak.jarak8,
            children: [
              for (final m in metodeBoleh)
                ChoiceChip(
                  label: Text(m.Nama),
                  selected: metode?.Uuid == m.Uuid,
                  onSelected: _sibuk ? null : (_) => setState(() => _metode = m),
                ),
            ],
          ),
          const SizedBox(height: TokenJarak.jarak12),
          TextField(
            controller: _uangMuka,
            keyboardType: TextInputType.number,
            inputFormatters: [MasukanUang.pemformat],
            textAlign: TextAlign.right,
            style: const TextStyle(fontFeatures: [FontFeature.tabularFigures()]),
            onChanged: (_) => setState(() => _galat = null),
            decoration: const InputDecoration(labelText: 'Uang muka', prefixText: 'Rp ', border: OutlineInputBorder()),
          ),
          if (metode != null && metode.Jenis != JenisMetodeBayar.tunai) ...[
            const SizedBox(height: TokenJarak.jarak8),
            TextField(
              controller: _referensi,
              maxLength: 60,
              decoration: const InputDecoration(labelText: 'Referensi (opsional)', border: OutlineInputBorder()),
            ),
          ],
          const SizedBox(height: TokenJarak.jarak8),
          TextField(
            controller: _catatan,
            maxLength: LayananPreOrder.panjangCatatanMaksimal,
            maxLines: 2,
            decoration: const InputDecoration(
              labelText: 'Catatan pesanan (opsional)',
              hintText: 'Misal: tulisan di kue, warna, ukuran',
              border: OutlineInputBorder(),
            ),
          ),
          if (_galat != null)
            Padding(
              padding: const EdgeInsets.only(top: TokenJarak.jarak8),
              child: Text(_galat!, style: TextStyle(color: warna.bahaya)),
            ),
          const SizedBox(height: TokenJarak.jarak16),
          SizedBox(
            height: 56,
            child: FilledButton(
              onPressed: _sibuk ? null : () => unawaited(_Simpan(k)),
              child: Text(_sibuk ? 'Menyimpan…' : 'Simpan pre-order'),
            ),
          ),
          const SizedBox(height: TokenJarak.jarak8),
          SizedBox(
            height: TokenJarak.targetSentuh,
            child: TextButton(onPressed: _sibuk ? null : widget.saatKembali, child: const Text('Kembali ke Bayar')),
          ),
        ],
      ),
    );
  }
}

/// Setelah pre-order tersimpan: nomor, uang muka, tanggal ambil, lalu transaksi baru.
class TampilanPreOrderSelesai extends StatelessWidget {
  const TampilanPreOrderSelesai({super.key, required this.hasil, required this.saatTransaksiBaru});

  final PreOrderTersimpan hasil;
  final VoidCallback saatTransaksiBaru;

  @override
  Widget build(BuildContext context) {
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    final t = hasil.tanggalAmbil;
    return Padding(
      padding: const EdgeInsets.all(TokenJarak.jarak24),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              Icon(Icons.check_circle_outline, color: warna.sukses),
              const SizedBox(width: TokenJarak.jarak8),
              Expanded(child: Text('Pre-order tersimpan', style: teks.titleMedium)),
            ],
          ),
          const SizedBox(height: TokenJarak.jarak4),
          TeksKode(hasil.nomor, gaya: teks.bodyMedium?.copyWith(color: warna.teksSekunder)),
          const SizedBox(height: TokenJarak.jarak16),
          Row(
            children: [
              Expanded(child: Text('Uang muka', style: teks.bodyMedium)),
              TeksUang(hasil.uangMuka, gaya: teks.titleMedium),
            ],
          ),
          Row(
            children: [
              Expanded(child: Text('Sisa saat diambil (perkiraan)', style: teks.bodyMedium)),
              TeksUang(hasil.totalPesanan.Kurangi(hasil.uangMuka)),
            ],
          ),
          const SizedBox(height: TokenJarak.jarak4),
          Text(
            'Diambil ${t.substring(8, 10)}/${t.substring(5, 7)}/${t.substring(0, 4)}. Ambil lewat menu Riwayat › Ambil '
            'pre-order.',
            style: teks.bodySmall,
          ),
          const SizedBox(height: TokenJarak.jarak16),
          // Cetak struk bagian 4a: bukti uang muka dicetak otomatis sekali; laci dibuka bila DP tunai.
          BagianCetakDokumen(
            kunci: 'PreOrder:${hasil.uuid}',
            namaDokumen: 'bukti uang muka',
            cetak: (layanan, cetakUlang, otomatis) =>
                layanan.CetakPreOrder(hasil, cetakUlang: cetakUlang, bukaLaci: otomatis),
          ),
          const SizedBox(height: TokenJarak.jarak24),
          SizedBox(
            height: 56,
            child: FilledButton(autofocus: true, onPressed: saatTransaksiBaru, child: const Text('Transaksi baru')),
          ),
        ],
      ),
    );
  }
}
