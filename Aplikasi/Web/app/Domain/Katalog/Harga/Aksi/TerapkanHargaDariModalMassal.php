<?php

declare(strict_types=1);

namespace App\Domain\Katalog\Harga\Aksi;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Katalog\Harga\Enum\SumberPerubahanHarga;
use App\Domain\Katalog\Harga\Kueri\PratinjauHargaDariModal;
use App\Domain\Katalog\Harga\Layanan\PenerapHargaOtomatis;
use App\Domain\Katalog\Harga\Layanan\PerencanaHargaDariModal;
use Illuminate\Support\Facades\DB;

/**
 * "Harga dari harga beli" massal (D-266): harga jual produk terpilih dihitung dari harga beli terakhir ditambah untung
 * persen dan pembulatan, lalu disimpan sekali. Memakai hitungan yang sama dengan pratinjaunya, jadi yang diterapkan
 * persis yang terlihat. Hanya produk yang statusnya Berubah yang disimpan, yang lain dilewati. Riwayat harga mencatat
 * sumber Manual (pemilik yang memutuskan). Semua atau tidak sama sekali (satu transaksi).
 */
final class TerapkanHargaDariModalMassal
{
    public function __construct(
        private readonly PratinjauHargaDariModal $pratinjau,
        private readonly PenerapHargaOtomatis $penerap,
    ) {}

    /**
     * @param  list<string>  $uuid
     * @return array{Produk: int, Dilewati: int} jumlah produk yang harganya berubah dan yang dilewati
     *
     * @throws PelanggaranAturanBisnis PilihanKosong, TerlaluBanyak, ProdukTidakDikenal, UntungTidakValid, PembulatanTidakDikenal
     */
    public function Jalankan(array $uuid, string $persenUntung, int $pembulatan, int $idPengguna): array
    {
        return DB::transaction(function () use ($uuid, $persenUntung, $pembulatan, $idPengguna): array {
            $berubah = 0;
            $dilewati = 0;

            foreach ($this->pratinjau->Susun($uuid, $persenUntung, $pembulatan) as $item) {
                $rencana = $item['Rencana'];

                if ($rencana['Status'] !== PerencanaHargaDariModal::BERUBAH || $rencana['Baru'] === null) {
                    $dilewati++;

                    continue;
                }

                $this->penerap->TerapkanLangsung($item['Produk'], $rencana['Baru'], $idPengguna, SumberPerubahanHarga::Manual);
                $berubah++;
            }

            return ['Produk' => $berubah, 'Dilewati' => $dilewati];
        });
    }
}
