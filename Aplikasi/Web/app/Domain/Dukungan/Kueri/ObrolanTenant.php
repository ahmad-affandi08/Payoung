<?php

declare(strict_types=1);

namespace App\Domain\Dukungan\Kueri;

use App\Domain\Dukungan\Enum\JenisPengirimPesan;
use App\Domain\Dukungan\Enum\KanalTiketDukungan;
use App\Domain\Dukungan\Enum\StatusTiketDukungan;
use App\Domain\Dukungan\Model\TiketDukungan;
use App\Domain\Dukungan\Model\TiketDukunganPesan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Obrolan langsung dari sisi pelapor (PLT-12, D-163): selalu dibatasi tenant aktif lewat MilikTenant dan hanya obrolan
 * milik pelapor itu sendiri; catatan internal tim tidak pernah ikut. Setiap pembacaan menandai pelapor "sedang
 * membaca" supaya balasan petugas saat itu tidak dikirimi email/notifikasi lagi.
 */
final class ObrolanTenant
{
    public static function KunciDilihat(string $uuidTiket): string
    {
        return "dukungan.obrolan.dilihat.{$uuidTiket}";
    }

    /** Obrolan terbuka milik pelapor (satu per pelapor; yang selesai/ditutup tidak dihitung). */
    public function CariAktif(int $idPengguna): ?TiketDukungan
    {
        return TiketDukungan::query()
            ->where('Kanal', KanalTiketDukungan::Obrolan->value)
            ->where('IdPelapor', $idPengguna)
            ->whereIn('Status', array_map(fn (StatusTiketDukungan $s): string => $s->value, StatusTiketDukungan::AmbilTerbuka()))
            ->orderByDesc('Id')
            ->first();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function AmbilKeadaan(?TiketDukungan $tiket, ?string $setelahUuid): ?array
    {
        if ($tiket === null) {
            return null;
        }

        Cache::put(self::KunciDilihat($tiket->Uuid), true, (int) config('dukungan.Obrolan.DilihatDetik'));

        $kueri = TiketDukunganPesan::query()->where('IdTiketDukungan', $tiket->Id)->where('CatatanInternal', false)->orderBy('Id');

        if ($setelahUuid !== null && $setelahUuid !== '') {
            $idPatokan = TiketDukunganPesan::query()->where('IdTiketDukungan', $tiket->Id)->where('Uuid', $setelahUuid)->value('Id');

            if ($idPatokan !== null) {
                $kueri->where('Id', '>', $idPatokan);
            }
        }

        $pesan = $kueri->limit(200)->get();
        $petugas = $tiket->IdPenanggungJawab === null ? null : DB::table('PenggunaPengelola')->where('Id', $tiket->IdPenanggungJawab)->value('Nama');

        return [
            'Uuid' => $tiket->Uuid,
            'Nomor' => $tiket->Nomor,
            'Status' => $tiket->Status->value,
            'LabelStatus' => $tiket->Status->AmbilLabel(),
            'Petugas' => $petugas === null ? null : (string) $petugas,
            'Pesan' => array_values($pesan->map(fn (TiketDukunganPesan $baris): array => [
                'Uuid' => $baris->Uuid,
                'JenisPengirim' => $baris->JenisPengirim->value,
                'NamaPengirim' => match ($baris->JenisPengirim) {
                    JenisPengirimPesan::Pengelola => trim(($baris->NamaPengirim ?? '').' | Tim Dukungan', ' |'),
                    JenisPengirimPesan::Sistem => 'Sistem',
                    JenisPengirimPesan::Pengguna => $baris->NamaPengirim ?? 'Pengguna',
                },
                'Isi' => $baris->Isi,
                'DibuatPada' => $baris->DibuatPada->toIso8601String(),
            ])->all()),
        ];
    }
}
