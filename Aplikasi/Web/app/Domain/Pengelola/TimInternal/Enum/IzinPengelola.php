<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\TimInternal\Enum;

/**
 * Izin Platform Pengelola (PRD §19.3). Nilai memakai format permission D-06: huruf kecil, titik, kebab-case.
 * Izin flow berikutnya (P-02 dst.) ditambahkan bersama flow-nya.
 */
enum IzinPengelola: string
{
    case TimAnggotaLihat = 'tim.anggota.lihat';
    case TimAnggotaUndang = 'tim.anggota.undang';
    case TimAnggotaNonaktifkan = 'tim.anggota.nonaktifkan';
    case TimPeranTetapkan = 'tim.peran.tetapkan';
    case AuditLihat = 'audit.lihat';

    // P-02 Master regulasi & referensi.
    case ReferensiLihat = 'referensi.lihat';
    case ReferensiWilayahKelola = 'referensi.wilayah.kelola';
    case ReferensiBankKelola = 'referensi.bank.kelola';
    case ReferensiSatuanKelola = 'referensi.satuan.kelola';
    case ReferensiTarifPajakAjukan = 'referensi.tarif-pajak.ajukan';
    case ReferensiTarifPajakSetujui = 'referensi.tarif-pajak.setujui';
    case ReferensiHariLiburAjukan = 'referensi.hari-libur.ajukan';
    case ReferensiHariLiburSetujui = 'referensi.hari-libur.setujui';

    // P-04 Katalog paket & fitur.
    case KatalogLihat = 'katalog.lihat';
    case KatalogFiturKelola = 'katalog.fitur.kelola';
    case KatalogPaketAjukan = 'katalog.paket.ajukan';
    case KatalogPaketSetujui = 'katalog.paket.setujui';
    case KatalogAddonKelola = 'katalog.addon.kelola';
    case KatalogKuponKelola = 'katalog.kupon.kelola';

    // P-03 Template sektor.
    case TemplateLihat = 'template.lihat';
    case TemplateDrafKelola = 'template.draf.kelola';
    case TemplateIsiUbah = 'template.isi.ubah';
    case TemplateAkunUbah = 'template.akun.ubah';
    case TemplateTerbitkan = 'template.terbitkan';

    // P-05 Konfigurasi integrasi platform (§19.3: Teknis & Super Admin).
    case IntegrasiLihat = 'integrasi.lihat';
    case IntegrasiKelola = 'integrasi.kelola';

    // P-06 Dokumen legal.
    case LegalLihat = 'legal.lihat';
    case LegalKelola = 'legal.kelola';

    // D-21 Situs pemasaran (payoung.id) diatur dari konsol.
    case SitusLihat = 'situs.lihat';
    case SitusKelola = 'situs.kelola';

    // P-07 Siklus hidup tenant (§19.3).
    case TenantLihat = 'tenant.lihat';
    case TenantCatatanTulis = 'tenant.catatan.tulis';
    case TenantTrialPerpanjang = 'tenant.trial.perpanjang';
    case TenantOverrideKelola = 'tenant.override.kelola';
    case TenantTangguhkan = 'tenant.tangguhkan';
    case TenantAktifkan = 'tenant.aktifkan';
    case TenantPenandaUbah = 'tenant.penanda.ubah';
    // P-08 Tagihan langganan & verifikasi pembayaran (§19.3: Keuangan & Super Admin).
    case TagihanLihat = 'tagihan.lihat';
    case TagihanVerifikasi = 'tagihan.verifikasi';
    case TagihanTerbitkan = 'tagihan.terbitkan';
    case TagihanRefund = 'tagihan.refund';
    case TagihanRefundSetujui = 'tagihan.refund.setujui';
    case TagihanPengaturan = 'tagihan.pengaturan';
    // P-09 Tiket dukungan (§19.3: Dukungan & Super Admin).
    case DukunganTiketLihat = 'dukungan.tiket.lihat';
    case DukunganTiketTangani = 'dukungan.tiket.tangani';

    // P-11 Monitoring operasional (§19.3: Teknis & Super Admin).
    case OperasionalLihat = 'operasional.lihat';
    case OperasionalKelola = 'operasional.kelola';

    // P-10 Rilis aplikasi & flag fitur (§19.3: Teknis & Super Admin).
    case RilisLihat = 'rilis.lihat';
    case RilisKelola = 'rilis.kelola';
    case FlagFiturKelola = 'flag-fitur.kelola';

    // P-12 Mitra, reseller & referral
    case MitraLihat = 'mitra.lihat';
    case MitraKelola = 'mitra.kelola';
    case MitraPencairan = 'mitra.pencairan';

    public function AmbilLabel(): string
    {
        return match ($this) {
            self::TimAnggotaLihat => 'Melihat anggota tim internal',
            self::TimAnggotaUndang => 'Mengundang anggota tim internal',
            self::TimAnggotaNonaktifkan => 'Menonaktifkan anggota tim internal',
            self::TimPeranTetapkan => 'Menetapkan peran anggota tim internal',
            self::AuditLihat => 'Melihat log audit pengelola',
            self::ReferensiLihat => 'Melihat data referensi & regulasi',
            self::ReferensiWilayahKelola => 'Mengelola data wilayah',
            self::ReferensiBankKelola => 'Mengelola referensi pembayaran',
            self::ReferensiSatuanKelola => 'Mengelola satuan standar',
            self::ReferensiTarifPajakAjukan => 'Mengajukan tarif pajak',
            self::ReferensiTarifPajakSetujui => 'Menyetujui atau menolak tarif pajak',
            self::ReferensiHariLiburAjukan => 'Mengajukan hari libur',
            self::ReferensiHariLiburSetujui => 'Menyetujui atau menolak hari libur',
            self::KatalogLihat => 'Melihat katalog paket & fitur',
            self::KatalogFiturKelola => 'Mengelola katalog fitur',
            self::KatalogPaketAjukan => 'Menyusun paket & mengusulkan harga',
            self::KatalogPaketSetujui => 'Menyetujui harga, mengaktifkan/mengarsipkan paket, mengubah paket aktif',
            self::KatalogAddonKelola => 'Mengelola add-on',
            self::KatalogKuponKelola => 'Mengelola kupon langganan',
            self::TemplateLihat => 'Melihat template sektor',
            self::TemplateDrafKelola => 'Membuat draf versi baru, memvalidasi, dan menghapus draf template',
            self::TemplateIsiUbah => 'Membuat template & mengubah isi bisnis template',
            self::TemplateAkunUbah => 'Mengubah COA, pemetaan akun, dan kelompok pajak template',
            self::TemplateTerbitkan => 'Menerbitkan, menonaktifkan, dan mengaktifkan kembali template sektor',
            self::IntegrasiLihat => 'Melihat status integrasi platform',
            self::IntegrasiKelola => 'Mengubah kredensial, menguji, dan mengaktifkan integrasi platform',
            self::LegalLihat => 'Melihat dokumen legal',
            self::LegalKelola => 'Menyusun dan menerbitkan dokumen legal',
            self::SitusLihat => 'Melihat pengaturan & halaman situs pemasaran',
            self::SitusKelola => 'Mengubah, menerbitkan halaman & mengunggah gambar situs pemasaran',
            // P-07 Siklus hidup tenant.
            self::TenantLihat => 'Melihat daftar & tampilan 360° tenant',
            self::TenantCatatanTulis => 'Menulis catatan internal tenant',
            self::TenantTrialPerpanjang => 'Memperpanjang trial tenant',
            self::TenantOverrideKelola => 'Membuat & mencabut override batas/fitur sementara',
            self::TenantTangguhkan => 'Menangguhkan tenant',
            self::TenantAktifkan => 'Mengaktifkan kembali tenant yang ditangguhkan',
            self::TenantPenandaUbah => 'Mengubah penanda tenant Uji/Demo/Internal',
            // P-08
            self::TagihanLihat => 'Melihat tagihan langganan & bukti transfer',
            self::TagihanVerifikasi => 'Menerima atau menolak pembayaran langganan',
            self::TagihanTerbitkan => 'Menerbitkan tagihan manual, mencatat pembayaran langsung, dan menghapus-buku tagihan langganan',
            self::TagihanRefund => 'Mengajukan refund tagihan langganan',
            self::TagihanRefundSetujui => 'Memutuskan refund langganan di atas batas',
            self::TagihanPengaturan => 'Mengatur rekening tujuan transfer dan status PKP untuk penagihan langganan',
            // P-09
            self::DukunganTiketLihat => 'Melihat antrean & percakapan tiket dukungan semua tenant',
            self::DukunganTiketTangani => 'Mengambil, menugaskan, membalas, dan mengubah status tiket dukungan',
            // P-11
            self::OperasionalLihat => 'Melihat dasbor operasional (scheduler, antrean, job gagal, backup)',
            self::OperasionalKelola => 'Mencoba ulang/membuang job gagal dan mencatat hasil backup',
            // P-10
            self::RilisLihat => 'Melihat rilis aplikasi & flag fitur',
            self::RilisKelola => 'Mencatat, menerbitkan, menghentikan rilis aplikasi dan menaikkan versi minimum',
            self::FlagFiturKelola => 'Mengubah flag fitur & kill switch',
            // P-12
            self::MitraLihat => 'Melihat mitra, tenant rujukan, komisi & pencairan',
            self::MitraKelola => 'Menambah & mengubah mitra (kode, komisi, rekening) dan membatalkan komisi tertunda',
            self::MitraPencairan => 'Mencatat pencairan komisi bulanan mitra',
        };
    }
}
