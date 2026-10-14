<?php

declare(strict_types=1);

namespace App\Http\Permintaan\Kelola\Katalog;

use App\Domain\Bersama\Nilai\Kuantitas;
use App\Http\Permintaan\Kelola\Persediaan\SimpanStokAwalPermintaan;
use Brick\Math\BigDecimal;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Grid ukuran × warna (D-143) `POST /kelola/produk/{produk}/varian/stok-awal`:
 * `{UuidGudang, Baris: [{Uuid, Jumlah, HargaBeli?}]}`. Izin kelola & posting stok awal dan keanggotaan varian pada
 * induk diperiksa kontroler/Aksi; jumlah & harga beli diperiksa ulang `PemeriksaStokAwal`.
 */
final class CatatStokAwalVarianPermintaan extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'UuidGudang' => ['required', 'ulid'],
            'Baris' => ['required', 'array', 'min:1', 'max:500'],
            'Baris.*.Uuid' => ['required', 'ulid', 'distinct'],
            'Baris.*.Jumlah' => ['required', SimpanStokAwalPermintaan::POLA_JUMLAH],
            'Baris.*.HargaBeli' => ['nullable', SimpanStokAwalPermintaan::POLA_HPP],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['UuidGudang' => 'lokasi stok', 'Baris.*.Jumlah' => 'stok', 'Baris.*.HargaBeli' => 'harga beli'];
    }

    public function AmbilUuidGudang(): string
    {
        return strtoupper((string) $this->validated('UuidGudang'));
    }

    /**
     * @return list<array{Uuid: string, Jumlah: Kuantitas, HargaBeli: BigDecimal}>
     */
    public function AmbilBaris(): array
    {
        /** @var list<array<string, mixed>> $baris */
        $baris = array_values((array) $this->validated('Baris'));

        return array_map(fn (array $b): array => [
            'Uuid' => strtoupper((string) $b['Uuid']),
            'Jumlah' => Kuantitas::Dari((string) $b['Jumlah']),
            'HargaBeli' => BigDecimal::of(isset($b['HargaBeli']) && $b['HargaBeli'] !== '' ? (string) $b['HargaBeli'] : '0'),
        ], $baris);
    }
}
