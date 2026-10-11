<?php

declare(strict_types=1);

namespace App\Domain\Situs\Kueri;

use App\Domain\PanduanAwal\Kueri\TemplateTerbit;
use App\Domain\Situs\Layanan\SektorSitus;

/**
 * Isi menu "Solusi" di kepala situs pemasaran (D-91): sektor dikelompokkan per kolom (Kafe & Resto, Toko & Retail,
 * Salon, Laundry & Jasa). Sebuah sektor tampil hanya bila template sektornya masih terbit dan tidak dinonaktifkan (D-47)
 * dan halamannya tampil publik (halaman bawaan, atau halaman bernama sama di konsol yang aktif dan terbit).
 */
final class MenuSolusiSitus
{
    public function __construct(
        private readonly TemplateTerbit $template,
        private readonly CekHalamanSitusTerbit $halaman,
    ) {}

    /**
     * @return list<array{Judul: string, Tautan: string, Ikon: string, Sektor: list<array{Nama: string, Ringkas: string, Ikon: string, Tautan: string}>}>
     */
    public function Ambil(): array
    {
        $aktif = array_flip($this->template->AmbilKode());
        $perKelompok = [];

        foreach (SektorSitus::AmbilDaftar() as $sektor) {
            if (! isset($aktif[$sektor['Kode']]) || ! $this->halaman->Ada($sektor['Slug'])) {
                continue;
            }

            $perKelompok[$sektor['Kelompok']][] = [
                'Nama' => $sektor['Nama'],
                'Ringkas' => $sektor['Ringkas'],
                'Ikon' => $sektor['Ikon'],
                'Tautan' => '/'.$sektor['Slug'],
            ];
        }

        $hasil = [];

        foreach (SektorSitus::KELOMPOK as $kunci => $kelompok) {
            $hasil[] = [
                'Judul' => $kelompok['Judul'],
                'Tautan' => '/solusi/'.$kunci,
                'Ikon' => $kelompok['Ikon'],
                'Sektor' => $perKelompok[$kunci] ?? [],
            ];
        }

        return array_values(array_filter($hasil, static fn (array $k): bool => $k['Sektor'] !== []));
    }
}
