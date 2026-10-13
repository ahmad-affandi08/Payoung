<?php

declare(strict_types=1);

namespace App\Domain\Bengkel\Kueri;

use App\Domain\Bengkel\Enum\StatusPerintahKerja;
use App\Domain\Bengkel\Model\PerintahKerja;
use App\Domain\Pelanggan\Kueri\IdentitasPelanggan;

/**
 * Perintah kerja untuk aplikasi kasir (§9.10, perlu online): daftar yang siap ditagih (disetujui pelanggan, belum
 * ditagih) atau semua yang masih berjalan di outlet perangkat, dan satu perintah kerja per Uuid. Hanya baris yang
 * **disetujui** yang dikirim sebagai `Baris` — itulah yang dimuat kasir ke keranjang; harga = snapshot server, mekanik
 * baris jasa = `UuidKaryawan` (diisi ke `Baris[].Staf` penjualan). Nomor HP pelanggan tersamar seperti data POS lain.
 */
final class PerintahKerjaPos
{
    public const BATAS = 100;

    public function __construct(
        private readonly DetailPerintahKerja $detail,
        private readonly IdentitasPelanggan $pelanggan,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function Daftar(int $idOutlet, bool $hanyaSiapTagih): array
    {
        $status = $hanyaSiapTagih
            ? StatusPerintahKerja::AmbilSiapTagih()
            : array_values(array_filter(StatusPerintahKerja::cases(), fn (StatusPerintahKerja $s): bool => $s->CekAktif()));

        return array_values(PerintahKerja::query()
            ->where('IdOutlet', $idOutlet)
            ->whereIn('Status', array_map(fn (StatusPerintahKerja $s): string => $s->value, $status))
            ->whereNull('IdPenjualan')
            ->orderBy('Id')
            ->limit(self::BATAS)
            ->get()
            ->map(fn (PerintahKerja $pk): array => $this->Petakan($pk))
            ->all());
    }

    /** @return array<string, mixed>|null */
    public function Ambil(int $idOutlet, string $uuid): ?array
    {
        $pk = PerintahKerja::query()->where('Uuid', strtoupper($uuid))->where('IdOutlet', $idOutlet)->first();

        return $pk === null ? null : $this->Petakan($pk);
    }

    /**
     * @return array<string, mixed>
     */
    private function Petakan(PerintahKerja $pk): array
    {
        $d = $this->detail->Ambil($pk);
        $pos = $this->pelanggan->AmbilUntukPos([$pk->IdPelanggan])[$pk->IdPelanggan] ?? null;

        return [
            'Uuid' => $d['Uuid'],
            'Nomor' => $d['Nomor'],
            'Status' => $d['Status'],
            'LabelStatus' => $d['LabelStatus'],
            'SiapTagih' => $pk->Status->CekSiapTagih() && $pk->IdPenjualan === null,
            'DibuatPada' => $d['DibuatPada'],
            'Pelanggan' => $pos === null ? null : ['Uuid' => $pos['Uuid'], 'Nama' => $pos['Nama'], 'NoHp' => $pos['NoHp'], 'KodeTier' => $pos['KodeTier']],
            'Kendaraan' => $d['Kendaraan'] === null ? null : [
                'Uuid' => $d['Kendaraan']['Uuid'],
                'Jenis' => $d['Kendaraan']['Jenis'],
                'NomorPolisi' => $d['Kendaraan']['NomorPolisi'],
                'Label' => $d['Kendaraan']['Label'],
            ],
            'KmMasuk' => $d['KmMasuk'],
            'Keluhan' => $d['Keluhan'],
            'CatatanQc' => $d['CatatanQc'],
            'TotalDisetujui' => $d['TotalDisetujui'],
            'Baris' => array_values(array_map(fn (array $b): array => [
                'Uuid' => $b['Uuid'],
                'Jenis' => $b['Jenis'],
                'UuidProduk' => $b['UuidProduk'],
                'UuidProdukSatuan' => $b['UuidProdukSatuan'],
                'NamaProduk' => $b['NamaProduk'],
                'Jumlah' => $b['Jumlah'],
                'HargaSatuan' => $b['HargaSatuan'],
                'Diskon' => $b['Diskon'],
                'UuidKaryawan' => $b['Karyawan']['Uuid'] ?? null,
                'NamaKaryawan' => $b['Karyawan']['Nama'] ?? null,
                'Catatan' => $b['Catatan'],
                'NomorSeri' => $b['NomorSeri'],
            ], array_filter($d['Baris'], fn (array $b): bool => $b['Disetujui']))),
        ];
    }
}
