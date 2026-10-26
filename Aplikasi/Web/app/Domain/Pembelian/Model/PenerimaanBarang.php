<?php

declare(strict_types=1);

namespace App\Domain\Pembelian\Model;

use App\Domain\Bersama\Dokumen\Enum\StatusDokumenTerposting;
use App\Domain\Bersama\Dokumen\Model\JagaDokumenTerposting;
use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Tenant\MilikTenant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Penerimaan barang (GRN) `GR/{OUTLET}/{YYMM}/{SEQ4}` (F-04 fase 1), langsung dicatat saat disimpan: mutasi stok
 * `PenerimaanPembelian` + jurnal J-04.1 (atau J-04.3 untuk belanja stok). Tidak pernah diubah; hanya pembatalan
 * (pembalik) dan penautan ke faktur (`IdFakturPembelian`) yang mengubah baris ini. `PathLampiran` tidak pernah dikirim
 * ke browser.
 *
 * @property int $Id
 * @property string $Uuid
 * @property int $IdTenant
 * @property string $Nomor
 * @property int|null $IdPesananPembelian
 * @property int|null $IdPemasok
 * @property int $IdGudang
 * @property int|null $IdOutlet
 * @property Carbon $Tanggal
 * @property StatusDokumenTerposting $Status
 * @property string|null $NomorSuratJalan
 * @property string|null $Catatan
 * @property int $TerminHari
 * @property bool $Pkp
 * @property string|null $TarifPpn
 * @property int|null $PengaliDppPembilang
 * @property int|null $PengaliDppPenyebut
 * @property bool $PpnDikreditkan
 * @property string $Subtotal
 * @property string $Ongkir
 * @property string $Pajak
 * @property string $TotalNilai
 * @property bool $BelanjaStok
 * @property int|null $IdFakturPembelian
 * @property int|null $IdJurnal
 * @property int|null $IdJurnalPembatalan
 * @property string|null $PathLampiran
 * @property string|null $NamaLampiran
 * @property string|null $MimeLampiran
 * @property int|null $UkuranLampiran
 * @property int|null $DibuatOleh
 * @property int|null $DibatalkanOleh
 * @property Carbon|null $DibatalkanPada
 * @property string|null $AlasanBatal
 * @property Carbon|null $DibuatPada
 * @property Carbon|null $DiubahPada
 * @property-read Collection<int, PenerimaanBarangDetail> $Detail
 */
final class PenerimaanBarang extends ModelDasar
{
    use JagaDokumenTerposting;
    use MilikTenant;

    public const JENIS_DOKUMEN = 'PenerimaanBarang';

    protected $table = 'PenerimaanBarang';

    /** @var list<string> */
    protected $hidden = ['PathLampiran'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'Status' => 'Diposting',
        'IdPesananPembelian' => null,
        'IdPemasok' => null,
        'NomorSuratJalan' => null,
        'Catatan' => null,
        'TarifPpn' => null,
        'PengaliDppPembilang' => null,
        'PengaliDppPenyebut' => null,
        'PpnDikreditkan' => false,
        'BelanjaStok' => false,
        'IdFakturPembelian' => null,
        'IdJurnal' => null,
        'IdJurnalPembatalan' => null,
        'PathLampiran' => null,
        'NamaLampiran' => null,
        'MimeLampiran' => null,
        'UkuranLampiran' => null,
    ];

    /**
     * @return list<string>
     */
    public function AmbilKolomBolehBerubah(): array
    {
        return ['Status', 'IdJurnal', 'IdFakturPembelian', 'IdJurnalPembatalan', 'DibatalkanOleh', 'DibatalkanPada', 'AlasanBatal'];
    }

    /**
     * @throws LogicException bila perpindahan status tidak diizinkan
     */
    public function UbahStatus(StatusDokumenTerposting $tujuan): void
    {
        if (! $this->Status->BisaBerubahKe($tujuan)) {
            throw new LogicException("Status penerimaan barang {$this->Status->value} tidak bisa berubah ke {$tujuan->value}.");
        }

        $this->Status = $tujuan;
    }

    /**
     * @return HasMany<PenerimaanBarangDetail, $this>
     */
    public function Detail(): HasMany
    {
        return $this->hasMany(PenerimaanBarangDetail::class, 'IdPenerimaanBarang', 'Id')->orderBy('Urutan');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'Tanggal' => 'date',
            'Status' => StatusDokumenTerposting::class,
            'TerminHari' => 'integer',
            'Pkp' => 'boolean',
            'PpnDikreditkan' => 'boolean',
            'BelanjaStok' => 'boolean',
            'PengaliDppPembilang' => 'integer',
            'PengaliDppPenyebut' => 'integer',
            'UkuranLampiran' => 'integer',
            'DibatalkanPada' => 'datetime',
        ];
    }
}
