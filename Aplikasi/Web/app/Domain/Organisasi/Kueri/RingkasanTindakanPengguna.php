<?php

declare(strict_types=1);

namespace App\Domain\Organisasi\Kueri;

use App\Domain\Bersama\Tindakan\Data\DataButirTindakan;
use App\Domain\Bersama\Tindakan\Kueri\KotakTindakan;
use Illuminate\Support\Facades\Cache;

/**
 * Ringkasan Kotak Tindakan per pengguna (D-23 C, D-204): butir tanpa rincian untuk kartu "Perlu tindakan" di Beranda
 * dan jumlah butir untuk lencana merah di menu samping. Kotak Tindakan menjalankan puluhan COUNT, dan menu samping
 * tampil di setiap halaman, jadi hasilnya disimpan 60 detik per tenant & pengguna (izin dan cakupan outlet berbeda
 * per pengguna). Halaman `/kelola/tindakan` tetap membaca langsung lalu memperbarui jumlahnya lewat `Simpan`, dan
 * menandai dokumen "sudah dicek" mengosongkannya lewat `Lupakan`, supaya lencana tidak tertinggal.
 */
final class RingkasanTindakanPengguna
{
    private const DETIK_SIMPAN = 60;

    public function __construct(
        private readonly KotakTindakan $kotak,
        private readonly KonteksTindakanPengguna $konteks,
        private readonly TanggalBisnisOutlet $tanggal,
    ) {}

    /**
     * @return list<array<string, mixed>> butir tanpa `Rincian` (kosong), urut seperti Kotak Tindakan
     */
    public function Ambil(int $idTenant, int $idPengguna): array
    {
        /** @var list<array<string, mixed>> $butir */
        $butir = Cache::remember(
            $this->Kunci($idTenant, $idPengguna),
            self::DETIK_SIMPAN,
            fn (): array => $this->Ringkas($this->kotak->Ambil($this->konteks->Buat($idTenant, $idPengguna, $this->tanggal->Hitung(null)))),
        );

        return $butir;
    }

    /**
     * Jumlah butir Kotak Tindakan yang perlu ditindak pengguna ini (lencana menu samping). Memakai ringkasan Beranda
     * bila sudah ada (tanpa menghitung dua kali); bila belum, menyimpan jumlahnya saja di kunci sendiri supaya
     * halaman lain tidak mengisi simpanan Beranda.
     */
    public function Hitung(int $idTenant, int $idPengguna): int
    {
        $ringkasan = Cache::get($this->Kunci($idTenant, $idPengguna));

        if (is_array($ringkasan)) {
            return count($ringkasan);
        }

        return (int) Cache::remember(
            $this->KunciJumlah($idTenant, $idPengguna),
            self::DETIK_SIMPAN,
            fn (): int => count($this->kotak->Ambil($this->konteks->Buat($idTenant, $idPengguna, $this->tanggal->Hitung(null)))),
        );
    }

    /**
     * @param  list<DataButirTindakan>  $butir  hasil bacaan langsung `KotakTindakan`
     */
    public function Simpan(int $idTenant, int $idPengguna, array $butir): void
    {
        Cache::put($this->KunciJumlah($idTenant, $idPengguna), count($butir), self::DETIK_SIMPAN);
    }

    public function Lupakan(int $idTenant, int $idPengguna): void
    {
        Cache::forget($this->Kunci($idTenant, $idPengguna));
        Cache::forget($this->KunciJumlah($idTenant, $idPengguna));
    }

    /**
     * @param  list<DataButirTindakan>  $butir
     * @return list<array<string, mixed>>
     */
    private function Ringkas(array $butir): array
    {
        return array_map(fn (DataButirTindakan $b): array => [...$b->KeLarik(false), 'Rincian' => []], $butir);
    }

    private function Kunci(int $idTenant, int $idPengguna): string
    {
        return "tindakan:beranda:{$idTenant}:{$idPengguna}";
    }

    private function KunciJumlah(int $idTenant, int $idPengguna): string
    {
        return "tindakan:jumlah:{$idTenant}:{$idPengguna}";
    }
}
