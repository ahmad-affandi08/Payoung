<?php

declare(strict_types=1);

namespace App\Http\Permintaan\Aplikasi;

/**
 * Balasan dari Aplikasi Kasir: hanya staf pelapor tiketnya (`UuidPengguna`) yang boleh membalas.
 */
final class BalasTiketKasirPermintaan extends BalasTiketAplikasiPermintaan
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [...parent::rules(), 'UuidPengguna' => ['required', 'string', 'ulid']];
    }
}
