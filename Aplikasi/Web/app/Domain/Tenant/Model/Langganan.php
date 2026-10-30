<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Tenant\Enum\SiklusTagihan;
use App\Domain\Tenant\Enum\StatusLangganan;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Langganan satu tenant (F-00, BR-00.3, BR-00.7). Satu baris per tenant; perubahan status hanya lewat transisi sah.
 *
 * @property int $Id
 * @property string $Uuid
 * @property int $IdTenant
 * @property int $IdPaket
 * @property StatusLangganan $Status
 * @property StatusLangganan|null $StatusSebelumDitangguhkan diisi hanya saat ditangguhkan manual (P-07); null = ditangguhkan
 *                                                           karena tunggakan (P-08). Dikosongkan otomatis begitu keluar dari Ditangguhkan.
 * @property Carbon|null $TrialBerakhirPada
 * @property Carbon|null $PeriodeMulai
 * @property Carbon|null $PeriodeSelesai
 * @property SiklusTagihan $SiklusTagihan
 * @property int|null $JumlahBulanSiklus Terisi bila lama selain 1/12 bulan (`SiklusTagihan` = Kustom); kosong = ikuti siklus lama
 * @property-read Paket $Paket
 * @property-read Tenant $Tenant
 */
final class Langganan extends ModelDasar
{
    protected $table = 'Langganan';

    /** @var array<string, mixed> */
    protected $attributes = [
        'StatusSebelumDitangguhkan' => null,
        'TrialBerakhirPada' => null,
        'PeriodeMulai' => null,
        'PeriodeSelesai' => null,
        'SiklusTagihan' => 'Bulanan',
        'JumlahBulanSiklus' => null,
    ];

    protected static function booted(): void
    {
        self::updating(static function (Langganan $langganan): void {
            $asal = $langganan->getOriginal('Status');

            if ($langganan->isDirty('Status') && $asal instanceof StatusLangganan && ! $asal->BisaBerubahKe($langganan->Status)) {
                throw new LogicException("Langganan {$asal->value} tidak bisa berubah menjadi {$langganan->Status->value} (BR-00.7).");
            }

            // Status asal penangguhan manual hanya bermakna selama masih Ditangguhkan (BR-P07.5). Jalur apa pun yang
            // mengeluarkan langganan dari Ditangguhkan (aktifkan kembali, pembayaran diterima) wajib mengosongkannya,
            // agar penangguhan otomatis berikutnya tidak memulihkan status lama yang sudah basi.
            if ($langganan->Status !== StatusLangganan::Ditangguhkan) {
                $langganan->StatusSebelumDitangguhkan = null;
            }
        });
    }

    /**
     * Ditangguhkan manual oleh Super Admin (penipuan, penyalahgunaan, permintaan hukum). Penangguhan ini hanya bisa
     * dicabut lewat "Aktifkan kembali" P-07, bukan lewat tagihan atau pembayaran (BR-P07.4).
     */
    public function CekDitangguhkanManual(): bool
    {
        return $this->Status === StatusLangganan::Ditangguhkan && $this->StatusSebelumDitangguhkan !== null;
    }

    /** Lama satu periode langganan dalam bulan (D-249): angka tersimpan, atau ikut siklus lama (Bulanan 1, Tahunan 12). */
    public function AmbilJumlahBulanSiklus(): int
    {
        return $this->JumlahBulanSiklus ?? $this->SiklusTagihan->JumlahBulanBawaan() ?? 1;
    }

    /**
     * @return BelongsTo<Paket, $this>
     */
    public function Paket(): BelongsTo
    {
        return $this->belongsTo(Paket::class, 'IdPaket', 'Id');
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function Tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'IdTenant', 'Id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'Status' => StatusLangganan::class,
            'StatusSebelumDitangguhkan' => StatusLangganan::class,
            'TrialBerakhirPada' => 'datetime',
            'PeriodeMulai' => 'datetime',
            'PeriodeSelesai' => 'datetime',
            'SiklusTagihan' => SiklusTagihan::class,
            'JumlahBulanSiklus' => 'integer',
        ];
    }
}
