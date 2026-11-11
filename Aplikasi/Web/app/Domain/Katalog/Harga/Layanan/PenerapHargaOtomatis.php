<?php

declare(strict_types=1);

namespace App\Domain\Katalog\Harga\Layanan;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Katalog\Harga\Aksi\TetapkanHargaDasarProduk;
use App\Domain\Katalog\Harga\Data\HasilHargaOtomatis;
use App\Domain\Katalog\Harga\Enum\ArahHargaOtomatis;
use App\Domain\Katalog\Harga\Enum\StatusUsulanHarga;
use App\Domain\Katalog\Harga\Enum\SumberPerubahanHarga;
use App\Domain\Katalog\Harga\Model\PengaturanHargaOtomatis;
use App\Domain\Katalog\Harga\Model\UsulanHargaOtomatis;
use App\Domain\Katalog\Model\Produk;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * Inti harga otomatis (D-266): untuk tiap produk yang punya modal (harga beli terakhir per satuan dasar), cari aturan
 * yang berlaku, hitung harga jual baru di satuan jual utama, lalu
 * - tidak berbuat apa-apa bila hasilnya sama dengan harga sekarang (pembulatan meredam gerak kecil),
 * - menerapkannya lewat `TetapkanHargaDasarProduk` (riwayat harga + audit, sumber Otomatis) bila perubahannya masih
 *   dalam batas aman,
 * - atau menahannya sebagai `UsulanHargaOtomatis` bila perubahannya melewati batas aman.
 *
 * Yang diubah hanya harga dasar (jumlah minimum 1) di satuan jual utama, di semua outlet sekaligus. Harga bertingkat,
 * daftar harga, dan satuan lain tidak disentuh. Hanya produk berstok biasa yang harganya tidak diketik kasir. Tiap
 * produk diproses di transaksinya sendiri dan hitungannya selalu dari keadaan terakhir, jadi aman diulang.
 */
final class PenerapHargaOtomatis
{
    public function __construct(
        private readonly TetapkanHargaDasarProduk $tetapkan,
        private readonly PerencanaHargaDariModal $perencana,
    ) {}

    /**
     * @param  array<int, array{Modal: string, IdPenerimaanBarang: int}>  $modal  kunci = IdProduk, modal per satuan dasar
     */
    public function Terapkan(array $modal, ?int $idPengguna = null): HasilHargaOtomatis
    {
        $hasil = new HasilHargaOtomatis;
        $pengaturan = PengaturanHargaOtomatis::query()->first();

        if ($modal === [] || $pengaturan === null || ! $pengaturan->Aktif) {
            return $hasil;
        }

        $batas = BigDecimal::of($pengaturan->BatasPerubahanPersen);
        $penentu = PenentuAturanHarga::Muat();

        if (! $penentu->CekAdaAturan()) {
            return $hasil;
        }

        foreach (Produk::query()->whereIn('Id', array_keys($modal))->orderBy('Id')->get() as $produk) {
            $aturan = $penentu->Tentukan($produk);

            if ($aturan === null) {
                continue;
            }

            $rencana = $this->perencana->Rencanakan($produk, $modal[$produk->Id], BigDecimal::of($aturan->PersenUntung), $aturan->Pembulatan);

            if (! in_array($rencana['Status'], [PerencanaHargaDariModal::SAMA, PerencanaHargaDariModal::BERUBAH], true)) {
                $hasil->dilewati++;

                continue;
            }

            /** @var Uang $lama */
            $lama = $rencana['Lama'];
            /** @var Uang $baru */
            $baru = $rencana['Baru'];
            $selisih = $baru->Bandingkan($lama);

            if ($selisih === 0 || ($selisih < 0 && $aturan->Arah === ArahHargaOtomatis::HanyaNaik)) {
                $this->TutupUsulanMenunggu($produk->Id, null);
                $hasil->tetap++;

                continue;
            }

            $persen = PenghitungHargaDariModal::HitungPerubahanPersen($lama, $baru);

            try {
                if ($persen->abs()->isGreaterThan($batas)) {
                    $this->Usulkan($produk, $rencana['Satuan']->Id ?? 0, $modal[$produk->Id], $rencana['ModalJual'] ?? BigDecimal::zero(), $aturan->PersenUntung, $lama, $baru, $persen);
                    $hasil->diusulkan++;
                } else {
                    $this->TerapkanLangsung($produk, $baru, $idPengguna);
                    $hasil->diterapkan++;
                }
            } catch (PelanggaranAturanBisnis) {
                $hasil->dilewati++;

                continue;
            }

            $hasil->perubahan[] = ['Nama' => $produk->Nama, 'Lama' => $lama->KeString(), 'Baru' => $baru->KeString()];
        }

        return $hasil;
    }

    /**
     * Menerapkan harga baru ke harga dasar produk dan menutup usulan yang masih menunggu untuk produk itu.
     *
     * @throws PelanggaranAturanBisnis
     */
    public function TerapkanLangsung(Produk $produk, Uang $harga, ?int $idPengguna, SumberPerubahanHarga $sumber = SumberPerubahanHarga::Otomatis): void
    {
        DB::transaction(function () use ($produk, $harga, $idPengguna, $sumber): void {
            $this->tetapkan->Jalankan($produk, $harga, $sumber);
            $this->TutupUsulanMenunggu($produk->Id, $idPengguna);
        });
    }

    /**
     * @param  array{Modal: string, IdPenerimaanBarang: int}  $modalDasar
     */
    private function Usulkan(Produk $produk, int $idProdukSatuan, array $modalDasar, BigDecimal $modalJual, string $persenUntung, Uang $lama, Uang $baru, BigDecimal $persen): void
    {
        DB::transaction(function () use ($produk, $idProdukSatuan, $modalDasar, $modalJual, $persenUntung, $lama, $baru, $persen): void {
            $this->TutupUsulanMenunggu($produk->Id, null);

            UsulanHargaOtomatis::query()->create([
                'IdProduk' => $produk->Id,
                'IdProdukSatuan' => $idProdukSatuan,
                'IdPenerimaanBarang' => $modalDasar['IdPenerimaanBarang'],
                'Modal' => (string) $modalJual->toScale(6),
                'PersenUntung' => $persenUntung,
                'HargaLama' => $lama->KeString(),
                'HargaBaru' => $baru->KeString(),
                'PerubahanPersen' => (string) $persen,
                'Status' => StatusUsulanHarga::Menunggu,
            ]);
        });
    }

    /** Usulan yang masih menunggu untuk produk ini sudah tidak relevan (harga berubah atau ada hitungan yang lebih baru). */
    private function TutupUsulanMenunggu(int $idProduk, ?int $idPengguna): void
    {
        UsulanHargaOtomatis::query()
            ->where('IdProduk', $idProduk)
            ->where('Status', StatusUsulanHarga::Menunggu->value)
            ->update(['Status' => StatusUsulanHarga::Digantikan->value, 'DiputuskanPada' => now(), 'DiputuskanOleh' => $idPengguna]);
    }
}
