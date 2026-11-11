<?php

declare(strict_types=1);

namespace App\Http\Permintaan\Kelola\Katalog;

/**
 * `PUT /kelola/produk/harga-otomatis/pengaturan` (D-266, D-268): isian bersama ditambah `Aktif` dan `HitungUlang`
 * (opsional: simpan lalu hitung ulang semua harga sekarang, hanya bila saklar menyala).
 */
final class UbahPengaturanHargaOtomatisPermintaan extends DasarHargaOtomatisPermintaan
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return ['Aktif' => ['required', 'boolean'], 'HitungUlang' => ['sometimes', 'boolean'], ...$this->AturanDasar()];
    }
}
