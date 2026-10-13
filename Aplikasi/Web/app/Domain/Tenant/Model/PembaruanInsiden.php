<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Tenant\Enum\StatusInsiden;
use Illuminate\Support\Carbon;

/**
 * Satu entri kronologi insiden (append-only): status saat itu dan isi pembaruan.
 *
 * @property int $Id
 * @property string $Uuid
 * @property int $IdInsiden
 * @property StatusInsiden $Status
 * @property string $Isi
 * @property int|null $DibuatOleh
 * @property Carbon|null $DibuatPada
 * @property Carbon|null $DiubahPada
 */
final class PembaruanInsiden extends ModelDasar
{
    protected $table = 'PembaruanInsiden';

    /** @var array<string, mixed> */
    protected $attributes = ['DibuatOleh' => null];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['Status' => StatusInsiden::class];
    }
}
