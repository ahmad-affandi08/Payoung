import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:sistem_desain/SistemDesain.dart';

import '../../Aplikasi/Penyedia.dart';
import '../../Data/BasisData/BasisDataKasir.dart';
import '../../Data/PesananMeja.dart';
import '../../Domain/GalatKasir.dart';
import '../../Domain/Katalog/KatalogLokal.dart';
import '../../Domain/Meja/KonteksPesananMeja.dart';
import '../../Domain/Meja/LayananPesananMeja.dart';
import '../../Domain/Penjualan/Keranjang.dart';
import '../../Domain/Sesi/StafLokal.dart';
import 'BagianPesanSendiri.dart';
import 'DialogPesananMeja.dart';

/// Denah meja (F-07 mode meja fase 1, PRD §17.2.7): meja per area sebagai ubin (kosong / terisi dengan nomor, jumlah
/// tamu, item, dan lama duduk), serta pesanan tanpa meja (antre/bawa pulang). Ketuk meja kosong → buka pesanan; ketuk
/// meja terisi → lanjutkan pesanan di layar Jual. Menu ⋮ pada pesanan: pindah meja/ubah, minta bill, batalkan pesanan.
/// K-12 (§9.1): ubin menampilkan status "Minta bill" dan "Perlu dibersihkan" (ikon + teks); meja kotor diketuk →
/// tandai sudah bersih atau tetap buka pesanan. Data
/// pesanan outlet ditarik berkala oleh bingkai ruang kerja; perubahan perangkat ini langsung tampil (offline-first).
class LayarMeja extends ConsumerStatefulWidget {
  const LayarMeja({super.key, required this.kasir, required this.saatBukaPesanan});

  static const double lebarUbin = 168;
  static const double tinggiUbin = 128;

  final StafLokal kasir;

  /// Dipanggil setelah konteks pesanan dipasang di keranjang (bingkai pindah ke layar Jual).
  final VoidCallback saatBukaPesanan;

  @override
  ConsumerState<LayarMeja> createState() => _LayarMejaState();
}

class _LayarMejaState extends ConsumerState<LayarMeja> {
  String? _pesan;
  bool _sibuk = false;
  Timer? _pewaktuJam;

  /// POS-03: true = tampilan denah (bila ada meja yang sudah ditata di back-office), false = daftar per area.
  bool _tampilDenah = true;

  @override
  void initState() {
    super.initState();
    // Lama duduk diperbarui tiap menit.
    _pewaktuJam = Timer.periodic(const Duration(minutes: 1), (_) {
      if (mounted) {
        setState(() {});
      }
    });
  }

  @override
  void dispose() {
    _pewaktuJam?.cancel();
    super.dispose();
  }

  /// Keranjang retail atau item baru pesanan lain yang belum disimpan tidak boleh tertimpa.
  bool _CekKeranjangBebas(String? uuidPesanan) {
    final draf = ref.read(penyediaKeranjang);
    final konteks = draf.pesananMeja;
    if (konteks != null && konteks.uuid == uuidPesanan) {
      return true;
    }
    if (!draf.CekKosong) {
      setState(
        () => _pesan = konteks == null
            ? 'Keranjang di layar Jual masih berisi. Selesaikan, tahan, atau batalkan dulu.'
            : 'Pesanan ${konteks.AmbilJudul()} punya item baru yang belum dikirim. Kirim ke dapur atau hapus dulu.',
      );
      return false;
    }
    return true;
  }

  void _PasangPesanan(PesananMeja pesanan) {
    final draf = ref.read(penyediaKeranjang);
    // Pesanan yang sama sudah di keranjang: pertahankan item draf yang belum dikirim, hanya perbarui isi tersimpannya.
    ref
        .read(penyediaKeranjang.notifier)
        .Ganti(
          draf.pesananMeja?.uuid == pesanan.uuid
              ? draf.Salin(pesananMeja: () => KonteksPesananMeja.DariPesanan(pesanan))
              : Keranjang(pesananMeja: KonteksPesananMeja.DariPesanan(pesanan)),
        );
    widget.saatBukaPesanan();
  }

  Future<void> _BukaBaru(BarisMeja? meja) async {
    if (!_CekKeranjangBebas(null)) {
      return;
    }
    final isian = await showDialog<IsianPesanan>(
      context: context,
      builder: (_) => DialogIsianPesanan(
        judul: meja == null ? 'Pesanan tanpa meja' : 'Buka ${meja.Nama}',
        labelWajib: meja == null,
        awal: IsianPesanan(jumlahTamu: meja == null ? 1 : meja.Kapasitas.clamp(1, 4)),
      ),
    );
    if (isian == null || !mounted) {
      return;
    }
    setState(() => _sibuk = true);
    try {
      final k = await ref.read(penyediaKonteksPenjualan.future);
      final pesanan = await ref
          .read(penyediaLayananPesananMeja)
          .Buka(kasir: widget.kasir, k: k, meja: meja, label: isian.label, jumlahTamu: isian.jumlahTamu);
      if (mounted) {
        setState(() => _pesan = null);
        _PasangPesanan(pesanan);
      }
      unawaited(ref.read(penyediaSesi.notifier).Sinkronkan());
    } on GalatKasir catch (galat) {
      if (mounted) {
        setState(() => _pesan = galat.pesan);
      }
    } finally {
      if (mounted) {
        setState(() => _sibuk = false);
      }
    }
  }

  void _Lanjutkan(PesananMeja pesanan) {
    if (!_CekKeranjangBebas(pesanan.uuid)) {
      return;
    }
    setState(() => _pesan = null);
    _PasangPesanan(pesanan);
  }

  /// v3.53 (§9.1): tagihan sementara (pre-bill) untuk tamu yang minta bill; dihitung dengan mesin yang sama dengan
  /// layar Bayar dari item aktif pesanan. Tidak mengubah pesanan.
  Future<void> _CetakTagihanSementara(PesananMeja pesanan) async {
    final katalog = ref.read(penyediaKatalog).value ?? KatalogLokal.kosong;
    final konteks = ref.read(penyediaKonteksPenjualan).value;
    final keranjang = LayananPesananMeja.SusunKeranjangEfektif(
      Keranjang(pesananMeja: KonteksPesananMeja.DariPesanan(pesanan)),
      pesanan,
      katalog,
    );
    if (konteks == null || keranjang.CekKosong) {
      setState(() => _pesan = 'Pesanan ${pesanan.AmbilJudul()} belum berisi item untuk ditagih.');
      return;
    }
    try {
      await ref
          .read(penyediaLayananStruk)
          .CetakTagihanSementara(
            judul: pesanan.AmbilJudul(),
            nomor: pesanan.nomor,
            keranjang: keranjang,
            hitungan: ref.read(penyediaLayananPenjualan).Hitung(keranjang, konteks),
            waktu: ref.read(penyediaJam)(),
            namaKasir: widget.kasir.nama,
            jumlahTamu: pesanan.jumlahTamu,
          );
      // K-12: tagihan sementara dicetak = tamu sedang minta bill.
      if (pesanan.mintaBillPada == null) {
        await ref
            .read(penyediaLayananPesananMeja)
            .AturMintaBill(uuidPesanan: pesanan.uuid, kasir: widget.kasir, minta: true);
        unawaited(ref.read(penyediaSesi.notifier).Sinkronkan());
      }
      if (mounted) {
        setState(() => _pesan = 'Tagihan sementara ${pesanan.AmbilJudul()} dicetak.');
      }
    } on GalatKasir catch (galat) {
      if (mounted) {
        setState(() => _pesan = galat.pesan);
      }
    }
  }

  /// Cetak struk bagian 4c (v1.89): cetak ulang tiket dapur semua item yang sudah dikirim, bertanda CETAK ULANG.
  Future<void> _CetakUlangTiket(PesananMeja pesanan) async {
    final hasil = await ref
        .read(penyediaLayananTiketDapur)
        .Cetak(
          pesanan: pesanan,
          uuidBaris: pesanan.AmbilBarisAktif().where((b) => b.dikirimKeDapur).map((b) => b.uuid),
          katalog: ref.read(penyediaKatalog).value ?? KatalogLokal.kosong,
          waktu: ref.read(penyediaJam)(),
          namaKasir: widget.kasir.nama,
          cetakUlang: true,
        );
    if (!mounted) {
      return;
    }
    final gagal = hasil.where((h) => h.galat != null).toList();
    setState(
      () => _pesan = hasil.isEmpty
          ? 'Belum ada printer dapur untuk item pesanan ini. Atur di Pengaturan › Printer dapur.'
          : gagal.isEmpty
          ? 'Tiket dapur ${pesanan.AmbilJudul()} dicetak ulang.'
          : 'Tiket ${gagal.map((g) => g.stasiun.nama).join(', ')} gagal dicetak: ${gagal.first.galat}',
    );
  }

  Future<void> _BukaMenu(PesananMeja pesanan, List<BarisMeja> meja, Set<String> terisi, List<PesananMeja> semua) async {
    final pilihan = await showModalBottomSheet<String>(
      context: context,
      showDragHandle: true,
      isScrollControlled: true,
      builder: (konteks) => SafeArea(
        child: ListView(
          shrinkWrap: true,
          children: [
            ListTile(title: Text(pesanan.AmbilJudul()), subtitle: Text(pesanan.nomor)),
            ListTile(
              leading: const Icon(Icons.restaurant_menu),
              title: const Text('Buka pesanan'),
              onTap: () => Navigator.of(konteks).pop('Buka'),
            ),
            ListTile(
              leading: const Icon(Icons.swap_horiz),
              title: const Text('Pindah meja'),
              onTap: () => Navigator.of(konteks).pop('Pindah'),
            ),
            if (pesanan.AmbilBarisAktif().length > 1)
              ListTile(
                leading: const Icon(Icons.call_split),
                title: const Text('Pisah tagihan'),
                onTap: () => Navigator.of(konteks).pop('Pisah'),
              ),
            if (semua.length > 1)
              ListTile(
                leading: const Icon(Icons.call_merge),
                title: const Text('Gabung ke pesanan lain'),
                onTap: () => Navigator.of(konteks).pop('Gabung'),
              ),
            ListTile(
              leading: const Icon(Icons.receipt_long_outlined),
              title: Text(pesanan.mintaBillPada == null ? 'Tandai minta bill' : 'Hapus tanda minta bill'),
              onTap: () => Navigator.of(konteks).pop('MintaBill'),
            ),
            if (pesanan.AmbilBarisAktif().isNotEmpty)
              ListTile(
                leading: const Icon(Icons.receipt_outlined),
                title: const Text('Cetak tagihan sementara'),
                onTap: () => Navigator.of(konteks).pop('CetakTagihan'),
              ),
            if (pesanan.AmbilBarisAktif().any((b) => b.dikirimKeDapur))
              ListTile(
                leading: const Icon(Icons.soup_kitchen_outlined),
                title: const Text('Cetak ulang tiket dapur'),
                onTap: () => Navigator.of(konteks).pop('CetakTiket'),
              ),
            ListTile(
              leading: const Icon(Icons.edit_outlined),
              title: const Text('Ubah tamu & nama'),
              onTap: () => Navigator.of(konteks).pop('Ubah'),
            ),
            ListTile(
              leading: Icon(Icons.cancel_outlined, color: TokenWarna.AmbilDari(konteks).bahaya),
              title: const Text('Batalkan pesanan'),
              onTap: () => Navigator.of(konteks).pop('Batal'),
            ),
          ],
        ),
      ),
    );
    if (!mounted || pilihan == null) {
      return;
    }
    switch (pilihan) {
      case 'Buka':
        _Lanjutkan(pesanan);
      case 'CetakTiket':
        await _CetakUlangTiket(pesanan);
      case 'CetakTagihan':
        await _CetakTagihanSementara(pesanan);
      case 'MintaBill':
        final minta = pesanan.mintaBillPada == null;
        await _Jalankan(() async {
          await ref
              .read(penyediaLayananPesananMeja)
              .AturMintaBill(uuidPesanan: pesanan.uuid, kasir: widget.kasir, minta: minta);
          return minta ? '${pesanan.AmbilJudul()} minta bill.' : 'Tanda minta bill ${pesanan.AmbilJudul()} dihapus.';
        });
      case 'Pisah':
        final pilihan = await PilihItemPisah(context, pesanan);
        if (pilihan != null && mounted) {
          await _Jalankan(() async {
            final hasil = await ref
                .read(penyediaLayananPesananMeja)
                .Pisah(
                  uuidAsal: pesanan.uuid,
                  uuidBaris: pilihan.uuidBaris,
                  label: pilihan.label,
                  kasir: widget.kasir,
                  k: await ref.read(penyediaKonteksPenjualan.future),
                );
            _SegarkanKeranjang(hasil.asal);
            return 'Tagihan dipisah: ${hasil.baru.AmbilJudul()} (${hasil.baru.AmbilBarisAktif().length} item).';
          });
        }
      case 'Gabung':
        final tujuan = await PilihPesananTujuan(context, asal: pesanan, pesanan: semua);
        if (tujuan != null && mounted) {
          await _Jalankan(() async {
            final hasil = await ref
                .read(penyediaLayananPesananMeja)
                .Gabung(uuidAsal: pesanan.uuid, uuidTujuan: tujuan.uuid, kasir: widget.kasir);
            if (ref.read(penyediaKeranjang).pesananMeja?.uuid == pesanan.uuid) {
              ref.read(penyediaKeranjang.notifier).Kosongkan();
            }
            _SegarkanKeranjang(hasil);
            return '${pesanan.AmbilJudul()} digabung ke ${hasil.AmbilJudul()}.';
          });
        }
      case 'Pindah':
        final tujuan = await PilihMejaTujuan(context, meja: meja, terisi: terisi, uuidSekarang: pesanan.uuidMeja);
        if (!tujuan.dipilih || !mounted) {
          return;
        }
        var label = pesanan.label;
        if (tujuan.meja == null && label == null) {
          final isian = await showDialog<IsianPesanan>(
            context: context,
            builder: (_) => DialogIsianPesanan(
              judul: 'Nama pesanan tanpa meja',
              labelWajib: true,
              awal: IsianPesanan(jumlahTamu: pesanan.jumlahTamu),
              labelTombol: 'Simpan',
            ),
          );
          if (isian == null) {
            return;
          }
          label = isian.label;
        }
        await _Ubah(pesanan, tujuan.meja, label, pesanan.jumlahTamu);
      case 'Ubah':
        final isian = await showDialog<IsianPesanan>(
          context: context,
          builder: (_) => DialogIsianPesanan(
            judul: 'Ubah ${pesanan.AmbilJudul()}',
            labelWajib: pesanan.uuidMeja == null,
            awal: IsianPesanan(jumlahTamu: pesanan.jumlahTamu, label: pesanan.label),
            labelTombol: 'Simpan',
          ),
        );
        if (isian == null) {
          return;
        }
        final mejaSekarang = meja.where((m) => m.Uuid == pesanan.uuidMeja).firstOrNull;
        await _Ubah(pesanan, mejaSekarang, isian.label, isian.jumlahTamu);
      case 'Batal':
        final pesan = await BatalkanPesananMeja(context, ref, kasir: widget.kasir, pesanan: pesanan);
        if (pesan != null && mounted) {
          if (ref.read(penyediaKeranjang).pesananMeja?.uuid == pesanan.uuid) {
            ref.read(penyediaKeranjang.notifier).Kosongkan();
          }
          setState(() => _pesan = pesan);
          unawaited(ref.read(penyediaSesi.notifier).Sinkronkan());
        }
    }
  }

  /// K-12: meja kosong yang perlu dibersihkan: tandai sudah bersih, atau tetap buka pesanan (tamu langsung duduk).
  Future<void> _BukaMenuMejaKotor(BarisMeja meja) async {
    final pilihan = await showModalBottomSheet<String>(
      context: context,
      showDragHandle: true,
      isScrollControlled: true,
      builder: (konteks) => SafeArea(
        child: ListView(
          shrinkWrap: true,
          children: [
            ListTile(
              title: Text(meja.Nama),
              subtitle: const Text('Perlu dibersihkan setelah tamu sebelumnya membayar'),
            ),
            ListTile(
              leading: const Icon(Icons.cleaning_services_outlined),
              title: const Text('Tandai sudah bersih'),
              onTap: () => Navigator.of(konteks).pop('Bersih'),
            ),
            ListTile(
              leading: const Icon(Icons.restaurant_menu),
              title: const Text('Buka pesanan'),
              onTap: () => Navigator.of(konteks).pop('Buka'),
            ),
          ],
        ),
      ),
    );
    if (!mounted || pilihan == null) {
      return;
    }
    if (pilihan == 'Buka') {
      await _BukaBaru(meja);
      return;
    }
    await _Jalankan(() async {
      await ref.read(penyediaLayananPesananMeja).TandaiMejaBersih(uuidMeja: meja.Uuid, kasir: widget.kasir);
      return 'Meja ${meja.Nama} siap dipakai.';
    });
  }

  /// Pesanan yang sedang dibuka di keranjang ikut diperbarui setelah pisah/gabung.
  void _SegarkanKeranjang(PesananMeja pesanan) {
    final draf = ref.read(penyediaKeranjang);
    if (draf.pesananMeja?.uuid == pesanan.uuid) {
      ref
          .read(penyediaKeranjang.notifier)
          .Ganti(draf.Salin(pesananMeja: () => KonteksPesananMeja.DariPesanan(pesanan)));
    }
  }

  Future<void> _Jalankan(Future<String> Function() aksi) async {
    try {
      final pesan = await aksi();
      if (mounted) {
        setState(() => _pesan = pesan);
      }
      unawaited(ref.read(penyediaSesi.notifier).Sinkronkan());
    } on GalatKasir catch (galat) {
      if (mounted) {
        setState(() => _pesan = galat.pesan);
      }
    }
  }

  Future<void> _Ubah(PesananMeja pesanan, BarisMeja? meja, String? label, int tamu) async {
    try {
      final hasil = await ref
          .read(penyediaLayananPesananMeja)
          .Ubah(uuidPesanan: pesanan.uuid, kasir: widget.kasir, meja: meja, label: label, jumlahTamu: tamu);
      final draf = ref.read(penyediaKeranjang);
      if (draf.pesananMeja?.uuid == pesanan.uuid) {
        ref
            .read(penyediaKeranjang.notifier)
            .Ganti(draf.Salin(pesananMeja: () => KonteksPesananMeja.DariPesanan(hasil)));
      }
      if (mounted) {
        setState(() => _pesan = 'Pesanan ${hasil.AmbilJudul()} disimpan.');
      }
      unawaited(ref.read(penyediaSesi.notifier).Sinkronkan());
    } on GalatKasir catch (galat) {
      if (mounted) {
        setState(() => _pesan = galat.pesan);
      }
    }
  }

  static String _FormatLama(Duration lama) {
    if (lama.inMinutes < 1) {
      return 'baru';
    }
    if (lama.inHours < 1) {
      return '${lama.inMinutes} mnt';
    }
    return '${lama.inHours} j ${lama.inMinutes % 60} mnt';
  }

  Widget _BangunUbin(
    BuildContext context, {
    required String judul,
    required PesananMeja? pesanan,
    required VoidCallback saatDiketuk,
    VoidCallback? saatMenu,
    int? kapasitas,
    bool perluDibersihkan = false,
  }) {
    final warna = TokenWarna.AmbilDari(context);
    final teks = Theme.of(context).textTheme;
    final sekarang = ref.read(penyediaJam)();
    final terisi = pesanan != null;
    final item = pesanan?.AmbilBarisAktif().length ?? 0;
    final keterangan = pesanan == null
        ? 'Kosong${kapasitas == null ? '' : ' | $kapasitas kursi'}'
        : '${pesanan.jumlahTamu} tamu | $item item | ${_FormatLama(sekarang.toUtc().difference(pesanan.dibukaPada.toUtc()))}';
    // K-12: status layanan; warna selalu disertai ikon & teks.
    final status = pesanan?.mintaBillPada != null
        ? (ikon: Icons.receipt_long_outlined, teks: 'Minta bill')
        : pesanan == null && perluDibersihkan
        ? (ikon: Icons.cleaning_services_outlined, teks: 'Perlu dibersihkan')
        : null;
    return Semantics(
      button: true,
      label: '$judul, ${status == null ? '' : '${status.teks}, '}$keterangan',
      excludeSemantics: true,
      child: Material(
        color: terisi ? warna.brand.withValues(alpha: 0.08) : warna.permukaan,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(TokenJarak.radiusPanel),
          side: BorderSide(
            color: status != null
                ? warna.peringatan
                : terisi
                ? warna.brand
                : warna.garis,
            width: TokenJarak.tebalGaris,
          ),
        ),
        child: InkWell(
          borderRadius: BorderRadius.circular(TokenJarak.radiusPanel),
          onTap: _sibuk ? null : saatDiketuk,
          onLongPress: saatMenu,
          child: Padding(
            padding: const EdgeInsets.fromLTRB(
              TokenJarak.jarak12,
              TokenJarak.jarak8,
              TokenJarak.jarak4,
              TokenJarak.jarak8,
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Expanded(
                      child: Text(judul, maxLines: 1, overflow: TextOverflow.ellipsis, style: teks.titleMedium),
                    ),
                    if (saatMenu != null)
                      IconButton(
                        tooltip: 'Menu pesanan $judul',
                        onPressed: saatMenu,
                        icon: const Icon(Icons.more_vert),
                      ),
                  ],
                ),
                const Spacer(),
                if (status != null)
                  Row(
                    key: ValueKey('StatusMeja-$judul'),
                    children: [
                      Icon(status.ikon, size: TokenJarak.ikonKecil, color: warna.peringatan),
                      const SizedBox(width: TokenJarak.jarak4),
                      Expanded(
                        child: Text(
                          status.teks,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: teks.labelMedium?.copyWith(color: warna.teksUtama),
                        ),
                      ),
                    ],
                  ),
                Row(
                  children: [
                    Icon(
                      terisi ? Icons.restaurant : Icons.event_seat_outlined,
                      size: TokenJarak.ikonKecil,
                      color: terisi ? warna.brand : warna.teksSekunder,
                    ),
                    const SizedBox(width: TokenJarak.jarak4),
                    Expanded(
                      child: Text(
                        keterangan,
                        maxLines: status == null ? 2 : 1,
                        overflow: TextOverflow.ellipsis,
                        style: teks.bodySmall?.copyWith(color: warna.teksSekunder),
                      ),
                    ),
                  ],
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  /// Kisi ubin: kolom menyesuaikan lebar (±168dp per ubin, 2–8 kolom), tinggi ubin tetap.
  Widget _BangunKisi(List<Widget> ubin) => LayoutBuilder(
    builder: (context, batas) {
      final kolom = (batas.maxWidth / LayarMeja.lebarUbin).floor().clamp(2, 8);
      return GridView.count(
        shrinkWrap: true,
        physics: const NeverScrollableScrollPhysics(),
        crossAxisCount: kolom,
        mainAxisSpacing: TokenJarak.jarak8,
        crossAxisSpacing: TokenJarak.jarak8,
        childAspectRatio: (batas.maxWidth - TokenJarak.jarak8 * (kolom - 1)) / kolom / LayarMeja.tinggiUbin,
        children: ubin,
      );
    },
  );

  /// Sisi kanvas denah dalam dp; di bawah ini kanvas digulir ke samping supaya meja tidak saling menimpa di HP.
  static const double _lebarKanvasMinimum = 640;
  static const double _lebarKanvasMaksimum = 880;
  static const double _rasioKanvas = 0.625;

  /// POS-03: satu meja di denah. Ukuran tetap per bentuk; letak = titik tengah pada kanvas relatif 0–1000. Status
  /// (terisi, minta bill, perlu dibersihkan) selalu disertai ikon & teks, bukan warna saja.
  Widget _BangunMejaDenah(
    BuildContext context, {
    required BarisMeja meja,
    required PesananMeja? pesanan,
    required bool perluDibersihkan,
    required double lebarKanvas,
    required double tinggiKanvas,
    required VoidCallback saatDiketuk,
    VoidCallback? saatMenu,
  }) {
    final warna = TokenWarna.AmbilDari(context);
    final teks = Theme.of(context).textTheme;
    final sekarang = ref.read(penyediaJam)();
    final terisi = pesanan != null;
    final mintaBill = pesanan?.mintaBillPada != null;
    final lebar = meja.Bentuk == 'Panjang' ? 120.0 : 80.0;
    final tinggi = meja.Bentuk == 'Panjang' ? 68.0 : 80.0;
    final status = mintaBill
        ? (ikon: Icons.receipt_long_outlined, teks: 'Minta bill')
        : terisi
        ? (ikon: Icons.restaurant, teks: '${pesanan.jumlahTamu} tamu')
        : perluDibersihkan
        ? (ikon: Icons.cleaning_services_outlined, teks: 'Kotor')
        : (ikon: Icons.event_seat_outlined, teks: '${meja.Kapasitas} kursi');
    final keterangan = pesanan == null
        ? 'Kosong | ${meja.Kapasitas} kursi${perluDibersihkan ? ' | perlu dibersihkan' : ''}'
        : '${pesanan.jumlahTamu} tamu | ${pesanan.AmbilBarisAktif().length} item | ${_FormatLama(sekarang.toUtc().difference(pesanan.dibukaPada.toUtc()))}${mintaBill ? ' | minta bill' : ''}';
    final sisi = BorderSide(
      color: mintaBill || perluDibersihkan
          ? warna.peringatan
          : terisi
          ? warna.brand
          : warna.garis,
      width: TokenJarak.tebalGaris,
    );
    final bentuk = meja.Bentuk == 'Bundar'
        ? CircleBorder(side: sisi)
        : RoundedRectangleBorder(borderRadius: BorderRadius.circular(TokenJarak.radiusPanel), side: sisi);
    return Positioned(
      left: (meja.PosisiX! / 1000 * lebarKanvas - lebar / 2).clamp(0.0, lebarKanvas - lebar),
      top: (meja.PosisiY! / 1000 * tinggiKanvas - tinggi / 2).clamp(0.0, tinggiKanvas - tinggi),
      width: lebar,
      height: tinggi,
      child: Semantics(
        button: true,
        label: '${meja.Nama}, $keterangan',
        excludeSemantics: true,
        child: Material(
          key: ValueKey('MejaDenah-${meja.Nama}'),
          color: terisi ? warna.brand.withValues(alpha: 0.08) : warna.permukaan,
          shape: bentuk,
          child: InkWell(
            customBorder: bentuk,
            onTap: _sibuk ? null : saatDiketuk,
            onLongPress: saatMenu,
            child: Padding(
              padding: const EdgeInsets.all(TokenJarak.jarak4),
              child: Column(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  Text(
                    meja.Nama,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: teks.titleSmall?.copyWith(fontWeight: FontWeight.w700),
                  ),
                  Row(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      Icon(
                        status.ikon,
                        size: TokenJarak.ikonKecil,
                        color: mintaBill || perluDibersihkan ? warna.peringatan : warna.teksSekunder,
                      ),
                      const SizedBox(width: TokenJarak.jarak4),
                      Flexible(
                        child: Text(
                          status.teks,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: teks.labelSmall?.copyWith(color: warna.teksSekunder),
                        ),
                      ),
                    ],
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }

  /// POS-03: kanvas denah berasio tetap (letak relatif sama di semua layar); lebarnya dibatasi [_lebarKanvasMaksimum] dan
  /// digulir ke samping bila layar lebih sempit dari [_lebarKanvasMinimum].
  Widget _BangunDenah(BuildContext context, List<BarisMeja> ditata, Widget Function(BarisMeja, double, double) bangun) {
    final warna = TokenWarna.AmbilDari(context);
    return LayoutBuilder(
      builder: (context, batas) {
        final lebar = batas.maxWidth.clamp(_lebarKanvasMinimum, _lebarKanvasMaksimum);
        final tinggi = lebar * _rasioKanvas;
        final kanvas = Container(
          key: const ValueKey('KanvasDenahMeja'),
          width: lebar,
          height: tinggi,
          decoration: BoxDecoration(
            color: warna.latar,
            borderRadius: BorderRadius.circular(TokenJarak.radiusPanel),
            border: Border.all(color: warna.garis, width: TokenJarak.tebalGaris),
          ),
          child: Stack(children: [for (final m in ditata) bangun(m, lebar, tinggi)]),
        );
        return batas.maxWidth < _lebarKanvasMinimum
            ? SingleChildScrollView(scrollDirection: Axis.horizontal, child: kanvas)
            : Align(alignment: Alignment.topCenter, child: kanvas);
      },
    );
  }

  @override
  Widget build(BuildContext context) {
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    final area = ref.watch(penyediaAreaMeja).value ?? const <BarisAreaMeja>[];
    final mejaAsync = ref.watch(penyediaMeja);
    final meja = mejaAsync.value ?? const <BarisMeja>[];
    final pesananAsync = ref.watch(penyediaPesananTerbuka);
    final pesanan = pesananAsync.value ?? const <PesananMeja>[];
    final perMeja = {
      for (final p in pesanan)
        if (p.uuidMeja != null) p.uuidMeja!: p,
    };
    final tanpaMeja = pesanan.where((p) => p.uuidMeja == null || !meja.any((m) => m.Uuid == p.uuidMeja)).toList();
    final terisi = perMeja.keys.toSet();
    final kotor = ref.watch(penyediaMejaPerluDibersihkan).value ?? const <String, DateTime>{};
    final jumlahKotor = meja.where((m) => kotor.containsKey(m.Uuid) && !terisi.contains(m.Uuid)).length;
    final jumlahMintaBill = pesanan.where((p) => p.mintaBillPada != null).length;
    final sempit = MediaQuery.sizeOf(context).width < 600;
    final tepi = sempit ? TokenJarak.jarak16 : TokenJarak.jarak24;

    final kelompok = <({String judul, List<BarisMeja> meja})>[
      for (final a in area) (judul: a.Nama, meja: meja.where((m) => m.UuidArea == a.Uuid).toList()),
      (
        judul: area.isEmpty ? 'Meja' : 'Tanpa area',
        meja: meja.where((m) => !area.any((a) => a.Uuid == m.UuidArea)).toList(),
      ),
    ].where((k) => k.meja.isNotEmpty).toList();

    VoidCallback SaatKetukMeja(BarisMeja m) {
      final p = perMeja[m.Uuid];
      final perluDibersihkan = p == null && kotor.containsKey(m.Uuid);
      return () => p != null
          ? _Lanjutkan(p)
          : perluDibersihkan
          ? unawaited(_BukaMenuMejaKotor(m))
          : unawaited(_BukaBaru(m));
    }

    VoidCallback? SaatMenuMeja(BarisMeja m) {
      final p = perMeja[m.Uuid];
      final perluDibersihkan = p == null && kotor.containsKey(m.Uuid);
      return p != null
          ? () => unawaited(_BukaMenu(p, meja, terisi, pesanan))
          : perluDibersihkan
          ? () => unawaited(_BukaMenuMejaKotor(m))
          : null;
    }

    Widget BangunUbinMeja(BarisMeja m) {
      final p = perMeja[m.Uuid];
      return _BangunUbin(
        context,
        judul: m.Nama,
        pesanan: p,
        kapasitas: m.Kapasitas,
        perluDibersihkan: p == null && kotor.containsKey(m.Uuid),
        saatDiketuk: SaatKetukMeja(m),
        saatMenu: SaatMenuMeja(m),
      );
    }

    // POS-03: meja yang sudah ditata di denah back-office. Tanpa satu pun, tampilan daftar per area seperti biasa.
    final ditata = meja.where((m) => m.PosisiX != null && m.PosisiY != null).toList();
    final denahAktif = ditata.isNotEmpty && _tampilDenah;
    final belumDitata = denahAktif ? meja.where((m) => m.PosisiX == null || m.PosisiY == null).toList() : <BarisMeja>[];

    return ListView(
      padding: EdgeInsets.all(tepi),
      children: [
        Row(
          children: [
            Expanded(
              child: Semantics(header: true, child: Text('Meja', style: teks.headlineSmall)),
            ),
            SizedBox(
              height: TokenJarak.targetSentuh,
              child: OutlinedButton.icon(
                onPressed: _sibuk ? null : () => unawaited(_BukaBaru(null)),
                icon: const Icon(Icons.add),
                label: Text(sempit ? 'Tanpa meja' : 'Pesanan tanpa meja'),
              ),
            ),
          ],
        ),
        const SizedBox(height: TokenJarak.jarak8),
        Text(
          [
            '${pesanan.length} pesanan terbuka',
            '${meja.length - terisi.length} meja kosong',
            if (jumlahMintaBill > 0) '$jumlahMintaBill minta bill',
            if (jumlahKotor > 0) '$jumlahKotor perlu dibersihkan',
          ].join(' | '),
          style: teks.bodyMedium?.copyWith(color: warna.teksSekunder),
        ),
        if (_pesan != null) ...[
          const SizedBox(height: TokenJarak.jarak8),
          Semantics(
            liveRegion: true,
            child: Row(
              children: [
                Icon(Icons.info_outline, size: TokenJarak.ikonSedang, color: warna.info),
                const SizedBox(width: TokenJarak.jarak8),
                Expanded(child: Text(_pesan!, style: teks.bodyMedium)),
                IconButton(
                  tooltip: 'Tutup pesan',
                  onPressed: () => setState(() => _pesan = null),
                  icon: const Icon(Icons.close),
                ),
              ],
            ),
          ),
        ],
        if (_sibuk) const LinearProgressIndicator(),
        if (ditata.isNotEmpty) ...[
          const SizedBox(height: TokenJarak.jarak8),
          Align(
            alignment: Alignment.centerLeft,
            child: SegmentedButton<bool>(
              segments: const [
                ButtonSegment(value: true, icon: Icon(Icons.map_outlined), label: Text('Denah')),
                ButtonSegment(value: false, icon: Icon(Icons.view_list_outlined), label: Text('Daftar')),
              ],
              selected: {_tampilDenah},
              onSelectionChanged: (pilih) => setState(() => _tampilDenah = pilih.first),
            ),
          ),
        ],
        BagianPesanSendiri(kasir: widget.kasir, saatPesan: (pesan) => setState(() => _pesan = pesan)),
        if (mejaAsync.isLoading && meja.isEmpty)
          const Padding(
            padding: EdgeInsets.all(TokenJarak.jarak24),
            child: Center(child: CircularProgressIndicator()),
          )
        else if (meja.isEmpty)
          const KeadaanKosong(
            ikon: Icons.table_restaurant_outlined,
            ilustrasi: IlustrasiKosong.Meja,
            judul: 'Belum ada meja di outlet ini',
            keterangan:
                'Atur meja di back-office menu Meja, lalu sambungkan perangkat ke internet. '
                'Pesanan tanpa meja tetap bisa dibuat.',
          ),
        if (denahAktif) ...[
          const SizedBox(height: TokenJarak.jarak16),
          _BangunDenah(
            context,
            ditata,
            (m, lebar, tinggi) => _BangunMejaDenah(
              context,
              meja: m,
              pesanan: perMeja[m.Uuid],
              perluDibersihkan: perMeja[m.Uuid] == null && kotor.containsKey(m.Uuid),
              lebarKanvas: lebar,
              tinggiKanvas: tinggi,
              saatDiketuk: SaatKetukMeja(m),
              saatMenu: SaatMenuMeja(m),
            ),
          ),
          if (belumDitata.isNotEmpty) ...[
            const SizedBox(height: TokenJarak.jarak16),
            Text('Belum ditata di denah', style: teks.titleMedium),
            const SizedBox(height: TokenJarak.jarak8),
            _BangunKisi([for (final m in belumDitata) BangunUbinMeja(m)]),
          ],
        ] else
          for (final k in kelompok) ...[
            const SizedBox(height: TokenJarak.jarak16),
            Text(k.judul, style: teks.titleMedium),
            const SizedBox(height: TokenJarak.jarak8),
            _BangunKisi([for (final m in k.meja) BangunUbinMeja(m)]),
          ],
        if (tanpaMeja.isNotEmpty) ...[
          const SizedBox(height: TokenJarak.jarak16),
          Text('Pesanan tanpa meja', style: teks.titleMedium),
          const SizedBox(height: TokenJarak.jarak8),
          _BangunKisi([
            for (final p in tanpaMeja)
              _BangunUbin(
                context,
                judul: p.AmbilJudul(),
                pesanan: p,
                saatDiketuk: () => _Lanjutkan(p),
                saatMenu: () => unawaited(_BukaMenu(p, meja, terisi, pesanan)),
              ),
          ]),
        ],
      ],
    );
  }
}
