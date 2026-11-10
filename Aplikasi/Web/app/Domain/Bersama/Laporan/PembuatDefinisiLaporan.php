<?php

declare(strict_types=1);

namespace App\Domain\Bersama\Laporan;

use App\Domain\Tenant\Kueri\RingkasanTenant;
use App\Domain\Tenant\Layanan\PenyimpanLogoTenant;
use App\Domain\Tenant\Model\Tenant;
use Brick\Math\BigDecimal;
use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Str;
use Throwable;

/**
 * Merakit `DefinisiLaporan` dengan data kop tenant (nama usaha, logo, zona waktu), supaya tiap laporan cukup
 * menyebut isinya: judul, saringan, ringkasan, kolom, dan baris (D-43).
 */
final class PembuatDefinisiLaporan
{
    public function __construct(
        private readonly RingkasanTenant $ringkasanTenant,
        private readonly PenyimpanLogoTenant $penyimpanLogo,
    ) {}

    /**
     * @param  list<array{0: string, 1: string}>  $saringan
     * @param  list<ItemRingkasan>|null  $ringkasan  null = otomatis: jumlah tiap kolom `jumlahkan` dan jumlah baris
     * @param  list<KolomLaporan>  $kolom
     * @param  Closure(): iterable<list<string|int|DateTimeInterface|RumusExcel|null>>  $baris
     */
    public function Buat(
        int $idTenant,
        string $judul,
        string $namaBerkas,
        string $cakupan,
        array $saringan,
        ?array $ringkasan,
        array $kolom,
        Closure $baris,
        ?DateTimeInterface $dataTerakhir = null,
        bool $denganLogo = true,
    ): DefinisiLaporan {
        $tenant = $this->ringkasanTenant->Ambil([$idTenant])[0] ?? null;
        $zona = Tenant::query()->whereKey($idTenant)->value('ZonaWaktu');

        return new DefinisiLaporan(
            judul: $judul,
            namaBerkas: $namaBerkas,
            namaUsaha: $tenant['Nama'] ?? 'Payoung',
            cakupan: $cakupan,
            saringan: $saringan,
            ringkasan: $ringkasan ?? self::RingkasanOtomatis($kolom, $baris),
            kolom: $kolom,
            baris: $baris,
            zonaWaktu: is_string($zona) && $zona !== '' ? $zona : 'Asia/Jakarta',
            dataTerakhir: $dataTerakhir,
            dibuatPada: new DateTimeImmutable('now', new \DateTimeZone('UTC')),
            logo: $denganLogo ? $this->BacaLogo($tenant['PathLogo'] ?? null) : null,
        );
    }

    /**
     * Ringkasan bawaan blok kop: jumlah tiap kolom angka yang `jumlahkan` dan jumlah baris. Menjumlah desimal persis
     * (BigDecimal, tanpa float). Membaca baris sekali lagi, jadi untuk sumber besar berikan ringkasan sendiri.
     *
     * @param  list<KolomLaporan>  $kolom
     * @param  Closure(): iterable<list<string|int|DateTimeInterface|RumusExcel|null>>  $baris
     * @return list<ItemRingkasan>
     */
    public static function RingkasanOtomatis(array $kolom, Closure $baris): array
    {
        $total = [];
        $jumlahBaris = 0;

        foreach ($baris() as $isi) {
            $jumlahBaris++;

            foreach ($kolom as $i => $k) {
                $nilai = $isi[$i] ?? null;

                if ($k->jumlahkan && $k->jenis->CekAngka() && (is_int($nilai) || (is_string($nilai) && preg_match('/^-?\d+(\.\d+)?$/', $nilai) === 1))) {
                    $total[$i] = ($total[$i] ?? BigDecimal::zero())->plus(BigDecimal::of($nilai));
                }
            }
        }

        $hasil = [];

        foreach ($kolom as $i => $k) {
            if ($k->jumlahkan && $k->jenis->CekAngka()) {
                $hasil[] = new ItemRingkasan('Total '.$k->judul, (string) ($total[$i] ?? BigDecimal::zero()), $k->jenis);
            }
        }

        $hasil[] = new ItemRingkasan('Jumlah Baris', $jumlahBaris, JenisKolom::Bilangan);

        return $hasil;
    }

    /** Nama berkas aman dari judul + periode: "laporan-penjualan-harian-2026-10-01-2026-10-04". */
    public static function NamaBerkas(string ...$bagian): string
    {
        return implode('-', array_filter(array_map(fn (string $b): string => Str::slug($b), $bagian), fn (string $b): bool => $b !== ''));
    }

    private function BacaLogo(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        try {
            return $this->penyimpanLogo->Baca($path);
        } catch (Throwable) {
            return null;
        }
    }
}
