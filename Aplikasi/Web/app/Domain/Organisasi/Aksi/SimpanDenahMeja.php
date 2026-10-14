<?php

declare(strict_types=1);

namespace App\Domain\Organisasi\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Organisasi\Model\Meja;
use App\Domain\Organisasi\Model\Outlet;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * POS-03: menyimpan letak meja pada denah outlet (seret-lepas di back-office). Posisi disimpan sebagai titik tengah
 * meja pada kanvas relatif 0–[BATAS] di kedua sumbu, sehingga denah sama di layar apa pun. `null` = meja tidak ditaruh
 * di denah (kasir menampilkannya di baris "Belum ditata"). Hanya mengubah tata letak; tidak menyentuh pesanan atau
 * status pakai meja. Semua atau tidak sama sekali: satu meja yang bukan milik outlet menggagalkan seluruhnya.
 */
final class SimpanDenahMeja
{
    public const BATAS = 1000;

    public function __construct(private readonly PencatatAudit $audit) {}

    /**
     * @param  list<array{Uuid: string, PosisiX: int|null, PosisiY: int|null}>  $posisi
     * @return int jumlah meja yang letaknya berubah
     *
     * @throws PelanggaranAturanBisnis MejaTidakDikenal, MejaGanda, PosisiTidakValid
     */
    public function Jalankan(Outlet $outlet, array $posisi): int
    {
        $uuid = array_column($posisi, 'Uuid');

        if (count($uuid) !== count(array_unique($uuid))) {
            throw new PelanggaranAturanBisnis('MejaGanda', 'Satu meja muncul dua kali di denah.', 'Meja');
        }

        foreach ($posisi as $baris) {
            $x = $baris['PosisiX'];
            $y = $baris['PosisiY'];

            if (($x === null) !== ($y === null) || ($x !== null && ($x < 0 || $x > self::BATAS)) || ($y !== null && ($y < 0 || $y > self::BATAS))) {
                throw new PelanggaranAturanBisnis('PosisiTidakValid', 'Letak meja harus di dalam denah.', 'Meja');
            }
        }

        return DB::transaction(function () use ($outlet, $posisi, $uuid): int {
            /** @var Collection<string, Meja> $meja */
            $meja = Meja::query()->where('IdOutlet', $outlet->Id)->whereIn('Uuid', $uuid)->lockForUpdate()->get()->keyBy('Uuid');

            if ($meja->count() !== count($uuid)) {
                throw new PelanggaranAturanBisnis('MejaTidakDikenal', 'Ada meja yang bukan milik outlet ini.', 'Meja');
            }

            $berubah = 0;

            foreach ($posisi as $baris) {
                $model = $meja->get($baris['Uuid']);

                if ($model === null || ($model->PosisiX === $baris['PosisiX'] && $model->PosisiY === $baris['PosisiY'])) {
                    continue;
                }

                $model->update(['PosisiX' => $baris['PosisiX'], 'PosisiY' => $baris['PosisiY']]);
                $berubah++;
            }

            if ($berubah > 0) {
                $this->audit->Catat('denah-meja.simpan', $outlet, nilaiBaru: ['JumlahMejaBerubah' => $berubah]);
            }

            return $berubah;
        });
    }
}
