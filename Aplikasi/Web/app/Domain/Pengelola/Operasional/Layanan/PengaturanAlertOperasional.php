<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Operasional\Layanan;

use App\Domain\Tenant\Model\PengaturanPlatform;

/**
 * Penerima WhatsApp alert operasional (P-11, PGL-20): nomor Teknis yang bertugas, diatur dari dasbor operasional
 * (`PengaturanPlatform`), bukan `.env`. Alert kritis dikirim ke semua nomor ini lewat nomor WhatsApp Payoung, di
 * samping email.
 */
final class PengaturanAlertOperasional
{
    public const KUNCI_NOMOR_WA = 'operasional.alert-nomor-wa';

    public const MAKS_NOMOR = 5;

    public const KUNCI_TAUTAN_RUNBOOK = 'operasional.tautan-runbook';

    public const MAKS_PANJANG_TAUTAN = 255;

    /**
     * @return list<string> Nomor format internasional tanpa plus (`628…`).
     */
    public function NomorWa(): array
    {
        $nilai = PengaturanPlatform::query()->where('Kunci', self::KUNCI_NOMOR_WA)->value('Nilai');
        $daftar = is_string($nilai) ? json_decode($nilai, true) : [];

        return is_array($daftar) ? array_values(array_filter($daftar, 'is_string')) : [];
    }

    /**
     * Alamat dasar runbook yang dikelola Teknis (wiki/dokumen internal); tiap alert menautkan `{alamat}#{kunci-alert}`.
     * Null = belum diatur, dasbor tidak menampilkan tautan.
     */
    public function TautanRunbook(): ?string
    {
        $nilai = PengaturanPlatform::query()->where('Kunci', self::KUNCI_TAUTAN_RUNBOOK)->value('Nilai');

        return is_string($nilai) && $nilai !== '' ? $nilai : null;
    }

    /** Jangkar runbook untuk satu kunci alert: `PenjadwalBerhenti` → `penjadwal-berhenti`. */
    public static function JangkarRunbook(string $kunciAlert): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $kunciAlert));
    }
}
