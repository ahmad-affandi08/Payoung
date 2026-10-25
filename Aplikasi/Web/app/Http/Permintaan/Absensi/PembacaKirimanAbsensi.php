<?php

declare(strict_types=1);

namespace App\Http\Permintaan\Absensi;

use App\Domain\Karyawan\Data\DataAbsensiWeb;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\Request;

/**
 * Validasi kiriman halaman Absen (F-18 bagian 4, D-37, D-180, D-198), dipakai bersama oleh halaman berlogin
 * `/kelola/absen` dan tautan pribadi `/{slug}/absen/{token}`: dua pintu masuk, satu aturan.
 */
final class PembacaKirimanAbsensi
{
    /** Pola derajat desimal (lintang & bujur) dari Geolocation API. */
    private const POLA_KOORDINAT = '/^-?\d{1,3}(\.\d{1,12})?$/';

    /** @return array{SidikWajah: list<list<int>>, Foto: list<string>, Persetujuan: bool} */
    public function BacaPendaftaranWajah(Request $permintaan): array
    {
        $valid = $permintaan->validate([
            'SidikWajah' => ['required', 'array', 'max:'.(int) config('karyawan.JumlahFotoDaftarWajah')],
            'SidikWajah.*' => ['array', 'max:1024'],
            'SidikWajah.*.*' => ['integer'],
            'Foto' => ['required', 'array', 'max:'.(int) config('karyawan.JumlahFotoDaftarWajah')],
            'Foto.*' => ['string', 'max:450000'],
            'Persetujuan' => ['required', 'boolean'],
        ]);

        return [
            'SidikWajah' => array_values((array) $valid['SidikWajah']),
            'Foto' => array_values((array) $valid['Foto']),
            'Persetujuan' => (bool) $valid['Persetujuan'],
        ];
    }

    /** @return list<int> */
    public function BacaSidikCekWajah(Request $permintaan): array
    {
        $valid = $permintaan->validate([
            'SidikWajah' => ['required', 'array', 'max:1024'],
            'SidikWajah.*' => ['integer'],
        ]);

        return array_values((array) $valid['SidikWajah']);
    }

    public function BacaDataAbsen(Request $permintaan): DataAbsensiWeb
    {
        $valid = $permintaan->validate([
            'Uuid' => ['required', 'string', 'regex:/^[0-9A-HJKMNP-TV-Z]{26}$/'],
            'Lintang' => ['required', 'string', 'regex:'.self::POLA_KOORDINAT],
            'Bujur' => ['required', 'string', 'regex:'.self::POLA_KOORDINAT],
            'AkurasiMeter' => ['required', 'integer', 'min:0', 'max:100000'],
            'SidikWajah' => ['required', 'array', 'max:1024'],
            'SidikWajah.*' => ['integer'],
            // Hanya dikirim halaman bila aturan menyimpan foto bukti; server yang memastikan wajib-tidaknya.
            'Swafoto' => ['nullable', 'string', 'max:450000'],
        ]);
        $lintang = BigDecimal::of((string) $valid['Lintang']);
        $bujur = BigDecimal::of((string) $valid['Bujur']);

        if ($lintang->abs()->isGreaterThan(90) || $bujur->abs()->isGreaterThan(180)) {
            abort(422, 'Koordinat di luar jangkauan.');
        }

        return new DataAbsensiWeb(
            uuid: (string) $valid['Uuid'],
            lintang: (string) $lintang->toScale(7, RoundingMode::HalfUp),
            bujur: (string) $bujur->toScale(7, RoundingMode::HalfUp),
            akurasiMeter: (int) $valid['AkurasiMeter'],
            sidikWajah: array_values((array) $valid['SidikWajah']),
            swafoto: is_string($valid['Swafoto'] ?? null) ? $valid['Swafoto'] : null,
        );
    }

    /** @return array{Jenis: string, Alasan: string} */
    public function BacaPermintaanKoreksi(Request $permintaan): array
    {
        $valid = $permintaan->validate([
            'Jenis' => ['required', 'string', 'in:Masuk,Keluar'],
            'Alasan' => ['required', 'string', 'max:200'],
        ]);

        return ['Jenis' => (string) $valid['Jenis'], 'Alasan' => (string) $valid['Alasan']];
    }
}
