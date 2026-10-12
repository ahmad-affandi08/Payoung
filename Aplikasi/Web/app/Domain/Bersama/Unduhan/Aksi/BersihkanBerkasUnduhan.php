<?php

declare(strict_types=1);

namespace App\Domain\Bersama\Unduhan\Aksi;

use App\Domain\Bersama\Unduhan\Enum\StatusBerkasUnduhan;
use App\Domain\Bersama\Unduhan\Model\BerkasUnduhan;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;

/**
 * Perawatan Pusat Unduhan (D-124), dipanggil dalam konteks tenant: berkas yang lewat masa berlaku dihapus dari disk,
 * proses yang macet (peladen berhenti di tengah penyiapan) ditandai Gagal, dan jejak lama yang tak berguna dibuang.
 */
final class BersihkanBerkasUnduhan
{
    public const HARI_SIMPAN_JEJAK = 30;

    /**
     * Kondisi pekerjaan, dipakai juga untuk menemukan tenant yang perlu dikunjungi.
     *
     * @param  Builder<BerkasUnduhan>  $kueri
     * @return Builder<BerkasUnduhan>
     */
    public static function SaringPekerjaan(Builder $kueri): Builder
    {
        $batasMacet = now()->subMinutes((int) config('tenant.MenitBatasProsesUnduhan'));

        return $kueri->where(function (Builder $q) use ($batasMacet): void {
            $q->where(fn (Builder $a) => $a->where('Status', StatusBerkasUnduhan::Siap->value)->where('KedaluwarsaPada', '<=', now()))
                ->orWhere(fn (Builder $a) => $a->whereIn('Status', [StatusBerkasUnduhan::Menunggu->value, StatusBerkasUnduhan::Diproses->value])->where('DibuatPada', '<=', $batasMacet))
                ->orWhere(fn (Builder $a) => $a->whereIn('Status', [StatusBerkasUnduhan::Gagal->value, StatusBerkasUnduhan::Kedaluwarsa->value])->where('DibuatPada', '<=', now()->subDays(self::HARI_SIMPAN_JEJAK)));
        });
    }

    public function Jalankan(): int
    {
        $jumlah = 0;
        $disk = Storage::disk((string) config('tenant.DiskUnduhan'));

        foreach (BerkasUnduhan::query()->where('Status', StatusBerkasUnduhan::Siap->value)->where('KedaluwarsaPada', '<=', now())->get() as $berkas) {
            if ($berkas->PathBerkas !== null) {
                $disk->delete($berkas->PathBerkas);
            }

            $berkas->update(['Status' => StatusBerkasUnduhan::Kedaluwarsa, 'PathBerkas' => null]);
            $jumlah++;
        }

        $batasMacet = now()->subMinutes((int) config('tenant.MenitBatasProsesUnduhan'));

        foreach (BerkasUnduhan::query()->whereIn('Status', [StatusBerkasUnduhan::Menunggu->value, StatusBerkasUnduhan::Diproses->value])->where('DibuatPada', '<=', $batasMacet)->get() as $berkas) {
            $berkas->update(['Status' => StatusBerkasUnduhan::Gagal, 'SelesaiPada' => now(), 'Galat' => 'Penyiapan terhenti sebelum selesai. Silakan ekspor ulang.']);
            $jumlah++;
        }

        $jumlah += BerkasUnduhan::query()
            ->whereIn('Status', [StatusBerkasUnduhan::Gagal->value, StatusBerkasUnduhan::Kedaluwarsa->value])
            ->where('DibuatPada', '<=', now()->subDays(self::HARI_SIMPAN_JEJAK))
            ->delete();

        return $jumlah;
    }
}
