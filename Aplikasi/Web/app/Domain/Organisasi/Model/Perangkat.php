<?php

declare(strict_types=1);

namespace App\Domain\Organisasi\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Tenant\MilikTenant;
use App\Domain\Organisasi\Enum\JenisPerangkat;
use App\Domain\Organisasi\Enum\PlatformPerangkat;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Perangkat POS terdaftar (PRD §15.3, F-02 langkah 5). Satu instalasi aplikasi = satu perangkat.
 *
 * - `Kode` (`{KodeOutlet}-{Huruf}{NN}`) unik per tenant dan tidak pernah dipakai ulang, juga setelah dicabut.
 * - Status: belum diaktifkan (`DiaktifkanPada` kosong), aktif, atau dicabut (`DicabutPada` terisi, final).
 * - `HashToken` = SHA-256 token perangkat; tetap disimpan setelah dicabut agar permintaan dari perangkat itu bisa
 *   dijawab `PerangkatDicabut` (BR-02.3), bukan sekadar token tidak dikenal.
 *
 * @property int $Id
 * @property string $Uuid
 * @property int $IdTenant
 * @property int $IdOutlet
 * @property string $Kode
 * @property string $Nama
 * @property JenisPerangkat $Jenis
 * @property PlatformPerangkat|null $Platform
 * @property string|null $VersiOs
 * @property string|null $VersiAplikasi
 * @property string|null $VersiSkemaSinkron
 * @property string|null $TokenPush
 * @property array<string, mixed>|null $ProfilHardware
 * @property string|null $HashToken
 * @property string|null $KunciPinOffline
 * @property string|null $AwalanAntrian K26: awalan huruf nomor antrian (maks. 2 huruf besar, unik per outlet), null = nomor polos
 * @property Carbon|null $DiaktifkanPada
 * @property Carbon|null $TerakhirAktifPada
 * @property int $JumlahOutboxTertunda
 * @property Carbon|null $OutboxTertundaSejak
 * @property Carbon|null $MintaLogPada
 * @property Carbon|null $LogDiunggahPada
 * @property Carbon|null $DicabutPada
 * @property Carbon $DibuatPada
 * @property-read Outlet $Outlet
 */
final class Perangkat extends ModelDasar
{
    use MilikTenant;

    /** PGL-16: permintaan unggah log dari dukungan berlaku sekian hari sejak diminta. */
    public const HARI_PERMINTAAN_LOG = 7;

    protected $table = 'Perangkat';

    /** @var list<string> */
    protected $hidden = ['HashToken', 'TokenPush', 'KunciPinOffline'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'Platform' => null,
        'VersiOs' => null,
        'VersiAplikasi' => null,
        'VersiSkemaSinkron' => null,
        'TokenPush' => null,
        'ProfilHardware' => null,
        'HashToken' => null,
        'DiaktifkanPada' => null,
        'TerakhirAktifPada' => null,
        'JumlahOutboxTertunda' => 0,
        'OutboxTertundaSejak' => null,
        'MintaLogPada' => null,
        'LogDiunggahPada' => null,
        'DicabutPada' => null,
        'AwalanAntrian' => null,
    ];

    public static function BuatHashToken(string $rahasia): string
    {
        return hash('sha256', $rahasia);
    }

    public function CekDicabut(): bool
    {
        return $this->DicabutPada !== null;
    }

    /** Dukungan meminta log, perangkat belum menjawab, dan permintaannya belum kedaluwarsa (PGL-16 bagian 2). */
    public function CekPermintaanLogBerjalan(): bool
    {
        $diminta = $this->MintaLogPada;

        if ($diminta === null || $diminta->lt(now()->subDays(self::HARI_PERMINTAAN_LOG))) {
            return false;
        }

        return $this->LogDiunggahPada === null || $this->LogDiunggahPada->lt($diminta);
    }

    public function AmbilStatus(): string
    {
        return match (true) {
            $this->DicabutPada !== null => 'Dicabut',
            $this->DiaktifkanPada !== null => 'Aktif',
            default => 'BelumDiaktifkan',
        };
    }

    /**
     * @return BelongsTo<Outlet, $this>
     */
    public function Outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class, 'IdOutlet', 'Id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'Jenis' => JenisPerangkat::class,
            'KunciPinOffline' => 'encrypted',
            'Platform' => PlatformPerangkat::class,
            'ProfilHardware' => 'array',
            'DiaktifkanPada' => 'datetime',
            'TerakhirAktifPada' => 'datetime',
            'JumlahOutboxTertunda' => 'integer',
            'OutboxTertundaSejak' => 'datetime',
            'MintaLogPada' => 'datetime',
            'LogDiunggahPada' => 'datetime',
            'DicabutPada' => 'datetime',
        ];
    }
}
