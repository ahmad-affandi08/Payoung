<?php

declare(strict_types=1);

namespace App\Domain\Organisasi\Kueri;

use App\Domain\Dukungan\Layanan\KonteksAksesDukungan;
use App\Domain\Organisasi\Enum\IzinTenant;
use App\Domain\Organisasi\Enum\StatusKeanggotaan;
use App\Domain\Organisasi\Model\OutletPengguna;
use App\Domain\Organisasi\Model\PeranIzin;
use App\Domain\Organisasi\Model\TenantPengguna;

/**
 * Hak akses anggota aktif di satu tenant (PRD §19.1): izin dari peran utamanya dan outlet yang boleh diakses.
 * Pemilik selalu memegang semua izin dan semua outlet. Bukan anggota aktif = tanpa izin.
 * Dipanggil hanya setelah tenant aktif ditetapkan (`PeranIzin` & `OutletPengguna` memakai `MilikTenant`).
 *
 * PGL-16: identitas teknis petugas dukungan tidak punya keanggotaan, jadi tidak punya hak apa pun kecuali selama sesi
 * akses dukungan yang sah pada permintaan ini (`KonteksAksesDukungan`): hak yang dipegang sesi itu (semua izin kecuali
 * yang dilarang saat dukungan) dan semua outlet. Di luar konteks itu (antrean, perintah konsol) hasilnya tetap null.
 */
final class AksesPengguna
{
    public function __construct(private readonly KonteksAksesDukungan $dukungan) {}

    /** @var array<string, array{Pemilik: bool, Izin: list<string>, SemuaOutlet: bool, IdPeran: int|null}|null> */
    private array $tembolok = [];

    /**
     * @return array{Pemilik: bool, Izin: list<string>, SemuaOutlet: bool, IdPeran: int|null}|null
     */
    public function Ambil(int $idTenant, int $idPengguna): ?array
    {
        $sesiDukungan = $this->dukungan->Ambil();

        if ($sesiDukungan !== null && $this->dukungan->CekPengguna($idTenant, $idPengguna)) {
            return ['Pemilik' => false, 'Izin' => $sesiDukungan->izin, 'SemuaOutlet' => true, 'IdPeran' => null];
        }

        $kunci = "{$idTenant}:{$idPengguna}";

        if (array_key_exists($kunci, $this->tembolok)) {
            return $this->tembolok[$kunci];
        }

        $anggota = TenantPengguna::query()
            ->where('IdTenant', $idTenant)
            ->where('IdPengguna', $idPengguna)
            ->where('Status', StatusKeanggotaan::Aktif->value)
            ->first();

        if ($anggota === null) {
            return $this->tembolok[$kunci] = null;
        }

        $izin = match (true) {
            $anggota->Pemilik => IzinTenant::AmbilSemuaKunci(),
            $anggota->IdPeran === null => [],
            default => array_values(array_map('strval', PeranIzin::query()
                ->where('IdPeran', $anggota->IdPeran)
                ->orderBy('KunciIzin')
                ->pluck('KunciIzin')
                ->all())),
        };

        return $this->tembolok[$kunci] = [
            'Pemilik' => $anggota->Pemilik,
            'Izin' => $izin,
            'SemuaOutlet' => $anggota->Pemilik || $anggota->SemuaOutlet,
            'IdPeran' => $anggota->IdPeran,
        ];
    }

    public function CekIzin(int $idTenant, int $idPengguna, IzinTenant $izin): bool
    {
        $akses = $this->Ambil($idTenant, $idPengguna);

        return $akses !== null && ($akses['Pemilik'] || in_array($izin->value, $akses['Izin'], true));
    }

    /**
     * Outlet yang boleh diakses. Null = semua outlet.
     *
     * @return list<int>|null
     */
    public function AmbilIdOutlet(int $idTenant, int $idPengguna): ?array
    {
        $akses = $this->Ambil($idTenant, $idPengguna);

        if ($akses === null) {
            return [];
        }

        if ($akses['SemuaOutlet']) {
            return null;
        }

        return array_values(array_map('intval', OutletPengguna::query()->where('IdPengguna', $idPengguna)->pluck('IdOutlet')->all()));
    }

    public function Lupakan(): void
    {
        $this->tembolok = [];
    }
}
