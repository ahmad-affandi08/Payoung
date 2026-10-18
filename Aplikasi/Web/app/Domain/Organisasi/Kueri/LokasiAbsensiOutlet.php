<?php

declare(strict_types=1);

namespace App\Domain\Organisasi\Kueri;

use App\Domain\Organisasi\Enum\StatusOrganisasi;
use App\Domain\Organisasi\Model\Outlet;
use Illuminate\Database\Eloquent\Builder;

/**
 * Titik lokasi & radius absensi outlet aktif (F-18 bagian 4, D-37) untuk geofence absensi web. Outlet tanpa titik
 * lokasi tidak ikut: tidak ada yang bisa dibuktikan tanpa titik acuan.
 */
final class LokasiAbsensiOutlet
{
    /**
     * @param  list<int>|null  $idOutlet  null = semua outlet aktif tenant
     * @return list<array{Id: int, Nama: string, Lintang: string, Bujur: string, RadiusMeter: int}>
     */
    public function Ambil(?array $idOutlet = null): array
    {
        return array_values(Outlet::query()
            ->where('Status', StatusOrganisasi::Aktif->value)
            ->whereNotNull('Lintang')
            ->whereNotNull('Bujur')
            ->when($idOutlet !== null, fn (Builder $k) => $k->whereIn('Id', $idOutlet ?? []))
            ->orderBy('Id')
            ->get(['Id', 'Nama', 'Lintang', 'Bujur', 'RadiusAbsensiMeter'])
            ->map(fn (Outlet $o): array => [
                'Id' => $o->Id,
                'Nama' => $o->Nama,
                'Lintang' => (string) $o->Lintang,
                'Bujur' => (string) $o->Bujur,
                'RadiusMeter' => $o->RadiusAbsensiMeter,
            ])
            ->all());
    }

    /** Nama outlet aktif (untuk pesan & status halaman absen). */
    public function AmbilNama(int $idOutlet): ?string
    {
        $nama = Outlet::query()->whereKey($idOutlet)->value('Nama');

        return is_string($nama) ? $nama : null;
    }
}
