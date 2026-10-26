<?php

declare(strict_types=1);

namespace App\Domain\Persediaan\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Tenant\MilikTenant;
use App\Domain\Persediaan\Enum\StatusStokAwal;
use App\Domain\Persediaan\Enum\SumberStokAwal;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Dokumen stok awal per lokasi stok (F-05a, DesainF05a B.2/C.6). Dokumen transaksi: tanpa soft delete dan tanpa
 * hapus (status Dibuang menggantikan hapus draf). Status hanya berubah lewat `UbahStatus()`
 * (`StatusStokAwal::BisaBerubahKe`) dan dicatat di `RiwayatStatusDokumen` oleh Aksi. `IdOutlet` = snapshot
 * `Gudang.IdOutlet`. `Nomor` diberikan saat posting.
 *
 * @property int $Id
 * @property string $Uuid
 * @property int $IdTenant
 * @property string|null $Nomor
 * @property int $IdGudang
 * @property int|null $IdOutlet
 * @property Carbon $Tanggal
 * @property StatusStokAwal $Status
 * @property SumberStokAwal $Sumber
 * @property int|null $IdImporStokAwal
 * @property string|null $Catatan
 * @property int $JumlahBaris
 * @property string $TotalNilai
 * @property int|null $IdJurnal
 * @property int|null $IdJurnalPembatalan
 * @property string|null $PesanGalat
 * @property int|null $DibuatOleh
 * @property int|null $DiubahOleh
 * @property int|null $DipostingOleh
 * @property int|null $DibatalkanOleh
 * @property Carbon|null $DipostingPada
 * @property Carbon|null $DibatalkanPada
 * @property string|null $AlasanBatal
 * @property Carbon|null $DibuatPada
 * @property Carbon|null $DiubahPada
 * @property-read Collection<int, StokAwalDetail> $Detail
 * @property-read ImporStokAwal|null $ImporStokAwal
 */
final class StokAwal extends ModelDasar
{
    use MilikTenant;

    /** Nilai `RiwayatStatusDokumen.JenisDokumen` untuk dokumen ini. */
    public const JENIS_DOKUMEN = 'StokAwal';

    protected $table = 'StokAwal';

    /**
     * Kolom yang boleh berubah per status asal (null = Draf, bebas diubah lewat `SimpanStokAwal`).
     *
     * @var array<string, list<string>>
     */
    private const KOLOM_BOLEH_BERUBAH = [
        'Memproses' => ['Status', 'Nomor', 'IdJurnal', 'TotalNilai', 'PesanGalat', 'DipostingOleh', 'DipostingPada', 'DiubahOleh', 'DiubahPada'],
        'Diposting' => ['Status', 'IdJurnalPembatalan', 'AlasanBatal', 'DibatalkanOleh', 'DibatalkanPada', 'DiubahOleh', 'DiubahPada'],
        'Dibatalkan' => [],
        'Dibuang' => [],
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'Nomor' => null,
        'Status' => 'Draf',
        'Sumber' => 'Manual',
        'IdImporStokAwal' => null,
        'Catatan' => null,
        'JumlahBaris' => 0,
        'TotalNilai' => '0.00',
        'IdJurnal' => null,
        'IdJurnalPembatalan' => null,
        'PesanGalat' => null,
        'AlasanBatal' => null,
    ];

    /**
     * @return HasMany<StokAwalDetail, $this>
     */
    public function Detail(): HasMany
    {
        return $this->hasMany(StokAwalDetail::class, 'IdStokAwal', 'Id');
    }

    /**
     * @return BelongsTo<ImporStokAwal, $this>
     */
    public function ImporStokAwal(): BelongsTo
    {
        return $this->belongsTo(ImporStokAwal::class, 'IdImporStokAwal', 'Id');
    }

    /**
     * Penjaga aturan #8 di tingkat model: dokumen tidak pernah dihapus, dan setelah keluar dari Draf hanya kolom
     * perpindahan status berikutnya yang boleh berubah (Memproses → Diposting/Draf; Diposting → Dibatalkan). Dibatalkan
     * dan Dibuang tidak bisa diubah lagi.
     */
    protected static function booted(): void
    {
        self::updating(function (self $stokAwal): void {
            $asal = $stokAwal->getOriginal('Status');
            $boleh = self::KOLOM_BOLEH_BERUBAH[$asal instanceof StatusStokAwal ? $asal->value : (string) $asal] ?? null;

            if ($boleh === null) {
                return;
            }

            $terlarang = array_diff(array_keys($stokAwal->getDirty()), $boleh);

            if ($terlarang !== []) {
                throw new LogicException('Stok awal berstatus '.($asal instanceof StatusStokAwal ? $asal->value : (string) $asal).' tidak bisa diubah: '.implode(', ', $terlarang).'.');
            }
        });
        self::deleting(function (): void {
            throw new LogicException('Dokumen stok awal tidak pernah dihapus. Draf dibuang (status Dibuang).');
        });
    }

    /**
     * @throws LogicException bila perpindahan status tidak diizinkan
     */
    public function UbahStatus(StatusStokAwal $tujuan): void
    {
        if (! $this->Status->BisaBerubahKe($tujuan)) {
            throw new LogicException("Status stok awal {$this->Status->value} tidak bisa berubah ke {$tujuan->value}.");
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
            'Status' => StatusStokAwal::class,
            'Sumber' => SumberStokAwal::class,
            'JumlahBaris' => 'integer',
            'TotalNilai' => 'string',
            'DipostingPada' => 'datetime',
            'DibatalkanPada' => 'datetime',
        ];
    }
}
