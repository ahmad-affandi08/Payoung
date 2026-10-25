<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Tenant\Aksi;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Pengelola\Tenant\Enum\KategoriPenangguhan;
use App\Domain\Pengelola\TimInternal\Model\PenggunaPengelola;
use App\Domain\Tenant\Model\Tenant;

/**
 * Aksi massal tenant terpilih di daftar tenant konsol (D-208): perpanjang uji coba, tangguhkan, aktifkan kembali, dan
 * tulis catatan internal. Tiap tenant tetap lewat aksi satuannya (aturan bisnis, audit per tenant dengan `IdTenant`,
 * email ke Owner), jadi aksi massal tidak pernah melonggarkan aturan satuan. Tenant saling lepas: yang tidak memenuhi
 * syarat (misal bukan lagi Trial, batas perpanjangan tercapai) dilewati dan alasannya dirangkum, bukan membatalkan
 * yang lain. Izin per aksi ditegakkan di rute, sama dengan aksi satuan.
 */
final class AksiTenantMassal
{
    public const MAKS = 50;

    public function __construct(
        private readonly PerpanjangTrial $perpanjang,
        private readonly TangguhkanTenant $tangguhkan,
        private readonly AktifkanKembaliTenant $aktifkan,
        private readonly TulisCatatanTenant $catatan,
    ) {}

    /**
     * @param  list<string>  $uuid
     * @return array{Diubah: int, Dilewati: array<string, int>}
     */
    public function PerpanjangTrial(PenggunaPengelola $pelaku, array $uuid, int $hari, string $alasan): array
    {
        return $this->Terapkan($uuid, fn (Tenant $t) => $this->perpanjang->Jalankan($pelaku, $t, $hari, $alasan));
    }

    /**
     * @param  list<string>  $uuid
     * @return array{Diubah: int, Dilewati: array<string, int>}
     */
    public function Tangguhkan(PenggunaPengelola $pelaku, array $uuid, KategoriPenangguhan $kategori, string $catatan): array
    {
        return $this->Terapkan($uuid, fn (Tenant $t) => $this->tangguhkan->Jalankan($pelaku, $t, $kategori, $catatan));
    }

    /**
     * @param  list<string>  $uuid
     * @return array{Diubah: int, Dilewati: array<string, int>}
     */
    public function Aktifkan(PenggunaPengelola $pelaku, array $uuid, string $alasan): array
    {
        return $this->Terapkan($uuid, fn (Tenant $t) => $this->aktifkan->Jalankan($pelaku, $t, $alasan));
    }

    /**
     * @param  list<string>  $uuid
     * @return array{Diubah: int, Dilewati: array<string, int>}
     */
    public function TulisCatatan(PenggunaPengelola $pelaku, array $uuid, string $isi): array
    {
        return $this->Terapkan($uuid, fn (Tenant $t) => $this->catatan->Jalankan($pelaku, $t, $isi));
    }

    /**
     * @param  list<string>  $uuid
     * @param  callable(Tenant): mixed  $aksi
     * @return array{Diubah: int, Dilewati: array<string, int>}
     *
     * @throws PelanggaranAturanBisnis PilihanKosong, TerlaluBanyak
     */
    private function Terapkan(array $uuid, callable $aksi): array
    {
        $uuid = array_values(array_unique($uuid));

        if ($uuid === []) {
            throw new PelanggaranAturanBisnis('PilihanKosong', 'Pilih minimal satu tenant.', 'Uuid');
        }

        if (count($uuid) > self::MAKS) {
            throw new PelanggaranAturanBisnis('TerlaluBanyak', 'Maksimal '.self::MAKS.' tenant sekali proses.', 'Uuid');
        }

        $tenant = Tenant::query()->whereIn('Uuid', $uuid)->get()->keyBy('Uuid');
        $diubah = 0;
        $dilewati = [];

        foreach ($uuid as $u) {
            $t = $tenant->get($u);

            if ($t === null) {
                $dilewati['Tenant tidak ditemukan.'] = ($dilewati['Tenant tidak ditemukan.'] ?? 0) + 1;

                continue;
            }

            try {
                $aksi($t);
                $diubah++;
            } catch (PelanggaranAturanBisnis $galat) {
                $dilewati[$galat->getMessage()] = ($dilewati[$galat->getMessage()] ?? 0) + 1;
            }
        }

        return ['Diubah' => $diubah, 'Dilewati' => $dilewati];
    }
}
