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
 * `PUT /kelola/produk/harga-otomatis/pengaturan` (D-266): `{Aktif, BatasPerubahanPersen, PersenUntung, Pembulatan, Arah, Khusus[]}`. `Khusus` (boleh kosong) menggantikan seluruh daftar untung khusus. Izin dan fitur paket dijaga rute.
 */
final class UbahPengaturanHargaOtomatisPermintaan extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'Aktif' => ['required', 'boolean'],
            'BatasPerubahanPersen' => ['required', 'string', 'max:8'],
            'PersenUntung' => ['required', 'string', 'max:10'],
            'Pembulatan' => ['required', 'integer', Rule::in(PenghitungPerubahanHarga::PEMBULATAN)],
            'Arah' => ['required', Rule::enum(ArahHargaOtomatis::class)],
            'Khusus' => ['present', 'array', 'max:200'],
            'Khusus.*.Cakupan' => ['required', Rule::in([CakupanAturanHarga::Kategori->value, CakupanAturanHarga::Produk->value])],
            'Khusus.*.UuidKategori' => ['nullable', 'ulid', 'required_if:Khusus.*.Cakupan,Kategori'],
            'Khusus.*.UuidProduk' => ['nullable', 'ulid', 'required_if:Khusus.*.Cakupan,Produk'],
            'Khusus.*.PersenUntung' => ['required', 'string', 'max:10'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'BatasPerubahanPersen' => 'batas perubahan',
            'PersenUntung' => 'untung',
            'Pembulatan' => 'pembulatan',
            'Khusus.*.UuidKategori' => 'kategori',
            'Khusus.*.UuidProduk' => 'produk',
            'Khusus.*.PersenUntung' => 'untung khusus',
        ];
    }

    /**
     * @return list<DataAturanHargaOtomatis> pembulatan dan arah diisi ulang oleh aksi
     */
    public function AmbilKhusus(): array
    {
        /** @var list<array<string, mixed>> $baris */
        $baris = array_values((array) $this->validated('Khusus'));

        return array_map(fn (array $b): DataAturanHargaOtomatis => new DataAturanHargaOtomatis(
            CakupanAturanHarga::from((string) $b['Cakupan']),
            $b['Cakupan'] === CakupanAturanHarga::Kategori->value ? (string) $b['UuidKategori'] : null,
            $b['Cakupan'] === CakupanAturanHarga::Produk->value ? (string) $b['UuidProduk'] : null,
            (string) $b['PersenUntung'],
            0,
            ArahHargaOtomatis::NaikTurun,
            true,
        ), $baris);
    }
}
