import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:inti/Inti.dart';
import 'package:sistem_desain/SistemDesain.dart';

import '../../Aplikasi/Penyedia.dart';
import '../../Aplikasi/PenyediaSalesman.dart';
import '../../Domain/GalatKasir.dart';
import '../../Domain/Katalog/KatalogLokal.dart';
import '../../Domain/Salesman/LayananSalesman.dart';
import '../../Domain/Sesi/StafLokal.dart';
import '../Komponen/FormatWaktu.dart';

/// Satu baris isian pesanan di layar (jumlah masih teks sampai dikirim).
class _IsianBaris {
  _IsianBaris({required this.produk, required this.satuan, String jumlah = '1'})
    : jumlah = TextEditingController(text: jumlah);

  final ProdukJual produk;
  SatuanJual satuan;
  final TextEditingController jumlah;
  String? galat;
}

/// Ambil pesanan grosir untuk satu pelanggan (Modul Salesman bagian 2) di panel tugas ruang kerja: cari produk (nama,
/// SKU, atau pindai barcode lewat pemindai yang mengetik + Enter), jumlah desimal per satuan, petunjuk stok kantor,
/// **perkiraan** total dari harga katalog lokal, catatan opsional, lalu "Kirim pesanan" (tersimpan offline + outbox).
/// Hanya produk berstok biasa (jenis Stok, tanpa batch/nomor seri) yang bisa dipilih — cakupan grosir server.
class LembarPesananSalesman extends ConsumerStatefulWidget {
  const LembarPesananSalesman({super.key, required this.staf, required this.uuidPelanggan, required this.saatTerkirim});

  static const String judul = 'Ambil pesanan';
  static const String keteranganPerkiraan = 'Perkiraan. Harga final dari kantor';

  final StafLokal staf;
  final String uuidPelanggan;
  final VoidCallback saatTerkirim;

  @override
  ConsumerState<LembarPesananSalesman> createState() => _LembarPesananSalesmanState();
}

class _LembarPesananSalesmanState extends ConsumerState<LembarPesananSalesman> {
  final _cari = TextEditingController();
  final _catatan = TextEditingController();
  final List<_IsianBaris> _baris = [];
  bool _sibuk = false;
  String? _galat;

  @override
  void initState() {
    super.initState();
    // Stok kantor disegarkan diam-diam saat online; offline memakai snapshot terakhir.
    unawaited(Future<void>.microtask(_SegarkanStok));
  }

  Future<void> _SegarkanStok() async {
    try {
      await ref.read(penyediaLayananSalesman).PerbaruiStok(widget.staf);
    } on GalatKasir {
      // Offline/tanpa izin: snapshot lama tetap dipakai sebagai petunjuk.
    }
  }

  @override
  void dispose() {
    _cari.dispose();
    _catatan.dispose();
    for (final b in _baris) {
      b.jumlah.dispose();
    }
    super.dispose();
  }

  /// Tambah [produk] (satuan [satuan] atau bawaan). Produk + satuan yang sudah ada: jumlahnya ditambah 1.
  void _Tambah(ProdukJual produk, {SatuanJual? satuan}) {
    final s = LayananSalesman.AmbilSatuanBawaan(produk, dariBarcode: satuan);
    setState(() {
      _galat = null;
      final ada = _baris.where((b) => b.produk.uuid == produk.uuid && b.satuan.uuid == s.uuid).firstOrNull;
      if (ada != null) {
        final rapi = ada.jumlah.text.trim().replaceAll(',', '.');
        final lama = RegExp(r'^\d+(\.\d+)?$').hasMatch(rapi) ? Decimal.parse(rapi) : Decimal.zero;
        ada.jumlah.text = LayananSalesman.FormatJumlah(Kuantitas.DariDesimal(lama + Decimal.one));
      } else if (_baris.length >= LayananSalesman.barisMaksimal) {
        _galat = 'Satu pesanan maksimal ${LayananSalesman.barisMaksimal} baris.';
      } else {
        _baris.add(_IsianBaris(produk: produk, satuan: s));
      }
      _cari.clear();
    });
  }

  /// Enter di kolom cari (pemindai barcode mengetik lalu Enter): kode persis → langsung ditambah.
  void _SaatKirimCari(String kata, KatalogLokal katalog) {
    final kode = katalog.CariKode(kata);
    if (kode != null && LayananSalesman.CekBolehDipesan(kode.produk)) {
      _Tambah(kode.produk, satuan: kode.satuan);
      return;
    }
    final hasil = LayananSalesman.CariProduk(katalog, kata);
    if (hasil.length == 1) {
      _Tambah(hasil.single);
    } else if (kode != null) {
      setState(() => _galat = '"${kode.produk.nama}" tidak bisa dipesan lewat salesman (bukan produk berstok biasa).');
    }
  }

  void _Hapus(_IsianBaris b) => setState(() {
    _baris.remove(b);
    b.jumlah.dispose();
  });

  /// Baris yang jumlahnya terbaca (untuk perkiraan); jumlah tidak valid dilewati tanpa galat.
  List<BarisPesananSalesman> _AmbilBarisTerbaca() => [
    for (final b in _baris)
      if (_BacaDiam(b) case final jumlah?) BarisPesananSalesman(produk: b.produk, satuan: b.satuan, jumlah: jumlah),
  ];

  static Kuantitas? _BacaDiam(_IsianBaris b) {
    try {
      return LayananSalesman.BacaJumlah(b.jumlah.text, b.satuan);
    } on GalatKasir {
      return null;
    }
  }

  Future<void> _Kirim(PelangganSalesman pelanggan, KatalogLokal katalog, String? uuidOutlet) async {
    final baris = <BarisPesananSalesman>[];
    var adaGalat = false;
    for (final b in _baris) {
      try {
        baris.add(
          BarisPesananSalesman(
            produk: b.produk,
            satuan: b.satuan,
            jumlah: LayananSalesman.BacaJumlah(b.jumlah.text, b.satuan),
          ),
        );
        b.galat = null;
      } on GalatKasir catch (galat) {
        b.galat = galat.pesan;
        adaGalat = true;
      }
    }
    if (adaGalat) {
      setState(() => _galat = 'Periksa jumlah yang ditandai.');
      return;
    }
    setState(() {
      _sibuk = true;
      _galat = null;
    });
    final perkiraan = LayananSalesman.HitungPerkiraan(
      baris,
      katalog,
      tierPelanggan: pelanggan.kodeTier,
      uuidOutlet: uuidOutlet,
      waktu: ref.read(penyediaJam)(),
    );
    final sesi = ref.read(penyediaSesi.notifier);
    try {
      await ref
          .read(penyediaLayananSalesman)
          .KirimPesanan(
            pelanggan: pelanggan,
            baris: baris,
            staf: widget.staf,
            perkiraan: perkiraan,
            catatan: _catatan.text,
          );
      if (mounted) {
        ScaffoldMessenger.maybeOf(context)?.showSnackBar(
          SnackBar(
            content: Text(
              'Pesanan ${pelanggan.nama} tersimpan dan dikirim otomatis saat online. Harga final dari kantor.',
            ),
          ),
        );
        widget.saatTerkirim();
      }
      await sesi.Sinkronkan();
    } on GalatKasir catch (galat) {
      if (mounted) {
        setState(() {
          _galat = galat.pesan;
          _sibuk = false;
        });
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    final pelanggan = ref
        .watch(penyediaPelangganSalesman)
        .value
        ?.where((p) => p.uuid == widget.uuidPelanggan)
        .firstOrNull;
    final katalog = ref.watch(penyediaKatalog).value ?? KatalogLokal.kosong;
    final stok = ref.watch(penyediaStokSalesman).value ?? StokKantor.kosong;
    final berjalan = ref.watch(penyediaKunjunganBerjalan(widget.staf.uuid)).value;
    final uuidOutlet = ref.watch(penyediaKonteksPenjualan).value?.uuidOutlet;

    if (pelanggan == null) {
      return Padding(
        padding: const EdgeInsets.all(TokenJarak.jarak24),
        child: Text('Pelanggan tidak ditemukan di perangkat ini. Perbarui data pelanggan.', style: teks.bodyMedium),
      );
    }

    final terbaca = _AmbilBarisTerbaca();
    final perkiraan = LayananSalesman.HitungPerkiraan(
      terbaca,
      katalog,
      tierPelanggan: pelanggan.kodeTier,
      uuidOutlet: uuidOutlet,
      waktu: ref.read(penyediaJam)(),
    );
    final hargaPer = {
      for (final (i, b) in terbaca.indexed) '${b.produk.uuid}:${b.satuan.uuid}': perkiraan.hargaSatuan[i],
    };
    final adaProdukBoleh = katalog.produk.any(LayananSalesman.CekBolehDipesan);
    final hasilCari = _cari.text.trim().isEmpty
        ? const <ProdukJual>[]
        : LayananSalesman.CariProduk(katalog, _cari.text);

    return Padding(
      padding: const EdgeInsets.all(TokenJarak.jarak24),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(pelanggan.nama, style: teks.titleMedium),
          Text(
            [
              if (pelanggan.namaTier != null) 'Tier ${pelanggan.namaTier}',
              if (berjalan?.UuidPelanggan == pelanggan.uuid) 'Bagian dari kunjungan yang sedang berjalan',
              'Pesanan masuk sebagai draf dan dikonfirmasi kantor',
            ].join(' | '),
            style: teks.bodySmall?.copyWith(color: warna.teksSekunder),
          ),
          const SizedBox(height: TokenJarak.jarak16),
          if (!adaProdukBoleh)
            Text(
              'Belum ada produk yang bisa dipesan di katalog perangkat ini. Pesanan salesman hanya untuk produk berstok '
              'biasa (tanpa batch atau nomor seri). Perbarui katalog saat online.',
              style: teks.bodyMedium,
            )
          else ...[
            TextField(
              key: const ValueKey('CariProdukPesanan'),
              controller: _cari,
              onChanged: (_) => setState(() {}),
              onSubmitted: (kata) => _SaatKirimCari(kata, katalog),
              decoration: const InputDecoration(
                labelText: 'Tambah produk',
                hintText: 'Nama, SKU, atau pindai barcode',
                prefixIcon: Icon(Icons.search),
                border: OutlineInputBorder(),
              ),
            ),
            if (_cari.text.trim().isNotEmpty && hasilCari.isEmpty)
              Padding(
                padding: const EdgeInsets.symmetric(vertical: TokenJarak.jarak8),
                child: Text(
                  'Produk tidak ditemukan, atau bukan produk berstok biasa yang bisa dipesan lewat salesman.',
                  style: teks.bodyMedium,
                ),
              ),
            for (final p in hasilCari)
              ListTile(
                minTileHeight: TokenJarak.targetSentuh,
                contentPadding: EdgeInsets.zero,
                title: Text(p.nama, maxLines: 2, overflow: TextOverflow.ellipsis),
                subtitle: Text(
                  [if (p.sku != null) p.sku!, _TeksStok(stok, p) ?? 'Stok kantor belum diketahui'].join(' | '),
                  style: teks.bodySmall,
                ),
                trailing: const Icon(Icons.add),
                onTap: () => _Tambah(p),
              ),
          ],
          const SizedBox(height: TokenJarak.jarak16),
          if (_baris.isEmpty && adaProdukBoleh)
            Text('Belum ada produk di pesanan ini.', style: teks.bodyMedium?.copyWith(color: warna.teksSekunder)),
          for (final b in _baris)
            _BarisPesanan(
              key: ObjectKey(b),
              isian: b,
              teksStok: _TeksStok(stok, b.produk),
              harga: hargaPer['${b.produk.uuid}:${b.satuan.uuid}'],
              sibuk: _sibuk,
              saatUbah: () => setState(() => b.galat = null),
              saatGantiSatuan: (s) => setState(() => b.satuan = s),
              saatHapus: () => _Hapus(b),
            ),
          if (_baris.isNotEmpty) ...[
            const SizedBox(height: TokenJarak.jarak8),
            Divider(height: TokenJarak.tebalGaris, color: warna.garis),
            const SizedBox(height: TokenJarak.jarak8),
            Row(
              children: [
                Expanded(child: Text('Perkiraan total', style: teks.titleSmall)),
                TeksUang(perkiraan.total, gaya: teks.titleMedium),
              ],
            ),
            Text(
              perkiraan.adaTanpaHarga
                  ? '${LembarPesananSalesman.keteranganPerkiraan}. Ada produk yang harganya belum ada di perangkat.'
                  : LembarPesananSalesman.keteranganPerkiraan,
              style: teks.bodySmall?.copyWith(color: warna.teksSekunder),
            ),
            Text(
              stok.diambilPada == null
                  ? 'Stok kantor belum pernah diunduh. Stok tidak menahan pesanan.'
                  : 'Stok kantor per ${FormatWaktu.FormatTanggalJam(stok.diambilPada!)}. Hanya petunjuk.',
              style: teks.bodySmall?.copyWith(color: warna.teksSekunder),
            ),
          ],
          const SizedBox(height: TokenJarak.jarak16),
          TextField(
            key: const ValueKey('CatatanPesanan'),
            controller: _catatan,
            maxLength: LayananSalesman.panjangCatatanPesanan,
            minLines: 1,
            maxLines: 3,
            decoration: const InputDecoration(
              labelText: 'Catatan (opsional)',
              hintText: 'Misal: kirim Kamis pagi',
              border: OutlineInputBorder(),
            ),
          ),
          if (_galat != null) ...[
            const SizedBox(height: TokenJarak.jarak8),
            Text(_galat!, style: TextStyle(color: warna.bahaya)),
          ],
          const SizedBox(height: TokenJarak.jarak8),
          SizedBox(
            height: 56,
            child: FilledButton.icon(
              onPressed: _sibuk || _baris.isEmpty ? null : () => _Kirim(pelanggan, katalog, uuidOutlet),
              icon: const Icon(Icons.send_outlined),
              label: Text(_sibuk ? 'Menyimpan…' : 'Kirim pesanan'),
            ),
          ),
        ],
      ),
    );
  }

  /// "Stok kantor ± 480 pcs" dalam satuan dasar, atau null bila belum diketahui.
  static String? _TeksStok(StokKantor stok, ProdukJual p) {
    final jumlah = stok.Ambil(p.uuid);
    if (jumlah == null) {
      return null;
    }
    final satuan = p.satuanDasar?.nama ?? '';
    return 'Stok kantor ± ${LayananSalesman.FormatJumlah(jumlah)}${satuan.isEmpty ? '' : ' $satuan'}';
  }
}

class _BarisPesanan extends StatelessWidget {
  const _BarisPesanan({
    super.key,
    required this.isian,
    required this.teksStok,
    required this.harga,
    required this.sibuk,
    required this.saatUbah,
    required this.saatGantiSatuan,
    required this.saatHapus,
  });

  final _IsianBaris isian;
  final String? teksStok;
  final Uang? harga;
  final bool sibuk;
  final VoidCallback saatUbah;
  final ValueChanged<SatuanJual> saatGantiSatuan;
  final VoidCallback saatHapus;

  @override
  Widget build(BuildContext context) {
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    final b = isian;
    return Padding(
      padding: const EdgeInsets.only(bottom: TokenJarak.jarak12),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(b.produk.nama, maxLines: 2, overflow: TextOverflow.ellipsis, style: teks.labelLarge),
              ),
              IconButton(
                tooltip: 'Hapus ${b.produk.nama}',
                onPressed: sibuk ? null : saatHapus,
                icon: const Icon(Icons.delete_outline),
              ),
            ],
          ),
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Expanded(
                child: TextField(
                  key: ValueKey('Jumlah-${b.produk.uuid}-${b.satuan.uuid}'),
                  controller: b.jumlah,
                  enabled: !sibuk,
                  onChanged: (_) => saatUbah(),
                  keyboardType: const TextInputType.numberWithOptions(decimal: true),
                  inputFormatters: [FilteringTextInputFormatter.allow(RegExp(r'[0-9.,]'))],
                  decoration: InputDecoration(
                    labelText: 'Jumlah',
                    errorText: b.galat,
                    errorMaxLines: 2,
                    border: const OutlineInputBorder(),
                  ),
                ),
              ),
              const SizedBox(width: TokenJarak.jarak12),
              Expanded(
                child: b.produk.satuan.length == 1
                    ? InputDecorator(
                        decoration: const InputDecoration(labelText: 'Satuan', border: OutlineInputBorder()),
                        child: Text(b.satuan.nama, maxLines: 1, overflow: TextOverflow.ellipsis),
                      )
                    : DropdownButtonFormField<String>(
                        key: ValueKey('Satuan-${b.produk.uuid}'),
                        isExpanded: true,
                        initialValue: b.satuan.uuid,
                        decoration: const InputDecoration(labelText: 'Satuan', border: OutlineInputBorder()),
                        items: [
                          for (final s in b.produk.satuan)
                            DropdownMenuItem(
                              value: s.uuid,
                              child: Text(s.nama, maxLines: 1, overflow: TextOverflow.ellipsis),
                            ),
                        ],
                        onChanged: sibuk
                            ? null
                            : (uuid) {
                                final s = b.produk.satuan.where((s) => s.uuid == uuid).firstOrNull;
                                if (s != null) {
                                  saatGantiSatuan(s);
                                }
                              },
                      ),
              ),
            ],
          ),
          const SizedBox(height: TokenJarak.jarak4),
          Text(
            [
              harga == null ? 'Harga belum ada di perangkat' : '≈ ${harga!.FormatRupiah()} / ${b.satuan.nama}',
              teksStok ?? 'Stok kantor belum diketahui',
            ].join(' | '),
            style: teks.bodySmall?.copyWith(color: warna.teksSekunder),
          ),
        ],
      ),
    );
  }
}
