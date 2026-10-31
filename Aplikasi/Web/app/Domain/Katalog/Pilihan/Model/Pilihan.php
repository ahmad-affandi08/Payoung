<?php

declare(strict_types=1);

namespace App\Domain\Katalog\Pilihan\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Tenant\MilikTenant;
use App\Domain\Katalog\Model\Produk;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Satu pilihan dalam kelompok pilihan (PRD §15.3, F-03): harga tambahan (string desimal 2 angka) dan bahan opsional
 * (`IdProduk`) yang dikurangi sebanyak `Jumlah` satuan dasar bahan saat dijual (F-07).
 *
 * @property int $Id
 * @property string $Uuid
 * @property int $IdTenant
 * @property int $IdKelompokPilihan
 * @property string $Nama
 * @property string $Harga
 * @property array<string, string>|null $HargaKanal peta kanal ke harga (X8, v7.86): kosong = pakai `Harga`
 * @property int|null $IdProduk
 * @property string|null $Jumlah
 * @property bool $Aktif
 * @property int $Urutan
 * @property Carbon|null $DibuatPada
 * @property Carbon|null $DiubahPada
 * @property-read KelompokPilihan $KelompokPilihan
 * @property-read Produk|null $ProdukBahan
 */
final class Pilihan extends ModelDasar
{
    use MilikTenant;

    protected $table = 'Pilihan';

    /** @var array<string, mixed> */
    protected $attributes = ['Harga' => '0', 'IdProduk' => null, 'Jumlah' => null, 'Aktif' => true, 'Urutan' => 0];

    /**
     * @return BelongsTo<KelompokPilihan, $this>
     */
    public function KelompokPilihan(): BelongsTo
    {
        return $this->belongsTo(KelompokPilihan::class, 'IdKelompokPilihan', 'Id');
    }

    /**
     * Bahan yang dikurangi (boleh sudah dihapus lunak: pilihan lama tetap terbaca).
     *
     * @return BelongsTo<Produk, $this>
     */
    public function ProdukBahan(): BelongsTo
    {
        return $this->belongsTo(Produk::class, 'IdProduk', 'Id')->withTrashed();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['Harga' => 'decimal:2', 'HargaKanal' => 'array', 'Jumlah' => 'decimal:4', 'Aktif' => 'boolean', 'Urutan' => 'integer'];
    }
}
