<?php

declare(strict_types=1);

namespace App\Domain\Persediaan\Layanan;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Persediaan\Enum\StatusNomorSeri;
use App\Domain\Persediaan\Model\NomorSeri;
use Illuminate\Support\Str;
use LogicException;

/**
 * Kunci & pembaruan NomorSeri (kunci L5) untuk mutasi produk ber-pelacakan Seri (DesainF05a C.4, F-05h).
 * - Nomor seri unik per produk (H-19), bukan per tenant. Satu baris mutasi seri = satu unit.
 * - Hanya dipanggil di dalam transaksi mesin buku stok, setelah SaldoStok (L3) dan BatchStok (L4). Pemanggil
 *   mengurutkan pemanggilan per (IdProduk, Nomor) agar urutan kunci global terjaga.
 * - Masuk: `KunciMasuk` lalu `TandaiMasuk`. Keluar: `KunciKeluar` lalu `TandaiKeluar`.
 */
final class PelacakNomorSeri
{
    public function __construct(private readonly KonteksTenant $konteks) {}

    /**
     * Nomor seri untuk mutasi masuk: dibuat bila belum ada (upsert berstatus `Keluar`, belum di stok), lalu dibaca
     * `FOR UPDATE`. Nomor yang masih `Tersedia` atau sedang dalam perjalanan ditolak (`NomorSeriSudahAda`); nomor
     * berstatus `Keluar`/`Terjual` diaktifkan kembali oleh `TandaiMasuk`.
     */
    public function KunciMasuk(int $idProduk, int $idGudang, string $nomor): NomorSeri
    {
        $nomor = trim($nomor);

        if ($nomor === '' || mb_strlen($nomor) > 100) {
            throw new PelanggaranAturanBisnis('NomorSeriTidakTersedia', 'Nomor seri wajib diisi, 1–100 karakter.', 'NomorSeri');
        }

        NomorSeri::query()->upsert(
            [[
                'Uuid' => (string) Str::ulid(),
                'IdTenant' => $this->konteks->Wajib(),
                'IdProduk' => $idProduk,
                'Nomor' => $nomor,
                'Status' => StatusNomorSeri::Keluar->value,
                'IdGudang' => null,
            ]],
            ['IdTenant', 'IdProduk', 'Nomor'],
            ['DiubahPada'],
        );

        $seri = NomorSeri::query()
            ->where('IdProduk', $idProduk)
            ->where('Nomor', $nomor)
            ->lockForUpdate()
            ->firstOrFail();

        if ($seri->Status === StatusNomorSeri::Tersedia || $seri->Status === StatusNomorSeri::DalamPerjalanan) {
            $keadaan = $seri->Status === StatusNomorSeri::Tersedia ? 'masih tercatat di stok' : 'sedang dalam perjalanan transfer';

            throw new PelanggaranAturanBisnis(
                'NomorSeriSudahAda',
                "Nomor seri {$seri->Nomor} {$keadaan}. Periksa lagi nomor serinya.",
                'NomorSeri',
                detail: ['UuidNomorSeri' => $seri->Uuid, 'NomorSeri' => $seri->Nomor, 'Status' => $seri->Status->value],
            );
        }

        return $seri;
    }

    /** Nomor seri untuk mutasi keluar: dikunci `FOR UPDATE`; wajib `Tersedia` di lokasi stok itu. */
    public function KunciKeluar(int $idNomorSeri, int $idProduk, int $idGudang): NomorSeri
    {
        $seri = NomorSeri::query()->whereKey($idNomorSeri)->lockForUpdate()->first();

        if ($seri === null || (int) $seri->IdProduk !== $idProduk) {
            throw new PelanggaranAturanBisnis('NomorSeriTidakTersedia', 'Nomor seri tidak ditemukan untuk produk ini. Muat ulang lalu pilih nomor seri lagi.', 'NomorSeri');
        }

        if ($seri->Status !== StatusNomorSeri::Tersedia || $seri->IdGudang === null || (int) $seri->IdGudang !== $idGudang) {
            throw new PelanggaranAturanBisnis(
                'NomorSeriTidakTersedia',
                "Nomor seri {$seri->Nomor} tidak tersedia di lokasi stok ini.",
                'NomorSeri',
                detail: ['UuidNomorSeri' => $seri->Uuid, 'NomorSeri' => $seri->Nomor, 'Status' => $seri->Status->value],
            );
        }

        return $seri;
    }

    /** Menandai nomor seri (sudah dikunci) masuk stok lokasi `idGudang`. */
    public function TandaiMasuk(NomorSeri $seri, int $idGudang): void
    {
        $seri->Status = StatusNomorSeri::Tersedia;
        $seri->IdGudang = $idGudang;
        $seri->IdPenjualanDetail = null;
        $seri->save();
    }

    /** Menandai nomor seri (sudah dikunci) keluar dari stok dengan status `status` (bukan `Tersedia`). */
    public function TandaiKeluar(NomorSeri $seri, StatusNomorSeri $status): void
    {
        if ($status === StatusNomorSeri::Tersedia) {
            throw new LogicException('Status keluar nomor seri tidak boleh Tersedia. Pakai TandaiMasuk.');
        }

        $seri->Status = $status;
        $seri->IdGudang = null;
        $seri->save();
    }

    /**
     * F-05h: menautkan nomor seri yang baru `Terjual` ke baris penjualannya (riwayat & garansi). `TandaiMasuk`
     * (void/retur) mengosongkannya lagi.
     *
     * @param  list<int>  $idNomorSeri
     */
    public function TautkanPenjualanDetail(array $idNomorSeri, int $idPenjualanDetail): void
    {
        if ($idNomorSeri === []) {
            return;
        }

        NomorSeri::query()->whereIn('Id', $idNomorSeri)->where('Status', StatusNomorSeri::Terjual->value)->update(['IdPenjualanDetail' => $idPenjualanDetail]);
    }
}
