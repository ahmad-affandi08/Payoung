<?php

declare(strict_types=1);

namespace App\Domain\Persediaan\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Tenant\MilikTenant;
use App\Domain\Persediaan\Enum\StatusTransferStok;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Dokumen transfer stok antar lokasi stok (F-05b). Dokumen transaksi: tanpa soft delete dan tanpa hapus. Status hanya
 * berubah lewat `UbahStatus()` dan dicatat di `RiwayatStatusDokumen` oleh Aksi. `IdOutletAsal`/`IdOutletTujuan` =
 * snapshot outlet lokasi stok (batas akses). Setelah keluar dari Draf, isi dokumen tidak berubah; yang berubah hanya
 * kolom kirim/terima/tutup. Diterima & Dibatalkan final.
 *
 * @property int $Id
 * @property string $Uuid
 * @property int $IdTenant
 * @property string|null $Nomor
 * @property int $IdGudangAsal
 * @property int|null $IdOutletAsal
 * @property int $IdGudangTujuan
 * @property int|null $IdOutletTujuan
 * @property int|null $IdGudangTransit
 * @property int|null $IdPermintaanStok
 * @property Carbon $Tanggal
 * @property StatusTransferStok $Status
 * @property string|null $Catatan
 * @property int $JumlahBaris
 * @property int $JumlahPenerimaan
 * @property string $TotalNilaiKirim
 * @property string $TotalNilaiDiterima
 * @property string $TotalNilaiSusut
 * @property string|null $AlasanSelisih
 * @property int|null $DibuatOleh
 * @property int|null $DiubahOleh
 * @property int|null $DikirimOleh
 * @property int|null $DitutupOleh
 * @property int|null $DibatalkanOleh
 * @property Carbon|null $DikirimPada
 * @property Carbon|null $DiterimaPada
 * @property Carbon|null $DitutupPada
 * @property Carbon|null $DibatalkanPada
 * @property string|null $AlasanBatal
 * @property Carbon|null $DibuatPada
 * @property Carbon|null $DiubahPada
 * @property-read Collection<int, TransferStokDetail> $Detail
 */
final class TransferStok extends ModelDasar
{
    use MilikTenant;

    public const JENIS_DOKUMEN = 'TransferStok';

    protected $table = 'TransferStok';

    /** @var list<string> */
    private const KOLOM_ISI = ['IdPermintaanStok', 'IdGudangAsal', 'IdOutletAsal', 'IdGudangTujuan', 'IdOutletTujuan', 'Catatan', 'JumlahBaris'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'Nomor' => null,
        'Status' => 'Draf',
        'Catatan' => null,
        'JumlahBaris' => 0,
        'JumlahPenerimaan' => 0,
        'TotalNilaiKirim' => '0.00',
        'TotalNilaiDiterima' => '0.00',
        'TotalNilaiSusut' => '0.00',
    ];

    /**
     * @return HasMany<TransferStokDetail, $this>
     */
    public function Detail(): HasMany
    {
        return $this->hasMany(TransferStokDetail::class, 'IdTransferStok', 'Id');
    }

    /** Penjaga aturan #8: tidak pernah dihapus; isi hanya berubah selama Draf; status final tidak berubah lagi. */
    protected static function booted(): void
    {
        self::updating(function (self $transfer): void {
            $asal = $transfer->getOriginal('Status');
            $asal = $asal instanceof StatusTransferStok ? $asal : StatusTransferStok::tryFrom((string) $asal);

            if ($asal === StatusTransferStok::Draf) {
                return;
            }

            if ($asal === StatusTransferStok::Diterima || $asal === StatusTransferStok::Dibatalkan
                || array_intersect(array_keys($transfer->getDirty()), self::KOLOM_ISI) !== []) {
                throw new LogicException("Transfer stok berstatus {$asal?->value} tidak bisa diubah isinya.");
            }
        });
        self::deleting(function (): void {
            throw new LogicException('Dokumen transfer stok tidak pernah dihapus; draf dibatalkan.');
        });
    }

    public function UbahStatus(StatusTransferStok $tujuan): void
    {
        if (! $this->Status->BisaBerubahKe($tujuan)) {
            throw new LogicException("Status transfer stok {$this->Status->value} tidak bisa berubah ke {$tujuan->value}.");
        }

        $this->Status = $tujuan;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'Tanggal' => 'date',
            'Status' => StatusTransferStok::class,
            'JumlahBaris' => 'integer',
            'JumlahPenerimaan' => 'integer',
            'TotalNilaiKirim' => 'string',
            'TotalNilaiDiterima' => 'string',
            'TotalNilaiSusut' => 'string',
            'DikirimPada' => 'datetime',
            'DiterimaPada' => 'datetime',
            'DitutupPada' => 'datetime',
            'DibatalkanPada' => 'datetime',
        ];
    }
}
