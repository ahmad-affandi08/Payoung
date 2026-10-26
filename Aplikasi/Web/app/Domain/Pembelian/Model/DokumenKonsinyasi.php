<?php

declare(strict_types=1);

namespace App\Domain\Pembelian\Model;

use App\Domain\Bersama\Dokumen\Model\JagaDokumenTerposting;
use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Tenant\MilikTenant;
use App\Domain\Pembelian\Enum\JenisDokumenKonsinyasi;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Dokumen konsinyasi `KS/{OUTLET}/{YYMM}/{SEQ4}` (F-05i): titipan **Masuk** dari penitip (dinilai harga titip) atau
 * **Retur** sisa titipan ke penitip (dinilai HPP berjalan). Hanya mutasi stok, tanpa jurnal: barang titipan bukan aset
 * toko. Langsung dicatat saat disimpan dan tidak pernah diubah; koreksi = dokumen berlawanan.
 *
 * @property int $Id
 * @property string $Uuid
 * @property int $IdTenant
 * @property string $Nomor
 * @property JenisDokumenKonsinyasi $Jenis
 * @property int $IdPemasok
 * @property int|null $IdOutlet
 * @property int $IdGudang
 * @property Carbon $Tanggal
 * @property string $TotalNilai
 * @property string|null $Catatan
 * @property int|null $DibuatOleh
 * @property Carbon|null $DibuatPada
 * @property Carbon|null $DiubahPada
 * @property-read Collection<int, DokumenKonsinyasiDetail> $Detail
 */
final class DokumenKonsinyasi extends ModelDasar
{
    use JagaDokumenTerposting;
    use MilikTenant;

    protected $table = 'DokumenKonsinyasi';

    /** @var array<string, mixed> */
    protected $attributes = ['Catatan' => null, 'IdOutlet' => null];

    /** Kolom total diisi setelah baris dicatat, di transaksi yang sama.
     *
     * @return list<string>
     */
    public function AmbilKolomBolehBerubah(): array
    {
        return ['TotalNilai'];
    }

    /**
     * @return HasMany<DokumenKonsinyasiDetail, $this>
     */
    public function Detail(): HasMany
    {
        return $this->hasMany(DokumenKonsinyasiDetail::class, 'IdDokumenKonsinyasi', 'Id')->orderBy('Id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['Tanggal' => 'date', 'Jenis' => JenisDokumenKonsinyasi::class];
    }
}
