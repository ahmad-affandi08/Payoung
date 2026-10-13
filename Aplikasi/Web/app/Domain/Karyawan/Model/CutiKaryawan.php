<?php

declare(strict_types=1);

namespace App\Domain\Karyawan\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Tenant\MilikTenant;
use App\Domain\Karyawan\Enum\JenisCuti;
use App\Domain\Karyawan\Enum\StatusCuti;
use Illuminate\Support\Carbon;

/**
 * Cuti/izin/sakit karyawan untuk rentang tanggal bisnis (F-18, EMP-03). Tidak diubah setelah dicatat; pembatalan
 * mengganti status dan menyimpan alasannya. `Berbayar` menentukan perlakuan di rekap gaji (berbayar = bukan "tidak masuk").
 *
 * @property int $Id
 * @property string $Uuid
 * @property int $IdTenant
 * @property int $IdKaryawan
 * @property JenisCuti $Jenis
 * @property Carbon $TanggalMulai
 * @property Carbon $TanggalSelesai
 * @property bool $Berbayar
 * @property string|null $Alasan
 * @property StatusCuti $Status
 * @property Carbon|null $DibatalkanPada
 * @property string|null $AlasanBatal
 * @property int|null $DibuatOleh
 * @property Carbon|null $DibuatPada
 * @property Carbon|null $DiubahPada
 */
final class CutiKaryawan extends ModelDasar
{
    use MilikTenant;

    protected $table = 'CutiKaryawan';

    /** @var array<string, mixed> */
    protected $attributes = ['Berbayar' => true, 'Alasan' => null, 'DibatalkanPada' => null, 'AlasanBatal' => null, 'DibuatOleh' => null];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'Jenis' => JenisCuti::class,
            'Status' => StatusCuti::class,
            'TanggalMulai' => 'date',
            'TanggalSelesai' => 'date',
            'Berbayar' => 'boolean',
            'DibatalkanPada' => 'datetime',
        ];
    }
}
