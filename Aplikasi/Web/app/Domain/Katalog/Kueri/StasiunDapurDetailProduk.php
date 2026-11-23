<?php

declare(strict_types=1);

namespace App\Domain\Katalog\Kueri;

use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Organisasi\Kueri\DaftarStasiunDapur;

/**
 * D-310: data panel "Stasiun dapur" di detail produk. Null bila panel tidak relevan (tenant tanpa stasiun yang
 * memasak, jasa, atau varian anak yang ikut produk induknya).
 */
final class StasiunDapurDetailProduk
{
    public function __construct(
        private readonly DaftarStasiunDapur $stasiun,
        private readonly StasiunDapurProduk $stasiunProduk,
    ) {}

    /**
     * @return array{Opsi: list<array{Nilai: string, Label: string}>, UuidStasiun: string|null, NamaStasiunKategori: string|null, NamaStasiunDiarsipkan: string|null}|null
     */
    public function Ambil(Produk $produk): ?array
    {
        if ($produk->IdInduk !== null || $produk->Jenis === JenisProduk::Jasa || ! $this->stasiun->CekAdaStasiunMemasak()) {
            return null;
        }

        $peta = $this->stasiun->AmbilPetaId();
        $dariKategori = $this->stasiunProduk->AmbilPerProduk([$produk->Id], abaikanProduk: true)[$produk->Id] ?? null;
        $langsung = $produk->IdStasiunDapur;
        $dataLangsung = $langsung === null ? null : ($peta[$langsung] ?? null);

        return [
            'Opsi' => $this->stasiun->AmbilOpsiAktif(),
            'UuidStasiun' => $dataLangsung['Uuid'] ?? null,
            'NamaStasiunKategori' => $dariKategori === null ? null : ($peta[$dariKategori]['Nama'] ?? null),
            'NamaStasiunDiarsipkan' => $dataLangsung !== null && ! $dataLangsung['Aktif'] ? $dataLangsung['Nama'] : null,
        ];
    }
}
