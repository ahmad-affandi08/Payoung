<?php

declare(strict_types=1);

namespace App\Domain\Pembelian\Model;

use App\Domain\Bersama\Dokumen\Enum\StatusDokumenTerposting;
use App\Domain\Bersama\Dokumen\Model\JagaDokumenTerposting;
use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Tenant\MilikTenant;
use App\Domain\Pembelian\Enum\JenisNotaDebit;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Nota debit pembelian `ND/{YYMM}/{SEQ4}` (v6.07, F-04 BR-04.10): pengurang hutang atas satu faktur pembelian karena
 * selisih harga atau klaim pemasok tanpa barang kembali. `NilaiKlaim` + `Pajak` (PPN masukan kontra) = `Total` =
 * `PotongHutang` + `NilaiRefund`. `KePersediaan` menurunkan nilai stok yang masih ada, `KeHpp` mengurangi HPP barang
 * yang sudah terjual. Langsung dicatat; pembatalan = jurnal & mutasi pembalik.
 *
 * @property int $Id
 * @property string $Uuid
 * @property int $IdTenant
 * @property string $Nomor
 * @property string|null $NomorNotaPemasok
 * @property int $IdFakturPembelian
 * @property int|null $IdPemasok
 * @property int|null $IdOutlet
 * @property JenisNotaDebit $Jenis
 * @property Carbon $Tanggal
 * @property string $NilaiKlaim
 * @property string $Pajak
 * @property string $Total
 * @property string $PotongHutang
 * @property string $NilaiRefund
 * @property int|null $IdAkunRefund
 * @property string $KePersediaan
 * @property string $KeHpp
 * @property string $Alasan
 * @property StatusDokumenTerposting $Status
 * @property int|null $IdJurnal
 * @property int|null $IdJurnalPembatalan
 * @property int|null $DibuatOleh
 * @property int|null $DibatalkanOleh
 * @property Carbon|null $DibatalkanPada
 * @property string|null $AlasanBatal
 * @property Carbon|null $DibuatPada
 * @property Carbon|null $DiubahPada
 * @property-read Collection<int, NotaDebitPembelianDetail> $Detail
 */
final class NotaDebitPembelian extends ModelDasar
{
    use JagaDokumenTerposting;
    use MilikTenant;

    public const JENIS_DOKUMEN = 'NotaDebitPembelian';

    protected $table = 'NotaDebitPembelian';

    /** @var array<string, mixed> */
    protected $attributes = [
        'Status' => 'Diposting',
        'NomorNotaPemasok' => null,
        'IdPemasok' => null,
        'Pajak' => '0.00',
        'NilaiRefund' => '0.00',
        'IdAkunRefund' => null,
        'KePersediaan' => '0.00',
        'KeHpp' => '0.00',
        'IdJurnal' => null,
        'IdJurnalPembatalan' => null,
    ];

    /**
     * @return list<string>
     */
    public function AmbilKolomBolehBerubah(): array
    {
        return ['Status', 'KePersediaan', 'KeHpp', 'IdJurnal', 'IdJurnalPembatalan', 'DibatalkanOleh', 'DibatalkanPada', 'AlasanBatal'];
    }

    /**
     * @throws LogicException bila perpindahan status tidak diizinkan
     */
    public function UbahStatus(StatusDokumenTerposting $tujuan): void
    {
        if (! $this->Status->BisaBerubahKe($tujuan)) {
            throw new LogicException("Status nota debit pembelian {$this->Status->value} tidak bisa berubah ke {$tujuan->value}.");
        }

        $this->Status = $tujuan;
    }

    /**
     * @return HasMany<NotaDebitPembelianDetail, $this>
     */
    public function Detail(): HasMany
    {
        return $this->hasMany(NotaDebitPembelianDetail::class, 'IdNotaDebitPembelian', 'Id')->orderBy('Urutan');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'Tanggal' => 'date',
            'Status' => StatusDokumenTerposting::class,
            'Jenis' => JenisNotaDebit::class,
            'DibatalkanPada' => 'datetime',
        ];
    }
}
