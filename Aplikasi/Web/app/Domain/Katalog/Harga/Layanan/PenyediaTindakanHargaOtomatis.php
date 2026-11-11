<?php

declare(strict_types=1);

namespace App\Domain\Katalog\Harga\Layanan;

use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Bersama\Tindakan\Data\DataButirTindakan;
use App\Domain\Bersama\Tindakan\Data\DataKonteksTindakan;
use App\Domain\Bersama\Tindakan\Data\DataRincianTindakan;
use App\Domain\Bersama\Tindakan\Enum\TingkatTindakan;
use App\Domain\Bersama\Tindakan\Kontrak\PenyediaTindakan;
use App\Domain\Katalog\Harga\Enum\StatusUsulanHarga;
use App\Domain\Katalog\Harga\Model\UsulanHargaOtomatis;

/**
 * Kotak Tindakan harga otomatis (D-266, izin `produk.harga.ubah`): perubahan harga yang melewati batas aman dan
 * menunggu keputusan pemilik. Butir ini selesai sendiri begitu usulannya diterapkan, ditolak, atau digantikan, jadi
 * tidak ada yang perlu ditandai "sudah dicek".
 */
final class PenyediaTindakanHargaOtomatis implements PenyediaTindakan
{
    public function Kumpulkan(DataKonteksTindakan $konteks): array
    {
        if (! $konteks->CekIzin('produk.harga.ubah')) {
            return [];
        }

        $menunggu = UsulanHargaOtomatis::query()->where('Status', StatusUsulanHarga::Menunggu->value)->with('Produk:Id,Nama')->orderByDesc('Id')->get();

        if ($menunggu->isEmpty()) {
            return [];
        }

        return [new DataButirTindakan(
            'harga-otomatis.usulan',
            'Produk',
            TingkatTindakan::Perhatian,
            'Perubahan harga besar menunggu keputusan',
            'Harga beli berubah cukup besar, jadi harga jual belum diubah sendiri. Terapkan atau tolak usulannya.',
            $menunggu->count(),
            '/kelola/produk/harga-otomatis',
            'Lihat usulan',
            array_values($menunggu->take(DataButirTindakan::BATAS_RINCIAN)->map(fn (UsulanHargaOtomatis $u): DataRincianTindakan => new DataRincianTindakan(
                $u->Uuid,
                $u->Produk->Nama,
                Uang::Dari((string) $u->HargaLama)->FormatRupiah().' ke '.Uang::Dari((string) $u->HargaBaru)->FormatRupiah(),
                $u->DibuatPada?->toDateString(),
                null,
            ))->all()),
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
