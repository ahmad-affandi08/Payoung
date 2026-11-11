<?php

declare(strict_types=1);

namespace App\Http\Permintaan\Kelola\Katalog;

use App\Domain\Katalog\Harga\Data\DataAturanHargaOtomatis;
use App\Domain\Katalog\Harga\Enum\ArahHargaOtomatis;
use App\Domain\Katalog\Harga\Enum\CakupanAturanHarga;
use App\Domain\Katalog\Harga\Layanan\PenghitungPerubahanHarga;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /kelola/produk/harga-otomatis/aturan` dan `PUT .../aturan/{aturan}` (D-266): satu aturan harga otomatis.
 * `UuidKategori` wajib untuk cakupan Kategori, `UuidProduk` untuk cakupan Produk. Izin dan fitur paket dijaga rute.
 */
final class SimpanAturanHargaOtomatisPermintaan extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'Cakupan' => ['required', Rule::enum(CakupanAturanHarga::class)],
            'UuidKategori' => ['nullable', 'ulid', 'required_if:Cakupan,Kategori'],
            'UuidProduk' => ['nullable', 'ulid', 'required_if:Cakupan,Produk'],
            'PersenUntung' => ['required', 'string', 'max:10'],
            'Pembulatan' => ['required', 'integer', Rule::in(PenghitungPerubahanHarga::PEMBULATAN)],
            'Arah' => ['required', Rule::enum(ArahHargaOtomatis::class)],
            'Aktif' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['UuidKategori' => 'kategori', 'UuidProduk' => 'produk', 'PersenUntung' => 'untung', 'Pembulatan' => 'pembulatan'];
    }

    public function AmbilData(): DataAturanHargaOtomatis
    {
        $cakupan = CakupanAturanHarga::from((string) $this->validated('Cakupan'));

        return new DataAturanHargaOtomatis(
            $cakupan,
            $cakupan === CakupanAturanHarga::Kategori ? (string) $this->validated('UuidKategori') : null,
            $cakupan === CakupanAturanHarga::Produk ? (string) $this->validated('UuidProduk') : null,
            (string) $this->validated('PersenUntung'),
            (int) $this->validated('Pembulatan'),
            ArahHargaOtomatis::from((string) $this->validated('Arah')),
            $this->boolean('Aktif'),
        );
    }
}
