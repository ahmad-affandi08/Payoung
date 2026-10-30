<?php

declare(strict_types=1);

namespace App\Domain\Organisasi\Kueri;

use App\Domain\Organisasi\Enum\StatusKeanggotaan;
use App\Domain\Organisasi\Layanan\VerifierPinOffline;
use App\Domain\Organisasi\Model\OutletPengguna;
use App\Domain\Organisasi\Model\Pengguna;
use App\Domain\Organisasi\Model\Perangkat;
use App\Domain\Organisasi\Model\TenantPengguna;

/**
 * Staf yang bisa masuk di satu perangkat POS (F-06, data awal): anggota aktif tenant dengan akses ke outlet
 * perangkat, urut nama, beserta izinnya dan verifier PIN offline yang dibungkus kunci perangkat (§25.2 no. 3).
 * `Pin` null bila PIN belum diatur, diatur sebelum F-06 (belum punya verifier; atur ulang PIN untuk memakainya
 * offline), atau perangkat belum punya kunci PIN (aktifkan ulang).
 */
final class StafPerangkat
{
    public function __construct(private readonly AksesPengguna $akses) {}

    /**
     * @return list<array{Uuid: string, Nama: string, Pemilik: bool, Izin: list<string>, PinDiatur: bool, Pin: array{Garam: string, Nonce: string, Sandi: string}|null}>
     */
    public function Ambil(Perangkat $perangkat): array
    {
        $idPenggunaOutlet = OutletPengguna::query()->where('IdOutlet', $perangkat->IdOutlet)->pluck('IdPengguna')->all();
        $anggota = TenantPengguna::query()
            ->where('IdTenant', $perangkat->IdTenant)
            ->where('Status', StatusKeanggotaan::Aktif->value)
            ->where(fn ($k) => $k->where('Pemilik', true)->orWhere('SemuaOutlet', true)->orWhereIn('IdPengguna', $idPenggunaOutlet))
            ->get()
            ->keyBy('IdPengguna');
        $pengguna = Pengguna::query()->whereIn('Id', $anggota->keys()->all())->orderBy('Nama')->orderBy('Id')->get();
        $kunci = $perangkat->KunciPinOffline;
        $izinPerPengguna = $this->akses->AmbilIzinBanyak($anggota);
        $hasil = [];

        foreach ($pengguna as $p) {
            $baris = $anggota->get($p->Id);

            if (! $baris instanceof TenantPengguna) {
                continue;
            }

            $verifier = $baris->VerifierPinOffline;
            $hasil[] = [
                'Uuid' => $p->Uuid,
                'Nama' => $p->Nama,
                'Pemilik' => $baris->Pemilik,
                'Izin' => $izinPerPengguna[$p->Id] ?? [],
                'PinDiatur' => $baris->HashPin !== null,
                'Pin' => $baris->HashPin !== null && $verifier !== null && $kunci !== null ? VerifierPinOffline::Bungkus($verifier, $kunci) : null,
            ];
        }

        return $hasil;
    }
}
