<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Tenant\MilikTenant;
use App\Domain\Organisasi\Model\Outlet;
use App\Domain\Pelanggan\Model\Pelanggan;
use App\Domain\Penjualan\Enum\StatusPenawaran;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Penawaran harga `PNW/{OUTLET}/{YYMM}/{SEQ4}` (F-21 bagian 3, D-168): dokumen sebelum invoice, tanpa dampak stok atau
 * jurnal. Draf bebas diubah dan belum bernomor; setelah `Terbit` hanya kolom keputusan dan pelacakan yang boleh berubah
 * (aturan #8). Dokumen tidak pernah dihapus; yang tidak jadi dibatalkan.
 *
 * @property int $Id
 * @property string $Uuid
 * @property int $IdTenant
 * @property string|null $Nomor
 * @property string $KodePublik
 * @property StatusPenawaran $Status
 * @property int $IdPelanggan
 * @property int $IdOutlet
 * @property Carbon $Tanggal
 * @property Carbon $BerlakuSampai
 * @property int $TerminHari
 * @property string|null $Perihal
 * @property string|null $NomorReferensi
 * @property bool $HargaTermasukPajak
 * @property string|null $TarifPpn
 * @property int|null $PengaliDppPembilang
 * @property int|null $PengaliDppPenyebut
 * @property string $Subtotal
 * @property string $Diskon
 * @property string $DasarPengenaanPajak
 * @property string $Pajak
 * @property string $Total
 * @property array<string, string>|null $RincianPajak
 * @property string|null $Catatan
 * @property string|null $Syarat
 * @property Carbon|null $DiterbitkanPada
 * @property Carbon|null $TerkirimPada
 * @property Carbon|null $DilihatPada
 * @property int $JumlahDilihat
 * @property string|null $SumberKeputusan
 * @property string|null $NamaPemutus
 * @property string|null $CatatanKeputusan
 * @property Carbon|null $DiputuskanPada
 * @property int|null $IdInvoice
 * @property int|null $DibuatOleh
 * @property int|null $DiubahOleh
 * @property int|null $DibatalkanOleh
 * @property Carbon|null $DibatalkanPada
 * @property string|null $AlasanBatal
 * @property Carbon|null $DibuatPada
 * @property Carbon|null $DiubahPada
 * @property-read Pelanggan $Pelanggan
 * @property-read Outlet $Outlet
 * @property-read Collection<int, PenawaranDetail> $Detail
 */
final class Penawaran extends ModelDasar
{
    use MilikTenant;

    public const JENIS_DOKUMEN = 'Penawaran';

    public const SUMBER_PELANGGAN = 'Pelanggan';

    public const SUMBER_STAF = 'Staf';

    /** Kolom yang tetap boleh berubah setelah terbit: keputusan, tautan invoice, pembatalan, dan pelacakan kirim/lihat. */
    private const KOLOM_SETELAH_TERBIT = [
        'Status', 'DiubahOleh', 'TerkirimPada', 'DilihatPada', 'JumlahDilihat',
        'SumberKeputusan', 'NamaPemutus', 'CatatanKeputusan', 'DiputuskanPada', 'IdInvoice',
        'DibatalkanOleh', 'DibatalkanPada', 'AlasanBatal',
    ];

    protected $table = 'Penawaran';

    /** @var array<string, mixed> */
    protected $attributes = [
        'Status' => 'Draf',
        'TerminHari' => 0,
        'HargaTermasukPajak' => false,
        'JumlahDilihat' => 0,
    ];

    protected static function booted(): void
    {
        self::updating(static function (Penawaran $penawaran): void {
            if ($penawaran->getRawOriginal('Status') === StatusPenawaran::Draf->value) {
                return;
            }

            $terlarang = array_diff(array_keys($penawaran->getDirty()), [...self::KOLOM_SETELAH_TERBIT, 'DiubahPada']);

            if ($terlarang !== []) {
                throw new LogicException('Penawaran yang sudah terbit tidak bisa diubah: '.implode(', ', $terlarang).'. Batalkan lalu buat penawaran baru.');
            }
        });

        self::deleting(static function (): void {
            throw new LogicException('Penawaran tidak pernah dihapus. Batalkan saja.');
        });
    }

    /**
     * @throws LogicException bila perpindahan status tidak diizinkan
     */
    public function UbahStatus(StatusPenawaran $tujuan): void
    {
        if (! $this->Status->BisaBerubahKe($tujuan)) {
            throw new LogicException("Status penawaran {$this->Status->value} tidak bisa berubah ke {$tujuan->value}.");
        }

        $this->Status = $tujuan;
    }

    /**
     * @return BelongsTo<Pelanggan, $this>
     */
    public function Pelanggan(): BelongsTo
    {
        return $this->belongsTo(Pelanggan::class, 'IdPelanggan', 'Id');
    }

    /**
     * @return BelongsTo<Outlet, $this>
     */
    public function Outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class, 'IdOutlet', 'Id');
    }

    /**
     * @return HasMany<PenawaranDetail, $this>
     */
    public function Detail(): HasMany
    {
        return $this->hasMany(PenawaranDetail::class, 'IdPenawaran', 'Id')->orderBy('Urutan');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'Status' => StatusPenawaran::class,
            'Tanggal' => 'date',
            'BerlakuSampai' => 'date',
            'TerminHari' => 'integer',
            'HargaTermasukPajak' => 'boolean',
            'TarifPpn' => 'decimal:6',
            'PengaliDppPembilang' => 'integer',
            'PengaliDppPenyebut' => 'integer',
            'Subtotal' => 'decimal:2',
            'Diskon' => 'decimal:2',
            'DasarPengenaanPajak' => 'decimal:2',
            'Pajak' => 'decimal:2',
            'Total' => 'decimal:2',
            'RincianPajak' => 'array',
            'DiterbitkanPada' => 'datetime',
            'TerkirimPada' => 'datetime',
            'DilihatPada' => 'datetime',
            'JumlahDilihat' => 'integer',
            'DiputuskanPada' => 'datetime',
            'DibatalkanPada' => 'datetime',
        ];
    }
}
