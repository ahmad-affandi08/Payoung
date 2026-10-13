import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:sistem_desain/SistemDesain.dart';

import '../../Aplikasi/Penyedia.dart';
import '../../Data/BasisData/BasisDataKasir.dart';
import '../../Data/PesananMeja.dart';
import '../../Domain/GalatKasir.dart';
import '../../Domain/Meja/LayananPesananMeja.dart';
import '../../Domain/Sesi/StafLokal.dart';
import '../LembarMutasiKas.dart';
import '../Komponen/PilihanAlasan.dart';

/// Isian header pesanan: jumlah tamu & label (nama pemesan/nomor antre).
class IsianPesanan {
  const IsianPesanan({required this.jumlahTamu, this.label});

  final int jumlahTamu;
  final String? label;
}

/// Dialog buka pesanan (atau ubah header): jumlah tamu (tombol − / +) dan label. Label wajib bila tanpa meja.
class DialogIsianPesanan extends StatefulWidget {
  const DialogIsianPesanan({
    super.key,
    required this.judul,
    required this.labelWajib,
    this.awal = const IsianPesanan(jumlahTamu: 2),
    this.labelTombol = 'Buka pesanan',
  });

  final String judul;
  final bool labelWajib;
  final IsianPesanan awal;
  final String labelTombol;

  @override
  State<DialogIsianPesanan> createState() => _DialogIsianPesananState();
}

class _DialogIsianPesananState extends State<DialogIsianPesanan> {
  late int _tamu = widget.awal.jumlahTamu;
  late final _label = TextEditingController(text: widget.awal.label ?? '');
  String? _galat;

  @override
  void dispose() {
    _label.dispose();
    super.dispose();
  }

  void _Simpan() {
    final label = _label.text.trim();
    if (widget.labelWajib && label.isEmpty) {
      setState(() => _galat = 'Isi nama pemesan atau nomor antre.');
      return;
    }
    Navigator.of(context).pop(IsianPesanan(jumlahTamu: _tamu, label: label.isEmpty ? null : label));
  }

  @override
  Widget build(BuildContext context) {
    final teks = Theme.of(context).textTheme;
    return AlertDialog(
      title: Text(widget.judul),
      content: SingleChildScrollView(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text('Jumlah tamu', style: teks.labelLarge),
            const SizedBox(height: TokenJarak.jarak8),
            Row(
              children: [
                IconButton.outlined(
                  tooltip: 'Kurangi tamu',
                  onPressed: _tamu > 1 ? () => setState(() => _tamu--) : null,
                  icon: const Icon(Icons.remove),
                ),
                Expanded(
                  child: Text('$_tamu orang', textAlign: TextAlign.center, style: teks.titleMedium),
                ),
                IconButton.outlined(
                  tooltip: 'Tambah tamu',
                  onPressed: _tamu < LayananPesananMeja.tamuMaksimal ? () => setState(() => _tamu++) : null,
                  icon: const Icon(Icons.add),
                ),
              ],
            ),
            const SizedBox(height: TokenJarak.jarak16),
            TextField(
              controller: _label,
              maxLength: LayananPesananMeja.panjangLabelMaksimal,
              textCapitalization: TextCapitalization.words,
              decoration: InputDecoration(
                labelText: widget.labelWajib ? 'Nama pemesan / nomor antre' : 'Nama pemesan (opsional)',
                border: const OutlineInputBorder(),
                errorText: _galat,
              ),
              onSubmitted: (_) => _Simpan(),
            ),
          ],
        ),
      ),
      actions: [
        TextButton(onPressed: () => Navigator.of(context).pop(), child: const Text('Batal')),
        FilledButton(onPressed: _Simpan, child: Text(widget.labelTombol)),
      ],
    );
  }
}

/// Minta alasan (wajib bila [wajib]). Hasil null = dibatalkan.
Future<String?> TanyaAlasan(
  BuildContext context, {
  required String judul,
  required String pesan,
  required String labelTombol,
  required bool wajib,
  List<String> pilihan = PilihanAlasan.batalPesanan,
}) => showDialog<String>(
  context: context,
  builder: (_) => _DialogAlasan(judul: judul, pesan: pesan, labelTombol: labelTombol, wajib: wajib, pilihan: pilihan),
);

class _DialogAlasan extends StatefulWidget {
  const _DialogAlasan({
    required this.judul,
    required this.pesan,
    required this.labelTombol,
    required this.wajib,
    required this.pilihan,
  });

  final String judul;
  final String pesan;
  final String labelTombol;
  final bool wajib;
  final List<String> pilihan;

  @override
  State<_DialogAlasan> createState() => _DialogAlasanState();
}

class _DialogAlasanState extends State<_DialogAlasan> {
  final _alasan = TextEditingController();
  String? _galat;

  @override
  void dispose() {
    _alasan.dispose();
    super.dispose();
  }

  void _Simpan() {
    final alasan = _alasan.text.trim();
    if (widget.wajib && alasan.runes.length < LayananPesananMeja.panjangAlasanMinimal) {
      setState(() => _galat = 'Tulis alasan minimal ${LayananPesananMeja.panjangAlasanMinimal} huruf.');
      return;
    }
    Navigator.of(context).pop(alasan);
  }

  @override
  Widget build(BuildContext context) {
    final warna = TokenWarna.AmbilDari(context);
    return AlertDialog(
      title: Text(widget.judul),
      content: SingleChildScrollView(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(widget.pesan),
            const SizedBox(height: TokenJarak.jarak16),
            if (widget.pilihan.isNotEmpty)
              PilihanAlasan(
                pengendali: _alasan,
                pilihan: widget.pilihan,
                saatDipilih: () => setState(() => _galat = null),
              ),
            TextField(
              controller: _alasan,
              autofocus: true,
              maxLength: 255,
              inputFormatters: [LengthLimitingTextInputFormatter(255)],
              decoration: InputDecoration(
                labelText: widget.wajib ? 'Alasan' : 'Alasan (opsional)',
                border: const OutlineInputBorder(),
                errorText: _galat,
              ),
              onSubmitted: (_) => _Simpan(),
            ),
          ],
        ),
      ),
      actions: [
        TextButton(onPressed: () => Navigator.of(context).pop(), child: const Text('Kembali')),
        FilledButton(
          style: FilledButton.styleFrom(backgroundColor: warna.bahaya),
          onPressed: _Simpan,
          child: Text(widget.labelTombol),
        ),
      ],
    );
  }
}

/// Minta PIN penyetuju ber-izin `penjualan.void` bila [kasir] sendiri tidak ber-izin (BR-07.5). Hasil: `(lanjut,
/// penyetuju)`; `lanjut` false = dibatalkan pengguna.
Future<({bool lanjut, StafLokal? penyetuju})> MintaPenyetujuVoid(
  BuildContext context,
  StafLokal kasir,
  String pesan,
) async {
  if (LayananPesananMeja.AmbilPenyetujuEfektif(kasir, null) != null) {
    return (lanjut: true, penyetuju: null);
  }
  final penyetuju = await showDialog<StafLokal>(
    context: context,
    builder: (_) =>
        DialogPinSupervisor(izin: IzinKasir.penjualanVoid, pesan: pesan, judul: 'Batalkan item pesanan meja'),
  );
  return (lanjut: penyetuju != null, penyetuju: penyetuju);
}

/// Tanya apakah item yang dibatalkan sudah dibuat/dimasak (BR-07.5 opsi waste). `true` = catat bahan terbuang, `false` =
/// bahan tidak dikurangi, null = kasir mundur dari pembatalan.
Future<bool?> TanyaSudahDibuat(BuildContext context, String namaProduk) => showDialog<bool>(
  context: context,
  barrierDismissible: false,
  builder: (konteks) => AlertDialog(
    title: Text('$namaProduk sudah dibuat?'),
    content: const Text(
      'Bila sudah dibuat atau dimasak, bahannya sudah terpakai. Pilih "Sudah dibuat" supaya bahan dicatat sebagai '
      'bahan terbuang dan stok tetap akurat.',
    ),
    actions: [
      TextButton(onPressed: () => Navigator.of(konteks).pop(), child: const Text('Kembali')),
      OutlinedButton(onPressed: () => Navigator.of(konteks).pop(false), child: const Text('Belum dibuat')),
      FilledButton(onPressed: () => Navigator.of(konteks).pop(true), child: const Text('Sudah dibuat')),
    ],
  ),
);

/// Batalkan satu baris pesanan tersimpan. Baris yang belum dikirim ke dapur cukup dikonfirmasi; yang sudah dikirim
/// butuh alasan dan penyetuju (void item, BR-07.5). Hasil = pesan untuk kasir (null = tidak jadi).
Future<String?> BatalkanBarisPesanan(
  BuildContext context,
  WidgetRef ref, {
  required StafLokal kasir,
  required String uuidPesanan,
  required BarisPesananMeja baris,
}) async {
  final terkirim = baris.dikirimKeDapur;
  final alasan = await TanyaAlasan(
    context,
    judul: 'Batalkan ${baris.namaProduk}?',
    pesan: terkirim
        ? 'Item ini sudah dikirim ke dapur. Pembatalan dicatat sebagai void item dan dapur diberi tahu.'
        : 'Item ini belum dikirim ke dapur dan akan dihapus dari pesanan.',
    labelTombol: 'Batalkan item',
    wajib: terkirim,
  );
  if (alasan == null || !context.mounted) {
    return null;
  }
  StafLokal? penyetuju;
  if (terkirim) {
    final hasil = await MintaPenyetujuVoid(
      context,
      kasir,
      'Pembatalan ${baris.namaProduk} yang sudah dikirim ke dapur wajib disetujui. Pilih supervisor.',
    );
    if (!hasil.lanjut) {
      return null;
    }
    penyetuju = hasil.penyetuju;
  }
  var sudahDibuat = false;
  if (terkirim) {
    if (!context.mounted) {
      return null;
    }
    // BR-07.5 opsi waste: item yang sudah dibuat sudah memakai bahan, jadi dicatat sebagai bahan terbuang.
    final jawab = await TanyaSudahDibuat(context, baris.namaProduk);
    if (jawab == null) {
      return null;
    }
    sudahDibuat = jawab;
  }
  try {
    await ref
        .read(penyediaLayananPesananMeja)
        .BatalkanBaris(
          uuidPesanan: uuidPesanan,
          uuidBaris: [baris.uuid],
          kasir: kasir,
          alasan: alasan,
          penyetuju: penyetuju,
          catatTerbuang: sudahDibuat,
        );
    return '${baris.namaProduk} dibatalkan.';
  } on GalatKasir catch (galat) {
    return galat.pesan;
  }
}

/// Batalkan seluruh pesanan (alasan wajib; penyetuju bila ada item yang sudah dikirim ke dapur). Hasil = pesan.
Future<String?> BatalkanPesananMeja(
  BuildContext context,
  WidgetRef ref, {
  required StafLokal kasir,
  required PesananMeja pesanan,
}) async {
  final terkirim = LayananPesananMeja.CekAdaTerkirim(pesanan, pesanan.baris.map((b) => b.uuid));
  final alasan = await TanyaAlasan(
    context,
    judul: 'Batalkan pesanan ${pesanan.AmbilJudul()}?',
    pesan: terkirim
        ? 'Sebagian item sudah dikirim ke dapur. Pesanan dibatalkan tanpa pembayaran dan dicatat.'
        : 'Pesanan dibatalkan tanpa pembayaran.',
    labelTombol: 'Batalkan pesanan',
    wajib: true,
  );
  if (alasan == null || !context.mounted) {
    return null;
  }
  StafLokal? penyetuju;
  if (terkirim) {
    final hasil = await MintaPenyetujuVoid(
      context,
      kasir,
      'Pembatalan pesanan yang sudah dikirim ke dapur wajib disetujui. Pilih supervisor.',
    );
    if (!hasil.lanjut) {
      return null;
    }
    penyetuju = hasil.penyetuju;
  }
  try {
    await ref
        .read(penyediaLayananPesananMeja)
        .Batal(uuidPesanan: pesanan.uuid, alasan: alasan, kasir: kasir, penyetuju: penyetuju);
    return 'Pesanan ${pesanan.AmbilJudul()} dibatalkan.';
  } on GalatKasir catch (galat) {
    return galat.pesan;
  }
}

/// Pilih meja tujuan pindah (meja kosong; atau tanpa meja). Hasil: `(dipilih, meja)`.
Future<({bool dipilih, BarisMeja? meja})> PilihMejaTujuan(
  BuildContext context, {
  required List<BarisMeja> meja,
  required Set<String> terisi,
  required String? uuidSekarang,
}) async {
  final kosong = meja.where((m) => !terisi.contains(m.Uuid) || m.Uuid == uuidSekarang).toList();
  final hasil = await showDialog<({BarisMeja? meja})>(
    context: context,
    builder: (konteks) => SimpleDialog(
      title: const Text('Pindah ke meja'),
      children: [
        for (final m in kosong)
          SimpleDialogOption(
            onPressed: () => Navigator.of(konteks).pop((meja: m)),
            child: ConstrainedBox(
              constraints: const BoxConstraints(minHeight: TokenJarak.targetSentuh),
              child: Align(
                alignment: Alignment.centerLeft,
                child: Text(m.Uuid == uuidSekarang ? '${m.Nama} (sekarang)' : m.Nama),
              ),
            ),
          ),
        SimpleDialogOption(
          onPressed: () => Navigator.of(konteks).pop((meja: null)),
          child: ConstrainedBox(
            constraints: const BoxConstraints(minHeight: TokenJarak.targetSentuh),
            child: const Align(alignment: Alignment.centerLeft, child: Text('Tanpa meja (bawa pulang/antre)')),
          ),
        ),
      ],
    ),
  );
  return hasil == null ? (dipilih: false, meja: null) : (dipilih: true, meja: hasil.meja);
}

/// v1.99 pisah tagihan: pilih item yang dibayar terpisah + nama tagihan baru. Hasil null = batal.
Future<({List<String> uuidBaris, String label})?> PilihItemPisah(BuildContext context, PesananMeja pesanan) =>
    showDialog<({List<String> uuidBaris, String label})>(
      context: context,
      builder: (_) => _DialogPisahTagihan(pesanan: pesanan),
    );

class _DialogPisahTagihan extends StatefulWidget {
  const _DialogPisahTagihan({required this.pesanan});

  final PesananMeja pesanan;

  @override
  State<_DialogPisahTagihan> createState() => _DialogPisahTagihanState();
}

class _DialogPisahTagihanState extends State<_DialogPisahTagihan> {
  final Set<String> _dipilih = {};
  late final TextEditingController _label = TextEditingController(text: '${widget.pesanan.AmbilJudul()} | Tagihan 2');
  String? _galat;

  @override
  void dispose() {
    _label.dispose();
    super.dispose();
  }

  void _Simpan(int jumlahAktif) {
    final label = _label.text.trim();
    if (_dipilih.isEmpty) {
      setState(() => _galat = 'Pilih item yang dibayar terpisah.');
      return;
    }
    if (_dipilih.length == jumlahAktif) {
      setState(() => _galat = 'Sisakan minimal satu item di tagihan ini.');
      return;
    }
    if (label.isEmpty) {
      setState(() => _galat = 'Isi nama tagihan baru.');
      return;
    }
    Navigator.of(context).pop((uuidBaris: _dipilih.toList(), label: label));
  }

  @override
  Widget build(BuildContext context) {
    final aktif = widget.pesanan.AmbilBarisAktif().toList();
    final teks = Theme.of(context).textTheme;
    return AlertDialog(
      title: Text('Pisah tagihan ${widget.pesanan.AmbilJudul()}'),
      content: SizedBox(
        width: 420,
        child: SingleChildScrollView(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisSize: MainAxisSize.min,
            children: [
              Text('Item yang dicentang pindah ke tagihan baru dan dibayar terpisah.', style: teks.bodySmall),
              const SizedBox(height: TokenJarak.jarak8),
              for (final b in aktif)
                CheckboxListTile(
                  contentPadding: EdgeInsets.zero,
                  value: _dipilih.contains(b.uuid),
                  title: Text('${b.namaProduk} × ${FormatJumlahPesanan(b.jumlah)}'),
                  subtitle: b.catatan == null ? null : Text(b.catatan!),
                  onChanged: (nilai) => setState(() {
                    nilai == true ? _dipilih.add(b.uuid) : _dipilih.remove(b.uuid);
                    _galat = null;
                  }),
                ),
              const SizedBox(height: TokenJarak.jarak8),
              TextField(
                controller: _label,
                maxLength: LayananPesananMeja.panjangLabelMaksimal,
                decoration: const InputDecoration(labelText: 'Nama tagihan baru'),
              ),
              if (_galat != null)
                Text(_galat!, style: teks.bodyMedium?.copyWith(color: TokenWarna.AmbilDari(context).bahaya)),
            ],
          ),
        ),
      ),
      actions: [
        TextButton(onPressed: () => Navigator.of(context).pop(), child: const Text('Batal')),
        FilledButton(onPressed: () => _Simpan(aktif.length), child: const Text('Pisah tagihan')),
      ],
    );
  }
}

/// `2.0000` → `2`; `1.5000` → `1,5`.
String FormatJumlahPesanan(String jumlah) {
  var teks = jumlah;
  if (teks.contains('.')) {
    teks = teks.replaceFirst(RegExp(r'0+$'), '').replaceFirst(RegExp(r'\.$'), '');
  }
  return teks.replaceAll('.', ',');
}

/// v1.99 gabung: pilih pesanan terbuka tujuan (meja/tagihan lain). Hasil null = batal.
Future<PesananMeja?> PilihPesananTujuan(
  BuildContext context, {
  required PesananMeja asal,
  required List<PesananMeja> pesanan,
}) {
  final pilihan = pesanan.where((p) => p.uuid != asal.uuid).toList();
  return showDialog<PesananMeja>(
    context: context,
    builder: (konteks) => SimpleDialog(
      title: Text('Gabungkan ${asal.AmbilJudul()} ke'),
      children: [
        if (pilihan.isEmpty)
          const Padding(
            padding: EdgeInsets.all(TokenJarak.jarak16),
            child: Text('Tidak ada pesanan terbuka lain untuk digabung.'),
          ),
        for (final p in pilihan)
          SimpleDialogOption(
            onPressed: () => Navigator.of(konteks).pop(p),
            child: ConstrainedBox(
              constraints: const BoxConstraints(minHeight: TokenJarak.targetSentuh),
              child: Align(
                alignment: Alignment.centerLeft,
                child: Text('${p.AmbilJudul()} | ${p.AmbilBarisAktif().length} item'),
              ),
            ),
          ),
      ],
    ),
  );
}
