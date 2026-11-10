<?php

declare(strict_types=1);

namespace App\Domain\Bersama\Laporan;

use Closure;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * Satu definisi laporan untuk semua format unduhan (Excel, CSV, cetak/PDF) agar kop, saringan, ringkasan, dan tabelnya
 * selalu sama (D-43). `baris` dipanggil saat berkas ditulis dan boleh generator (streaming); tiap baris berisi nilai
 * sesuai urutan `kolom`: teks/angka desimal sebagai string, atau DateTimeInterface untuk kolom tanggal.
 */
final readonly class DefinisiLaporan
{
    /**
     * @param  list<array{0: string, 1: string}>  $saringan  pasangan label dan nilai yang terbaca manusia
     * @param  list<ItemRingkasan>  $ringkasan
     * @param  list<KolomLaporan>  $kolom
     * @param  Closure(): iterable<list<string|int|DateTimeInterface|RumusExcel|null>>  $baris
     */
    public function __construct(
        public string $judul,
        public string $namaBerkas,
        public string $namaUsaha,
        public string $cakupan,
        public array $saringan,
        public array $ringkasan,
        public array $kolom,
        public Closure $baris,
        public string $zonaWaktu = 'Asia/Jakarta',
        public ?DateTimeInterface $dataTerakhir = null,
        public ?DateTimeImmutable $dibuatPada = null,
        public ?string $logo = null,
        /** Baris "Jumlah" di kaki tabel ditulis sebagai rumus SUM (D-255), bukan angka mati. */
        public bool $jumlahBerumus = false,
    ) {}

    /** Indeks kolom yang punya `jumlahkan`. */
    public function AdaJumlah(): bool
    {
        foreach ($this->kolom as $kolom) {
            if ($kolom->jumlahkan && $kolom->jenis->CekAngka()) {
                return true;
            }
        }

        return false;
    }
}
