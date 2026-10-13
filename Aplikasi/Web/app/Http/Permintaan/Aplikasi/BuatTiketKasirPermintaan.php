<?php

declare(strict_types=1);

namespace App\Http\Permintaan\Aplikasi;

/**
 * Tiket dari Aplikasi Kasir: staf pelapor (`UuidPengguna`) dipilih di perangkat, diperiksa keanggotaan outletnya.
 */
final class BuatTiketKasirPermintaan extends BuatTiketAplikasiPermintaan
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [...parent::rules(), 'UuidPengguna' => ['required', 'string', 'ulid']];
    }
}
