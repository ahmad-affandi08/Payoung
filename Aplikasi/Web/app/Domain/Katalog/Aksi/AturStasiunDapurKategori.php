<?php

declare(strict_types=1);

namespace App\Domain\Katalog\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Katalog\Layanan\PenjagaPelakuRuteDapur;
use App\Domain\Katalog\Model\Kategori;
use App\Domain\Organisasi\Kueri\DaftarStasiunDapur;
use Illuminate\Support\Facades\DB;

/**
 * D-310 (F-10): mengatur stasiun dapur/bar sebuah kategori dari kasir (POS). Stasiun kategori berlaku untuk seluruh
 * usaha (sama dengan form kategori di back-office), diwarisi kategori turunan, dan dipakai server serta semua perangkat
 * untuk merutekan tiket. `null` = ikut stasiun kategori induk atau stasiun bawaan. Idempoten: mengatur ke stasiun yang
 * sama tidak menulis apa pun dan tidak mencatat audit.
 */
final class AturStasiunDapurKategori
{
    public function __construct(
        private readonly PenjagaPelakuRuteDapur $penjaga,
        private readonly DaftarStasiunDapur $stasiun,
        private readonly PencatatAudit $audit,
    ) {}

    /**
     * @return string|null Uuid stasiun akhir (null = ikut aturan induk)
     */
    public function Jalankan(int $idOutlet, string $uuidKategori, ?string $uuidStasiun, ?string $uuidPengguna = null, ?int $idPenggunaBackOffice = null): ?string
    {
        $idPengguna = $idPenggunaBackOffice ?? $this->penjaga->PeriksaPelaku($idOutlet, (string) $uuidPengguna);
        $idStasiun = $this->penjaga->PeriksaStasiun($uuidStasiun);

        return DB::transaction(function () use ($uuidKategori, $idStasiun, $idPengguna): ?string {
            $kategori = Kategori::query()->where('Uuid', strtoupper($uuidKategori))->lockForUpdate()->first()
                ?? throw new PelanggaranAturanBisnis('KategoriTidakDitemukan', 'Kategori tidak ditemukan.', 'UuidKategori', 404);

            if ($kategori->IdStasiunDapur !== $idStasiun) {
                $lama = $kategori->IdStasiunDapur;
                $kategori->forceFill(['IdStasiunDapur' => $idStasiun])->save();
                $peta = $this->stasiun->AmbilPetaId();
                $this->audit->Catat('dapur.rute-atur', $kategori, nilaiLama: ['Stasiun' => $lama === null ? null : ($peta[$lama]['Nama'] ?? null)], nilaiBaru: [
                    'Jenis' => 'Kategori',
                    'Nama' => $kategori->Nama,
                    'Stasiun' => $idStasiun === null ? null : ($peta[$idStasiun]['Nama'] ?? null),
                ], idPengguna: $idPengguna);
            }

            return $idStasiun === null ? null : ($this->stasiun->AmbilPetaId()[$idStasiun]['Uuid'] ?? null);
        });
    }
}
