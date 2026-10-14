<?php

declare(strict_types=1);

namespace App\Domain\Organisasi\Aksi;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Organisasi\Enum\JenisNotifikasiPengguna;
use App\Domain\Organisasi\Kueri\AksesPengguna;
use App\Domain\Organisasi\Model\NotifikasiDibisukan;
use App\Domain\Organisasi\Model\NotifikasiOutletDibisukan;
use App\Domain\Organisasi\Model\NotifikasiPushDimatikan;
use App\Domain\Organisasi\Model\Outlet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * F31 + PLT-08: mengganti preferensi notifikasi satu pengguna di tenant aktif. Tiap daftar yang dikirim menggantikan
 * yang lama; `null` berarti tidak diubah.
 * - `$jenisDibisukan`: jenis yang tidak dibuat sama sekali (web maupun push);
 * - `$jenisTanpaPush`: jenis yang tetap tampil di pusat notifikasi web tetapi tidak dikirim sebagai push;
 * - `$uuidOutletDibisukan`: outlet (yang boleh diakses pengguna) yang kejadiannya tidak dikabarkan kepadanya.
 * Jenis tidak dikenal dan Persetujuan ditolak (menunggu keputusan pengguna). Ini preferensi pribadi, jadi tidak
 * diaudit; notifikasi yang sudah ada tidak dihapus.
 */
final class UbahPengaturanNotifikasi
{
    public function __construct(private readonly AksesPengguna $akses) {}

    /**
     * @param  list<string>  $jenisDibisukan
     * @param  list<string>|null  $jenisTanpaPush
     * @param  list<string>|null  $uuidOutletDibisukan
     *
     * @throws PelanggaranAturanBisnis JenisNotifikasiTidakDikenal, PersetujuanTidakBisaDibisukan, OutletTidakDikenal
     */
    public function Jalankan(int $idPengguna, array $jenisDibisukan, ?array $jenisTanpaPush = null, ?array $uuidOutletDibisukan = null): void
    {
        $jenis = $this->UraiJenis($jenisDibisukan);
        $tanpaPush = $jenisTanpaPush === null ? null : $this->UraiJenis($jenisTanpaPush);
        $idOutlet = $uuidOutletDibisukan === null ? null : $this->UraiOutlet($idPengguna, $uuidOutletDibisukan);

        DB::transaction(function () use ($idPengguna, $jenis, $tanpaPush, $idOutlet): void {
            $this->Selaraskan(NotifikasiDibisukan::class, $idPengguna, 'Jenis', array_keys($jenis));

            if ($tanpaPush !== null) {
                $this->Selaraskan(NotifikasiPushDimatikan::class, $idPengguna, 'Jenis', array_keys($tanpaPush));
            }

            if ($idOutlet !== null) {
                $this->Selaraskan(NotifikasiOutletDibisukan::class, $idPengguna, 'IdOutlet', $idOutlet);
            }
        });
    }

    /**
     * @param  list<string>  $nilai
     * @return array<string, JenisNotifikasiPengguna>
     */
    private function UraiJenis(array $nilai): array
    {
        $jenis = [];

        foreach (array_unique($nilai) as $teks) {
            $pilihan = JenisNotifikasiPengguna::tryFrom($teks);

            if ($pilihan === null) {
                throw new PelanggaranAturanBisnis('JenisNotifikasiTidakDikenal', 'Jenis notifikasi tidak dikenal.');
            }

            if (! $pilihan->BisaDibisukan()) {
                throw new PelanggaranAturanBisnis('PersetujuanTidakBisaDibisukan', 'Permintaan persetujuan tidak bisa dibisukan karena menunggu keputusan Anda.');
            }

            $jenis[$pilihan->value] = $pilihan;
        }

        return $jenis;
    }

    /**
     * @param  list<string>  $uuid
     * @return list<int>
     */
    private function UraiOutlet(int $idPengguna, array $uuid): array
    {
        if ($uuid === []) {
            return [];
        }

        $idTenant = app(KonteksTenant::class)->Wajib();
        $boleh = $this->akses->AmbilIdOutlet($idTenant, $idPengguna);
        $outlet = Outlet::query()->whereIn('Uuid', array_map('strtoupper', array_unique($uuid)))
            ->when($boleh !== null, fn ($kueri) => $kueri->whereIn('Id', $boleh === [] ? [0] : $boleh))
            ->pluck('Id')->map(fn (mixed $id): int => (int) $id)->all();

        if (count($outlet) !== count(array_unique($uuid))) {
            throw new PelanggaranAturanBisnis('OutletTidakDikenal', 'Outlet tidak ditemukan atau di luar akses Anda.', 'OutletDibisukan');
        }

        return array_values($outlet);
    }

    /**
     * Mengganti isi satu himpunan preferensi pengguna (hapus yang tidak lagi disebut, tambah yang baru).
     *
     * @param  class-string<Model>  $model
     * @param  list<string|int>  $nilaiBaru
     */
    private function Selaraskan(string $model, int $idPengguna, string $kolom, array $nilaiBaru): void
    {
        $model::query()->where('IdPengguna', $idPengguna)->when($nilaiBaru !== [], fn ($kueri) => $kueri->whereNotIn($kolom, $nilaiBaru))->delete();
        $ada = $model::query()->where('IdPengguna', $idPengguna)->pluck($kolom)->map(fn (mixed $n): string => $n instanceof JenisNotifikasiPengguna ? $n->value : (string) $n)->all();

        foreach (array_diff(array_map('strval', $nilaiBaru), $ada) as $baru) {
            $model::query()->create(['IdPengguna' => $idPengguna, $kolom => $kolom === 'IdOutlet' ? (int) $baru : $baru]);
        }
    }
}
