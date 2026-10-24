<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Layanan;

use App\Domain\Bersama\Tindakan\Data\DataButirTindakan;
use App\Domain\Bersama\Tindakan\Data\DataKonteksTindakan;
use App\Domain\Bersama\Tindakan\Enum\TingkatTindakan;
use App\Domain\Bersama\Tindakan\Kontrak\PenyediaTindakan;
use App\Domain\Bersama\Tindakan\Kontrak\TerbatasSektor;
use App\Domain\Penjualan\Enum\StatusKuotaTransaksi;

/**
 * Kotak Tindakan kuota transaksi penjualan per bulan (P-04): hampir habis (Perhatian, ≥ 80%) atau habis (Penting).
 * Untuk pemegang izin `langganan.kelola`; tidak muncul bila paket tanpa batas. Selesai sendiri saat bulan berganti atau
 * paket/add-on menambah kuota.
 */
final class PenyediaTindakanKuotaTransaksi implements PenyediaTindakan, TerbatasSektor
{
    public const KODE = 'kuota.transaksi';

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

        $kuota = $this->kuota->Ambil($konteks->idTenant);

        if (! in_array($kuota->status, [StatusKuotaTransaksi::HampirHabis, StatusKuotaTransaksi::Habis], true)) {
            return [];
        }

        $habis = $kuota->status === StatusKuotaTransaksi::Habis;

        return [new DataButirTindakan(
            self::KODE,
            'Langganan',
            $habis ? TingkatTindakan::Penting : TingkatTindakan::Perhatian,
            $habis ? 'Kuota transaksi bulan ini habis' : 'Kuota transaksi bulan ini hampir habis',
            "Terpakai {$kuota->terpakai} dari {$kuota->batas} transaksi."
                .($habis ? ' Layar jual aplikasi kasir terkunci dan pesanan online baru ditolak sampai bulan depan atau kuota bertambah.' : '')
                .' Tingkatkan paket atau tambah add-on transaksi.',
            1,
            '/kelola/langganan',
            'Lihat langganan',
        )];
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
