<?php

declare(strict_types=1);

namespace App\Domain\Bersama\Unduhan\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Tenant\MilikTenant;
use App\Domain\Bersama\Unduhan\Enum\StatusBerkasUnduhan;
use Illuminate\Support\Carbon;

/**
 * Satu ekspor laporan besar yang disiapkan di latar belakang (D-124). Berkas di `PathBerkas` (disk privat), hanya
 * peminta (`IdPengguna`) yang bisa mengunduh, kedaluwarsa otomatis.
 *
 * @property int $Id
 * @property string $Uuid
 * @property int $IdTenant
 * @property int $IdPengguna
 * @property string $Judul
 * @property string|null $NamaBerkas
 * @property string $Format
 * @property StatusBerkasUnduhan $Status
 * @property string|null $PathBerkas
 * @property int $UkuranByte
 * @property string|null $Galat
 * @property Carbon|null $MulaiPada
 * @property Carbon|null $SelesaiPada
 * @property Carbon|null $KedaluwarsaPada
 * @property Carbon|null $DiunduhPada
 * @property Carbon|null $DibuatPada
 */
final class BerkasUnduhan extends ModelDasar
{
    use MilikTenant;

    protected $table = 'BerkasUnduhan';

    /** @var array<string, mixed> */
    protected $attributes = [
        'Status' => 'Menunggu',
        'NamaBerkas' => null,
        'PathBerkas' => null,
        'UkuranByte' => 0,
        'Galat' => null,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'Status' => StatusBerkasUnduhan::class,
            'UkuranByte' => 'integer',
            'MulaiPada' => 'datetime',
            'SelesaiPada' => 'datetime',
            'KedaluwarsaPada' => 'datetime',
            'DiunduhPada' => 'datetime',
        ];
    }
}
