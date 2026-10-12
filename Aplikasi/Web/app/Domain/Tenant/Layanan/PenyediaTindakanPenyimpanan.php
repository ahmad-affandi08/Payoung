<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Layanan;

use App\Domain\Bersama\Tindakan\Data\DataButirTindakan;
use App\Domain\Bersama\Tindakan\Data\DataKonteksTindakan;
use App\Domain\Bersama\Tindakan\Enum\TingkatTindakan;
use App\Domain\Bersama\Tindakan\Kontrak\PenyediaTindakan;

/**
 * Kotak Tindakan domain Tenant (audit E2): penyimpanan berkas hampir atau sudah penuh menurut `BatasPenyimpananMb`
 * paket. Untuk pemegang izin `langganan.kelola`. Tidak muncul bila paket tanpa batas.
 */
final class PenyediaTindakanPenyimpanan implements PenyediaTindakan
{
    /** Pemakaian (persen dari batas) di atasnya dianggap hampir penuh. */
    public const AMBANG_HAMPIR_PENUH = 0.9;

    public function __construct(private readonly PenjagaPenyimpanan $penjaga) {}

    public function Kumpulkan(DataKonteksTindakan $konteks): array
    {
        if (! $konteks->CekIzin('langganan.kelola')) {
            return [];
        }

        $ringkasan = $this->penjaga->AmbilRingkasanMb($konteks->idTenant);

        if ($ringkasan['Batas'] === null || $ringkasan['Batas'] === 0) {
            return [];
        }

        $penuh = $ringkasan['Terpakai'] >= $ringkasan['Batas'];

        if (! $penuh && $ringkasan['Terpakai'] / $ringkasan['Batas'] < self::AMBANG_HAMPIR_PENUH) {
            return [];
        }

        return [new DataButirTindakan(
            'penyimpanan.penuh',
            'Langganan',
            $penuh ? TingkatTindakan::Penting : TingkatTindakan::Perhatian,
            $penuh ? 'Penyimpanan berkas penuh' : 'Penyimpanan berkas hampir penuh',
            "Terpakai {$ringkasan['Terpakai']} MB dari {$ringkasan['Batas']} MB."
                .($penuh ? ' Gambar produk dan lampiran baru ditolak sampai ruang bertambah.' : '')
                .' Tingkatkan paket atau tambah add-on penyimpanan.',
            1,
            '/kelola/langganan',
            'Lihat langganan',
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
