<?php

declare(strict_types=1);

namespace App\Domain\Persediaan\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Tenant\MilikTenant;
use App\Domain\Persediaan\Enum\StatusPenyesuaianStok;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Baris penyesuaian stok (F-05b). `Jumlah` bertanda dalam satuan dasar: + masuk (wajib `HppSatuan`), − keluar
 * (dinilai HPP berjalan). Batch: keluar menyebut `IdBatchStok`, masuk `NomorBatch` + kedaluwarsa; seri: satu baris
 * per nomor seri. `Nilai` (bertanda) diisi saat dicatat. Isi hanya berubah selama dokumennya Draf.
 *
 * @property int $Id
 * @property int $IdTenant
 * @property int $IdPenyesuaianStok
 * @property int $Urutan
 * @property int $IdProduk
 * @property string $NamaProduk
 * @property string|null $Sku
 * @property string $Jumlah
 * @property string|null $HppSatuan
 * @property string|null $Nilai
 * @property int|null $IdBatchStok
 * @property string|null $NomorBatch
 * @property Carbon|null $TanggalKedaluwarsa
 * @property int|null $IdNomorSeri
 * @property string|null $NomorSeri
 * @property-read PenyesuaianStok $PenyesuaianStok
 */
final class PenyesuaianStokDetail extends ModelDasar
{
    use MilikTenant;

    protected $table = 'PenyesuaianStokDetail';

    protected bool $pakaiUuid = false;

    /** @var list<string> */
    protected $guarded = ['Id'];

    protected static function booted(): void
    {
        $jaga = function (self $baris): void {
            $nilai = PenyesuaianStok::query()->whereKey($baris->IdPenyesuaianStok)->sharedLock()->value('Status');
            $status = $nilai instanceof StatusPenyesuaianStok ? $nilai : StatusPenyesuaianStok::tryFrom((string) $nilai);
            // Nilai sebenarnya diisi saat dicatat, juga dari MenungguPersetujuan (disetujui).
            $hanyaNilai = array_diff(array_keys($baris->getDirty()), ['Nilai', 'DiubahPada']) === [];

            if ($status !== StatusPenyesuaianStok::Draf && ! ($hanyaNilai && $status === StatusPenyesuaianStok::MenungguPersetujuan)) {
                throw new LogicException('Baris penyesuaian stok hanya bisa diubah selama dokumennya masih Draf.');
            }
        };

        self::updating($jaga);
        self::deleting(function (self $baris): void {
            $nilai = PenyesuaianStok::query()->whereKey($baris->IdPenyesuaianStok)->sharedLock()->value('Status');
            $status = $nilai instanceof StatusPenyesuaianStok ? $nilai : StatusPenyesuaianStok::tryFrom((string) $nilai);

            if ($status !== StatusPenyesuaianStok::Draf) {
                throw new LogicException('Baris penyesuaian stok hanya bisa dihapus selama dokumennya masih Draf.');
            }
        });
    }

    /**
     * @return BelongsTo<PenyesuaianStok, $this>
     */
    public function PenyesuaianStok(): BelongsTo
    {
        return $this->belongsTo(PenyesuaianStok::class, 'IdPenyesuaianStok', 'Id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'Urutan' => 'integer',
            'Jumlah' => 'string',
            'HppSatuan' => 'string',
            'Nilai' => 'string',
            'TanggalKedaluwarsa' => 'date',
        ];
    }
}
