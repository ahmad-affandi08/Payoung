<?php

declare(strict_types=1);

namespace App\Domain\Dukungan\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Tenant\MilikTenant;
use App\Domain\Dukungan\Enum\KanalTiketDukungan;
use App\Domain\Dukungan\Enum\KategoriTiketDukungan;
use App\Domain\Dukungan\Enum\PrioritasTiketDukungan;
use App\Domain\Dukungan\Enum\StatusTiketDukungan;
use App\Domain\Dukungan\Enum\TingkatEskalasiTiket;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Tiket dukungan milik satu tenant (P-09, PRD §15.3). Perubahan status hanya lewat transisi sah
 * (`StatusTiketDukungan::BisaBerubahKe`). Tim internal mengaksesnya lintas tenant hanya lewat KonteksPengelola.
 *
 * @property int $Id
 * @property string $Uuid
 * @property int $IdTenant
 * @property string $Nomor
 * @property int $IdPelapor
 * @property KanalTiketDukungan $Kanal
 * @property KategoriTiketDukungan $Kategori
 * @property PrioritasTiketDukungan $Prioritas
 * @property StatusTiketDukungan $Status
 * @property string $Judul
 * @property int|null $IdPenanggungJawab
 * @property TingkatEskalasiTiket $TingkatEskalasi
 * @property Carbon|null $DieskalasiPada
 * @property int $JamSla
 * @property bool $SlaJamKalender true = jam kalender (tiket mendesak paket berbayar, tiket lama); false = jam kerja
 * @property Carbon $BatasSlaPada
 * @property Carbon|null $ResponsPertamaPada
 * @property Carbon|null $PesanTerakhirPada
 * @property Carbon|null $DiselesaikanPada
 * @property Carbon|null $DitutupPada
 * @property array<string, mixed>|null $Konteks
 * @property Carbon $DibuatPada
 * @property Carbon $DiubahPada
 * @property-read Collection<int, TiketDukunganPesan> $Pesan
 */
final class TiketDukungan extends ModelDasar
{
    use MilikTenant;

    protected $table = 'TiketDukungan';

    /** @var array<string, mixed> */
    protected $attributes = [
        'Kanal' => 'BackOffice',
        'Status' => 'Baru',
        'IdPenanggungJawab' => null,
        'SlaJamKalender' => true,
        'TingkatEskalasi' => 'L1',
        'DieskalasiPada' => null,
        'ResponsPertamaPada' => null,
        'PesanTerakhirPada' => null,
        'DiselesaikanPada' => null,
        'DitutupPada' => null,
        'Konteks' => null,
    ];

    protected static function booted(): void
    {
        self::updating(static function (TiketDukungan $tiket): void {
            $asal = $tiket->getOriginal('Status');

            if ($tiket->isDirty('Status') && $asal instanceof StatusTiketDukungan && ! $asal->BisaBerubahKe($tiket->Status)) {
                throw new LogicException("Tiket {$asal->value} tidak bisa berubah menjadi {$tiket->Status->value} (P-09).");
            }
        });
    }

    /**
     * @return HasMany<TiketDukunganPesan, $this>
     */
    public function Pesan(): HasMany
    {
        return $this->hasMany(TiketDukunganPesan::class, 'IdTiketDukungan', 'Id');
    }

    /** Belum ada respons pertama tim dan batas SLA sudah lewat, atau respons pertama datang terlambat. */
    public function CekLewatSla(?Carbon $saatIni = null): bool
    {
        $saatIni ??= now();

        return $this->ResponsPertamaPada === null
            ? $this->Status->CekTerbuka() && $saatIni->gt($this->BatasSlaPada)
            : $this->ResponsPertamaPada->gt($this->BatasSlaPada);
    }

    /** P-09: tiket `Selesai` masih bisa dibuka lagi selama batas hari buka ulang belum lewat. */
    public function CekBisaDibukaLagi(?Carbon $saatIni = null): bool
    {
        return $this->Status === StatusTiketDukungan::Selesai
            && $this->DiselesaikanPada !== null
            && ($saatIni ?? now())->lte($this->DiselesaikanPada->copy()->addDays((int) config('dukungan.HariBukaUlang')));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'Kanal' => KanalTiketDukungan::class,
            'Kategori' => KategoriTiketDukungan::class,
            'Prioritas' => PrioritasTiketDukungan::class,
            'Status' => StatusTiketDukungan::class,
            'JamSla' => 'integer',
            'SlaJamKalender' => 'boolean',
            'TingkatEskalasi' => TingkatEskalasiTiket::class,
            'DieskalasiPada' => 'datetime',
            'BatasSlaPada' => 'datetime',
            'ResponsPertamaPada' => 'datetime',
            'PesanTerakhirPada' => 'datetime',
            'DiselesaikanPada' => 'datetime',
            'DitutupPada' => 'datetime',
            'Konteks' => 'array',
        ];
    }
}
