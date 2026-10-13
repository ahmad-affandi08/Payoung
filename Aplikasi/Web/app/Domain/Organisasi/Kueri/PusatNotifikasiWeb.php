<?php

declare(strict_types=1);

namespace App\Domain\Organisasi\Kueri;

use App\Domain\Organisasi\Enum\JenisNotifikasiPengguna;
use App\Domain\Organisasi\Model\NotifikasiDibisukan;
use App\Domain\Organisasi\Model\NotifikasiPengguna;

/**
 * Pusat notifikasi back-office web (F31, PLT-08): membaca baris `NotifikasiPengguna` yang sama dengan Aplikasi Owner,
 * hanya milik pengguna di tenant aktif. Tiap notifikasi membawa `Tautan` halaman web tujuannya: yang tersimpan di data
 * (`Href`, hanya alamat dalam `/kelola`) atau, untuk notifikasi lama, tautan bawaan jenisnya.
 */
final class PusatNotifikasiWeb
{
    public const BATAS = 100;

    /**
     * @return array{Notifikasi: list<array<string, mixed>>, BelumDibaca: int, Dibisukan: list<string>, Jenis: list<array{Nilai: string, Label: string, BisaDibisukan: bool}>}
     */
    public function Ambil(int $idPengguna, bool $hanyaBelumDibaca): array
    {
        $dasar = NotifikasiPengguna::query()->where('IdPengguna', $idPengguna);
        $daftar = (clone $dasar)
            ->when($hanyaBelumDibaca, fn ($kueri) => $kueri->whereNull('DibacaPada'))
            ->orderByDesc('DibuatPada')
            ->orderByDesc('Id')
            ->limit(self::BATAS)
            ->get();

        return [
            'Notifikasi' => array_values($daftar->map(fn (NotifikasiPengguna $n): array => [
                'Uuid' => $n->Uuid,
                'Jenis' => $n->Jenis->value,
                'LabelJenis' => $n->Jenis->AmbilLabel(),
                'Judul' => $n->Judul,
                'Isi' => $n->Isi,
                'Tautan' => self::TentukanTautan($n),
                'DibacaPada' => $n->DibacaPada?->toIso8601ZuluString(),
                'DibuatPada' => $n->DibuatPada?->toIso8601ZuluString(),
            ])->all()),
            'BelumDibaca' => $this->HitungBelumDibaca($idPengguna),
            'Dibisukan' => array_values(NotifikasiDibisukan::query()->where('IdPengguna', $idPengguna)->get()->map(fn (NotifikasiDibisukan $b): string => $b->Jenis->value)->all()),
            'Jenis' => array_map(fn (JenisNotifikasiPengguna $j): array => ['Nilai' => $j->value, 'Label' => $j->AmbilLabel(), 'BisaDibisukan' => $j->BisaDibisukan()], JenisNotifikasiPengguna::cases()),
        ];
    }

    public function HitungBelumDibaca(int $idPengguna): int
    {
        return NotifikasiPengguna::query()->where('IdPengguna', $idPengguna)->whereNull('DibacaPada')->count();
    }

    private static function TentukanTautan(NotifikasiPengguna $notifikasi): ?string
    {
        $href = $notifikasi->Data['Href'] ?? null;

        // Hanya halaman back-office (jalur mutlak di bawah /kelola): data notifikasi tidak boleh mengarahkan ke luar.
        if (is_string($href) && preg_match('#^/kelola(/|$|\?)#', $href) === 1) {
            return $href;
        }

        return $notifikasi->Jenis->AmbilTautanBawaan();
    }
}
