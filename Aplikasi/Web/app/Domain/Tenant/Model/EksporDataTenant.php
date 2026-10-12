<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Tenant\MilikTenant;
use App\Domain\Tenant\Enum\StatusEksporDataTenant;
use Illuminate\Support\Carbon;

/**
 * Permintaan ekspor data lengkap usaha (audit E5, UU PDP). Berkas di `PathBerkas` (disk privat), hanya bisa diunduh
 * Pemilik, kedaluwarsa otomatis.
 *
 * @property int $Id
 * @property string $Uuid
 * @property int $IdTenant
 * @property StatusEksporDataTenant $Status
 * @property int|null $IdPenggunaPeminta
 * @property string|null $PathBerkas
 * @property int $UkuranByte
 * @property int $JumlahTabel
 * @property int $JumlahBaris
 * @property Carbon|null $SelesaiPada
 * @property Carbon|null $KedaluwarsaPada
 * @property Carbon|null $DiunduhPada
 * @property string|null $Galat
 * @property Carbon|null $DibuatPada
 */
final class EksporDataTenant extends ModelDasar
{
    use MilikTenant;

    protected $table = 'EksporDataTenant';

    /** @var array<string, mixed> */
    protected $attributes = [
        'Status' => 'Antre',
        'PathBerkas' => null,
        'UkuranByte' => 0,
        'JumlahTabel' => 0,
        'JumlahBaris' => 0,
        'SelesaiPada' => null,
        'KedaluwarsaPada' => null,
        'DiunduhPada' => null,
        'Galat' => null,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'Status' => StatusEksporDataTenant::class,
            'UkuranByte' => 'integer',
            'JumlahTabel' => 'integer',
            'JumlahBaris' => 'integer',
            'SelesaiPada' => 'datetime',
            'KedaluwarsaPada' => 'datetime',
            'DiunduhPada' => 'datetime',
        ];
    }
}
