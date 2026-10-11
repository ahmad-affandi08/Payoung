<?php

declare(strict_types=1);

namespace App\Domain\Persediaan\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Bersama\Tenant\MilikTenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Baris permintaan stok (F-05b, D-105): satu baris per produk, jumlah dalam satuan dasar. `JumlahDiminta` tetap;
 * `JumlahDikirim` bertambah setiap transfer berkait dikirim.
 *
 * @property int $Id
 * @property int $IdTenant
 * @property int $IdPermintaanStok
 * @property int $Urutan
 * @property int $IdProduk
 * @property string $NamaProduk
 * @property string|null $Sku
 * @property string $JumlahDiminta
 * @property string $JumlahDikirim
 * @property-read PermintaanStok $PermintaanStok
 */
final class PermintaanStokDetail extends ModelDasar
{
    use MilikTenant;

    protected $table = 'PermintaanStokDetail';

    protected bool $pakaiUuid = false;

    /** @var list<string> */
    protected $guarded = ['Id'];

    /** Sisa yang belum dikirim, tidak pernah negatif. */
    public function AmbilSisa(): Kuantitas
    {
        $sisa = Kuantitas::Dari($this->JumlahDiminta)->Kurangi(Kuantitas::Dari($this->JumlahDikirim));

        return $sisa->BernilaiNegatif() ? Kuantitas::Nol() : $sisa;
    }

    /**
     * @return BelongsTo<PermintaanStok, $this>
     */
    public function PermintaanStok(): BelongsTo
    {
        return $this->belongsTo(PermintaanStok::class, 'IdPermintaanStok', 'Id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['Urutan' => 'integer', 'JumlahDiminta' => 'string', 'JumlahDikirim' => 'string'];
    }
}
