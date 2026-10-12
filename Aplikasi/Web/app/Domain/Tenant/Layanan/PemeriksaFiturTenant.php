<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Layanan;

use App\Domain\Tenant\Kueri\SumberFiturTenant;

/**
 * Layanan publik untuk domain & lapisan HTTP lain: apakah satu fitur paket aktif untuk tenant (P-04, BR-P04.7).
 */
final class PemeriksaFiturTenant
{
    /** §20.2: 2FA wajib untuk Owner/Admin/Akuntan di paket Bisnis ke atas. */
    public const KUNCI_2FA_WAJIB = 'keamanan.2fa-wajib';

    /** F-07 mode meja & F-10a data meja. */
    public const KUNCI_MODE_MEJA = 'pos.mode-meja';

    /** F-10 kitchen display. */
    public const KUNCI_KDS = 'pos.kds';

    /** F-17 Self-Order QR Meja (X12, add-on). */
    public const KUNCI_PESAN_SENDIRI = 'kanal.self-order';

    /** F-17 toko online publik. */
    public const KUNCI_TOKO_ONLINE = 'kanal.toko-online';

    /** X11 pesan WhatsApp (K3 kirim struk digital lewat WhatsApp). Fitur tingkat tenant, bukan modul outlet. */
    public const KUNCI_WHATSAPP = 'integrasi.whatsapp';

    public function __construct(
        private readonly SumberFiturTenant $sumber,
        private readonly EvaluatorFitur $evaluator,
    ) {}

    public function CekAktif(int $idTenant, string $kunci): bool
    {
        return $this->evaluator->CekFiturAktif($this->sumber->Ambil($idTenant), $kunci);
    }

    /**
     * F-01 (BR-01.3): fitur aktif di satu outlet = paket/add-on/override ∩ modul yang diaktifkan template outlet.
     * Hanya untuk fitur yang memang modul template (`pos.*`, `stok.*`, ...). Fitur kanal (`kanal.*`) bukan modul
     * template, jadi dicek tingkat tenant lewat `CekAktif`; pemilihan outlet kanal ada di bendera outlet sendiri
     * (`TokoOnlineAktif`, `PesanSendiriAktif`). Memakai method ini untuk kanal menolak semua tenant yang sudah
     * menerapkan template, walau paketnya memuat fitur itu.
     */
    public function CekAktifDiOutlet(int $idTenant, int $idOutlet, string $kunci): bool
    {
        return $this->evaluator->CekFiturAktif($this->sumber->Ambil($idTenant, idOutlet: $idOutlet), $kunci);
    }
}
