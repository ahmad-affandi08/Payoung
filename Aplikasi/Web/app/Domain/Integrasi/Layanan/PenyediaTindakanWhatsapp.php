<?php

declare(strict_types=1);

namespace App\Domain\Integrasi\Layanan;

use App\Domain\Bersama\Tindakan\Data\DataButirTindakan;
use App\Domain\Bersama\Tindakan\Data\DataKonteksTindakan;
use App\Domain\Bersama\Tindakan\Enum\TingkatTindakan;
use App\Domain\Bersama\Tindakan\Kontrak\PenyediaTindakan;
use App\Domain\Integrasi\Model\WhatsappTenant;
use App\Domain\Integrasi\Whatsapp\MeterKuotaWhatsapp;
use App\Domain\Tenant\Layanan\PemeriksaFiturTenant;

/**
 * Kotak Tindakan domain Integrasi (D-108, izin `integrasi.api.kelola`): kuota pesan WhatsApp nomor Payoung hampir
 * habis (sisa ≤ 20%) atau sudah habis bulan ini. Tidak muncul bila paket tanpa batas, tenant tidak memakai WhatsApp,
 * atau tenant sudah memasang nomor WhatsApp sendiri yang aktif (pesan tidak memakai kuota).
 */
final class PenyediaTindakanWhatsapp implements PenyediaTindakan
{
    /** Sisa kuota (persen dari batas) di bawahnya dianggap hampir habis. */
    public const AMBANG_HAMPIR_HABIS = 0.2;

    public function __construct(
        private readonly MeterKuotaWhatsapp $meter,
        private readonly PemeriksaFiturTenant $fitur,
    ) {}

    public function Kumpulkan(DataKonteksTindakan $konteks): array
    {
        if (! $konteks->CekIzin('integrasi.api.kelola') || ! $this->fitur->CekAktif($konteks->idTenant, PemeriksaFiturTenant::KUNCI_WHATSAPP)) {
            return [];
        }

        if (WhatsappTenant::query()->where('Aktif', true)->exists()) {
            return [];
        }

        $kuota = $this->meter->AmbilRingkasan($konteks->idTenant);

        if ($kuota['Batas'] === null || $kuota['Batas'] === 0 || $kuota['Sisa'] === null) {
            return [];
        }

        $habis = $kuota['Sisa'] === 0;

        if (! $habis && $kuota['Sisa'] / $kuota['Batas'] > self::AMBANG_HAMPIR_HABIS) {
            return [];
        }

        return [new DataButirTindakan(
            'whatsapp.kuota',
            'WhatsApp',
            $habis ? TingkatTindakan::Penting : TingkatTindakan::Perhatian,
            $habis ? 'Kuota pesan WhatsApp bulan ini habis' : 'Kuota pesan WhatsApp hampir habis',
            $habis
                ? 'Pesan ke pelanggan lewat nomor Payoung ditolak sampai bulan depan. Tambah add-on kuota atau pasang WhatsApp sendiri.'
                : "Sisa {$kuota['Sisa']} dari {$kuota['Batas']} pesan bulan ini. Tambah add-on kuota atau pasang WhatsApp sendiri.",
            1,
            '/kelola/pengaturan/whatsapp',
            'Atur WhatsApp',
        )];
    }

    public function AmbilJenisDokumen(): array
    {
        return [];
    }

    public function SaringDokumen(string $jenisDokumen, array $uuid): array
    {
        return [];
    }
}
