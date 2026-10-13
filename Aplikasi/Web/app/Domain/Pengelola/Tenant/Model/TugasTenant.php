<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Tenant\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Pengelola\Tenant\Enum\JenisTugasTenant;
use Illuminate\Support\Carbon;

/**
 * Tugas otomatis tim untuk satu tenant dari skor kesehatan (P-07 PGL-09). `Status` `Terbuka` atau `Selesai`;
 * `CaraSelesai` `Otomatis` (kondisinya sudah pulih) atau `Manual` (tim menandai sudah dihubungi). `KunciTerbuka` = 1
 * selama terbuka dan NULL setelahnya, sehingga indeks unik hanya membatasi satu tugas terbuka per tenant per jenis.
 *
 * @property int $Id
 * @property string $Uuid
 * @property int $IdTenant
 * @property JenisTugasTenant $Jenis
 * @property string $Status
 * @property int|null $KunciTerbuka
 * @property string $Ringkasan
 * @property Carbon|null $SelesaiPada
 * @property string|null $CaraSelesai
 * @property int|null $SelesaiOleh
 * @property string|null $CatatanSelesai
 * @property Carbon|null $DibuatPada
 * @property Carbon|null $DiubahPada
 */
final class TugasTenant extends ModelDasar
{
    public const TERBUKA = 'Terbuka';

    public const SELESAI = 'Selesai';

    protected $table = 'TugasTenant';

    /** @var array<string, mixed> */
    protected $attributes = ['KunciTerbuka' => 1, 'SelesaiPada' => null, 'CaraSelesai' => null, 'SelesaiOleh' => null, 'CatatanSelesai' => null];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['Jenis' => JenisTugasTenant::class, 'KunciTerbuka' => 'integer', 'SelesaiPada' => 'datetime'];
    }
}
