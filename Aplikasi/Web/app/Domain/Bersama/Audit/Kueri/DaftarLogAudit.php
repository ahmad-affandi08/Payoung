<?php

declare(strict_types=1);

namespace App\Domain\Bersama\Audit\Kueri;

use App\Domain\Bersama\Audit\Layanan\KamusPeristiwaAudit;
use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Bersama\Tabel\Data\DataPermintaanTabel;
use App\Domain\Bersama\Tabel\Layanan\PenerapKueriTabel;
use Closure;
use Illuminate\Support\Collection;

/**
 * Log audit tenant aktif untuk `TabelData` (D-16), bawaan terbaru di atas. Cari sebagian nama peristiwa (misal
 * `outlet` atau `sesi.masuk`) atau kata dari kalimatnya (misal "outlet diubah"), saring rentang tanggal. Scope `MilikTenant` memastikan hanya log tenant aktif yang
 * terbaca. Nama pelaku dipetakan pemanggil (milik domain Organisasi).
 */
final class DaftarLogAudit
{
    public const KOLOM_URUT = ['DibuatPada', 'Peristiwa'];

    public const KOLOM_SARING = ['Tanggal'];

    /**
     * @param  Closure(list<LogAudit>): list<array<string, mixed>>  $petakan
     * @return array{Data: list<array<string, mixed>>, Meta: array{Halaman: int, PerHalaman: int, Total: int, JumlahHalaman: int}}
     */
    public function AmbilTabel(DataPermintaanTabel $permintaan, Closure $petakan): array
    {
        $tanggal = $permintaan->AmbilRentangTanggal('Tanggal');
        $kueri = LogAudit::query()
            ->when($permintaan->cari !== '', fn ($kueri) => $kueri->where(fn ($bagian) => $bagian
                ->where('Peristiwa', 'like', PenerapKueriTabel::PolaCari($permintaan->cari))
                ->orWhereIn('Peristiwa', KamusPeristiwaAudit::CariKunci($permintaan->cari))))
            ->when($tanggal['Dari'] !== null, fn ($kueri) => $kueri->where('DibuatPada', '>=', $tanggal['Dari'].' 00:00:00'))
            ->when($tanggal['Sampai'] !== null, fn ($kueri) => $kueri->where('DibuatPada', '<=', $tanggal['Sampai'].' 23:59:59'));

        return PenerapKueriTabel::Terapkan($kueri, $permintaan, ['DibuatPada' => 'Id', 'Peristiwa' => 'Peristiwa'], fn (Collection $log): array => $petakan(array_values($log->all())));
    }
}
