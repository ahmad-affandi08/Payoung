<?php

declare(strict_types=1);

namespace App\Domain\Dukungan\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Tenant\MilikTenant;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Log lokal aplikasi kasir yang diunggah atas permintaan dukungan (PGL-16 bagian 2). Isi sudah disaring dari data pribadi di
 * perangkat dan sekali lagi di server (`SaringLogPerangkat`). Append-only; dihapus `dukungan:bersihkan-log-perangkat`
 * setelah `HARI_SIMPAN` hari.
 *
 * @property int $Id
 * @property string $Uuid
 * @property int $IdTenant
 * @property int $IdPerangkat
 * @property string|null $VersiAplikasi
 * @property int $JumlahBaris
 * @property string $Isi
 * @property Carbon $DiunggahPada
 */
final class LogPerangkatDiunggah extends ModelDasar
{
    use MilikTenant;

    public const HARI_SIMPAN = 30;

    public const CREATED_AT = 'DiunggahPada';

    public const UPDATED_AT = null;

    protected $table = 'LogPerangkatDiunggah';

    protected static function booted(): void
    {
        self::updating(static function (): never {
            throw new LogicException('LogPerangkatDiunggah bersifat append-only dan tidak boleh diubah.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['JumlahBaris' => 'integer', 'DiunggahPada' => 'datetime'];
    }
}
