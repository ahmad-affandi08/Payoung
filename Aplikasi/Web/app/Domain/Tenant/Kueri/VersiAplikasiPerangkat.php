<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Kueri;

use App\Domain\Tenant\Enum\AplikasiRilis;
use App\Domain\Tenant\Enum\KanalRilis;
use App\Domain\Tenant\Enum\PenandaTenant;
use App\Domain\Tenant\Enum\StatusRilis;
use App\Domain\Tenant\Model\RilisAplikasi;
use App\Domain\Tenant\Model\Tenant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Versi aplikasi untuk satu perangkat (P-10, §14.6) dari `RilisAplikasi`:
 * - **Versi terbaru** = versi tertinggi di antara rilis `Aktif` yang ditawarkan ke perangkat ini: kanal `Stabil` bila
 *   ember perangkat (`crc32(Uuid) mod 100`) di bawah `PersenRollout`; kanal `Beta` hanya untuk tenant berpenanda
 *   Uji/Internal (tanpa persentase).
 * - **Versi minimal** = `VersiMinimum` tertinggi dari rilis yang tidak berstatus Draf dan sudah berlaku
 *   (`VersiMinimumBerlakuPada` ≤ sekarang). Rilis yang dihentikan tetap bisa menaikkan versi minimum bila sudah berlaku.
 * - Tanpa rilis yang cocok: cadangan `config/aplikasi.php` (tetap dipakai sampai rilis pertama dicatat).
 */
final class VersiAplikasiPerangkat
{
    /** @var array<int, bool> */
    private array $tenantBeta = [];

    /**
     * @return array{VersiTerbaru: string, VersiMinimal: string, TautanUnduh: string|null, Sha256Unduhan: string|null, CatatanRilis: string|null}
     */
    public function Tentukan(AplikasiRilis $aplikasi, string $platform, int $idTenant, string $uuidPerangkat): array
    {
        $cadangan = self::AmbilCadangan($aplikasi, $platform);
        /** @var Collection<int, RilisAplikasi> $rilis */
        // Daftar rilis sama untuk semua tenant dan dibaca di setiap polling `konfigurasi-aplikasi` (tiga platform per
        // panggilan): cache 30 detik, dibatalkan otomatis oleh `RilisAplikasi` saat berubah.
        $rilis = Cache::remember(
            RilisAplikasi::KunciCache($aplikasi->value, $platform),
            30,
            fn (): Collection => RilisAplikasi::query()
                ->where('Aplikasi', $aplikasi->value)
                ->where('Platform', $platform)
                ->where('Status', '!=', StatusRilis::Draf->value)
                ->get(),
        );

        $ember = self::HitungEmber($uuidPerangkat);
        $beta = $this->CekTenantBeta($idTenant);
        $terbaru = null;

        foreach ($rilis as $r) {
            if ($r->Status !== StatusRilis::Aktif) {
                continue;
            }

            $ditawarkan = $r->Kanal === KanalRilis::Beta ? $beta : $ember < $r->PersenRollout;

            if ($ditawarkan && ($terbaru === null || RilisAplikasi::BandingkanVersi($r->Versi, $terbaru->Versi) > 0)) {
                $terbaru = $r;
            }
        }

        $minimal = $cadangan['VersiMinimal'];

        foreach ($rilis as $r) {
            if ($r->VersiMinimum !== null && $r->VersiMinimumBerlakuPada !== null && $r->VersiMinimumBerlakuPada->lte(now())
                && RilisAplikasi::BandingkanVersi($r->VersiMinimum, $minimal) > 0) {
                $minimal = $r->VersiMinimum;
            }
        }

        return [
            'VersiTerbaru' => $terbaru->Versi ?? $cadangan['VersiTerbaru'],
            'VersiMinimal' => $minimal,
            'TautanUnduh' => $terbaru->UrlUnduh ?? $cadangan['TautanUnduh'],
            // POS-23: checksum hanya berlaku untuk tautan dari rilis yang sama (tautan cadangan config tidak punya checksum).
            'Sha256Unduhan' => $terbaru?->UrlUnduh === null ? null : $terbaru->Sha256Unduhan,
            'CatatanRilis' => $terbaru?->CatatanRilis,
        ];
    }

    /** Ember rollout 0–99 yang tetap untuk satu perangkat. */
    public static function HitungEmber(string $uuidPerangkat): int
    {
        return crc32($uuidPerangkat) % 100;
    }

    /**
     * @return array{VersiTerbaru: string, VersiMinimal: string, TautanUnduh: string|null}
     */
    private static function AmbilCadangan(AplikasiRilis $aplikasi, string $platform): array
    {
        $konfigurasi = config("aplikasi.{$aplikasi->value}.{$platform}");
        $konfigurasi = is_array($konfigurasi) ? $konfigurasi : [];
        $tautan = $konfigurasi['TautanUnduh'] ?? null;

        return [
            'VersiTerbaru' => (string) ($konfigurasi['VersiTerbaru'] ?? '1.0.0'),
            'VersiMinimal' => (string) ($konfigurasi['VersiMinimal'] ?? '1.0.0'),
            'TautanUnduh' => is_string($tautan) && $tautan !== '' ? $tautan : null,
        ];
    }

    private function CekTenantBeta(int $idTenant): bool
    {
        return $this->tenantBeta[$idTenant] ??= in_array(
            Tenant::query()->whereKey($idTenant)->value('Penanda'),
            [PenandaTenant::Uji, PenandaTenant::Internal, PenandaTenant::Uji->value, PenandaTenant::Internal->value],
            true,
        );
    }
}
