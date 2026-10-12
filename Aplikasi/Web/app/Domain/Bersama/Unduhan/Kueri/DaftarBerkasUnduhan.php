<?php

declare(strict_types=1);

namespace App\Domain\Bersama\Unduhan\Kueri;

use App\Domain\Bersama\Tabel\Data\DataPermintaanTabel;
use App\Domain\Bersama\Tabel\Layanan\PenerapKueriTabel;
use App\Domain\Bersama\Unduhan\Enum\StatusBerkasUnduhan;
use App\Domain\Bersama\Unduhan\Model\BerkasUnduhan;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Daftar Pusat Unduhan (D-124, `TabelData` mode server): hanya berkas milik pengguna yang sedang masuk, karena isi
 * laporan mengikuti izin dan cakupan outlet peminta.
 */
final class DaftarBerkasUnduhan
{
    public const KOLOM_URUT = ['DibuatPada', 'Judul', 'UkuranByte'];

    public const KOLOM_SARING = ['Status'];

    public const URUT_BAWAAN = '-DibuatPada';

    /**
     * @return array{Data: list<array<string, mixed>>, Meta: array{Halaman: int, PerHalaman: int, Total: int, JumlahHalaman: int}}
     */
    public function Ambil(DataPermintaanTabel $p, int $idPengguna): array
    {
        $kueri = BerkasUnduhan::query()->where('IdPengguna', $idPengguna);
        $status = $p->AmbilDaftar('Status', array_map(fn (StatusBerkasUnduhan $s): string => $s->value, StatusBerkasUnduhan::cases()));

        if ($status !== []) {
            $kueri->whereIn('Status', $status);
        }

        if ($p->cari !== '') {
            $kueri->where(fn (Builder $dalam) => $dalam->where('Judul', 'like', PenerapKueriTabel::PolaCari($p->cari))->orWhere('NamaBerkas', 'like', PenerapKueriTabel::PolaCari($p->cari)));
        }

        return PenerapKueriTabel::Terapkan($kueri, $p, ['DibuatPada' => 'DibuatPada', 'Judul' => 'Judul', 'UkuranByte' => 'UkuranByte'], fn (Collection $baris): array => array_values($baris->map(fn (BerkasUnduhan $b): array => [
            'Uuid' => $b->Uuid,
            'Judul' => $b->Judul,
            'NamaBerkas' => $b->NamaBerkas,
            'Format' => $b->Format,
            'Status' => $b->Status->value,
            'LabelStatus' => $b->Status->AmbilLabel(),
            'UkuranByte' => $b->UkuranByte,
            'DibuatPada' => $b->DibuatPada?->toIso8601ZuluString(),
            'SelesaiPada' => $b->SelesaiPada?->toIso8601ZuluString(),
            'KedaluwarsaPada' => $b->KedaluwarsaPada?->toIso8601ZuluString(),
            'DiunduhPada' => $b->DiunduhPada?->toIso8601ZuluString(),
            'Galat' => $b->Galat,
            'BisaDiunduh' => $b->Status === StatusBerkasUnduhan::Siap && $b->PathBerkas !== null && $b->KedaluwarsaPada?->isFuture() === true,
        ])->all()));
    }

    /** Ada berkas milik pengguna yang masih disiapkan (halaman memuat ulang otomatis selama ini true). */
    public function CekAdaYangAktif(int $idPengguna): bool
    {
        return BerkasUnduhan::query()
            ->where('IdPengguna', $idPengguna)
            ->whereIn('Status', [StatusBerkasUnduhan::Menunggu->value, StatusBerkasUnduhan::Diproses->value])
            ->exists();
    }
}
