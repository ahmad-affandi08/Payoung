<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Operasional\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Pengelola\Operasional\Enum\JenisAlertOperasional;
use App\Domain\Pengelola\Operasional\Enum\TingkatAlert;
use Illuminate\Support\Carbon;

/**
 * Satu insiden alert otomatis (P-11). Aktif selama `SelesaiPada` kosong; email dikirim sekali per insiden.
 *
 * @property int $Id
 * @property JenisAlertOperasional $Kunci
 * @property TingkatAlert $Tingkat
 * @property string $Pesan
 * @property Carbon $MulaiPada
 * @property Carbon|null $SelesaiPada
 * @property Carbon|null $EmailTerkirimPada
 * @property Carbon|null $WaTerkirimPada
 */
final class AlertOperasional extends ModelDasar
{
    protected $table = 'AlertOperasional';

    protected bool $pakaiUuid = false;

    /** @var array<string, mixed> */
    protected $attributes = ['SelesaiPada' => null, 'EmailTerkirimPada' => null, 'WaTerkirimPada' => null];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'Kunci' => JenisAlertOperasional::class,
            'Tingkat' => TingkatAlert::class,
            'MulaiPada' => 'datetime',
            'SelesaiPada' => 'datetime',
            'EmailTerkirimPada' => 'datetime',
            'WaTerkirimPada' => 'datetime',
        ];
    }
}
