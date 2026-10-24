<?php

declare(strict_types=1);

namespace App\Http\Respons;

use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Format galat seragam API (PRD §16.2, F-02b): `{"Galat": {"Kode", "Pesan", "Detail"}}`. Dipakai perantara API POS
 * dan penangan galat di bootstrap/app.php untuk rute `api/*`.
 */
final class GalatApi
{
    /**
     * @param  array<string, mixed>  $detail
     */
    public static function Buat(string $kode, string $pesan, int $status, array $detail = []): JsonResponse
    {
        return response()->json([
            'Galat' => ['Kode' => $kode, 'Pesan' => $pesan, 'Detail' => $detail === [] ? new \stdClass : $detail],
        ], $status);
    }

    /** Galat framework (validasi, 404, 405, 429, ...) → format seragam. Null = biarkan penangan bawaan. */
    public static function DariGalat(Throwable $galat): ?JsonResponse
    {
        if ($galat instanceof ValidationException) {
            return self::Buat('ValidasiGagal', $galat->validator->errors()->first(), 422, $galat->errors());
        }

        if (! $galat instanceof HttpExceptionInterface) {
            return null;
        }

        $status = $galat->getStatusCode();
        [$kode, $pesan] = match ($status) {
            401 => ['TidakTerautentikasi', 'Perangkat belum terautentikasi.'],
            403 => ['Dilarang', 'Akses ditolak.'],
            404 => ['TidakDitemukan', 'Data tidak ditemukan.'],
            405 => ['MetodeTidakDidukung', 'Metode permintaan tidak didukung.'],
            429 => ['TerlaluBanyakPermintaan', 'Terlalu banyak permintaan. Coba lagi sebentar lagi.'],
            default => [$status >= 500 ? 'GalatServer' : 'PermintaanTidakValid', $status >= 500 ? 'Terjadi masalah di server.' : 'Permintaan tidak valid.'],
        };

        $respons = self::Buat($kode, $pesan, $status);
        $respons->headers->add($galat->getHeaders());

        return $respons;
    }
}
