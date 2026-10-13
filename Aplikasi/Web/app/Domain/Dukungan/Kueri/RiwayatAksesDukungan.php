<?php

declare(strict_types=1);

namespace App\Domain\Dukungan\Kueri;

use App\Domain\Dukungan\Model\AksesDukungan;
use App\Domain\Dukungan\Model\AktivitasAksesDukungan;
use App\Domain\Dukungan\Model\HasilAlatDukungan;
use App\Domain\Dukungan\Model\SesiAksesDukungan;
use App\Domain\Dukungan\Model\TiketDukungan;
use Illuminate\Support\Facades\DB;

/**
 * Izin dan sesi akses dukungan satu tenant untuk dibaca Owner (Pengaturan › Akses dukungan) dan Platform Pengelola
 * (tampilan 360° tenant, P-09). Tenant ditentukan konteks tenant yang sedang aktif. Data pribadi tidak ada di sini:
 * hanya nama petugas, waktu, rute, dan status.
 */
final class RiwayatAksesDukungan
{
    /**
     * @return array{Aktif: array<string, mixed>|null, Izin: list<array<string, mixed>>, Sesi: list<array<string, mixed>>, Alat: list<array<string, mixed>>}
     */
    public function Ambil(int $batas = 20): array
    {
        $izin = AksesDukungan::query()->orderByDesc('Id')->limit($batas)->get();
        $idAkses = $izin->pluck('Id')->all();
        $nomorTiket = TiketDukungan::query()->whereIn('Id', $izin->pluck('IdTiketDukungan')->filter()->all())->pluck('Nomor', 'Id')->all();

        $sesiPerAkses = SesiAksesDukungan::query()->whereIn('IdAksesDukungan', $idAkses)->whereNotNull('TokenDipakaiPada')->get()->groupBy('IdAksesDukungan');
        $sesi = SesiAksesDukungan::query()->whereNotNull('TokenDipakaiPada')->orderByDesc('Id')->limit($batas)->get();
        $hitung = $this->HitungAktivitas(array_values(array_map('intval', $sesi->pluck('Id')->all())));

        $baris = $izin->map(fn (AksesDukungan $a): array => $this->PetakanIzin($a, $nomorTiket, $sesiPerAkses->get($a->Id)?->count() ?? 0))->values();
        $aktif = $izin->first(fn (AksesDukungan $a): bool => $a->CekAktif());

        return [
            'Aktif' => $aktif === null ? null : [
                ...$this->PetakanIzin($aktif, $nomorTiket, $sesiPerAkses->get($aktif->Id)?->count() ?? 0),
                'SesiBerjalan' => array_values(($sesiPerAkses->get($aktif->Id) ?? collect())
                    ->filter(fn (SesiAksesDukungan $s): bool => $s->CekBerjalan())
                    ->map(fn (SesiAksesDukungan $s): array => ['NamaPetugas' => $s->NamaPetugas, 'MulaiPada' => $s->MulaiPada?->toIso8601String(), 'BerakhirPada' => $s->BerakhirPada->toIso8601String()])
                    ->all()),
            ],
            'Izin' => array_values($baris->all()),
            // BR-P09.3: alat bantu yang pernah dijalankan petugas, dengan keadaan sebelum dan sesudah.
            'Alat' => array_values(HasilAlatDukungan::query()->orderByDesc('Id')->limit($batas)->get()->map(fn (HasilAlatDukungan $h): array => [
                'Uuid' => $h->Uuid,
                'Alat' => $h->Alat->value,
                'LabelAlat' => $h->Alat->AmbilLabel(),
                'NamaPetugas' => $h->NamaPetugas,
                'Status' => $h->Status->value,
                'LabelStatus' => $h->Status->AmbilLabel(),
                'Ringkasan' => $h->Ringkasan,
                'Sebelum' => $h->Sebelum,
                'Sesudah' => $h->Sesudah,
                'DibuatPada' => $h->DibuatPada->toIso8601String(),
            ])->all()),
            'Sesi' => array_values($sesi->map(fn (SesiAksesDukungan $s): array => [
                'Uuid' => $s->Uuid,
                'NamaPetugas' => $s->NamaPetugas,
                'MulaiPada' => $s->MulaiPada?->toIso8601String(),
                'DiakhiriPada' => $s->DiakhiriPada?->toIso8601String(),
                'BerakhirPada' => $s->BerakhirPada->toIso8601String(),
                'Berjalan' => $s->CekBerjalan(),
                'AlasanAkhir' => $s->AlasanAkhir?->AmbilLabel(),
                'JumlahAktivitas' => $hitung[$s->Id]['Semua'] ?? 0,
                'JumlahUbah' => $hitung[$s->Id]['Ubah'] ?? 0,
                'JumlahDitolak' => $hitung[$s->Id]['Ditolak'] ?? 0,
            ])->all()),
        ];
    }

    /**
     * Jejak permintaan satu sesi (terbaru dulu), untuk dibuka Owner dari riwayat.
     *
     * @return list<array{Waktu: string, Metode: string, Rute: string|null, Jalur: string, StatusHttp: int, Ditolak: bool, Alasan: string|null}>|null null bila sesi tidak ada di tenant ini
     */
    public function AmbilAktivitas(string $uuidSesi, int $batas = 200): ?array
    {
        $sesi = SesiAksesDukungan::query()->where('Uuid', $uuidSesi)->first();

        if (! $sesi instanceof SesiAksesDukungan) {
            return null;
        }

        return array_values(AktivitasAksesDukungan::query()
            ->where('IdSesiAksesDukungan', $sesi->Id)
            ->orderByDesc('Id')
            ->limit($batas)
            ->get()
            ->map(fn (AktivitasAksesDukungan $a): array => [
                'Waktu' => $a->DibuatPada->toIso8601String(),
                'Metode' => $a->Metode,
                'Rute' => $a->Rute,
                'Jalur' => $a->Jalur,
                'StatusHttp' => $a->StatusHttp,
                'Ditolak' => $a->Ditolak,
                'Alasan' => $a->Alasan,
            ])
            ->all());
    }

    /**
     * @param  list<int>  $idSesi
     * @return array<int, array{Semua: int, Ubah: int, Ditolak: int}>
     */
    private function HitungAktivitas(array $idSesi): array
    {
        if ($idSesi === []) {
            return [];
        }

        $hasil = [];

        foreach (AktivitasAksesDukungan::query()
            ->whereIn('IdSesiAksesDukungan', $idSesi)
            ->groupBy('IdSesiAksesDukungan')
            ->get([
                'IdSesiAksesDukungan',
                DB::raw('COUNT(*) AS Semua'),
                DB::raw("SUM(CASE WHEN Metode NOT IN ('GET','HEAD','OPTIONS') THEN 1 ELSE 0 END) AS Ubah"),
                DB::raw('SUM(Ditolak) AS Ditolak'),
            ]) as $baris) {
            $hasil[(int) $baris->getAttribute('IdSesiAksesDukungan')] = [
                'Semua' => (int) $baris->getAttribute('Semua'),
                'Ubah' => (int) $baris->getAttribute('Ubah'),
                'Ditolak' => (int) $baris->getAttribute('Ditolak'),
            ];
        }

        return $hasil;
    }

    /**
     * @param  array<int, string>  $nomorTiket
     * @return array<string, mixed>
     */
    private function PetakanIzin(AksesDukungan $a, array $nomorTiket, int $jumlahSesi): array
    {
        $status = match (true) {
            $a->DicabutPada !== null => 'Dicabut',
            $a->BerakhirPada->lte(now()) => 'Berakhir',
            default => 'Berlaku',
        };

        return [
            'Uuid' => $a->Uuid,
            'Jenis' => $a->Jenis->value,
            'LabelJenis' => $a->Jenis->AmbilLabel(),
            'Cakupan' => $a->Cakupan->value,
            'LabelCakupan' => $a->Cakupan->AmbilLabel(),
            'DataPribadiTerbuka' => $a->DataPribadiTerbuka,
            'NamaPemberi' => $a->NamaPemberi,
            'Alasan' => $a->Alasan,
            'KategoriDarurat' => $a->KategoriDarurat?->AmbilLabel(),
            'NomorTiket' => $a->IdTiketDukungan === null ? null : ($nomorTiket[$a->IdTiketDukungan] ?? null),
            'MulaiPada' => $a->MulaiPada->toIso8601String(),
            'BerakhirPada' => $a->BerakhirPada->toIso8601String(),
            'DicabutPada' => $a->DicabutPada?->toIso8601String(),
            'Status' => $status,
            'JumlahSesi' => $jumlahSesi,
        ];
    }
}
