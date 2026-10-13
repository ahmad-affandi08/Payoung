<?php

declare(strict_types=1);

namespace App\Domain\Bengkel\Layanan;

use App\Domain\Bengkel\Kueri\DaftarPerintahKerja;
use App\Domain\Bengkel\Model\Kendaraan;
use App\Domain\Bengkel\Model\PerintahKerja;
use App\Domain\Bersama\Tindakan\Data\DataButirTindakan;
use App\Domain\Bersama\Tindakan\Data\DataKonteksTindakan;
use App\Domain\Bersama\Tindakan\Data\DataRincianTindakan;
use App\Domain\Bersama\Tindakan\Enum\TingkatTindakan;
use App\Domain\Bersama\Tindakan\Kontrak\PenyediaTindakan;
use Illuminate\Database\Eloquent\Builder;

/**
 * Kotak Tindakan bengkel (§9.10, izin `bengkel.kelola`, dibatasi outlet akses):
 * - **Servis berkala jatuh tempo** (Perhatian): kendaraan yang jadwal servis berikutnya ≤ 7 hari lagi (atau lewat ≤ 30
 *   hari) dan belum datang lagi — hubungi pelanggan / booking. Selesai sendiri saat kendaraannya punya perintah kerja
 *   baru atau tanggalnya diubah.
 * - **Estimasi menunggu persetujuan > 24 jam** (Perhatian): kendaraan tertahan di bengkel; telepon pelanggan.
 */
final class PenyediaTindakanBengkel implements PenyediaTindakan
{
    public function Kumpulkan(DataKonteksTindakan $konteks): array
    {
        if (! $konteks->CekIzin('bengkel.kelola')) {
            return [];
        }

        $dasar = fn (): Builder => PerintahKerja::query()
            ->when($konteks->idOutletBoleh !== null, fn (Builder $k) => $k->whereIn('IdOutlet', $konteks->idOutletBoleh ?: [0]));

        $jatuhTempo = $dasar();
        DaftarPerintahKerja::TerapkanServisJatuhTempo($jatuhTempo, $konteks->hariIni);
        $menunggu = $dasar();
        DaftarPerintahKerja::TerapkanMenungguLama($menunggu);

        return [
            $this->Butir($jatuhTempo, 'bengkel.servis-jatuh-tempo', 'Servis berkala jatuh tempo', 'Hubungi pelanggan untuk menjadwalkan servis kendaraannya.', 'ServisJatuhTempo', 'ServisBerikutnyaPada'),
            $this->Butir($menunggu, 'bengkel.menunggu-persetujuan', 'Estimasi servis menunggu persetujuan lebih dari sehari', 'Telepon pelanggan atau kirim ulang tautan persetujuannya.', 'MenungguLama', 'TokenPersetujuanKedaluwarsaPada'),
        ];
    }

    public function AmbilJenisDokumen(): array
    {
        return [];
    }

    public function SaringDokumen(string $jenisDokumen, array $uuid): array
    {
        return [];
    }

    /**
     * @param  Builder<PerintahKerja>  $kueri
     */
    private function Butir(Builder $kueri, string $kode, string $judul, string $saran, string $saring, string $kolomUrut): DataButirTindakan
    {
        $jumlah = (clone $kueri)->count();
        $daftar = $jumlah === 0 ? collect() : $kueri->orderBy($kolomUrut)->orderBy('Id')->limit(DataButirTindakan::BATAS_RINCIAN)->get();
        $plat = $daftar->isEmpty() ? collect() : Kendaraan::query()->whereIn('Id', $daftar->pluck('IdKendaraan')->all())->get()->mapWithKeys(fn (Kendaraan $k): array => [$k->Id => $k->AmbilPengenal()]);

        return new DataButirTindakan(
            $kode,
            'Bengkel',
            TingkatTindakan::Perhatian,
            $judul,
            $saran,
            $jumlah,
            '/kelola/bengkel/perintah-kerja?saring[Perhatian]='.$saring,
            'Lihat perintah kerja',
            array_values($daftar->map(fn (PerintahKerja $pk): DataRincianTindakan => new DataRincianTindakan(
                $pk->Uuid,
                (string) ($plat->get($pk->IdKendaraan) ?? $pk->Nomor),
                $pk->Nomor,
                $pk->ServisBerikutnyaPada?->toDateString(),
                '/kelola/bengkel/perintah-kerja/'.$pk->Uuid,
            ))->all()),
        );
    }
}
