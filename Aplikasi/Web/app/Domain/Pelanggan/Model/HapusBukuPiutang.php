<?php

declare(strict_types=1);

namespace App\Domain\Pelanggan\Model;

use App\Domain\Bersama\Dokumen\Enum\StatusDokumenTerposting;
use App\Domain\Bersama\Dokumen\Model\JagaDokumenTerposting;
use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Bersama\Tenant\MilikTenant;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Hapus-buku piutang `HB/{YYYY}/{MM}/{SEQ4}` (E8): sisa piutang yang tidak akan tertagih dibukukan sebagai beban.
 * Dokumen langsung diposting; koreksi hanya lewat pembatalan (jurnal pembalik).
 *
 * @property int $Id
 * @property string $Uuid
 * @property int $IdTenant
 * @property string $Nomor
 * @property int $IdPiutang
 * @property int|null $IdPelanggan
 * @property int $IdOutlet
 * @property string $NomorPiutang
 * @property Carbon $Tanggal
 * @property string $Jumlah
 * @property string $Alasan
 * @property StatusDokumenTerposting $Status
 * @property int|null $IdPiutangAktif
 * @property int|null $IdJurnal
 * @property int|null $IdJurnalPembatalan
 * @property int|null $DibuatOleh
 * @property int|null $DiubahOleh
 * @property int|null $DibatalkanOleh
 * @property Carbon|null $DibatalkanPada
 * @property string|null $AlasanBatal
 * @property Carbon|null $DibuatPada
 */
final class HapusBukuPiutang extends ModelDasar
{
    use JagaDokumenTerposting;
    use MilikTenant;

    public const JENIS_DOKUMEN = 'HapusBukuPiutang';

    /** Kolom yang boleh berubah setelah diposting: pembatalan, pelepasan klaim piutang, dan hasil posting sendiri. */
    private const KOLOM_STATUS = [
        'Status', 'IdPiutangAktif', 'IdJurnalPembatalan', 'DibatalkanOleh', 'DibatalkanPada', 'AlasanBatal', 'DiubahOleh', 'IdJurnal',
    ];

    protected $table = 'HapusBukuPiutang';

    /** @var array<string, mixed> */
    protected $attributes = ['Status' => 'Diposting', 'IdPelanggan' => null];

    /**
     * @return list<string>
     */
    public function AmbilKolomBolehBerubah(): array
    {
        return self::KOLOM_STATUS;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['Tanggal' => 'date', 'Jumlah' => 'decimal:2', 'Status' => StatusDokumenTerposting::class, 'DibatalkanPada' => 'datetime'];
    }

    /**
     * @throws LogicException bila perpindahan status tidak diizinkan
     */
    public function UbahStatus(StatusDokumenTerposting $tujuan): void
    {
        if (! $this->Status->BisaBerubahKe($tujuan)) {
            throw new LogicException("Status hapus-buku piutang {$this->Status->value} tidak bisa berubah ke {$tujuan->value}.");
        }

        $this->Status = $tujuan;
    }

    public function AmbilJumlah(): Uang
    {
        return Uang::Dari($this->Jumlah);
    }
}
