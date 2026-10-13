<?php

declare(strict_types=1);

namespace App\Domain\Organisasi\Aksi;

use App\Domain\Organisasi\Enum\JenisGudang;
use App\Domain\Organisasi\Layanan\PembuatKodeOutlet;
use App\Domain\Organisasi\Model\Gudang;
use App\Domain\Organisasi\Model\Merek;
use App\Domain\Organisasi\Model\Outlet;

/**
 * Organisasi bawaan tenant baru (F-00 langkah 3): satu merek, outlet pertama (bernama sama dengan usaha, D-78; kode diturunkan dari nama usaha lewat `PembuatKodeOutlet`), dan gudang tokonya, serta peran
 * bawaan tenant dengan Owner sebagai Pemilik (F-02, §19.1). Dipanggil setelah Owner dibuat.
 */
final class SiapkanOrganisasiAwal
{
    public function __construct(private readonly SiapkanPeranBawaanTenant $siapkanPeran) {}

    public function Jalankan(int $idTenant, string $namaUsaha, string $zonaWaktu): Outlet
    {
        $kode = PembuatKodeOutlet::Buat($namaUsaha);
        $merek = Merek::query()->create(['IdTenant' => $idTenant, 'Nama' => $namaUsaha]);
        $outlet = Outlet::query()->create([
            'IdTenant' => $idTenant,
            'IdMerek' => $merek->Id,
            'Kode' => $kode,
            'Nama' => $namaUsaha,
            'ZonaWaktu' => $zonaWaktu,
        ]);
        Gudang::query()->create([
            'IdTenant' => $idTenant,
            'IdOutlet' => $outlet->Id,
            'Kode' => $kode,
            'Nama' => mb_substr("Gudang {$namaUsaha}", 0, 100),
            'Jenis' => JenisGudang::Toko,
        ]);

        $this->siapkanPeran->Jalankan($idTenant);

        return $outlet;
    }
}
