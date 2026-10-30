<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Kueri;

use App\Domain\Tenant\Enum\CakupanFlagFitur;
use App\Domain\Tenant\Model\FlagFitur;
use App\Domain\Tenant\Model\Langganan;
use Illuminate\Support\Facades\Cache;

/**
 * Nilai flag fitur untuk satu tenant (P-10, §5 P-04 "flag fitur global mengizinkan"). Per kunci:
 * 1. aturan `Global` bernilai mati = **kill switch**, selalu mati;
 * 2. aturan `Tenant` untuk tenant ini;
 * 3. aturan `Paket` untuk paket langganan tenant;
 * 4. aturan `Persentase`: hidup bila ember tenant (`crc32(kunci|IdTenant) mod 100`) di bawah `Persen`, selain itu mati;
 * 5. aturan `Global` bernilai hidup.
 * Kunci tanpa aturan tidak muncul (diizinkan, bagi `EvaluatorFitur`).
 */
final class FlagFiturTenant
{
    /** @var list<FlagFitur>|null */
    private ?array $aturan = null;

    /** Buang aturan yang diingat instance ini (dipanggil `SumberFiturTenant::Lupakan` saat flag berubah). */
    public function Lupakan(): void
    {
        $this->aturan = null;
    }

    /**
     * @return array<string, bool>
     */
    public function Ambil(int $idTenant, ?int $idPaket): array
    {
        $perKunci = [];

        foreach ($this->AmbilAturan() as $a) {
            $perKunci[$a->Kunci][] = $a;
        }

        $hasil = [];

        foreach ($perKunci as $kunci => $aturan) {
            $hasil[$kunci] = self::Evaluasi($kunci, $aturan, $idTenant, $idPaket);
        }

        ksort($hasil);

        return $hasil;
    }

    /**
     * Untuk `konfigurasi-aplikasi`: paket diambil dari langganan tenant.
     *
     * @return array<string, bool>
     */
    public function AmbilUntukTenant(int $idTenant): array
    {
        $idPaket = Langganan::query()->where('IdTenant', $idTenant)->value('IdPaket');

        return $this->Ambil($idTenant, $idPaket === null ? null : (int) $idPaket);
    }

    public static function HitungEmber(string $kunci, int $idTenant): int
    {
        return crc32($kunci.'|'.$idTenant) % 100;
    }

    /**
     * @param  list<FlagFitur>  $aturan
     */
    private static function Evaluasi(string $kunci, array $aturan, int $idTenant, ?int $idPaket): bool
    {
        $cari = fn (CakupanFlagFitur $cakupan, ?int $idObjek = null): ?FlagFitur => array_values(array_filter(
            $aturan,
            fn (FlagFitur $a): bool => $a->Cakupan === $cakupan && ($idObjek === null || $a->IdObjek === $idObjek),
        ))[0] ?? null;

        $global = $cari(CakupanFlagFitur::Global);

        if ($global !== null && ! $global->Nilai) {
            return false;
        }

        $tenant = $cari(CakupanFlagFitur::Tenant, $idTenant);

        if ($tenant !== null) {
            return $tenant->Nilai;
        }

        $paket = $idPaket === null ? null : $cari(CakupanFlagFitur::Paket, $idPaket);

        if ($paket !== null) {
            return $paket->Nilai;
        }

        $persen = $cari(CakupanFlagFitur::Persentase);

        if ($persen !== null) {
            return self::HitungEmber($kunci, $idTenant) < ($persen->Persen ?? 0);
        }

        return $global->Nilai ?? true;
    }

    /**
     * @return list<FlagFitur>
     */
    private function AmbilAturan(): array
    {
        // Tabel kecil yang sama untuk semua tenant dan dibaca di setiap polling kasir: cache 30 detik, dibatalkan otomatis
        // oleh `FlagFitur` saat berubah (kill switch tetap seketika bila diubah lewat aplikasi).
        return $this->aturan ??= array_values(Cache::remember(FlagFitur::KUNCI_CACHE_ATURAN, 30, fn (): array => FlagFitur::query()->orderBy('Id')->get()->all()));
    }
}
