<?php

declare(strict_types=1);

namespace App\Domain\PanduanAwal\Layanan;

use App\Domain\Bersama\Tindakan\Data\DataButirTindakan;
use App\Domain\Bersama\Tindakan\Data\DataKonteksTindakan;
use App\Domain\Bersama\Tindakan\Data\DataRincianTindakan;
use App\Domain\Bersama\Tindakan\Enum\TingkatTindakan;
use App\Domain\Bersama\Tindakan\Kontrak\PenyediaTindakan;
use App\Domain\PanduanAwal\Kueri\PembaruanTemplateSektor;

/**
 * Kotak Tindakan (P-03 langkah 6): template jenis usaha outlet punya versi terbit yang lebih baru. Untuk pemegang izin
 * `outlet.kelola`; butir hilang sendiri setelah pembaruan diterapkan. Tidak ada yang berubah tanpa persetujuan (BR-P03.1).
 */
final class PenyediaTindakanPembaruanTemplate implements PenyediaTindakan
{
    public function __construct(private readonly PembaruanTemplateSektor $tawaran) {}

    public function Kumpulkan(DataKonteksTindakan $konteks): array
    {
        if (! $konteks->CekIzin('outlet.kelola')) {
            return [];
        }

        $daftar = $this->tawaran->AmbilTawaran();

        if ($daftar === []) {
            return [];
        }

        return [new DataButirTindakan(
            'template.pembaruan',
            'Pengaturan',
            TingkatTindakan::Info,
            'Template jenis usaha punya versi baru',
            'Payoung memperbarui template jenis usaha Anda. Lihat apa yang akan ditambahkan, lalu terapkan bila cocok. Data yang sudah ada tidak diubah.',
            count($daftar),
            '/kelola/pengaturan/pembaruan-template',
            'Lihat pembaruan',
            array_map(
                fn (array $t): DataRincianTindakan => new DataRincianTindakan(
                    $t['UuidOutlet'],
                    "{$t['NamaOutlet']} | {$t['NamaTemplate']}",
                    "Versi {$t['VersiSaatIni']} menjadi versi {$t['VersiBaru']}",
                    null,
                    null,
                ),
                array_slice($daftar, 0, DataButirTindakan::BATAS_RINCIAN),
            ),
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
