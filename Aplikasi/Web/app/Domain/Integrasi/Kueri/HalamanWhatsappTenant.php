<?php

declare(strict_types=1);

namespace App\Domain\Integrasi\Kueri;

use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Integrasi\Model\WhatsappTenant;
use App\Domain\Integrasi\Whatsapp\DaftarPenyediaWhatsapp;
use App\Domain\Integrasi\Whatsapp\MeterKuotaWhatsapp;
use App\Domain\Integrasi\Whatsapp\PembuatPengirimWhatsapp;
use App\Domain\Pengelola\Integrasi\Enum\PenyediaIntegrasi;

/**
 * Data halaman Pengaturan › WhatsApp (D-108): kuota pesan nomor Payoung bulan ini, penyedia yang bisa dipasang beserta
 * bidangnya, dan pengaturan tenant tanpa kredensial (hanya petunjuk 4 karakter terakhir, BR-P05.1).
 */
final class HalamanWhatsappTenant
{
    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly MeterKuotaWhatsapp $meter,
        private readonly PembuatPengirimWhatsapp $pembuat,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function Ambil(): array
    {
        $baris = WhatsappTenant::query()->first();

        return [
            'Kuota' => $this->meter->AmbilRingkasan($this->konteks->Wajib()),
            'NomorPayoungTersedia' => $this->pembuat->AmbilAktifPlatform() !== null,
            'Milik' => $baris === null ? null : [
                'Uuid' => $baris->Uuid,
                'Penyedia' => $baris->Penyedia->value,
                'LabelPenyedia' => $baris->Penyedia->AmbilLabel(),
                'Pengaturan' => $baris->Pengaturan,
                'PetunjukKredensial' => $baris->PetunjukKredensial,
                'StatusUji' => $baris->StatusUji->value,
                'LabelStatusUji' => $baris->StatusUji->AmbilLabel(),
                'PesanUji' => $baris->PesanUji,
                'DiujiPada' => $baris->DiujiPada?->toIso8601String(),
                'Aktif' => $baris->Aktif,
            ],
            'DaftarPenyedia' => array_map(fn (PenyediaIntegrasi $p): array => [
                'Nilai' => $p->value,
                'Label' => $p->AmbilLabel(),
                'Keterangan' => $p->AmbilKeterangan(),
                'BidangPengaturan' => DaftarPenyediaWhatsapp::AmbilBidangPengaturan($p),
                'BidangKredensial' => $p->AmbilBidangKredensial(),
            ], DaftarPenyediaWhatsapp::Ambil()),
        ];
    }
}
