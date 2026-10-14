<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\TimInternal\Layanan;

use App\Domain\Tenant\Model\PengaturanPlatform;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Pembatasan alamat IP konsol Platform Pengelola (PGL-01, BR-P01.2, D-146). Satu daftar untuk **seluruh** konsol (semua
 * anggota tim), disimpan di `PengaturanPlatform` sebagai satu alamat atau rentang CIDR per baris. Daftar kosong = tidak
 * ada pembatasan (bawaan). Dipakai perantara `BatasiIpPengelola` di setiap rute konsol, termasuk halaman masuk, sehingga
 * kata sandi yang bocor pun tidak bisa dicoba dari luar jaringan kantor.
 */
final class AllowlistIpPengelola
{
    public const KUNCI = 'pengelola.allowlist-ip';

    public const MAKS_ENTRI = 50;

    /** @return list<string> */
    public function Ambil(): array
    {
        $nilai = PengaturanPlatform::query()->where('Kunci', self::KUNCI)->value('Nilai');

        return is_string($nilai) ? self::Urai($nilai)['Sah'] : [];
    }

    public function CekAktif(): bool
    {
        return $this->Ambil() !== [];
    }

    /** Alamat IP boleh masuk bila daftar kosong atau cocok dengan salah satu entri. */
    public function CekBoleh(string $ip): bool
    {
        $daftar = $this->Ambil();

        return $daftar === [] || IpUtils::checkIp($ip, $daftar);
    }

    /**
     * Memecah isian (dipisah baris, koma, atau spasi) menjadi entri sah (IPv4/IPv6 tunggal atau CIDR) dan entri yang
     * ditolak. Entri sah diberi bentuk baku tanpa duplikat.
     *
     * @return array{Sah: list<string>, TidakSah: list<string>}
     */
    public static function Urai(string $teks): array
    {
        $sah = [];
        $tidakSah = [];

        foreach (preg_split('/[\s,;]+/', trim($teks)) ?: [] as $entri) {
            if ($entri === '') {
                continue;
            }

            if (self::CekEntriSah($entri)) {
                $sah[strtolower($entri)] = strtolower($entri);
            } else {
                $tidakSah[] = $entri;
            }
        }

        return ['Sah' => array_values($sah), 'TidakSah' => $tidakSah];
    }

    private static function CekEntriSah(string $entri): bool
    {
        $bagian = explode('/', $entri);

        if (count($bagian) > 2) {
            return false;
        }

        $ip = $bagian[0];
        $adaV4 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
        $adaV6 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;

        if (! $adaV4 && ! $adaV6) {
            return false;
        }

        if (count($bagian) === 1) {
            return true;
        }

        $awalan = $bagian[1];

        return ctype_digit($awalan) && (int) $awalan >= 0 && (int) $awalan <= ($adaV4 ? 32 : 128);
    }
}
