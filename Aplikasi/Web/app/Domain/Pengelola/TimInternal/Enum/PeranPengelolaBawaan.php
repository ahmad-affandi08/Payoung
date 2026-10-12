<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\TimInternal\Enum;

/**
 * Tujuh peran internal bawaan (P-01 langkah 2, PRD §19.3). Nilai = kolom `PeranPengelola.Kode`.
 *
 * Super Admin memegang semua izin ("semua menu pengelola"). Izin peran lain ditambahkan bersama flow
 * yang menjadi cakupannya. P-02: Konten & Legal mengajukan, Keuangan meninjau data master regulasi.
 * P-04: Keuangan menyusun paket & mengusulkan harga, Super Admin menyetujui.
 * P-03 (BR-P03.5): Konten & Legal mengubah isi bisnis template, Keuangan COA & pemetaan akun, Teknis menerbitkan.
 * P-06: Konten & Legal menyusun dan menerbitkan dokumen legal; semua peran boleh membaca (dokumen publik).
 * P-05 (BR-P05.2): kredensial integrasi hanya Teknis & Super Admin; Keuangan dan Dukungan dilarang (§19.3).
 * P-07: lihat `AmbilIzinSiklusTenant()`.
 * P-09: tiket dukungan untuk Dukungan (Super Admin lewat semua izin). P-11: monitoring & job gagal untuk Teknis.
 * P-10: rilis aplikasi & flag fitur untuk Teknis.
 * P-12: Mitra & Penjualan mengelola mitra; Keuangan mencatat pencairan komisi (keduanya bisa melihat).
 */
enum PeranPengelolaBawaan: string
{
    case SuperAdmin = 'SuperAdmin';
    case Keuangan = 'Keuangan';
    case Dukungan = 'Dukungan';
    case Teknis = 'Teknis';
    case KontenLegal = 'KontenLegal';
    case MitraPenjualan = 'MitraPenjualan';
    case Analis = 'Analis';

    public function AmbilNama(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Admin',
            self::Keuangan => 'Keuangan',
            self::Dukungan => 'Dukungan',
            self::Teknis => 'Teknis',
            self::KontenLegal => 'Konten & Legal',
            self::MitraPenjualan => 'Mitra & Penjualan',
            self::Analis => 'Analis',
        };
    }

    /**
     * @return list<IzinPengelola>
     */
    public function AmbilIzin(): array
    {
        $izin = match ($this) {
            self::SuperAdmin => IzinPengelola::cases(),
            self::KontenLegal => [
                IzinPengelola::ReferensiLihat,
                IzinPengelola::ReferensiWilayahKelola,
                IzinPengelola::ReferensiBankKelola,
                IzinPengelola::ReferensiSatuanKelola,
                IzinPengelola::ReferensiTarifPajakAjukan,
                IzinPengelola::ReferensiHariLiburAjukan,
                IzinPengelola::KatalogLihat,
                IzinPengelola::TemplateLihat,
                IzinPengelola::TemplateDrafKelola,
                IzinPengelola::TemplateIsiUbah,
                IzinPengelola::LegalLihat,
                IzinPengelola::LegalKelola,
                // D-21: situs pemasaran.
                IzinPengelola::SitusLihat,
                IzinPengelola::SitusKelola,
            ],
            self::Keuangan => [
                IzinPengelola::ReferensiLihat,
                IzinPengelola::ReferensiTarifPajakSetujui,
                IzinPengelola::ReferensiHariLiburSetujui,
                // §19.3: Keuangan mengusulkan paket & harga; persetujuan oleh Super Admin (BR-P04.5).
                IzinPengelola::KatalogLihat,
                IzinPengelola::KatalogPaketAjukan,
                IzinPengelola::KatalogAddonKelola,
                IzinPengelola::KatalogKuponKelola,
                IzinPengelola::TemplateLihat,
                IzinPengelola::TemplateDrafKelola,
                IzinPengelola::TemplateAkunUbah,
                IzinPengelola::LegalLihat,
                // P-08: tagihan & verifikasi pembayaran (§19.3).
                IzinPengelola::TagihanLihat,
                IzinPengelola::TagihanVerifikasi,
                IzinPengelola::TagihanTerbitkan,
                IzinPengelola::TagihanRefund,
                // P-12: pencairan komisi mitra.
                IzinPengelola::MitraLihat,
                IzinPengelola::MitraPencairan,
            ],
            self::Teknis => [
                IzinPengelola::ReferensiLihat,
                IzinPengelola::KatalogLihat,
                IzinPengelola::TemplateLihat,
                IzinPengelola::TemplateDrafKelola,
                IzinPengelola::TemplateTerbitkan,
                IzinPengelola::IntegrasiLihat,
                IzinPengelola::IntegrasiKelola,
                IzinPengelola::LegalLihat,
                // P-11 Monitoring operasional.
                IzinPengelola::OperasionalLihat,
                IzinPengelola::OperasionalKelola,
                // P-10 Rilis aplikasi & flag fitur.
                IzinPengelola::RilisLihat,
                IzinPengelola::RilisKelola,
                IzinPengelola::FlagFiturKelola,
            ],
            // P-09 Tiket dukungan: Dukungan dipisah dari Mitra & Penjualan dan Analis.
            self::Dukungan => [
                IzinPengelola::ReferensiLihat,
                IzinPengelola::KatalogLihat,
                IzinPengelola::TemplateLihat,
                IzinPengelola::LegalLihat,
                IzinPengelola::DukunganTiketLihat,
                IzinPengelola::DukunganTiketTangani,
                // PGL-16: Dukungan boleh masuk ke tenant yang memberi izin; akses darurat khusus Super Admin.
                IzinPengelola::DukunganAksesMasuk,
            ],
            self::MitraPenjualan => [
                IzinPengelola::ReferensiLihat,
                IzinPengelola::KatalogLihat,
                IzinPengelola::TemplateLihat,
                IzinPengelola::LegalLihat,
                // P-12: mitra, reseller & referral.
                IzinPengelola::MitraLihat,
                IzinPengelola::MitraKelola,
            ],
            self::Analis => [
                IzinPengelola::ReferensiLihat,
                IzinPengelola::KatalogLihat,
                IzinPengelola::TemplateLihat,
                IzinPengelola::LegalLihat,
            ],
        };

        // P-07 Siklus hidup tenant.
        return [...$izin, ...$this->AmbilIzinSiklusTenant()];
    }

    /**
     * P-07 (§19.3): tampilan 360° & catatan internal untuk semua peran yang bekerja dengan tenant (Konten & Legal
     * "tidak boleh: tenant", Analis hanya data agregat tanpa data pribadi). Perpanjang trial: Dukungan & Mitra
     * Penjualan; override: Dukungan; aktifkan kembali: Keuangan. Tangguhkan & penanda hanya Super Admin (sudah
     * memegang semua izin).
     *
     * @return list<IzinPengelola>
     */
    private function AmbilIzinSiklusTenant(): array
    {
        $dasar = [IzinPengelola::TenantLihat, IzinPengelola::TenantCatatanTulis];

        return match ($this) {
            self::SuperAdmin, self::KontenLegal, self::Analis => [],
            self::Keuangan => [...$dasar, IzinPengelola::TenantAktifkan],
            self::Dukungan => [...$dasar, IzinPengelola::TenantTrialPerpanjang, IzinPengelola::TenantOverrideKelola],
            self::MitraPenjualan => [...$dasar, IzinPengelola::TenantTrialPerpanjang],
            self::Teknis => $dasar,
        };
    }
}
