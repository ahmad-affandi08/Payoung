<?php

declare(strict_types=1);

namespace App\Domain\PanduanAwal\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Tenant\MilikTenant;
use App\Domain\PanduanAwal\Enum\LangkahPanduan;
use App\Domain\PanduanAwal\Enum\StatusLangkahPanduan;
use Illuminate\Support\Carbon;

/**
 * Progres wizard panduan awal satu tenant (F-01; tabel baru, dicatat ke §15). Satu baris per tenant.
 * `StatusLangkah` = {LangkahPanduan: {Status, Pada}}; langkah tanpa entri = Belum.
 *
 * @property int $Id
 * @property int $IdTenant
 * @property int|null $IdOutlet
 * @property bool $Wajib D-24: tenant baru wajib menyelesaikan panduan sebelum membuka back-office.
 * @property array<string, array{Status: string, Pada: string}>|null $StatusLangkah
 * @property Carbon|null $SelesaiPada
 * @property int|null $IdPenggunaPenyelesai
 */
final class ProgresPanduanAwal extends ModelDasar
{
    use MilikTenant;

    protected $table = 'ProgresPanduanAwal';

    protected bool $pakaiUuid = false;

    /** @var array<string, mixed> */
    protected $attributes = ['IdOutlet' => null, 'Wajib' => false, 'StatusLangkah' => null, 'SelesaiPada' => null, 'IdPenggunaPenyelesai' => null];

    public function AmbilStatus(LangkahPanduan $langkah): StatusLangkahPanduan
    {
        $status = $this->StatusLangkah[$langkah->value]['Status'] ?? null;

        return is_string($status) ? (StatusLangkahPanduan::tryFrom($status) ?? StatusLangkahPanduan::Belum) : StatusLangkahPanduan::Belum;
    }

    /**
     * D-24: langkah wajib (semua kecuali Perangkat) yang belum Selesai.
     *
     * @return list<LangkahPanduan>
     */
    public function AmbilLangkahWajibBelumSelesai(bool $tanpaKasir = false): array
    {
        return array_values(array_filter(
            LangkahPanduan::AmbilBerlaku($tanpaKasir),
            fn (LangkahPanduan $langkah): bool => $langkah->CekWajib() && $this->AmbilStatus($langkah) !== StatusLangkahPanduan::Selesai,
        ));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['StatusLangkah' => 'array', 'SelesaiPada' => 'datetime', 'Wajib' => 'boolean'];
    }
}
