import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:klien_api/KlienApi.dart';
import 'package:mesin_kasir/MesinKasir.dart' show Uang;
import 'package:sistem_desain/SistemDesain.dart';

import '../../Aplikasi/Penyedia.dart';
import '../../Domain/GalatKasir.dart';
import '../../Domain/Penjualan/KonteksPenjualan.dart';
import '../../Domain/Penjualan/LayananReservasi.dart';
import '../../Domain/Sesi/StafLokal.dart';
import '../Komponen/FormatWaktu.dart';
import 'LembarReservasi.dart';

/// K-20 (§9.8): kalender booking per staf dan buat booking dari kasir (perlu online). Kalender satu tanggal menampilkan
/// jam kerja tiap staf, reservasinya, dan rentang kosong; "Buat booking" memilih layanan, staf (atau siapa saja), jam
/// kosong dari server, lalu nama & HP pelanggan. Server mengunci per staf sehingga dua kasir tidak mengisi slot yang sama.
class PanelKalenderBooking extends ConsumerStatefulWidget {
  const PanelKalenderBooking({super.key, required this.kasir});

  final StafLokal kasir;

  @override
  ConsumerState<PanelKalenderBooking> createState() => _PanelKalenderBookingState();
}

class _PanelKalenderBookingState extends ConsumerState<PanelKalenderBooking> {
  late DateTime _tanggal = _HariIni();
  KalenderReservasiPos? _kalender;
  String? _galat;
  String? _info;
  bool _sibuk = false;
  bool _formulir = false;

  // Formulir booking.
  String? _uuidLayanan;
  String? _uuidStaf;
  List<SlotReservasiPos>? _slot;
  String? _jam;
  final _nama = TextEditingController();
  final _noHp = TextEditingController();
  final _catatan = TextEditingController();

  @override
  void initState() {
    super.initState();
    unawaited(_Muat());
  }

  @override
  void dispose() {
    _nama.dispose();
    _noHp.dispose();
    _catatan.dispose();
    super.dispose();
  }

  String get _zona => ref.read(penyediaKonteksPenjualan).value?.zonaWaktu ?? ZonaWaktuOutlet.bawaan;

  DateTime _HariIni() {
    final lokal = ZonaWaktuOutlet.KeWaktuOutlet(
      ref.read(penyediaJam)(),
      ref.read(penyediaKonteksPenjualan).value?.zonaWaktu,
    );
    return DateTime(lokal.year, lokal.month, lokal.day);
  }

  static String _Iso(DateTime t) =>
      '${t.year}-${t.month.toString().padLeft(2, '0')}-${t.day.toString().padLeft(2, '0')}';

  static const List<String> _hari = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'];

  Future<void> _Jalankan(Future<void> Function() kerja) async {
    setState(() {
      _sibuk = true;
      _galat = null;
    });
    try {
      await kerja();
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

  Future<void> _Muat() => _Jalankan(() async {
    final kalender = await ref.read(penyediaLayananReservasi).AmbilKalender(tanggal: _Iso(_tanggal));
    if (mounted) {
      setState(() {
        _kalender = kalender;
        _uuidLayanan ??= kalender.layanan.firstOrNull?.uuid;
      });
    }
  });

  void _GeserTanggal(int hari) {
    final baru = _tanggal.add(Duration(days: hari));
    if (baru.isBefore(_HariIni())) {
      return;
    }
    setState(() {
      _tanggal = baru;
      _slot = null;
      _jam = null;
      _info = null;
    });
    unawaited(_Muat());
    if (_formulir) {
      unawaited(_MuatSlot());
    }
  }

  Future<void> _MuatSlot() async {
    final layanan = _uuidLayanan;
    if (layanan == null) {
      return;
    }
    setState(() {
      _slot = null;
      _jam = null;
    });
    await _Jalankan(() async {
      final slot = await ref
          .read(penyediaLayananReservasi)
          .AmbilSlot(uuidLayanan: layanan, tanggal: _Iso(_tanggal), uuidStaf: _uuidStaf);
      if (mounted) {
        setState(() => _slot = slot);
      }
    });
  }

  void _BukaFormulir() {
    final pelanggan = ref.read(penyediaKeranjang).pelanggan;
    if (pelanggan != null && _nama.text.isEmpty) {
      _nama.text = pelanggan.nama;
    }
    setState(() {
      _formulir = true;
      _info = null;
    });
    unawaited(_MuatSlot());
  }

  Future<void> _Simpan() async {
    final layanan = _uuidLayanan;
    final jam = _jam;
    if (layanan == null || jam == null) {
      setState(() => _galat = 'Pilih layanan dan jam kosong dulu.');
      return;
    }
    await _Jalankan(() async {
      final r = await ref
          .read(penyediaLayananReservasi)
          .Buat(
            kasir: widget.kasir,
            uuidLayanan: layanan,
            tanggal: _Iso(_tanggal),
            jam: jam,
            uuidStaf: _uuidStaf,
            namaPelanggan: _nama.text,
            noHp: _noHp.text,
            catatan: _catatan.text,
          );
      if (!mounted) {
        return;
      }
      setState(() {
        _formulir = false;
        _info =
            'Booking ${r.nomor} dicatat: ${r.namaPelanggan}, ${LembarReservasi.FormatJam(r.mulaiPada, _zona)}'
            '${r.namaStaf == null ? '' : ' dengan ${r.namaStaf}'}.';
        _nama.clear();
        _noHp.clear();
        _catatan.clear();
        _slot = null;
        _jam = null;
      });
      await _Muat();
    });
  }

  @override
  Widget build(BuildContext context) {
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    final hariIni = _tanggal == _HariIni();
    return Column(
      key: const ValueKey('KalenderBooking'),
      mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Row(
          children: [
            IconButton(
              tooltip: 'Hari sebelumnya',
              onPressed: _sibuk || hariIni ? null : () => _GeserTanggal(-1),
              icon: const Icon(Icons.chevron_left),
            ),
            Expanded(
              child: Text(
                '${_hari[_tanggal.weekday - 1]}, ${FormatWaktu.FormatTanggal(_tanggal)}${hariIni ? ' (hari ini)' : ''}',
                textAlign: TextAlign.center,
                style: teks.titleSmall,
              ),
            ),
            IconButton(
              tooltip: 'Hari berikutnya',
              onPressed: _sibuk ? null : () => _GeserTanggal(1),
              icon: const Icon(Icons.chevron_right),
            ),
          ],
        ),
        if (_sibuk) const LinearProgressIndicator(),
        if (_galat case final galat?)
          Padding(
            padding: const EdgeInsets.only(top: TokenJarak.jarak8),
            child: Text(galat, style: TextStyle(color: warna.bahaya)),
          ),
        if (_info case final info?)
          Padding(
            padding: const EdgeInsets.only(top: TokenJarak.jarak8),
            child: Text(info, style: teks.bodyMedium?.copyWith(color: warna.sukses)),
          ),
        const SizedBox(height: TokenJarak.jarak8),
        if (_formulir) _BangunFormulir(context) else ..._BangunKalender(context),
      ],
    );
  }

  List<Widget> _BangunKalender(BuildContext context) {
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    final kalender = _kalender;
    if (kalender == null) {
      return const [];
    }
    final zona = _zona;
    final tanpaStaf = kalender.reservasi.where((r) => r.uuidStaf == null).toList();
    return [
      SizedBox(
        height: TokenJarak.targetSentuh,
        child: FilledButton.icon(
          onPressed: _sibuk || kalender.layanan.isEmpty ? null : _BukaFormulir,
          icon: const Icon(Icons.event_available),
          label: const Text('Buat booking'),
        ),
      ),
      if (kalender.layanan.isEmpty)
        Padding(
          padding: const EdgeInsets.only(top: TokenJarak.jarak8),
          child: Text(
            'Belum ada layanan yang bisa dibooking. Isi durasi layanan jasa di back-office.',
            style: teks.bodySmall,
          ),
        ),
      if (kalender.staf.isEmpty)
        const KeadaanKosong(
          ikon: Icons.event_busy_outlined,
          ilustrasi: IlustrasiKosong.Kalender,
          ringkas: true,
          judul: 'Belum ada staf berjadwal pada tanggal ini',
          keterangan: 'Pilih tanggal lain atau atur jadwal kerja di back-office.',
        ),
      for (final s in kalender.staf)
        Padding(
          padding: const EdgeInsets.only(top: TokenJarak.jarak12),
          child: DecoratedBox(
            key: ValueKey('staf-${s.uuid}'),
            decoration: BoxDecoration(
              border: Border.all(color: warna.garis, width: TokenJarak.tebalGaris),
              borderRadius: BorderRadius.circular(TokenJarak.radiusPanel),
            ),
            child: Padding(
              padding: const EdgeInsets.all(TokenJarak.jarak12),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Text('${s.nama} | ${s.jamMulai}–${s.jamSelesai}', style: teks.titleSmall),
                  for (final r in kalender.reservasi.where((r) => r.uuidStaf == s.uuid))
                    Padding(
                      padding: const EdgeInsets.only(top: TokenJarak.jarak4),
                      child: Text(
                        '${LembarReservasi.FormatJam(r.mulaiPada, zona)}–${LembarReservasi.FormatJam(r.selesaiPada, zona)}'
                        ' | ${r.namaPelanggan} | ${r.namaLayanan} | ${r.labelStatus}',
                        style: teks.bodyMedium,
                      ),
                    ),
                  const SizedBox(height: TokenJarak.jarak4),
                  Text(switch (LayananReservasi.HitungKosong(s, kalender.reservasi, zona)) {
                    final kosong when kosong.isEmpty => 'Penuh',
                    final kosong => 'Kosong: ${kosong.map((k) => '${k.mulai}–${k.selesai}').join(', ')}',
                  }, style: teks.bodySmall?.copyWith(color: warna.teksSekunder)),
                ],
              ),
            ),
          ),
        ),
      if (tanpaStaf.isNotEmpty)
        Padding(
          padding: const EdgeInsets.only(top: TokenJarak.jarak12),
          child: Text(
            'Tanpa staf: ${tanpaStaf.map((r) => '${LembarReservasi.FormatJam(r.mulaiPada, zona)} ${r.namaPelanggan}').join(', ')}',
            style: teks.bodySmall,
          ),
        ),
    ];
  }

  Widget _BangunFormulir(BuildContext context) {
    final teks = Theme.of(context).textTheme;
    final kalender = _kalender;
    final layanan = kalender?.layanan ?? const <LayananReservasiPos>[];
    final staf = kalender?.staf ?? const <StafReservasiPos>[];
    final slot = _slot;
    final adaWhatsapp = ref.watch(penyediaKonteksPenjualan).value?.whatsappTersedia ?? false;
    return Column(
      key: const ValueKey('FormulirBooking'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        DropdownButtonFormField<String>(
          key: const ValueKey('BookingLayanan'),
          isExpanded: true,
          initialValue: _uuidLayanan,
          decoration: const InputDecoration(labelText: 'Layanan'),
          items: [
            for (final l in layanan)
              DropdownMenuItem(
                value: l.uuid,
                child: Text(
                  '${l.nama} | ${l.durasiMenit} menit${l.harga == null ? '' : ' | ${Uang.Dari(l.harga!).FormatRupiah()}'}',
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                ),
              ),
          ],
          onChanged: _sibuk
              ? null
              : (nilai) {
                  setState(() => _uuidLayanan = nilai);
                  unawaited(_MuatSlot());
                },
        ),
        const SizedBox(height: TokenJarak.jarak12),
        DropdownButtonFormField<String?>(
          key: const ValueKey('BookingStaf'),
          isExpanded: true,
          initialValue: _uuidStaf,
          decoration: const InputDecoration(labelText: 'Staf'),
          items: [
            const DropdownMenuItem<String?>(value: null, child: Text('Siapa saja yang kosong')),
            for (final s in staf) DropdownMenuItem<String?>(value: s.uuid, child: Text(s.nama)),
          ],
          onChanged: _sibuk
              ? null
              : (nilai) {
                  setState(() => _uuidStaf = nilai);
                  unawaited(_MuatSlot());
                },
        ),
        const SizedBox(height: TokenJarak.jarak12),
        Text('Jam mulai', style: teks.labelLarge),
        const SizedBox(height: TokenJarak.jarak4),
        if (slot != null && slot.isEmpty)
          Text('Tidak ada jam kosong pada tanggal ini. Coba staf atau hari lain.', style: teks.bodySmall)
        else if (slot != null)
          Wrap(
            spacing: TokenJarak.jarak8,
            runSpacing: TokenJarak.jarak8,
            children: [
              for (final s in slot)
                ChoiceChip(
                  key: ValueKey('slot-${s.jam}'),
                  label: Text(s.jam),
                  selected: _jam == s.jam,
                  onSelected: _sibuk ? null : (_) => setState(() => _jam = s.jam),
                ),
            ],
          ),
        const SizedBox(height: TokenJarak.jarak12),
        TextField(
          key: const ValueKey('BookingNama'),
          controller: _nama,
          maxLength: 100,
          textCapitalization: TextCapitalization.words,
          decoration: const InputDecoration(labelText: 'Nama pelanggan'),
        ),
        TextField(
          key: const ValueKey('BookingNoHp'),
          controller: _noHp,
          maxLength: 20,
          keyboardType: TextInputType.phone,
          decoration: InputDecoration(
            // D-299: tanpa layanan WhatsApp, tidak ada pengingat H-1, jadi label dan keterangannya hilang.
            labelText: adaWhatsapp ? 'Nomor HP (WhatsApp)' : 'Nomor HP',
            helperText: adaWhatsapp ? 'Untuk pengingat H-1 lewat WhatsApp.' : null,
          ),
        ),
        TextField(
          key: const ValueKey('BookingCatatan'),
          controller: _catatan,
          maxLength: 255,
          decoration: const InputDecoration(labelText: 'Catatan (opsional)'),
        ),
        const SizedBox(height: TokenJarak.jarak8),
        Wrap(
          alignment: WrapAlignment.end,
          spacing: TokenJarak.jarak8,
          runSpacing: TokenJarak.jarak8,
          children: [
            OutlinedButton(
              onPressed: _sibuk ? null : () => setState(() => _formulir = false),
              child: const Text('Kembali ke kalender'),
            ),
            FilledButton(onPressed: _sibuk ? null : _Simpan, child: const Text('Simpan booking')),
          ],
        ),
      ],
    );
  }
}
