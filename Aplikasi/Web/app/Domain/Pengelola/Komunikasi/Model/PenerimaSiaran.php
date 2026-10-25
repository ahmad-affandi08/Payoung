<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Komunikasi\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Pengelola\Komunikasi\Enum\StatusPenerimaSiaran;
use Illuminate\Support\Carbon;

/**
 * Satu penerima siaran (D-210): pemilik satu tenant dengan alamat tujuannya (nomor WhatsApp atau email).
 *
 * @property int $Id
 * @property int $IdPesanSiaran
 * @property int $IdTenant
 * @property string $Nama
 * @property string $Tujuan
 * @property StatusPenerimaSiaran $Status
 * @property string|null $Alasan
 * @property Carbon|null $DikirimPada
 */
final class PenerimaSiaran extends ModelDasar
{
    protected $table = 'PenerimaSiaran';

    protected bool $pakaiUuid = false;

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return ['Status' => StatusPenerimaSiaran::class, 'DikirimPada' => 'datetime'];
    }
}
