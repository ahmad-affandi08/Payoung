<?php

declare(strict_types=1);

namespace App\Domain\Karyawan\Kueri;

use App\Domain\Karyawan\Enum\StatusPermintaanKoreksi;
use App\Domain\Karyawan\Model\Karyawan;
use App\Domain\Karyawan\Model\PermintaanKoreksiAbsensi;
use App\Domain\Organisasi\Kueri\PetaUuidOutlet;
use Illuminate\Database\Eloquent\Builder;

/**
 * Permintaan koreksi absensi yang masih menunggu (F-18, D-180): panel di rekap absensi dan Kotak Tindakan. Dibatasi outlet
 * yang boleh diakses pelihat; permintaan karyawan tanpa outlet utama terlihat oleh semua pelihat.
 */
final class DaftarPermintaanKoreksi
{
    public function __construct(private readonly PetaUuidOutlet $outlet) {}

    /**
     * @param  list<int>|null  $idOutletBoleh
     */
    public function Hitung(?array $idOutletBoleh): int
    {
        return $this->Kueri($idOutletBoleh)->count();
    }

    /**
     * @param  list<int>|null  $idOutletBoleh
     * @return list<array{Uuid: string, UuidKaryawan: string|null, NamaKaryawan: string, UuidOutlet: string|null, NamaOutlet: string|null, Jenis: string, Alasan: string, DiajukanPada: string}>
     */
    public function Ambil(?array $idOutletBoleh, int $batas = 50): array
    {
        $baris = $this->Kueri($idOutletBoleh)->orderBy('DibuatPada')->limit($batas)->get();
        $karyawan = Karyawan::query()->whereKey($baris->pluck('IdKaryawan')->all())->get(['Id', 'Uuid', 'Nama'])->keyBy('Id');
        $idOutlet = array_values(array_unique(array_map('intval', $baris->pluck('IdOutlet')->filter()->all())));
        $outlet = [];

        foreach ($this->outlet->AmbilRingkas($idOutlet) as $o) {
            $outlet[$o['Id']] = $o;
        }

        return array_values($baris->map(fn (PermintaanKoreksiAbsensi $p): array => [
            'Uuid' => $p->Uuid,
            'UuidKaryawan' => $karyawan->get($p->IdKaryawan)?->Uuid,
            'NamaKaryawan' => (string) ($karyawan->get($p->IdKaryawan)->Nama ?? 'Karyawan'),
            'UuidOutlet' => $p->IdOutlet === null ? null : ($outlet[$p->IdOutlet]['Uuid'] ?? null),
            'NamaOutlet' => $p->IdOutlet === null ? null : ($outlet[$p->IdOutlet]['Nama'] ?? null),
            'Jenis' => $p->Jenis,
            'Alasan' => $p->Alasan,
            'DiajukanPada' => $p->DibuatPada?->toIso8601String() ?? '',
        ])->all());
    }

    /**
     * @param  list<int>|null  $idOutletBoleh
     * @return Builder<PermintaanKoreksiAbsensi>
     */
    private function Kueri(?array $idOutletBoleh): Builder
    {
        return PermintaanKoreksiAbsensi::query()
            ->where('Status', StatusPermintaanKoreksi::Menunggu->value)
            ->when($idOutletBoleh !== null, fn (Builder $k) => $k->where(fn (Builder $w) => $w->whereNull('IdOutlet')->orWhereIn('IdOutlet', $idOutletBoleh ?: [0])));
    }
}
