<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Tenant\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Pengelola\Tenant\Enum\KategoriKesehatan;
use Illuminate\Support\Carbon;

/**
 * Skor kesehatan harian satu tenant (P-07 PGL-09). Catatan platform tentang tenant (bukan data usaha), satu baris per
 * tenant per tanggal; `Faktor` menyimpan ukuran dan poin tiap faktor supaya skor bisa dijelaskan.
 *
 * @property int $Id
 * @property int $IdTenant
 * @property Carbon $Tanggal
 * @property int $Skor
 * @property KategoriKesehatan $Kategori
 * @property array<string, array{Nilai: int|bool, Poin: int, Maks: int}> $Faktor
 * @property Carbon|null $DibuatPada
 * @property Carbon|null $DiubahPada
 */
final class SkorKesehatanTenant extends ModelDasar
{
    protected $table = 'SkorKesehatanTenant';

    protected bool $pakaiUuid = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['Tanggal' => 'date', 'Skor' => 'integer', 'Kategori' => KategoriKesehatan::class, 'Faktor' => 'array'];
    }
}
