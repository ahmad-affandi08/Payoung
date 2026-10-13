<?php

declare(strict_types=1);

namespace App\Domain\Persediaan\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Tenant\MilikTenant;
use Illuminate\Support\Carbon;

/**
 * Rak/zona dalam satu lokasi stok (F-05c INV-07). Rak tidak menyimpan saldo: stok tetap per lokasi, rak hanya menentukan
 * produk mana yang dihitung dalam opname per rak (`ProdukRak`). `Kode` unik per lokasi stok.
 *
 * @property int $Id
 * @property string $Uuid
 * @property int $IdTenant
 * @property int $IdGudang
 * @property string $Kode
 * @property string $Nama
 * @property int $Urutan
 * @property bool $Aktif
 * @property Carbon|null $DibuatPada
 * @property Carbon|null $DiubahPada
 */
final class RakGudang extends ModelDasar
{
    use MilikTenant;

    protected $table = 'RakGudang';

    /** @var array<string, mixed> */
    protected $attributes = ['Urutan' => 0, 'Aktif' => true];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['Urutan' => 'integer', 'Aktif' => 'boolean'];
    }
}
