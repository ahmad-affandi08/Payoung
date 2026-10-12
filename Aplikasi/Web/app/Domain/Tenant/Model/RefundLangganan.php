<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Bersama\Tenant\MilikTenant;
use App\Domain\Tenant\Enum\StatusRefundLangganan;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Pengajuan refund tagihan langganan yang sudah lunas (P-08, BR-P08.2, D-112). Dokumen keuangan: tidak pernah dihapus,
 * angkanya tidak berubah; hanya keputusan (status, pemutus, waktu, alasan tolak, penanda layanan diakhiri) yang diisi
 * satu kali dari `Diajukan`. Pengembalian uangnya dilakukan Keuangan di luar sistem; yang dicatat di sini keputusan dan
 * akibatnya pada tagihan, layanan, dan komisi mitra.
 *
 * @property int $Id
 * @property string $Uuid
 * @property int $IdTenant
 * @property int $IdTagihanLangganan
 * @property StatusRefundLangganan $Status
 * @property string $Jumlah
 * @property string $Alasan
 * @property string|null $CatatanPengembalian
 * @property int $IdPenggunaPengelolaPengaju
 * @property int|null $IdPenggunaPengelolaPemutus
 * @property Carbon|null $DiputuskanPada
 * @property string|null $AlasanTolak
 * @property bool $LayananDiakhiri
 * @property Carbon|null $DibuatPada
 * @property-read TagihanLangganan $TagihanLangganan
 */
final class RefundLangganan extends ModelDasar
{
    use MilikTenant;

    /** Kolom keputusan yang boleh diisi setelah pengajuan dibuat. */
    public const KOLOM_BOLEH_BERUBAH = [
        'Status', 'IdPenggunaPengelolaPemutus', 'DiputuskanPada', 'AlasanTolak', 'LayananDiakhiri', self::UPDATED_AT,
    ];

    protected $table = 'RefundLangganan';

    /** @var array<string, mixed> */
    protected $attributes = [
        'Status' => 'Diajukan',
        'CatatanPengembalian' => null,
        'IdPenggunaPengelolaPemutus' => null,
        'DiputuskanPada' => null,
        'AlasanTolak' => null,
        'LayananDiakhiri' => false,
    ];

    protected static function booted(): void
    {
        self::updating(static function (RefundLangganan $refund): void {
            $kolomBerubah = array_diff(array_keys($refund->getDirty()), self::KOLOM_BOLEH_BERUBAH);

            if ($kolomBerubah !== []) {
                throw new LogicException('Angka refund langganan tidak boleh diubah setelah diajukan: '.implode(', ', $kolomBerubah).'.');
            }

            if ($refund->isDirty('Status') && $refund->getOriginal('Status') !== StatusRefundLangganan::Diajukan) {
                throw new LogicException('Refund yang sudah diputuskan tidak bisa diubah lagi.');
            }
        });

        self::deleting(static function (): void {
            throw new LogicException('Refund langganan tidak boleh dihapus.');
        });
    }

    /**
     * @return BelongsTo<TagihanLangganan, $this>
     */
    public function TagihanLangganan(): BelongsTo
    {
        return $this->belongsTo(TagihanLangganan::class, 'IdTagihanLangganan', 'Id');
    }

    public function AmbilJumlah(): Uang
    {
        return Uang::Dari($this->Jumlah);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'Status' => StatusRefundLangganan::class,
            'Jumlah' => 'decimal:2',
            'DiputuskanPada' => 'datetime',
            'LayananDiakhiri' => 'boolean',
        ];
    }
}
