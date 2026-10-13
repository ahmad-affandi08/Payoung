<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Operasional\Layanan;

use App\Domain\Pengelola\Operasional\Model\MetrikApi;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Pencatat metrik API (P-11, PGL-20). Tiap permintaan hanya memperbarui satu penghitung di store cache `metrik` (berkas
 * lokal, tanpa kueri database di jalur permintaan; angkanya perkiraan); detak scheduler memindahkan penghitung menit yang sudah lewat ke `MetrikApi` lewat
 * `Vaskan()`. Kegagalan cache tidak pernah boleh menggagalkan permintaan.
 *
 * Batas atas bucket latensi (ms): 100, 250, 400, 800, 1600, lebih. 400 ms = target p95 API POS (§20).
 */
final class PencatatMetrikApi
{
    public const KELOMPOK = ['Pos', 'Pemilik', 'ApiPublik'];

    /** Kolom bucket berurutan dengan batas atas inklusifnya; yang terakhir tanpa batas. */
    public const BUCKET = ['Hingga100Ms' => 100, 'Hingga250Ms' => 250, 'Hingga400Ms' => 400, 'Hingga800Ms' => 800, 'Hingga1600Ms' => 1600, 'Lebih1600Ms' => null];

    private const AWALAN = 'metrik-api';

    private const TTL_DETIK = 1800;

    /** Menit yang masih dicari penghitungnya saat menyiram (menutup detak scheduler yang terlewat). */
    private const MENIT_TELUSUR = 10;

    /** Kelompok dari jalur permintaan; null = bukan API yang dipantau. */
    public static function TentukanKelompok(string $jalur): ?string
    {
        return match (true) {
            str_starts_with($jalur, 'api/pos/') => 'Pos',
            str_starts_with($jalur, 'api/pemilik/') => 'Pemilik',
            str_starts_with($jalur, 'api/v1/') => 'ApiPublik',
            default => null,
        };
    }

    /** Nama kolom bucket untuk durasi [durasiMs]. */
    public static function TentukanBucket(int $durasiMs): string
    {
        foreach (self::BUCKET as $kolom => $batas) {
            if ($batas === null || $durasiMs <= $batas) {
                return $kolom;
            }
        }

        return 'Lebih1600Ms';
    }

    public function Catat(string $kelompok, int $status, int $durasiMs, ?Carbon $waktu = null): void
    {
        $menit = ($waktu ?? now())->copy()->startOfMinute()->getTimestamp();

        try {
            // Satu kunci per kelompok per menit: satu baca + satu tulis berkas per permintaan.
            $kunci = $this->Kunci($kelompok, $menit);
            $nilai = $this->Penyimpan()->get($kunci);
            $nilai = is_array($nilai) ? $nilai : [];
            $bucket = self::TentukanBucket($durasiMs);
            $nilai['JumlahPermintaan'] = (int) ($nilai['JumlahPermintaan'] ?? 0) + 1;
            $nilai['TotalDurasiMs'] = (int) ($nilai['TotalDurasiMs'] ?? 0) + max(0, $durasiMs);
            $nilai[$bucket] = (int) ($nilai[$bucket] ?? 0) + 1;

            if ($status >= 500) {
                $nilai['JumlahGalatServer'] = (int) ($nilai['JumlahGalatServer'] ?? 0) + 1;
            }

            $this->Penyimpan()->put($kunci, $nilai, self::TTL_DETIK);
        } catch (Throwable) {
            // Metrik adalah pelengkap; cache yang bermasalah tidak boleh mengganggu API.
        }
    }

    /**
     * Memindahkan penghitung menit-menit yang sudah lewat ke `MetrikApi` dan membersihkan metrik yang melewati retensi.
     *
     * @return int jumlah baris menit-kelompok yang ditulis
     */
    public function Vaskan(): int
    {
        $menitIni = now()->startOfMinute()->getTimestamp();
        $ditulis = 0;

        for ($mundur = 1; $mundur <= self::MENIT_TELUSUR; $mundur++) {
            $menit = $menitIni - ($mundur * 60);

            foreach (self::KELOMPOK as $kelompok) {
                $nilai = $this->Penyimpan()->pull($this->Kunci($kelompok, $menit));

                if (! is_array($nilai) || (int) ($nilai['JumlahPermintaan'] ?? 0) === 0) {
                    continue;
                }

                $this->Simpan($kelompok, Carbon::createFromTimestampUTC($menit), array_map(
                    fn (string $kolom): int => (int) ($nilai[$kolom] ?? 0),
                    array_combine($kolomSemua = ['JumlahPermintaan', 'JumlahGalatServer', 'TotalDurasiMs', ...array_keys(self::BUCKET)], $kolomSemua),
                ));
                $ditulis++;
            }
        }

        MetrikApi::query()->where('Menit', '<', now()->subDays((int) config('operasional.RetensiMetrikHari'))->startOfMinute())->delete();

        return $ditulis;
    }

    /**
     * @param  array<string, int>  $nilai
     */
    private function Simpan(string $kelompok, Carbon $menit, array $nilai): void
    {
        DB::transaction(function () use ($kelompok, $menit, $nilai): void {
            $baris = MetrikApi::query()->where('Kelompok', $kelompok)->where('Menit', $menit)->lockForUpdate()->first();

            if ($baris === null) {
                MetrikApi::query()->create(['Kelompok' => $kelompok, 'Menit' => $menit, ...$nilai]);

                return;
            }

            foreach ($nilai as $kolom => $tambah) {
                $baris->{$kolom} += $tambah;
            }

            $baris->save();
        });
    }

    private function Kunci(string $kelompok, int $menit): string
    {
        return self::AWALAN.":{$kelompok}:{$menit}";
    }

    private function Penyimpan(): Repository
    {
        return Cache::store('metrik');
    }
}
