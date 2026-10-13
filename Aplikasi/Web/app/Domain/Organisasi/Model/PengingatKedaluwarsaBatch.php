<?php

declare(strict_types=1);

namespace App\Domain\Organisasi\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Tenant\MilikTenant;
use Illuminate\Support\Carbon;

/**
 * Penanda bahwa satu batch sudah diumumkan lewat push pada satu tingkat kedaluwarsa (F23). Hanya ditulis
 * `BuatNotifikasiKedaluwarsa`.
 *
 * @property int $Id
 * @property int $IdTenant
 * @property string $KunciBatch
 * @property string $Tingkat
 * @property Carbon $TanggalKedaluwarsa
 * @property Carbon $DibuatPada
 */
final class PengingatKedaluwarsaBatch extends ModelDasar
{
    use MilikTenant;

    public const UPDATED_AT = null;

    protected $table = 'PengingatKedaluwarsaBatch';

    protected bool $pakaiUuid = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['TanggalKedaluwarsa' => 'date', 'DibuatPada' => 'datetime'];
    }
}
