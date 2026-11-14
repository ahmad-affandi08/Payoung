<?php

declare(strict_types=1);

namespace App\Domain\Organisasi\Aksi;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Organisasi\Model\Perangkat;

/**
 * D-301 (lanjutan): kasir mengganti PIN miliknya dari aplikasi kasir (`POST /api/pos/v1/kasir/ubah-pin`, online).
 * PIN lama diperiksa lewat jalur masuk PIN (`MasukPinKasir`: batas 5 salah → kunci 5 menit, §20.2), PIN baru lewat
 * `AturPinSendiri` (kekuatan PIN, hash, verifier offline, audit `pengguna.pin.atur`). PIN baru wajib berbeda.
 */
final class UbahPinKasir
{
    public function __construct(
        private readonly MasukPinKasir $masuk,
        private readonly AturPinSendiri $atur,
    ) {}

    public function Jalankan(Perangkat $perangkat, string $uuidPengguna, string $pinLama, string $pinBaru): void
    {
        if ($pinLama === $pinBaru) {
            throw new PelanggaranAturanBisnis('PinBaruSama', 'PIN baru harus berbeda dari PIN lama.', 'PinBaru', 422);
        }

        $hasil = $this->masuk->Jalankan($perangkat, $uuidPengguna, $pinLama);
        $this->atur->Jalankan($perangkat->IdTenant, $hasil['Pengguna']->Id, $pinBaru);
    }
}
