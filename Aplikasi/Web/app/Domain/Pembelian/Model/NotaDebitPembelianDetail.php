<?php

declare(strict_types=1);

namespace App\Domain\Pembelian\Model;

use App\Domain\Bersama\Dokumen\Model\JagaDokumenTerposting;
use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Tenant\MilikTenant;
use Illuminate\Support\Carbon;

/**
 * Bagian nota debit pembelian pada satu baris faktur (v6.07): `NilaiKlaim` = `KePersediaan` + `KeHpp`; `Pajak` = PPN
 * masukan kontra sebanding tarif efektif baris faktur itu.
 *
 * @property int $Id
 * @property int $IdTenant
 * @property int $IdNotaDebitPembelian
 * @property int $Urutan
 * @property int $IdFakturPembelianDetail
 * @property int $IdProduk
 * @property string $NamaProduk
 * @property string $NilaiKlaim
 * @property string $Pajak
 * @property string $KePersediaan
 * @property string $KeHpp
 * @property Carbon|null $DibuatPada
 * @property Carbon|null $DiubahPada
 */
final class NotaDebitPembelianDetail extends ModelDasar
{
    use JagaDokumenTerposting;
    use MilikTenant;

    protected $table = 'NotaDebitPembelianDetail';

    protected bool $pakaiUuid = false;

    /**
     * @return list<string>
     */
    public function AmbilKolomBolehBerubah(): array
    {
        return [];
    }
}
