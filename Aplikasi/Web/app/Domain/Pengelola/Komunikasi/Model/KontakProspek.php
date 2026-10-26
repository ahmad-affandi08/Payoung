<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Komunikasi\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Pengelola\Komunikasi\Enum\SektorProspek;
use App\Domain\Pengelola\Komunikasi\Enum\StatusKontakProspek;
use Illuminate\Support\Carbon;

/**
 * Calon pelanggan yang dikirimi kampanye email (D-216). Alamat disimpan huruf kecil dan unik; kontak yang pernah
 * berhenti atau pentalan tidak bisa dimasukkan lagi lewat impor.
 *
 * @property int $Id
 * @property string $Uuid
 * @property string $Email
 * @property SektorProspek $Sektor
 * @property string $Sumber
 * @property StatusKontakProspek $Status
 * @property Carbon|null $BerhentiPada
 * @property Carbon $DibuatPada
 */
final class KontakProspek extends ModelDasar
{
    protected $table = 'KontakProspek';

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return ['Sektor' => SektorProspek::class, 'Status' => StatusKontakProspek::class, 'BerhentiPada' => 'datetime'];
    }
}
