<?php

declare(strict_types=1);

namespace App\Domain\Kasir\Kueri;

use App\Domain\Akuntansi\Kueri\DaftarAkunPilihan;
use App\Domain\Kasir\Enum\JenisKategoriKas;
use App\Domain\Kasir\Model\KategoriKas;

/**
 * Pilihan untuk pengeluaran cepat di Aplikasi Owner (OWN-07): kategori kas keluar yang aktif (akun bebannya sudah
 * dipetakan, jadi pemilik tidak perlu mengenal kode akun) dan akun kas/bank sumber uangnya.
 */
final class OpsiPengeluaranPemilik
{
    public function __construct(private readonly DaftarAkunPilihan $akun) {}

    /**
     * @return array{Kategori: list<array{Uuid: string, Nama: string}>, Sumber: list<array{Uuid: string, Kode: string, Nama: string}>}
     */
    public function Ambil(): array
    {
        $kategori = KategoriKas::query()
            ->where('Jenis', JenisKategoriKas::Keluar->value)
            ->where('Aktif', true)
            ->orderBy('Urutan')
            ->orderBy('Nama')
            ->get();

        return [
            'Kategori' => array_values($kategori->map(fn (KategoriKas $k): array => ['Uuid' => $k->Uuid, 'Nama' => $k->Nama])->all()),
            'Sumber' => array_map(
                fn (array $a): array => ['Uuid' => $a['Uuid'], 'Kode' => $a['Kode'], 'Nama' => $a['Nama']],
                $this->akun->AmbilKasBank(),
            ),
        ];
    }
}
