<?php

declare(strict_types=1);

namespace App\Domain\Karyawan\Layanan;

use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Karyawan\Data\DataBarisKomisi;
use App\Domain\Karyawan\Enum\CakupanKomisi;
use App\Domain\Karyawan\Enum\JenisKomisi;
use App\Domain\Karyawan\Enum\StatusAturanKomisi;
use App\Domain\Karyawan\Model\AturanKomisi;
use App\Domain\Karyawan\Model\Karyawan;
use App\Domain\Karyawan\Model\Komisi;
use Brick\Math\BigDecimal;
use Brick\Math\BigRational;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Layanan publik domain Karyawan untuk domain Penjualan (F-18, EMP-04): komisi dicatat, dibatalkan (void), dan
 * dikurangi (retur) di transaksi DB yang sama dengan dokumen penjualannya. Tanpa jurnal (hanya laporan).
 *
 * Per staf: aturan aktif paling spesifik (Produk > Kategori > Semua; di tingkat yang sama, level staf yang cocok
 * mengalahkan "semua level"; seri = aturan terbaru). Komisi = persen × dasar, atau nominal × jumlah; lalu × porsi
 * (1/n bila n staf; sisa pembulatan ke staf terakhir). Staf tanpa aturan tetap tercatat dengan komisi 0 (siapa melayani).
 */
final class PencatatKomisiPenjualan
{
    public const MAKS_STAF_PER_BARIS = 5;

    /**
     * @param  list<DataBarisKomisi>  $baris
     * @return list<string> masalah untuk tinjauan penjualan (staf tidak dikenal/nonaktif)
     */
    public function Catat(int $idPenjualan, int $idOutlet, CarbonImmutable $tanggalBisnis, array $baris): array
    {
        $uuid = array_values(array_unique(array_merge(...array_map(fn (DataBarisKomisi $b): array => $b->uuidKaryawan, $baris))));

        if ($uuid === []) {
            return [];
        }

        $karyawan = Karyawan::query()->whereIn('Uuid', $uuid)->get()->keyBy('Uuid');
        $aturan = AturanKomisi::query()->where('Status', StatusAturanKomisi::Aktif->value)->orderByDesc('Id')->get();
        // Komisi yang sudah ada dibaca sekali untuk seluruh baris (dulu `firstOrCreate` = SELECT + INSERT per staf per baris,
        // di dalam transaksi sinkron penjualan). Pencatatan ulang item yang sama tetap idempoten, dan indeks unik
        // (IdPenjualanDetail, IdKaryawan) tetap menjaga bila ada penulis lain.
        $sudahAda = [];

        foreach (Komisi::query()->whereIn('IdPenjualanDetail', array_map(fn (DataBarisKomisi $b): int => $b->idPenjualanDetail, $baris))->get(['IdPenjualanDetail', 'IdKaryawan']) as $komisi) {
            $sudahAda[$komisi->IdPenjualanDetail.':'.$komisi->IdKaryawan] = true;
        }

        $masalah = [];

        foreach ($baris as $b) {
            $staf = [];

            foreach ($b->uuidKaryawan as $u) {
                $k = $karyawan->get($u);

                if ($k instanceof Karyawan) {
                    $staf[] = $k;
                } else {
                    $masalah[$u] = "staf {$u} belum dikenal server";
                }
            }

            if ($staf === []) {
                continue;
            }

            $porsi = BigRational::of('1/'.count($staf));
            $pilih = array_map(fn (Karyawan $k): ?AturanKomisi => self::PilihAturan($aturan, $b, $k->LevelStaf), $staf);
            $samaSemua = count(array_unique(array_map(fn (?AturanKomisi $a): ?int => $a?->Id, $pilih))) === 1;
            $terbagi = Uang::Nol();

            foreach ($staf as $i => $k) {
                $penuh = $pilih[$i] === null ? Uang::Nol() : self::Hitung($pilih[$i], $b);
                // Aturan sama untuk semua staf: staf terakhir menerima sisa agar Σ bagian = komisi penuh baris.
                $jumlah = $samaSemua && $i === count($staf) - 1 ? $penuh->Kurangi($terbagi) : $penuh->Kali($porsi);
                $terbagi = $terbagi->Tambah($jumlah);

                if (isset($sudahAda[$b->idPenjualanDetail.':'.$k->Id])) {
                    continue;
                }

                Komisi::query()->create([
                    'IdPenjualanDetail' => $b->idPenjualanDetail,
                    'IdKaryawan' => $k->Id,
                    'IdPenjualan' => $idPenjualan,
                    'IdAturanKomisi' => $pilih[$i]?->Id,
                    'IdOutlet' => $idOutlet,
                    'TanggalBisnis' => $tanggalBisnis->toDateString(),
                    'Dasar' => $b->dasar->KeString(),
                    'Porsi' => (string) $porsi->toScale(4, RoundingMode::HalfUp),
                    'Jumlah' => $jumlah->KeString(),
                ]);
                $sudahAda[$b->idPenjualanDetail.':'.$k->Id] = true;
            }
        }

        return array_values($masalah);
    }

    /** Void: seluruh komisi penjualan dibatalkan. */
    public function Batalkan(int $idPenjualan): void
    {
        foreach (Komisi::query()->where('IdPenjualan', $idPenjualan)->lockForUpdate()->get() as $k) {
            $k->JumlahDibatalkan = (string) $k->Jumlah;
            $k->DasarDibatalkan = (string) $k->Dasar;
            $k->save();
        }
    }

    /**
     * Retur: komisi baris dibatalkan proporsional kumulatif, `Jumlah` × [diretur] ÷ [dijual] (retur terakhir = penuh),
     * sehingga Σ pembatalan tepat sama dengan komisi baris. Dasar yang dibatalkan (realisasi target, v1.85) sama.
     */
    public function KurangiRetur(int $idPenjualanDetail, BigDecimal $dijual, BigDecimal $diretur): void
    {
        foreach (Komisi::query()->where('IdPenjualanDetail', $idPenjualanDetail)->lockForUpdate()->get() as $k) {
            $penuh = $diretur->isGreaterThanOrEqualTo($dijual) || $dijual->isZero();
            $bagian = fn (string $nilai): string => (string) ($penuh
                ? BigDecimal::of($nilai)
                : BigDecimal::of($nilai)->multipliedBy($diretur)->dividedBy($dijual, 2, RoundingMode::HalfUp))->toScale(2);
            $k->JumlahDibatalkan = $bagian((string) $k->Jumlah);
            $k->DasarDibatalkan = $bagian((string) $k->Dasar);
            $k->save();
        }
    }

    /**
     * @param  Collection<int, AturanKomisi>  $aturan  urut terbaru dulu
     */
    private static function PilihAturan(Collection $aturan, DataBarisKomisi $b, ?string $level): ?AturanKomisi
    {
        $terbaik = null;
        $skorTerbaik = -1;

        foreach ($aturan as $a) {
            $cocokCakupan = match ($a->Cakupan) {
                CakupanKomisi::Produk => $a->UuidProduk === $b->uuidProduk ? 20 : -1,
                CakupanKomisi::Kategori => $b->uuidKategori !== null && $a->UuidKategori === $b->uuidKategori ? 10 : -1,
                CakupanKomisi::Semua => 0,
            };
            $cocokLevel = match (true) {
                $a->LevelStaf === null => 0,
                $level !== null && mb_strtolower($a->LevelStaf) === mb_strtolower($level) => 1,
                default => -100,
            };
            $skor = $cocokCakupan + $cocokLevel;

            if ($cocokCakupan >= 0 && $cocokLevel >= 0 && $skor > $skorTerbaik) {
                $terbaik = $a;
                $skorTerbaik = $skor;
            }
        }

        return $terbaik;
    }

    private static function Hitung(AturanKomisi $a, DataBarisKomisi $b): Uang
    {
        return $a->Jenis === JenisKomisi::Persen
            ? $b->dasar->Kali(BigDecimal::of((string) $a->Nilai)->dividedBy(100, 6, RoundingMode::HalfUp))
            : Uang::Dari((string) $a->Nilai)->Kali($b->jumlah->KeDesimal());
    }
}
