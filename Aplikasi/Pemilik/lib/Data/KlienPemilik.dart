import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:http/http.dart' as http;
import 'package:klien_api/KlienApi.dart';

/// Tenant yang boleh diakses pengguna di Aplikasi Owner.
class TenantPemilik {
  const TenantPemilik({required this.uuid, required this.nama, required this.pemilik});

  final String uuid;
  final String nama;
  final bool pemilik;

  static TenantPemilik DariJson(Map<String, Object?> json) => TenantPemilik(
    uuid: UraiJson.AmbilTeks(json['Uuid']),
    nama: UraiJson.AmbilTeks(json['Nama']),
    pemilik: UraiJson.AmbilBenar(json['Pemilik']),
  );
}

/// Hasil masuk: token + tenant, atau tantangan dua faktor.
class HasilMasukPemilik {
  const HasilMasukPemilik({this.token, this.namaPengguna = '', this.tenant = const [], this.tokenTantangan});

  final String? token;
  final String namaPengguna;
  final List<TenantPemilik> tenant;

  /// Terisi bila akun memakai 2FA: kirim kode lewat [KlienPemilik.MasukDuaFaktor].
  final String? tokenTantangan;

  bool get perluDuaFaktor => tokenTantangan != null;

  static HasilMasukPemilik DariJson(Map<String, Object?> json) => HasilMasukPemilik(
    token: UraiJson.AmbilTeksAtauNull(json['Token']),
    namaPengguna: UraiJson.AmbilTeks(UraiJson.AmbilPeta(json['Pengguna'])['Nama']),
    tenant: [for (final t in UraiJson.AmbilDaftarPeta(json['Tenant'])) TenantPemilik.DariJson(t)],
    tokenTantangan: UraiJson.AmbilBenar(json['PerluDuaFaktor'])
        ? UraiJson.AmbilTeksAtauNull(json['TokenTantangan'])
        : null,
  );
}

class OutletRingkas {
  const OutletRingkas({required this.uuid, required this.nama});

  final String uuid;
  final String nama;

  static OutletRingkas DariJson(Map<String, Object?> json) =>
      OutletRingkas(uuid: UraiJson.AmbilTeks(json['Uuid']), nama: UraiJson.AmbilTeks(json['Nama']));
}

/// Satu baris nilai (per outlet, per produk, per jam, dll.). Uang & jumlah berupa string desimal.
class BarisNilai {
  const BarisNilai({required this.nama, required this.omzet, this.jumlah, this.transaksi});

  final String nama;
  final String omzet;
  final String? jumlah;
  final int? transaksi;

  static BarisNilai DariJson(Map<String, Object?> json, {String kunciNama = 'Nama'}) => BarisNilai(
    nama: json[kunciNama] is int ? '${json[kunciNama]}' : UraiJson.AmbilTeks(json[kunciNama]),
    omzet: UraiJson.AmbilDesimal(json['Omzet']),
    jumlah: UraiJson.AmbilDesimalAtauNull(json['Jumlah']),
    transaksi: UraiJson.AmbilBulatAtauNull(json['Transaksi']),
  );
}

class HalPerluTindakan {
  const HalPerluTindakan({required this.jenis, required this.judul, required this.keterangan});

  final String jenis;
  final String judul;
  final String keterangan;

  static HalPerluTindakan DariJson(Map<String, Object?> json) => HalPerluTindakan(
    jenis: UraiJson.AmbilTeks(json['Jenis']),
    judul: UraiJson.AmbilTeks(json['Judul']),
    keterangan: UraiJson.AmbilTeks(json['Keterangan']),
  );
}

/// Dasbor OWN-02: satu angka besar (omzet) + perbandingan, lalu hal yang butuh tindakan.
class DasborPemilik {
  const DasborPemilik({
    required this.tanggal,
    required this.outlet,
    required this.omzet,
    required this.labaKotor,
    required this.transaksi,
    required this.rataRata,
    required this.omzetKemarin,
    required this.omzetMingguLalu,
    required this.perOutlet,
    required this.perJam,
    required this.produkTeratas,
    required this.perluTindakan,
  });

  final String tanggal;
  final List<OutletRingkas> outlet;
  final String omzet;

  /// Null bila pengguna tidak berizin melihat laporan keuangan.
  final String? labaKotor;
  final int transaksi;
  final String rataRata;
  final String omzetKemarin;
  final String omzetMingguLalu;
  final List<BarisNilai> perOutlet;
  final List<BarisNilai> perJam;
  final List<BarisNilai> produkTeratas;
  final List<HalPerluTindakan> perluTindakan;

  static DasborPemilik DariJson(Map<String, Object?> json) {
    final r = UraiJson.AmbilPeta(json['Ringkasan']);
    return DasborPemilik(
      tanggal: UraiJson.AmbilTeks(json['Tanggal']),
      outlet: [for (final o in UraiJson.AmbilDaftarPeta(json['Outlet'])) OutletRingkas.DariJson(o)],
      omzet: UraiJson.AmbilDesimal(r['Omzet']),
      labaKotor: UraiJson.AmbilDesimalAtauNull(r['LabaKotor']),
      transaksi: UraiJson.AmbilBulat(r['Transaksi']),
      rataRata: UraiJson.AmbilDesimal(r['RataRata']),
      omzetKemarin: UraiJson.AmbilDesimal(r['OmzetKemarin']),
      omzetMingguLalu: UraiJson.AmbilDesimal(r['OmzetMingguLalu']),
      perOutlet: [for (final o in UraiJson.AmbilDaftarPeta(json['PerOutlet'])) BarisNilai.DariJson(o)],
      perJam: [for (final j in UraiJson.AmbilDaftarPeta(json['PerJam'])) BarisNilai.DariJson(j, kunciNama: 'Jam')],
      produkTeratas: [for (final p in UraiJson.AmbilDaftarPeta(json['ProdukTeratas'])) BarisNilai.DariJson(p)],
      perluTindakan: [for (final h in UraiJson.AmbilDaftarPeta(json['PerluTindakan'])) HalPerluTindakan.DariJson(h)],
    );
  }
}

class LaporanPenjualanPemilik {
  const LaporanPenjualanPemilik({required this.kelompok, required this.baris, required this.totalOmzet});

  final String kelompok;
  final List<BarisNilai> baris;
  final String totalOmzet;

  static LaporanPenjualanPemilik DariJson(Map<String, Object?> json) => LaporanPenjualanPemilik(
    kelompok: UraiJson.AmbilTeks(json['Kelompok']),
    baris: [for (final b in UraiJson.AmbilDaftarPeta(json['Baris'])) BarisNilai.DariJson(b)],
    totalOmzet: UraiJson.AmbilDesimal(UraiJson.AmbilPeta(json['Total'])['Omzet']),
  );
}

/// OWN-05: satu angka laba rugi untuk periode ini dan periode pembanding sama panjang.
class NilaiLabaRugi {
  const NilaiLabaRugi({required this.nilai, required this.nilaiSebelumnya});

  final String nilai;
  final String nilaiSebelumnya;

  static NilaiLabaRugi DariJson(Map<String, Object?> json) => NilaiLabaRugi(
    nilai: UraiJson.AmbilDesimal(json['Nilai']),
    nilaiSebelumnya: UraiJson.AmbilDesimal(json['NilaiSebelumnya']),
  );
}

/// Satu baris rincian laba rugi: akun (`Jenis` = Akun), kepala/subtotal kelompok, atau baris laba.
class BarisLabaRugi {
  const BarisLabaRugi({
    required this.jenis,
    required this.kelompok,
    required this.label,
    required this.kode,
    required this.nilai,
    required this.nilaiSebelumnya,
  });

  final String jenis;
  final String kelompok;
  final String label;
  final String? kode;
  final String? nilai;
  final String? nilaiSebelumnya;

  static BarisLabaRugi DariJson(Map<String, Object?> json) => BarisLabaRugi(
    jenis: UraiJson.AmbilTeks(json['Jenis']),
    kelompok: UraiJson.AmbilTeks(json['Kelompok']),
    label: UraiJson.AmbilTeks(json['Label']),
    kode: UraiJson.AmbilTeksAtauNull(json['Kode']),
    nilai: UraiJson.AmbilDesimalAtauNull(json['Nilai']),
    nilaiSebelumnya: UraiJson.AmbilDesimalAtauNull(json['NilaiSebelumnya']),
  );
}

/// OWN-05: laba rugi sederhana (`GET laporan/laba-rugi`), angkanya sama dengan laporan keuangan di back-office.
class LabaRugiPemilik {
  const LabaRugiPemilik({
    required this.dari,
    required this.sampai,
    required this.pendapatan,
    required this.hpp,
    required this.labaKotor,
    required this.beban,
    required this.labaBersih,
    required this.baris,
  });

  final String dari;
  final String sampai;
  final NilaiLabaRugi pendapatan;
  final NilaiLabaRugi hpp;
  final NilaiLabaRugi labaKotor;
  final NilaiLabaRugi beban;
  final NilaiLabaRugi labaBersih;
  final List<BarisLabaRugi> baris;

  static LabaRugiPemilik DariJson(Map<String, Object?> json) {
    final ringkasan = UraiJson.AmbilPeta(json['Ringkasan']);
    final periode = UraiJson.AmbilPeta(json['Periode']);
    NilaiLabaRugi Ambil(String kunci) => NilaiLabaRugi.DariJson(UraiJson.AmbilPeta(ringkasan[kunci]));
    return LabaRugiPemilik(
      dari: UraiJson.AmbilTeks(periode['Dari']),
      sampai: UraiJson.AmbilTeks(periode['Sampai']),
      pendapatan: Ambil('Pendapatan'),
      hpp: Ambil('Hpp'),
      labaKotor: Ambil('LabaKotor'),
      beban: Ambil('Beban'),
      labaBersih: Ambil('LabaBersih'),
      baris: [for (final b in UraiJson.AmbilDaftarPeta(json['Baris'])) BarisLabaRugi.DariJson(b)],
    );
  }
}

/// OWN-09: skor risiko satu kasir pada periode laporan, dengan alasan berteks (petunjuk untuk diperiksa, bukan bukti).
class RisikoKasirPemilik {
  const RisikoKasirPemilik({
    required this.nama,
    required this.skor,
    required this.tingkat,
    required this.alasan,
    required this.jumlahTransaksi,
    required this.jumlahVoid,
  });

  final String nama;
  final int skor;

  /// `Tinggi`, `Sedang`, atau `Rendah`.
  final String tingkat;
  final List<String> alasan;
  final int jumlahTransaksi;
  final int jumlahVoid;

  static RisikoKasirPemilik DariJson(Map<String, Object?> json) => RisikoKasirPemilik(
    nama: UraiJson.AmbilTeks(json['NamaKasir']),
    skor: UraiJson.AmbilBulat(json['Skor']),
    tingkat: UraiJson.AmbilTeks(json['Tingkat']),
    alasan: [for (final a in (json['Alasan'] as List<Object?>? ?? const [])) UraiJson.AmbilTeks(a)],
    jumlahTransaksi: UraiJson.AmbilBulat(json['JumlahTransaksi']),
    jumlahVoid: UraiJson.AmbilBulat(json['JumlahVoid']),
  );
}

class ShiftPemilik {
  const ShiftPemilik({
    required this.uuid,
    required this.outlet,
    required this.kasir,
    required this.dibukaPada,
    required this.ditutupPada,
    required this.status,
    required this.selisih,
  });

  final String uuid;
  final String outlet;
  final String kasir;
  final DateTime? dibukaPada;
  final DateTime? ditutupPada;
  final String status;

  /// Null selama shift belum ditutup.
  final String? selisih;

  static ShiftPemilik DariJson(Map<String, Object?> json) => ShiftPemilik(
    uuid: UraiJson.AmbilTeks(json['Uuid']),
    outlet: UraiJson.AmbilTeks(json['Outlet']),
    kasir: UraiJson.AmbilTeks(json['Kasir']),
    dibukaPada: DateTime.tryParse(UraiJson.AmbilTeks(json['DibukaPada']))?.toLocal(),
    ditutupPada: DateTime.tryParse(UraiJson.AmbilTeks(json['DitutupPada']))?.toLocal(),
    status: UraiJson.AmbilTeks(json['Status']),
    selisih: UraiJson.AmbilDesimalAtauNull(json['Selisih']),
  );
}

class PerangkatPemilik {
  const PerangkatPemilik({
    required this.uuid,
    required this.kode,
    required this.nama,
    required this.jenis,
    required this.outlet,
    required this.status,
    required this.terakhirAktifPada,
    required this.outboxTertunda,
    required this.versiAplikasi,
  });

  final String uuid;
  final String kode;
  final String nama;
  final String jenis;
  final String outlet;
  final String status;
  final DateTime? terakhirAktifPada;
  final int outboxTertunda;
  final String? versiAplikasi;

  static PerangkatPemilik DariJson(Map<String, Object?> json) => PerangkatPemilik(
    uuid: UraiJson.AmbilTeks(json['Uuid']),
    kode: UraiJson.AmbilTeks(json['Kode']),
    nama: UraiJson.AmbilTeks(json['Nama']),
    jenis: UraiJson.AmbilTeks(json['Jenis']),
    outlet: UraiJson.AmbilTeks(json['Outlet']),
    status: UraiJson.AmbilTeks(json['Status']),
    terakhirAktifPada: DateTime.tryParse(UraiJson.AmbilTeks(json['TerakhirAktifPada']))?.toLocal(),
    outboxTertunda: UraiJson.AmbilBulat(json['JumlahOutboxTertunda']),
    versiAplikasi: UraiJson.AmbilTeksAtauNull(json['VersiAplikasi']),
  );
}

/// OWN-10: pantau karyawan — kehadiran hari ini, komisi & progres target bulan berjalan.
class PantauKaryawanPemilik {
  const PantauKaryawanPemilik({
    required this.tanggal,
    required this.periode,
    required this.hadir,
    required this.sedangBekerja,
    required this.terlambat,
    required this.belumMasuk,
    required this.kehadiran,
    required this.daftarBelumMasuk,
    required this.komisi,
    required this.target,
  });

  final String tanggal;
  final String periode;
  final int hadir;
  final int sedangBekerja;
  final int terlambat;
  final int belumMasuk;
  final List<KehadiranKaryawan> kehadiran;
  final List<({String nama, String? outlet, String jadwal})> daftarBelumMasuk;
  final List<({String nama, String komisi})> komisi;
  final List<TargetKaryawanPemilik> target;

  static PantauKaryawanPemilik DariJson(Map<String, Object?> json) {
    final ringkasan = UraiJson.AmbilPeta(json['Ringkasan']);
    return PantauKaryawanPemilik(
      tanggal: UraiJson.AmbilTeks(json['Tanggal']),
      periode: UraiJson.AmbilTeks(json['Periode']),
      hadir: UraiJson.AmbilBulat(ringkasan['Hadir']),
      sedangBekerja: UraiJson.AmbilBulat(ringkasan['SedangBekerja']),
      terlambat: UraiJson.AmbilBulat(ringkasan['Terlambat']),
      belumMasuk: UraiJson.AmbilBulat(ringkasan['BelumMasuk']),
      kehadiran: [for (final k in UraiJson.AmbilDaftarPeta(json['Kehadiran'])) KehadiranKaryawan.DariJson(k)],
      daftarBelumMasuk: [
        for (final b in UraiJson.AmbilDaftarPeta(json['BelumMasuk']))
          (
            nama: UraiJson.AmbilTeks(b['NamaKaryawan']),
            outlet: UraiJson.AmbilTeksAtauNull(b['NamaOutlet']),
            jadwal: UraiJson.AmbilTeks(b['Jadwal']),
          ),
      ],
      komisi: [
        for (final k in UraiJson.AmbilDaftarPeta(json['Komisi']))
          (nama: UraiJson.AmbilTeks(k['NamaKaryawan']), komisi: UraiJson.AmbilDesimal(k['Komisi'])),
      ],
      target: [for (final t in UraiJson.AmbilDaftarPeta(json['Target'])) TargetKaryawanPemilik.DariJson(t)],
    );
  }
}

class KehadiranKaryawan {
  const KehadiranKaryawan({
    required this.nama,
    required this.outlet,
    required this.jadwal,
    required this.jamMasuk,
    required this.jamKeluar,
    required this.terlambatMenit,
    required this.sumber,
  });

  final String nama;
  final String? outlet;
  final String? jadwal;
  final String jamMasuk;
  final String? jamKeluar;
  final int terlambatMenit;

  /// `Pos` (aplikasi kasir), `Web` (HP pribadi), atau `Manual` (dicatat pengelola).
  final String sumber;

  static KehadiranKaryawan DariJson(Map<String, Object?> json) => KehadiranKaryawan(
    nama: UraiJson.AmbilTeks(json['NamaKaryawan']),
    outlet: UraiJson.AmbilTeksAtauNull(json['NamaOutlet']),
    jadwal: UraiJson.AmbilTeksAtauNull(json['Jadwal']),
    jamMasuk: UraiJson.AmbilTeks(json['JamMasuk']),
    jamKeluar: UraiJson.AmbilTeksAtauNull(json['JamKeluar']),
    terlambatMenit: UraiJson.AmbilBulat(json['TerlambatMenit']),
    sumber: UraiJson.AmbilTeks(json['Sumber'], 'Pos'),
  );
}

class TargetKaryawanPemilik {
  const TargetKaryawanPemilik({
    required this.cakupan,
    required this.sasaran,
    required this.nilai,
    required this.realisasi,
    required this.persen,
    required this.proyeksi,
  });

  final String cakupan;
  final String sasaran;
  final String nilai;
  final String realisasi;
  final String persen;
  final String? proyeksi;

  static TargetKaryawanPemilik DariJson(Map<String, Object?> json) => TargetKaryawanPemilik(
    cakupan: UraiJson.AmbilTeks(json['LabelCakupan']),
    sasaran: UraiJson.AmbilTeks(json['NamaSasaran']),
    nilai: UraiJson.AmbilDesimal(json['Nilai']),
    realisasi: UraiJson.AmbilDesimal(json['Realisasi']),
    persen: UraiJson.AmbilDesimal(json['Persen']),
    proyeksi: UraiJson.AmbilDesimalAtauNull(json['Proyeksi']),
  );
}

/// OWN-11: insight minggu lalu (Senin–Minggu) dibanding minggu sebelumnya; sama dengan pesan WhatsApp mingguan.
class InsightMingguanPemilik {
  const InsightMingguanPemilik({
    required this.dari,
    required this.sampai,
    required this.bersih,
    required this.bersihSebelumnya,
    required this.persenPerubahan,
    required this.jumlahTransaksi,
    required this.rataTransaksi,
    required this.hariTeramai,
    required this.terlaris,
    required this.naik,
    required this.turun,
    required this.restock,
    required this.lebaran,
  });

  final String dari;
  final String sampai;
  final String bersih;
  final String bersihSebelumnya;
  final String? persenPerubahan;
  final int jumlahTransaksi;
  final String rataTransaksi;
  final ({String tanggal, String bersih})? hariTeramai;
  final List<({String nama, String qty, String bersih})> terlaris;
  final List<({String nama, String selisih})> naik;
  final List<({String nama, String selisih})> turun;
  final List<({String nama, String gudang, int hariHabis, String saranBeli, String satuan})> restock;
  final ({String tanggal, int sisaHari})? lebaran;

  static InsightMingguanPemilik DariJson(Map<String, Object?> json) {
    final teramai = UraiJson.AmbilPetaAtauNull(json['HariTeramai']);
    final lebaran = UraiJson.AmbilPetaAtauNull(json['Lebaran']);
    List<({String nama, String selisih})> Perubahan(Object? nilai) => [
      for (final p in UraiJson.AmbilDaftarPeta(nilai))
        (nama: UraiJson.AmbilTeks(p['NamaProduk']), selisih: UraiJson.AmbilDesimal(p['Selisih'])),
    ];
    return InsightMingguanPemilik(
      dari: UraiJson.AmbilTeks(json['Dari']),
      sampai: UraiJson.AmbilTeks(json['Sampai']),
      bersih: UraiJson.AmbilDesimal(json['Bersih']),
      bersihSebelumnya: UraiJson.AmbilDesimal(json['BersihSebelumnya']),
      persenPerubahan: UraiJson.AmbilDesimalAtauNull(json['PersenPerubahan']),
      jumlahTransaksi: UraiJson.AmbilBulat(json['JumlahTransaksi']),
      rataTransaksi: UraiJson.AmbilDesimal(json['RataTransaksi']),
      hariTeramai: teramai == null
          ? null
          : (tanggal: UraiJson.AmbilTeks(teramai['Tanggal']), bersih: UraiJson.AmbilDesimal(teramai['Bersih'])),
      terlaris: [
        for (final p in UraiJson.AmbilDaftarPeta(json['Terlaris']))
          (
            nama: UraiJson.AmbilTeks(p['NamaProduk']),
            qty: UraiJson.AmbilDesimal(p['Qty']),
            bersih: UraiJson.AmbilDesimal(p['Bersih']),
          ),
      ],
      naik: Perubahan(json['Naik']),
      turun: Perubahan(json['Turun']),
      restock: [
        for (final r in UraiJson.AmbilDaftarPeta(json['Restock']))
          (
            nama: UraiJson.AmbilTeks(r['NamaProduk']),
            gudang: UraiJson.AmbilTeks(r['NamaGudang']),
            hariHabis: UraiJson.AmbilBulat(r['HariHabis']),
            saranBeli: UraiJson.AmbilDesimal(r['SaranBeli']),
            satuan: UraiJson.AmbilTeks(r['SimbolSatuan']),
          ),
      ],
      lebaran: lebaran == null
          ? null
          : (tanggal: UraiJson.AmbilTeks(lebaran['Tanggal']), sisaHari: UraiJson.AmbilBulat(lebaran['SisaHari'])),
    );
  }
}

class NotifikasiPemilik {
  const NotifikasiPemilik({
    required this.uuid,
    required this.jenis,
    required this.judul,
    required this.isi,
    required this.data,
    required this.dibuatPada,
    this.dibacaPada,
  });

  final String uuid;
  final String jenis;
  final String judul;
  final String isi;
  final Map<String, String> data;
  final DateTime? dibacaPada;
  final DateTime? dibuatPada;

  bool get belumDibaca => dibacaPada == null;

  static NotifikasiPemilik DariJson(Map<String, Object?> json) {
    final mentah = UraiJson.AmbilPeta(json['Data']);
    return NotifikasiPemilik(
      uuid: UraiJson.AmbilTeks(json['Uuid']),
      jenis: UraiJson.AmbilTeks(json['Jenis']),
      judul: UraiJson.AmbilTeks(json['Judul']),
      isi: UraiJson.AmbilTeks(json['Isi']),
      data: {for (final MapEntry(:key, :value) in mentah.entries) key: '$value'},
      dibacaPada: DateTime.tryParse(UraiJson.AmbilTeks(json['DibacaPada']))?.toLocal(),
      dibuatPada: DateTime.tryParse(UraiJson.AmbilTeks(json['DibuatPada']))?.toLocal(),
    );
  }
}

class DaftarNotifikasiPemilik {
  const DaftarNotifikasiPemilik({required this.notifikasi, required this.belumDibaca});

  final List<NotifikasiPemilik> notifikasi;
  final int belumDibaca;

  static DaftarNotifikasiPemilik DariJson(Map<String, Object?> json) => DaftarNotifikasiPemilik(
    notifikasi: [for (final n in UraiJson.AmbilDaftarPeta(json['Notifikasi'])) NotifikasiPemilik.DariJson(n)],
    belumDibaca: UraiJson.AmbilBulat(json['BelumDibaca']),
  );
}

/// Klien `/api/pemilik/v1` (PRD §16, OWN-01..08) dengan user token Sanctum + header `X-Tenant` (tenant aktif).
/// Galat seragam `GalatApi`/`GalatJaringan` dari `klien_api`. 401 = token kedaluwarsa/dicabut → masuk ulang.
class KlienPemilik {
  KlienPemilik({
    required this.alamatDasar,
    required this.versiAplikasi,
    required this.ambilToken,
    required this.ambilTenant,
    http.Client? klien,
    this.batasWaktu = const Duration(seconds: 20),
  }) : _klien = klien ?? http.Client();

  final Uri alamatDasar;
  final String versiAplikasi;
  final FutureOr<String?> Function() ambilToken;
  final FutureOr<String?> Function() ambilTenant;
  final Duration batasWaktu;
  final http.Client _klien;

  Future<HasilMasukPemilik> Masuk({
    required String email,
    required String kataSandi,
    required String namaPerangkat,
  }) async => HasilMasukPemilik.DariJson(
    await _Kirim('POST', 'masuk', {
      'Email': email.trim(),
      'KataSandi': kataSandi,
      'NamaPerangkat': namaPerangkat,
    }, pakaiToken: false),
  );

  Future<HasilMasukPemilik> MasukDuaFaktor({
    required String tokenTantangan,
    required String kode,
    required String namaPerangkat,
  }) async => HasilMasukPemilik.DariJson(
    await _Kirim('POST', 'masuk/dua-faktor', {
      'TokenTantangan': tokenTantangan,
      'Kode': kode.replaceAll(' ', ''),
      'NamaPerangkat': namaPerangkat,
    }, pakaiToken: false),
  );

  /// Lupa kata sandi: server mengirim tautan atur ulang ke email dan selalu menjawab sama, terdaftar atau tidak.
  /// Mengembalikan pesan jawaban server untuk ditampilkan apa adanya.
  Future<String> LupaKataSandi(String email) async {
    final json = await _Kirim('POST', 'lupa-kata-sandi', {'Email': email.trim()}, pakaiToken: false);
    final pesan = UraiJson.AmbilTeks(json['Pesan']);

    return pesan.isEmpty ? 'Jika email tersebut terdaftar, tautan atur ulang sudah dikirim.' : pesan;
  }

  /// D-57: Client ID web Google untuk `serverClientId`; `null` bila toko belum mengaktifkan Masuk dengan Google.
  Future<String?> AmbilClientIdGoogle() async {
    final json = await _Kirim('GET', 'masuk/google/konfigurasi', null, pakaiToken: false);
    return json['Aktif'] == true ? UraiJson.AmbilTeks(json['ClientId']) : null;
  }

  /// D-57: masuk dengan token ID Google; menggantikan 2FA (jawaban tidak pernah berupa tantangan).
  Future<HasilMasukPemilik> MasukGoogle({required String idToken, required String namaPerangkat}) async =>
      HasilMasukPemilik.DariJson(
        await _Kirim('POST', 'masuk/google', {'IdToken': idToken, 'NamaPerangkat': namaPerangkat}, pakaiToken: false),
      );

  Future<void> Keluar() => _Kirim('POST', 'keluar', null);

  Future<HasilMasukPemilik> AmbilProfil() async => HasilMasukPemilik.DariJson(await _Kirim('GET', 'profil', null));

  Future<DasborPemilik> AmbilDasbor({required String tanggal, String? outlet}) async =>
      DasborPemilik.DariJson(await _Kirim('GET', _Jalur('dasbor', {'tanggal': tanggal, 'outlet': ?outlet}), null));

  Future<LaporanPenjualanPemilik> AmbilLaporanPenjualan({
    required String dari,
    required String sampai,
    required String kelompok,
    String? outlet,
  }) async => LaporanPenjualanPemilik.DariJson(
    await _Kirim(
      'GET',
      _Jalur('laporan/penjualan', {'dari': dari, 'sampai': sampai, 'kelompok': kelompok, 'outlet': ?outlet}),
      null,
    ),
  );

  /// OWN-05: laba rugi periode [dari]–[sampai] (izin `laporan.keuangan.lihat`, paket Laporan lengkap).
  Future<LabaRugiPemilik> AmbilLabaRugi({required String dari, required String sampai, String? outlet}) async =>
      LabaRugiPemilik.DariJson(
        await _Kirim('GET', _Jalur('laporan/laba-rugi', {'dari': dari, 'sampai': sampai, 'outlet': ?outlet}), null),
      );

  /// OWN-09: skor risiko kasir periode [dari]–[sampai] (maks. 31 hari; izin laporan penjualan, paket Anti-fraud).
  Future<List<RisikoKasirPemilik>> AmbilAntiFraud({required String dari, required String sampai, String? outlet}) async {
    final json = await _Kirim('GET', _Jalur('laporan/anti-fraud', {'dari': dari, 'sampai': sampai, 'outlet': ?outlet}), null);
    return [for (final k in UraiJson.AmbilDaftarPeta(json['Kasir'])) RisikoKasirPemilik.DariJson(k)];
  }

  Future<List<ShiftPemilik>> AmbilShift({required String tanggal, String? outlet}) async {
    final json = await _Kirim('GET', _Jalur('shift', {'tanggal': tanggal, 'outlet': ?outlet}), null);
    return [for (final s in UraiJson.AmbilDaftarPeta(json['Shift'])) ShiftPemilik.DariJson(s)];
  }

  Future<List<PerangkatPemilik>> AmbilPerangkat() async {
    final json = await _Kirim('GET', 'perangkat', null);
    return [for (final p in UraiJson.AmbilDaftarPeta(json['Perangkat'])) PerangkatPemilik.DariJson(p)];
  }

  /// OWN-08: cabut perangkat POS (izin `perangkat.kelola`); perangkat langsung ditolak server. Idempoten.
  Future<PerangkatPemilik> CabutPerangkat(String uuid) async => PerangkatPemilik.DariJson(
    UraiJson.AmbilPeta((await _Kirim('POST', 'perangkat/${Uri.encodeComponent(uuid)}/cabut', null))['Perangkat']),
  );

  /// OWN-11: insight minggu lalu; null bila belum ada penjualan dua minggu terakhir.
  Future<InsightMingguanPemilik?> AmbilInsight() async {
    final isi = UraiJson.AmbilPetaAtauNull((await _Kirim('GET', 'insight', null))['Insight']);
    return isi == null ? null : InsightMingguanPemilik.DariJson(isi);
  }

  /// OWN-10: kehadiran karyawan hari ini, komisi & target bulan berjalan (izin `karyawan.lihat`).
  Future<PantauKaryawanPemilik> AmbilPantauKaryawan() async =>
      PantauKaryawanPemilik.DariJson(await _Kirim('GET', 'karyawan', null));

  /// P-10 PGL-19 (v3.47): pengumuman platform yang berlaku untuk tenant aktif di Aplikasi Pemilik.
  Future<List<PengumumanAplikasi>> AmbilPengumuman() async {
    final json = await _Kirim('GET', 'pengumuman', null);
    return [for (final p in UraiJson.AmbilDaftarPeta(json['Pengumuman'])) PengumumanAplikasi.DariJson(p)];
  }

  Future<DaftarNotifikasiPemilik> AmbilNotifikasi() async =>
      DaftarNotifikasiPemilik.DariJson(await _Kirim('GET', 'notifikasi', null));

  Future<void> TandaiNotifikasiDibaca({List<String> uuid = const [], bool semua = false}) async {
    await _Kirim('PATCH', 'notifikasi', {'Uuid': uuid, 'Semua': semua});
  }

  Future<void> DaftarkanTokenNotifikasi({
    required String token,
    required String platform,
    required String namaPerangkat,
  }) async {
    await _Kirim('POST', 'token-notifikasi', {'Token': token, 'Platform': platform, 'NamaPerangkat': namaPerangkat});
  }

  /// OWN-03 / X4: antrean persetujuan jarak jauh yang boleh diputuskan pengguna (terlama dulu).
  Future<List<PermintaanPersetujuanPos>> AmbilPersetujuan() async {
    final json = await _Kirim('GET', 'persetujuan', null);
    return [for (final p in UraiJson.AmbilDaftarPeta(json['Persetujuan'])) PermintaanPersetujuanPos.DariJson(p)];
  }

  Future<PermintaanPersetujuanPos> SetujuiPersetujuan(String uuid) async => PermintaanPersetujuanPos.DariJson(
    UraiJson.AmbilPeta((await _Kirim('POST', 'persetujuan/${Uri.encodeComponent(uuid)}/setujui', null))['Persetujuan']),
  );

  /// Tolak dengan [alasan] 5–255 karakter (ditampilkan ke kasir).
  Future<PermintaanPersetujuanPos> TolakPersetujuan(String uuid, String alasan) async =>
      PermintaanPersetujuanPos.DariJson(
        UraiJson.AmbilPeta(
          (await _Kirim('POST', 'persetujuan/${Uri.encodeComponent(uuid)}/tolak', {
            'Alasan': alasan.trim(),
          }))['Persetujuan'],
        ),
      );

  static String _Jalur(String jalur, Map<String, String> kueri) =>
      kueri.isEmpty ? jalur : '$jalur?${Uri(queryParameters: kueri).query}';

  Future<Map<String, Object?>> _Kirim(
    String metode,
    String jalur,
    Map<String, Object?>? isi, {
    bool pakaiToken = true,
  }) async {
    final permintaan = http.Request(metode, alamatDasar.resolve('api/pemilik/v1/$jalur'))
      ..headers.addAll({'Accept': 'application/json', 'X-Versi-Aplikasi': versiAplikasi});
    if (pakaiToken) {
      final token = await ambilToken();
      if (token != null && token.isNotEmpty) {
        permintaan.headers['Authorization'] = 'Bearer $token';
      }
      final tenant = await ambilTenant();
      if (tenant != null && tenant.isNotEmpty) {
        permintaan.headers['X-Tenant'] = tenant;
      }
    }
    if (isi != null) {
      permintaan.headers['Content-Type'] = 'application/json';
      permintaan.body = jsonEncode(isi);
    }

    final http.Response respons;
    try {
      respons = await http.Response.fromStream(await _klien.send(permintaan).timeout(batasWaktu));
    } on TimeoutException {
      throw const GalatJaringan('Server tidak menjawab. Periksa koneksi internet.');
    } on SocketException {
      throw const GalatJaringan('Tidak ada koneksi ke server.');
    } on http.ClientException catch (galat) {
      throw GalatJaringan(galat.message);
    }
    if (respons.statusCode >= 500) {
      throw GalatJaringan('Server sedang bermasalah (${respons.statusCode}). Coba lagi sebentar lagi.');
    }

    final json = _Urai(respons.body);
    if (respons.statusCode >= 400) {
      final galat = UraiJson.AmbilPetaAtauNull(json['Galat']);
      final kesalahan = UraiJson.AmbilPetaAtauNull(json['errors']);
      throw GalatApi(
        kode: galat != null
            ? UraiJson.AmbilTeks(galat['Kode'], 'GalatServer')
            : kesalahan != null
            ? 'DataTidakValid'
            : respons.statusCode == 401
            ? 'SesiBerakhir'
            : 'GalatServer',
        pesan: galat != null
            ? UraiJson.AmbilTeks(galat['Pesan'], 'Permintaan ditolak server.')
            : kesalahan != null && kesalahan.values.first is List && (kesalahan.values.first! as List).isNotEmpty
            ? '${(kesalahan.values.first! as List).first}'
            : respons.statusCode == 401
            ? 'Sesi berakhir. Masuk lagi.'
            : 'Permintaan ditolak server (${respons.statusCode}).',
        statusHttp: respons.statusCode,
        bidang: kesalahan?.keys.first,
      );
    }
    return json;
  }

  static Map<String, Object?> _Urai(String isi) {
    if (isi.isEmpty || !isi.trimLeft().startsWith('{')) {
      return const {};
    }
    try {
      final hasil = jsonDecode(isi);
      return hasil is Map<String, Object?> ? hasil : const {};
    } on FormatException {
      return const {};
    }
  }
}
