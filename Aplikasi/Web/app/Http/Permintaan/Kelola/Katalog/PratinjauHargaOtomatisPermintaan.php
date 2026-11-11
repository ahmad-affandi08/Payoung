<?php

declare(strict_types=1);

namespace App\Http\Permintaan\Kelola\Katalog;

/**
 * `POST /kelola/produk/harga-otomatis/pratinjau` (D-268): pengaturan yang belum disimpan, dihitung tanpa menyimpan apa pun.
 */
final class PratinjauHargaOtomatisPermintaan extends DasarHargaOtomatisPermintaan
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return $this->AturanDasar();
    }
}
