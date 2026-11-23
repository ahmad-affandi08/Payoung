<?php

declare(strict_types=1);

namespace App\Domain\Organisasi\Kueri;

use App\Domain\Organisasi\Enum\JenisPesanan;
use App\Domain\Organisasi\Enum\StatusOrganisasi;
use App\Domain\Organisasi\Model\Outlet;
use App\Domain\Organisasi\Model\StasiunDapur;
use App\Domain\Tenant\Kueri\FiturOutlet;

/**
 * Jenis pesanan yang ditawarkan kasir outlet (§9.1–§9.2, v3.51) beserta bawaannya untuk transaksi baru.
 *
 * Diatur pemilik di detail outlet (`Outlet.PengaturanKasir`). Bila belum pernah diatur (`Otomatis`): outlet FnB — mode
 * kasir template `Cepat`/`Meja` atau tenant punya stasiun dapur aktif — menawarkan Makan di tempat & Bawa pulang
 * (bawaan Makan di tempat); outlet lain tidak menawarkan pilihan (penjualan = Bawa pulang seperti sebelumnya).
 */
final class JenisPesananOutlet
{
    /** Mode kasir template yang berarti outlet melayani pesanan makanan/minuman. */
    private const MODE_FNB = ['Cepat', 'Meja'];

    public function __construct(private readonly FiturOutlet $fiturOutlet) {}

    /**
     * @return array{JenisPesanan: list<string>, JenisPesananBawaan: string|null, Otomatis: bool}
     */
    public function AmbilDariId(?int $idOutlet): array
    {
        $outlet = $idOutlet === null ? null : Outlet::query()->whereKey($idOutlet)->first();

        return $outlet === null
            ? ['JenisPesanan' => [], 'JenisPesananBawaan' => null, 'Otomatis' => true]
            : $this->Ambil($outlet);
    }

    /**
     * @return array{JenisPesanan: list<string>, JenisPesananBawaan: string|null, Otomatis: bool}
     */
    public function Ambil(Outlet $outlet): array
    {
        $atur = $outlet->PengaturanKasir ?? [];
        $daftar = self::BacaDaftar($atur['JenisPesanan'] ?? null);

        if ($daftar !== null) {
            $bawaan = is_string($atur['JenisPesananBawaan'] ?? null) && in_array($atur['JenisPesananBawaan'], $daftar, true)
                ? $atur['JenisPesananBawaan']
                : ($daftar[0] ?? null);

            return ['JenisPesanan' => $daftar, 'JenisPesananBawaan' => $daftar === [] ? null : $bawaan, 'Otomatis' => false];
        }

        if (! $this->CekFnb($outlet)) {
            return ['JenisPesanan' => [], 'JenisPesananBawaan' => null, 'Otomatis' => true];
        }

        return [
            'JenisPesanan' => [JenisPesanan::MakanDiTempat->value, JenisPesanan::BawaPulang->value],
            'JenisPesananBawaan' => JenisPesanan::MakanDiTempat->value,
            'Otomatis' => true,
        ];
    }

    private function CekFnb(Outlet $outlet): bool
    {
        if (array_intersect(self::MODE_FNB, $this->fiturOutlet->AmbilModeKasir($outlet->Id)) !== []) {
            return true;
        }

        return StasiunDapur::query()->where('Status', StatusOrganisasi::Aktif->value)->where('Salinan', false)->exists();
    }

    /**
     * Urutan tetap mengikuti enum (Makan di tempat, Bawa pulang, Antar) apa pun urutan simpanannya.
     *
     * @return list<string>|null null = belum diatur
     */
    private static function BacaDaftar(mixed $nilai): ?array
    {
        if (! is_array($nilai)) {
            return null;
        }

        return array_values(array_map(
            fn (JenisPesanan $j): string => $j->value,
            array_filter(JenisPesanan::cases(), fn (JenisPesanan $j): bool => in_array($j->value, $nilai, true)),
        ));
    }
}
