<?php

declare(strict_types=1);

namespace App\Domain\Persediaan\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Bersama\Tenant\MilikTenant;
use App\Domain\Persediaan\Enum\StatusTransferStok;
use App\Domain\Persediaan\Layanan\Hpp\AritmetikaHpp;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Baris transfer stok (F-05b). `NamaProduk`/`Sku` snapshot. Jumlah dalam satuan dasar. `NilaiKirim` = nilai HPP asal
 * saat dikirim (hasil buku stok), `NilaiDiterima`/`NilaiSusut` = nilai yang keluar dari lokasi dalam perjalanan.
 * Produk batch: `IdBatchStok` (batch asal) + `IdBatchStokTransit`; produk seri: satu baris per nomor seri. Isi baris
 * (produk, jumlah kirim, batch/seri) hanya berubah selama dokumennya Draf.
 *
 * @property int $Id
 * @property int $IdTenant
 * @property int $IdTransferStok
 * @property int $Urutan
 * @property int $IdProduk
 * @property string $NamaProduk
 * @property string|null $Sku
 * @property string $JumlahDikirim
 * @property string $JumlahDiterima
 * @property string $JumlahSusut
 * @property string $JumlahDitolak
 * @property string $NilaiKirim
 * @property string $NilaiDiterima
 * @property string $NilaiSusut
 * @property string $NilaiDitolak
 * @property int|null $IdBatchStok
 * @property int|null $IdBatchStokTransit
 * @property string|null $NomorBatch
 * @property Carbon|null $TanggalKedaluwarsa
 * @property int|null $IdNomorSeri
 * @property string|null $NomorSeri
 * @property-read TransferStok $TransferStok
 */
final class TransferStokDetail extends ModelDasar
{
    use MilikTenant;

    protected $table = 'TransferStokDetail';

    protected bool $pakaiUuid = false;

    /** @var list<string> */
    protected $guarded = ['Id'];

    /** @var list<string> */
    private const KOLOM_ISI = ['IdProduk', 'JumlahDikirim', 'IdBatchStok', 'NomorBatch', 'TanggalKedaluwarsa', 'IdNomorSeri', 'NomorSeri'];

    protected static function booted(): void
    {
        $status = function (self $baris): ?StatusTransferStok {
            $nilai = TransferStok::query()->whereKey($baris->IdTransferStok)->sharedLock()->value('Status');

            return $nilai instanceof StatusTransferStok ? $nilai : StatusTransferStok::tryFrom((string) $nilai);
        };

        self::updating(function (self $baris) use ($status): void {
            if (array_intersect(array_keys($baris->getDirty()), self::KOLOM_ISI) !== [] && $status($baris) !== StatusTransferStok::Draf) {
                throw new LogicException('Isi baris transfer stok hanya bisa diubah selama dokumennya masih Draf.');
            }
        });
        self::deleting(function (self $baris) use ($status): void {
            if ($status($baris) !== StatusTransferStok::Draf) {
                throw new LogicException('Baris transfer stok hanya bisa dihapus selama dokumennya masih Draf.');
            }
        });
    }

    /** Sisa dalam perjalanan: dikirim dikurangi yang sudah diterima, disusutkan (rusak/selisih), dan ditolak (dikembalikan). */
    public function AmbilSisa(): Kuantitas
    {
        return Kuantitas::Dari($this->JumlahDikirim)
            ->Kurangi(Kuantitas::Dari($this->JumlahDiterima))
            ->Kurangi(Kuantitas::Dari($this->JumlahSusut))
            ->Kurangi(Kuantitas::Dari($this->JumlahDitolak));
    }

    /** Nilai sisa dalam perjalanan (HPP kirim yang belum keluar dari lokasi dalam perjalanan), tidak pernah negatif. */
    public function AmbilSisaNilai(): Uang
    {
        $sisa = Uang::Dari($this->NilaiKirim)
            ->Kurangi(Uang::Dari($this->NilaiDiterima))
            ->Kurangi(Uang::Dari($this->NilaiSusut))
            ->Kurangi(Uang::Dari($this->NilaiDitolak));

        return AritmetikaHpp::AmbilMaksimum($sisa, Uang::Nol());
    }

    /**
     * @return BelongsTo<TransferStok, $this>
     */
    public function TransferStok(): BelongsTo
    {
        return $this->belongsTo(TransferStok::class, 'IdTransferStok', 'Id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'Urutan' => 'integer',
            'JumlahDikirim' => 'string',
            'JumlahDiterima' => 'string',
            'JumlahSusut' => 'string',
            'JumlahDitolak' => 'string',
            'NilaiKirim' => 'string',
            'NilaiDiterima' => 'string',
            'NilaiSusut' => 'string',
            'NilaiDitolak' => 'string',
            'TanggalKedaluwarsa' => 'date',
        ];
    }
}
