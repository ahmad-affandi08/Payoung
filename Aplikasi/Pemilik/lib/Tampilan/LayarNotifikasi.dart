import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:sistem_desain/SistemDesain.dart';

import '../Aplikasi/Penyedia.dart';
import '../Data/KlienPemilik.dart';
import 'FormatTampilan.dart';
import 'Komponen/KomponenModern.dart';
import 'KeadaanData.dart';

/// Membuka pusat notifikasi sebagai halaman turunan (dari lonceng Beranda, tautan push, dan menu Lainnya).
Future<void> BukaNotifikasi(BuildContext context) =>
    BukaHalaman(context, judul: 'Notifikasi', isi: const LayarNotifikasi());

/// Pusat notifikasi (OWN-03): dikelompokkan per hari, yang belum dibaca bertanda titik apricot. Tampil "Itu semua kabar"
/// dengan ilustrasi lonceng saat daftar sudah habis.
class LayarNotifikasi extends ConsumerStatefulWidget {
  const LayarNotifikasi({super.key});

  @override
  ConsumerState<LayarNotifikasi> createState() => _LayarNotifikasiState();
}

class _LayarNotifikasiState extends ConsumerState<LayarNotifikasi> {
  bool _hanyaBelumDibaca = false;

  static String _Hari(DateTime t) =>
      '${t.year.toString().padLeft(4, '0')}-${t.month.toString().padLeft(2, '0')}-${t.day.toString().padLeft(2, '0')}';

  static String? _KunciHari(DateTime? dibuatPada) => dibuatPada == null ? null : _Hari(dibuatPada);

  String _TentukanJudulHari(String? kunci) {
    if (kunci == null) {
      return 'Lebih lama';
    }
    final sekarang = ref.read(penyediaJam)();
    if (kunci == _Hari(sekarang)) {
      return 'Hari ini';
    }
    if (kunci == _Hari(sekarang.subtract(const Duration(days: 1)))) {
      return 'Kemarin';
    }
    return FormatTampilan.Tanggal(DateTime.tryParse(kunci) ?? sekarang);
  }

  @override
  Widget build(BuildContext context) {
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    return RefreshIndicator(
      onRefresh: () => ref.refresh(penyediaNotifikasi.future),
      child: KeadaanData(
        nilai: ref.watch(penyediaNotifikasi),
        saatCobaLagi: () => ref.invalidate(penyediaNotifikasi),
        isi: (data) {
          final tampil = _hanyaBelumDibaca ? data.notifikasi.where((n) => n.belumDibaca).toList() : data.notifikasi;
          final kelompok = <String?, List<NotifikasiPemilik>>{};
          for (final n in tampil) {
            kelompok.putIfAbsent(_KunciHari(n.dibuatPada), () => []).add(n);
          }
          if (tampil.isEmpty) {
            return DaftarDenganKosong(
              kepala: [
                Row(
                  children: [
                    Expanded(
                      child: SegmenPil<bool>(
                        pilihan: [(false, 'Semua'), (true, 'Belum dibaca (${data.belumDibaca})')],
                        nilai: _hanyaBelumDibaca,
                        saatPilih: (nilai) => setState(() => _hanyaBelumDibaca = nilai),
                      ),
                    ),
                  ],
                ),
              ],
              kosong: KosongModern(
                ilustrasi: IlustrasiOwner.Lonceng,
                judul: _hanyaBelumDibaca ? 'Semua notifikasi sudah dibaca.' : 'Belum ada notifikasi.',
                keterangan: 'Kabar penting usaha Anda muncul di sini.',
              ),
            );
          }
          return ListView(
            padding: const EdgeInsets.all(TokenJarak.jarak16),
            physics: const AlwaysScrollableScrollPhysics(),
            children: [
              Row(
                children: [
                  Expanded(
                    child: SegmenPil<bool>(
                      pilihan: [(false, 'Semua'), (true, 'Belum dibaca (${data.belumDibaca})')],
                      nilai: _hanyaBelumDibaca,
                      saatPilih: (nilai) => setState(() => _hanyaBelumDibaca = nilai),
                    ),
                  ),
                ],
              ),
              if (data.belumDibaca > 0)
                Align(
                  alignment: Alignment.centerRight,
                  child: TextButton.icon(
                    onPressed: () => unawaited(_TandaiSemua()),
                    icon: const Icon(Icons.done_all),
                    label: const Text('Tandai semua dibaca'),
                  ),
                ),
              for (final MapEntry(key: kunci, value: daftar) in kelompok.entries) ...[
                Padding(
                  padding: const EdgeInsets.fromLTRB(TokenJarak.jarak4, TokenJarak.jarak16, 0, TokenJarak.jarak8),
                  child: Text(
                    _TentukanJudulHari(kunci),
                    style: teks.labelLarge?.copyWith(fontWeight: FontWeight.w800, color: warna.teksSekunder),
                  ),
                ),
                DaftarMenu(
                  baris: [
                    for (final n in daftar)
                      BarisMenu(
                        ikon: n.belumDibaca ? Icons.notifications_active : Icons.notifications_none,
                        warnaIkon: n.belumDibaca ? warna.brand : warna.teksSekunder,
                        judul: n.judul,
                        keterangan: [
                          n.isi,
                          if (n.dibuatPada != null) FormatTampilan.TanggalJam(n.dibuatPada!),
                        ].join('\n'),
                        saatTekan: n.belumDibaca ? () => unawaited(_Tandai(n.uuid)) : null,
                        akhir: n.belumDibaca
                            ? Container(
                                width: 10,
                                height: 10,
                                decoration: BoxDecoration(color: warna.aksen, shape: BoxShape.circle),
                              )
                            : const SizedBox.shrink(),
                      ),
                  ],
                ),
              ],
              Padding(
                padding: const EdgeInsets.only(top: TokenJarak.jarak16),
                child: Column(
                  children: [
                    const IlustrasiMerek(IlustrasiOwner.Lonceng, lebar: 96),
                    Text('Itu semua kabar terbaru', style: teks.bodyMedium?.copyWith(color: warna.teksSekunder)),
                  ],
                ),
              ),
            ],
          );
        },
      ),
    );
  }

  Future<void> _Tandai(String uuid) async {
    await ref.read(penyediaKlien).TandaiNotifikasiDibaca(uuid: [uuid]);
    ref.invalidate(penyediaNotifikasi);
  }

  Future<void> _TandaiSemua() async {
    await ref.read(penyediaKlien).TandaiNotifikasiDibaca(semua: true);
    ref.invalidate(penyediaNotifikasi);
  }
}
