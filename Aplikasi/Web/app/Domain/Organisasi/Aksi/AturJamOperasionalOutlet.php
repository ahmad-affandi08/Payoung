<?php

declare(strict_types=1);

namespace App\Domain\Organisasi\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Organisasi\Layanan\JamOperasionalOutlet;
use App\Domain\Organisasi\Model\Outlet;
use Illuminate\Support\Facades\DB;

/**
 * F-e: jam operasional outlet per hari (jam lokal outlet; hari kosong = libur). `null` menghapus pengaturan (outlet
 * dianggap selalu buka). Audit `outlet.jam-operasional.ubah` hanya bila isinya berubah.
 */
final class AturJamOperasionalOutlet
{
    public function __construct(private readonly PencatatAudit $audit) {}

    /**
     * @param  array<string, mixed>|null  $jam
     *
     * @throws PelanggaranAturanBisnis
     */
    public function Jalankan(Outlet $outlet, ?array $jam): Outlet
    {
        $baru = JamOperasionalOutlet::Normalkan($jam);

        return DB::transaction(function () use ($outlet, $baru): Outlet {
            $outlet = Outlet::query()->lockForUpdate()->findOrFail($outlet->Id);
            $lama = $outlet->JamOperasional;

            // `==` (bukan `===`): JSON MySQL menyimpan kunci dalam urutan lain, isi yang sama tidak boleh dianggap berubah.
            if ($lama != $baru) {
                $outlet->forceFill(['JamOperasional' => $baru])->save();
                $this->audit->Catat('outlet.jam-operasional.ubah', $outlet, nilaiLama: ['JamOperasional' => $lama], nilaiBaru: ['JamOperasional' => $baru]);
            }

            return $outlet;
        });
    }
}
