<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Operasional\Model;

use App\Domain\Bersama\Model\ModelDasar;
use Illuminate\Support\Carbon;

/**
 * Galat sinkron per jam (PGL-20 lanjutan): berapa kali item outbox ditolak server per jenis item dan kode galat.
 * Catatan platform tanpa pesan, isi, pengguna, atau tenant.
 *
 * @property int $Id
 * @property Carbon $Jam
 * @property string $JenisItem
 * @property string $KodeGalat
 * @property int $Jumlah
 */
final class MetrikGalatSinkron extends ModelDasar
{
    protected $table = 'MetrikGalatSinkron';

    protected bool $pakaiUuid = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['Jam' => 'datetime'];
    }
}
