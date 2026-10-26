import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:mesin_kasir/MesinKasir.dart';
import 'package:sistem_desain/SistemDesain.dart';

import '../../Aplikasi/Penyedia.dart';
import '../../Aplikasi/PenyediaSalesman.dart';
import '../../Domain/GalatKasir.dart';
import '../../Domain/Katalog/KatalogLokal.dart';
import '../../Domain/Penjualan/Keranjang.dart';
import '../../Domain/Salesman/LayananSalesman.dart';
import '../../Domain/Sesi/StafLokal.dart';

/// Pesanan grosir yang baru disimpan dari keranjang: dipakai layar konfirmasi.
class PesananGrosirTersimpan {
  const PesananGrosirTersimpan({
    required this.uuid,
    required this.namaPelanggan,
    required this.jumlahBaris,
    required this.perkiraanTotal,
  });

  final String uuid;
  final String namaPelanggan;
  final int jumlahBaris;
  final Uang perkiraanTotal;
}

/// Sisa limit kredit pelanggan dikurangi [perkiraan]; negatif = perkiraan melebihi limit. Null bila pelanggan tanpa
/// limit atau datanya belum dibaca. Hanya peringatan: keputusan menerima pesanan ada di back-office (BR-12.6).
Uang? SelisihLimitKredit(PelangganTerpilih pelanggan, Uang perkiraan) {
  final limit = pelanggan.limitKredit;
  if (limit == null) {
    return null;
  }
  final piutang = pelanggan.sisaPiutang == null ? Uang.Nol() : Uang.Dari(pelanggan.sisaPiutang!);
  return Uang.Dari(limit).Kurangi(piutang).Kurangi(perkiraan);
}

/// POS-05 (§9.7): SO cepat di layar Jual. Jadikan keranjang ber-pelanggan pesanan grosir (bisa offline) tanpa
/// membayar: harga ditentukan server, pesanan masuk Draf dan dikonfirmasi di back-office, stok baru berkurang saat
/// surat jalan. Panel hanya menampilkan perkiraan dari harga katalog lokal.
class PanelPesananGrosir extends ConsumerStatefulWidget {
  const PanelPesananGrosir({super.key, required this.kasir, required this.saatSelesai, required this.saatKembali});

  final StafLokal kasir;
  final ValueChanged<PesananGrosirTersimpan> saatSelesai;
  final VoidCallback saatKembali;

  @override
  ConsumerState<PanelPesananGrosir> createState() => _PanelPesananGrosirState();
}

class _PanelPesananGrosirState extends ConsumerState<PanelPesananGrosir> {
  final _catatan = TextEditingController();
  String? _galat;
  bool _sibuk = false;

  @override
  void dispose() {
    _catatan.dispose();
    super.dispose();
  }

  Future<void> _Simpan(Keranjang keranjang, KatalogLokal katalog) async {
    if (_sibuk) {
      return;
    }
    if (ref.read(penyediaModeLatihan)) {
      setState(() => _galat = 'Mode latihan hidup, jadi pesanan grosir tidak disimpan. Matikan dulu di Pengaturan.');
      return;
    }
    setState(() {
      _sibuk = true;
      _galat = null;
    });
    try {
      final k = ref.read(penyediaKonteksPenjualan).value;
      final layanan = ref.read(penyediaLayananSalesman);
      final uuid = await layanan.KirimPesananDariKeranjang(
        keranjang: keranjang,
        katalog: katalog,
        staf: widget.kasir,
        uuidOutlet: k?.uuidOutlet,
        catatan: _catatan.text,
      );
      final pelanggan = keranjang.pelanggan!;
      final perkiraan = LayananSalesman.HitungPerkiraan(
        LayananSalesman.BarisDariKeranjang(keranjang, katalog),
        katalog,
        tierPelanggan: pelanggan.kodeTier,
        uuidOutlet: k?.uuidOutlet,
        waktu: DateTime.now(),
      );
      ref.read(penyediaKeranjang.notifier).Kosongkan();
      widget.saatSelesai(
        PesananGrosirTersimpan(
          uuid: uuid,
          namaPelanggan: pelanggan.nama,
          jumlahBaris: keranjang.baris.length,
          perkiraanTotal: perkiraan.total,
        ),
      );
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
    final keranjang = ref.watch(penyediaKeranjang);
    final katalog = ref.watch(penyediaKatalog).value;
    final k = ref.watch(penyediaKonteksPenjualan).value;
    if (katalog == null || k == null) {
      return const Padding(padding: EdgeInsets.all(TokenJarak.jarak24), child: LinearProgressIndicator());
    }

    final pelanggan = keranjang.pelanggan;
    final alasan = LayananSalesman.AlasanKeranjangTidakBisaJadiSo(keranjang, katalog);
    final perkiraan = alasan == null
        ? LayananSalesman.HitungPerkiraan(
            LayananSalesman.BarisDariKeranjang(keranjang, katalog),
            katalog,
            tierPelanggan: pelanggan?.kodeTier,
            uuidOutlet: k.uuidOutlet,
            waktu: DateTime.now(),
          )
        : null;
    final selisihLimit = pelanggan == null || perkiraan == null ? null : SelisihLimitKredit(pelanggan, perkiraan.total);

    return Padding(
      padding: const EdgeInsets.all(TokenJarak.jarak24),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(
            'Pesanan atas nama ${pelanggan?.nama ?? '-'}. Harga akhir ditentukan server sesuai tier pelanggan; '
            'pesanan masuk sebagai draf untuk dikonfirmasi di back-office. Stok berkurang saat surat jalan.',
            style: teks.bodySmall,
          ),
          const SizedBox(height: TokenJarak.jarak12),
          Text('${keranjang.baris.length} barang', style: teks.bodyMedium),
          if (perkiraan != null)
            Row(
              children: [
                Expanded(child: Text('Perkiraan total', style: teks.bodyMedium)),
                TeksUang(perkiraan.total, gaya: teks.titleMedium),
              ],
            ),
          if (perkiraan != null && perkiraan.adaTanpaHarga)
            Padding(
              padding: const EdgeInsets.only(top: TokenJarak.jarak4),
              child: Text(
                'Ada barang tanpa harga di perangkat ini, jadi perkiraan belum lengkap. Server tetap menghitung harganya.',
                style: teks.bodySmall?.copyWith(color: warna.peringatan),
              ),
            ),
          if (selisihLimit != null && selisihLimit.BernilaiNegatif())
            Padding(
              padding: const EdgeInsets.only(top: TokenJarak.jarak8),
              child: Text(
                'Perkiraan melebihi sisa limit kredit ${pelanggan!.nama} sebesar ${Uang.Nol().Kurangi(selisihLimit).FormatRupiah()}. '
                'Pesanan tetap bisa disimpan. Konfirmasinya diputuskan di back-office.',
                style: teks.bodySmall?.copyWith(color: warna.peringatan),
              ),
            ),
          if (alasan != null)
            Padding(
              padding: const EdgeInsets.only(top: TokenJarak.jarak8),
              child: Text(alasan, style: TextStyle(color: warna.bahaya)),
            ),
          const SizedBox(height: TokenJarak.jarak12),
          TextField(
            controller: _catatan,
            maxLength: LayananSalesman.panjangCatatanPesanan,
            maxLines: 2,
            decoration: const InputDecoration(
              labelText: 'Catatan pesanan (opsional)',
              hintText: 'Misal: kirim Kamis pagi sebelum jam 10',
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
              onPressed: _sibuk || alasan != null ? null : () => unawaited(_Simpan(keranjang, katalog)),
              child: Text(_sibuk ? 'Menyimpan…' : 'Simpan pesanan grosir'),
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

/// Setelah pesanan grosir tersimpan: ringkasan singkat, lalu transaksi baru.
class TampilanPesananGrosirSelesai extends StatelessWidget {
  const TampilanPesananGrosirSelesai({super.key, required this.hasil, required this.saatTransaksiBaru});

  final PesananGrosirTersimpan hasil;
  final VoidCallback saatTransaksiBaru;

  @override
  Widget build(BuildContext context) {
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
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
              Expanded(child: Text('Pesanan grosir tersimpan', style: teks.titleMedium)),
            ],
          ),
          const SizedBox(height: TokenJarak.jarak4),
          Text('${hasil.namaPelanggan} | ${hasil.jumlahBaris} barang', style: teks.bodyMedium),
          const SizedBox(height: TokenJarak.jarak12),
          Row(
            children: [
              Expanded(child: Text('Perkiraan total', style: teks.bodyMedium)),
              TeksUang(hasil.perkiraanTotal, gaya: teks.titleMedium),
            ],
          ),
          const SizedBox(height: TokenJarak.jarak8),
          Text(
            'Pesanan dikirim ke server sebagai draf begitu perangkat online. Nomor pesanan dan harga akhirnya muncul di '
            'back-office (Grosir › Pesanan), tempat pesanan dikonfirmasi dan surat jalannya dibuat.',
            style: teks.bodySmall,
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
