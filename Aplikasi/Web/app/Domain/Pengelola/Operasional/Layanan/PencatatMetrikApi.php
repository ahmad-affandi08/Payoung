<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Operasional\Layanan;

use App\Domain\Bersama\Sinkron\Layanan\PencatatGalatSinkron;
use App\Domain\Pengelola\Operasional\Model\MetrikApi;
use App\Domain\Pengelola\Operasional\Model\MetrikGalatSinkron;
use App\Domain\Pengelola\Operasional\Model\MetrikRuteApi;
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

    /** Rute berbeda per kelompok per menit yang dihitung; sisanya digabung ke `lainnya` (kardinalitas dibatasi). */
    private const MAKS_RUTE_PER_MENIT = 80;

    public const RUTE_LAINNYA = 'lainnya';

    public const RUTE_TANPA_NAMA = 'tanpa-nama';

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

    public function Catat(string $kelompok, int $status, int $durasiMs, ?Carbon $waktu = null, ?string $namaRute = null): void
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

            // Rincian per nama rute di kunci yang sama (tanpa tambahan baca/tulis berkas): p95 per rute dibaca dari sini.
            $rute = $this->NormalisasiRute($namaRute);
            $peta = is_array($nilai['Rute'] ?? null) ? $nilai['Rute'] : [];

            if (! isset($peta[$rute]) && count($peta) >= self::MAKS_RUTE_PER_MENIT) {
                $rute = self::RUTE_LAINNYA;
            }

            $baris = is_array($peta[$rute] ?? null) ? $peta[$rute] : [];
            $baris['JumlahPermintaan'] = (int) ($baris['JumlahPermintaan'] ?? 0) + 1;
            $baris['TotalDurasiMs'] = (int) ($baris['TotalDurasiMs'] ?? 0) + max(0, $durasiMs);
            $baris[$bucket] = (int) ($baris[$bucket] ?? 0) + 1;

            if ($status >= 500) {
                $baris['JumlahGalatServer'] = (int) ($baris['JumlahGalatServer'] ?? 0) + 1;
            }

            $peta[$rute] = $baris;
            $nilai['Rute'] = $peta;

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
                $this->SimpanRute($kelompok, Carbon::createFromTimestampUTC($menit)->startOfHour(), is_array($nilai['Rute'] ?? null) ? $nilai['Rute'] : []);
                $ditulis++;
            }
        }

        $batas = now()->subDays((int) config('operasional.RetensiMetrikHari'));
        MetrikApi::query()->where('Menit', '<', $batas->copy()->startOfMinute())->delete();
        MetrikRuteApi::query()->where('Jam', '<', $batas->copy()->startOfHour())->delete();

        return $ditulis;
    }

    /**
     * Menyimpan galat sinkron yang dipanen dari penghitung cache ke `MetrikGalatSinkron` (per jam) dan memangkas yang
     * melewati retensi. Dipanggil detak scheduler bersama `Vaskan()`.
     *
     * @return int jumlah baris jam-jenis-kode yang diperbarui
     */
    public function VaskanGalatSinkron(PencatatGalatSinkron $pencatat): int
    {
        /** @var array<string, array{Jam: Carbon, JenisItem: string, KodeGalat: string, Jumlah: int}> $gabung */
        $gabung = [];

        foreach ($pencatat->Panen() as $baris) {
            $jam = Carbon::createFromTimestampUTC($baris['Menit'])->startOfHour();
            $kunci = $jam->getTimestamp().'|'.$baris['JenisItem'].'|'.$baris['KodeGalat'];
            $ada = $gabung[$kunci] ?? ['Jam' => $jam, 'JenisItem' => $baris['JenisItem'], 'KodeGalat' => $baris['KodeGalat'], 'Jumlah' => 0];
            $ada['Jumlah'] += $baris['Jumlah'];
            $gabung[$kunci] = $ada;
        }

        foreach ($gabung as $baris) {
            DB::transaction(function () use ($baris): void {
                $ada = MetrikGalatSinkron::query()->where('Jam', $baris['Jam'])->where('JenisItem', $baris['JenisItem'])->where('KodeGalat', $baris['KodeGalat'])->lockForUpdate()->first();

                if ($ada === null) {
                    MetrikGalatSinkron::query()->create($baris);

                    return;
                }

                $ada->Jumlah += $baris['Jumlah'];
                $ada->save();
            });
        }

        MetrikGalatSinkron::query()->where('Jam', '<', now()->subDays((int) config('operasional.RetensiMetrikHari'))->startOfHour())->delete();

        return count($gabung);
    }

    /** Nama rute yang aman sebagai kunci: huruf kecil, angka, titik, strip; selain itu `tanpa-nama`. */
    private function NormalisasiRute(?string $namaRute): string
    {
        $nama = $namaRute === null ? '' : trim($namaRute);

        return $nama === '' || preg_match('/^[a-z0-9.\-_]{1,120}$/', $nama) !== 1 ? self::RUTE_TANPA_NAMA : $nama;
    }

    /**
     * @param  array<string, mixed>  $rute
     */
    private function SimpanRute(string $kelompok, Carbon $jam, array $rute): void
    {
        $kolom = ['JumlahPermintaan', 'JumlahGalatServer', 'TotalDurasiMs', ...array_keys(self::BUCKET)];

        foreach ($rute as $nama => $isi) {
            if (! is_array($isi)) {
                continue;
            }

            $nilai = array_combine($kolom, array_map(fn (string $k): int => (int) ($isi[$k] ?? 0), $kolom));

            DB::transaction(function () use ($kelompok, $jam, $nama, $nilai): void {
                $baris = MetrikRuteApi::query()->where('Kelompok', $kelompok)->where('NamaRute', (string) $nama)->where('Jam', $jam)->lockForUpdate()->first();

                if ($baris === null) {
                    MetrikRuteApi::query()->create(['Kelompok' => $kelompok, 'NamaRute' => (string) $nama, 'Jam' => $jam] + $nilai);

                    return;
                }

                foreach ($nilai as $k => $tambah) {
                    $baris->{$k} += $tambah;
                }

                $baris->save();
            });
        }
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
