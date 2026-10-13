<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Tenant\Enum\StatusInsiden;
use App\Domain\Tenant\Enum\TingkatInsiden;
use Illuminate\Support\Carbon;

/**
 * Insiden layanan platform (P-11 PGL-21). Data platform (tanpa `MilikTenant`). Terlihat publik bila `DiumumkanPada`
 * terisi; post-mortem hanya tampil publik bila `PostMortemDiterbitkanPada` terisi.
 *
 * @property int $Id
 * @property string $Uuid
 * @property string $Judul
 * @property TingkatInsiden $Tingkat
 * @property StatusInsiden $Status
 * @property string $Dampak
 * @property list<string> $Komponen
 * @property int|null $PersenTenantTerdampak
 * @property Carbon $MulaiPada
 * @property Carbon|null $DiumumkanPada
 * @property Carbon|null $SelesaiPada
 * @property string|null $PostMortem
 * @property Carbon|null $PostMortemDiterbitkanPada
 * @property int|null $DibuatOleh
 * @property Carbon|null $DibuatPada
 * @property Carbon|null $DiubahPada
 */
final class InsidenPlatform extends ModelDasar
{
    protected $table = 'InsidenPlatform';

    /** @var array<string, mixed> */
    protected $attributes = ['PersenTenantTerdampak' => null, 'DiumumkanPada' => null, 'SelesaiPada' => null, 'PostMortem' => null, 'PostMortemDiterbitkanPada' => null];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'Tingkat' => TingkatInsiden::class,
            'Status' => StatusInsiden::class,
            'Komponen' => 'array',
            'PersenTenantTerdampak' => 'integer',
            'MulaiPada' => 'datetime',
            'DiumumkanPada' => 'datetime',
            'SelesaiPada' => 'datetime',
            'PostMortemDiterbitkanPada' => 'datetime',
        ];
    }
}
