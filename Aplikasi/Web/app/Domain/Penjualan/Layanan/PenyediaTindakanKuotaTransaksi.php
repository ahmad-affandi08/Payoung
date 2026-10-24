<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Layanan;

use App\Domain\Bersama\Tindakan\Data\DataButirTindakan;
use App\Domain\Bersama\Tindakan\Data\DataKonteksTindakan;
use App\Domain\Bersama\Tindakan\Enum\TingkatTindakan;
use App\Domain\Bersama\Tindakan\Kontrak\PenyediaTindakan;
use App\Domain\Bersama\Tindakan\Kontrak\TerbatasSektor;
use App\Domain\Penjualan\Enum\JangkaKuotaTransaksi;
use App\Domain\Penjualan\Enum\StatusKuotaTransaksi;

/**
 * Kotak Tindakan kuota transaksi penjualan (P-04): satu butir per jangka (bulan, hari) yang hampir habis (Perhatian,
 * >= 80%) atau habis (Penting). Untuk pemegang izin `langganan.kelola`; tidak muncul bila paket tanpa batas. Selesai
 * sendiri saat bulan/hari berganti atau paket/add-on menambah kuota.
 */
final class PenyediaTindakanKuotaTransaksi implements PenyediaTindakan, TerbatasSektor
{
    public function __construct(private readonly KuotaTransaksiTenant $kuota) {}

    public function AmbilSektor(): ?array
    {
        return null;
    }

    public function AmbilKecualiSektor(): array
    {
        return ['BIZ'];
    }

    public function Kumpulkan(DataKonteksTindakan $konteks): array
    {
        if (! $konteks->CekIzin('langganan.kelola')) {
            return [];
        }

        $butir = [];

        foreach (JangkaKuotaTransaksi::cases() as $jangka) {
            $kuota = $this->kuota->Ambil($konteks->idTenant, $jangka);

            if (! in_array($kuota->status, [StatusKuotaTransaksi::HampirHabis, StatusKuotaTransaksi::Habis], true)) {
                continue;
            }

            $habis = $kuota->status === StatusKuotaTransaksi::Habis;
            $sebutan = $jangka->AmbilSebutan();

            $butir[] = new DataButirTindakan(
                $jangka->AmbilKodeTindakan(),
                'Langganan',
                $habis ? TingkatTindakan::Penting : TingkatTindakan::Perhatian,
                $habis ? "Kuota transaksi {$sebutan} habis" : "Kuota transaksi {$sebutan} hampir habis",
                "Terpakai {$kuota->terpakai} dari {$kuota->batas} transaksi {$sebutan}."
                    .($habis ? ' Layar jual aplikasi kasir terkunci dan pesanan online baru ditolak sampai kuota bertambah atau '.($jangka === JangkaKuotaTransaksi::Hari ? 'hari bisnis berganti.' : 'bulan depan.') : '')
                    .' Tingkatkan paket atau tambah add-on transaksi.',
                1,
                '/kelola/langganan',
                'Lihat langganan',
            );
        }

        return $butir;
    }

    public function AmbilJenisDokumen(): array
    {
        return [];
    }

    public function SaringDokumen(string $jenisDokumen, array $uuid): array
    {
        return [];
    }
}
