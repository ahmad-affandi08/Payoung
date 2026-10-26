<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Komunikasi\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Pengelola\Komunikasi\Enum\StatusPenerimaSiaran;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Satu kontak yang akan atau sudah dikirimi sebuah kampanye prospek (D-216).
 *
 * @property int $Id
 * @property int $IdKampanyeProspek
 * @property int $IdKontakProspek
 * @property StatusPenerimaSiaran $Status
 * @property string|null $Alasan
 * @property Carbon|null $DikirimPada
 */
final class PenerimaKampanyeProspek extends ModelDasar
{
    protected $table = 'PenerimaKampanyeProspek';

    protected bool $pakaiUuid = false;

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return ['Status' => StatusPenerimaSiaran::class, 'DikirimPada' => 'datetime'];
    }

    /**
     * @return BelongsTo<KontakProspek, $this>
     */
    public function Kontak(): BelongsTo
    {
        return $this->belongsTo(KontakProspek::class, 'IdKontakProspek', 'Id');
    }
}
