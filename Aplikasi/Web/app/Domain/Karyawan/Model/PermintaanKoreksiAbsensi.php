<?php

declare(strict_types=1);

namespace App\Domain\Karyawan\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Tenant\MilikTenant;
use App\Domain\Karyawan\Enum\StatusPermintaanKoreksi;
use Illuminate\Support\Carbon;

/**
 * Permintaan koreksi absensi dari karyawan (F-18, D-180): ia gagal absen dan meminta manajer mencatat jamnya. Pengelola
 * menutupnya (Selesai setelah mencatat absensi manual, atau Ditolak). Satu karyawan paling banyak satu permintaan
 * Menunggu per jenis.
 *
 * @property int $Id
 * @property string $Uuid
 * @property int $IdTenant
 * @property int $IdKaryawan
 * @property int|null $IdOutlet
 * @property string $Jenis Masuk atau Keluar
 * @property string $Alasan
 * @property StatusPermintaanKoreksi $Status
 * @property int|null $DiprosesOleh
 * @property Carbon|null $DiprosesPada
 * @property Carbon|null $DibuatPada
 */
final class PermintaanKoreksiAbsensi extends ModelDasar
{
    use MilikTenant;

    public const JENIS_MASUK = 'Masuk';

    public const JENIS_KELUAR = 'Keluar';

    protected $table = 'PermintaanKoreksiAbsensi';

    /** @var array<string, mixed> */
    protected $attributes = ['Status' => 'Menunggu', 'DiprosesOleh' => null, 'DiprosesPada' => null];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'Status' => StatusPermintaanKoreksi::class,
            'DiprosesPada' => 'datetime',
        ];
    }
}
