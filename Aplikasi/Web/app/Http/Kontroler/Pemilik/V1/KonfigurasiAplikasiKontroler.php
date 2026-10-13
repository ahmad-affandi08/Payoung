<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Pemilik\V1;

use App\Domain\Organisasi\Enum\PlatformPerangkat;
use App\Domain\Tenant\Enum\AplikasiRilis;
use App\Domain\Tenant\Kueri\FlagFiturTenant;
use App\Domain\Tenant\Kueri\VersiAplikasiPerangkat;
use App\Domain\Tenant\Model\RilisAplikasi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /api/pemilik/v1/konfigurasi-aplikasi` (P-10, PGL-18): versi terbaru & minimal Aplikasi Owner, catatan rilis, dan
 * flag fitur tenant aktif (kunci → hidup/mati; Global mati = kill switch). Bentuknya sama dengan `konfigurasi-aplikasi`
 * aplikasi POS supaya model Dart bersama (`KonfigurasiAplikasi`) membacanya tanpa cabang.
 *
 * Platform dari header `X-Platform-Aplikasi` (`Android`/`Ios`; kosong atau tak dikenal = Android). Versi saat ini dari
 * `X-Versi-Aplikasi`. Rollout bertahap memakai Uuid pengguna sebagai ember (Aplikasi Owner tidak punya `Perangkat`).
 * Tanpa izin khusus: setiap pengguna Owner perlu tahu aplikasinya wajib diperbarui.
 */
final class KonfigurasiAplikasiKontroler extends DasarPemilikKontroler
{
    public function Tampilkan(Request $permintaan, VersiAplikasiPerangkat $versi, FlagFiturTenant $flag): JsonResponse
    {
        $pengguna = $this->Pengguna($permintaan);
        $idTenant = $this->IdTenant();
        $platformHeader = $permintaan->header('X-Platform-Aplikasi');
        $platform = $platformHeader === PlatformPerangkat::Ios->value ? PlatformPerangkat::Ios : PlatformPerangkat::Android;
        $versiHeader = $permintaan->header('X-Versi-Aplikasi');
        $versiSaatIni = is_string($versiHeader) && $versiHeader !== '' ? $versiHeader : null;

        $perPlatform = [];

        foreach ([PlatformPerangkat::Android, PlatformPerangkat::Ios] as $kandidat) {
            $perPlatform[$kandidat->value] = $versi->Tentukan(AplikasiRilis::Pemilik, $kandidat->value, $idTenant, $pengguna->Uuid);
        }

        $ini = $perPlatform[$platform->value];

        return response()->json([
            'Aplikasi' => [
                'Platform' => $platform->value,
                'VersiSaatIni' => $versiSaatIni,
                'VersiTerbaru' => $ini['VersiTerbaru'],
                'VersiMinimal' => $ini['VersiMinimal'],
                'TautanUnduh' => $ini['TautanUnduh'],
                'CatatanRilis' => $ini['CatatanRilis'],
                'AdaPembaruan' => $versiSaatIni !== null && RilisAplikasi::BandingkanVersi($versiSaatIni, $ini['VersiTerbaru']) < 0,
                'WajibPembaruan' => $versiSaatIni !== null && RilisAplikasi::BandingkanVersi($versiSaatIni, $ini['VersiMinimal']) < 0,
                'PerPlatform' => array_map(fn (array $v): array => ['VersiTerbaru' => $v['VersiTerbaru'], 'VersiMinimal' => $v['VersiMinimal'], 'TautanUnduh' => $v['TautanUnduh']], $perPlatform),
            ],
            'FlagFitur' => (object) $flag->AmbilUntukTenant($idTenant),
            'WaktuServer' => now()->utc()->toIso8601ZuluString(),
        ]);
    }
}
