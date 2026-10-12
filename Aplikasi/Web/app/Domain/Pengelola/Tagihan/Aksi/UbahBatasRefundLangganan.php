<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Tagihan\Aksi;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Pengelola\Tagihan\Layanan\BatasRefundLangganan;
use App\Domain\Pengelola\Tagihan\Model\PengaturanPlatform;
use App\Domain\Pengelola\TimInternal\Enum\IzinPengelola;
use App\Domain\Pengelola\TimInternal\Layanan\PencatatAuditPengelola;
use App\Domain\Pengelola\TimInternal\Model\PenggunaPengelola;
use Illuminate\Support\Facades\DB;

/**
 * Super Admin mengubah batas refund tanpa persetujuan kedua (BR-P08.2, D-113). Nilai Rupiah penuh, 0 sampai
 * Rp 1 miliar; 0 berarti semua refund (selain yang diajukan Super Admin) menunggu persetujuan. Perubahan hanya berlaku
 * untuk pengajuan berikutnya dan tercatat di audit beserta nilai lama dan alasannya.
 */
final class UbahBatasRefundLangganan
{
    public const MAKS = '1000000000';

    public function __construct(
        private readonly BatasRefundLangganan $batas,
        private readonly PencatatAuditPengelola $audit,
    ) {}

    public function Jalankan(PenggunaPengelola $pelaku, string $nilai, string $alasan): Uang
    {
        if (! $pelaku->PunyaIzin(IzinPengelola::TagihanRefundSetujui)) {
            throw new PelanggaranAturanBisnis('BR-P08.2', 'Batas refund hanya bisa diubah Super Admin.');
        }

        $baru = Uang::Dari($nilai);

        if ($baru->BernilaiNegatif() || $baru->Bandingkan(Uang::Dari(self::MAKS)) > 0) {
            throw new PelanggaranAturanBisnis('BatasTidakSah', 'Batas refund harus antara Rp 0 dan Rp 1.000.000.000.', 'Batas');
        }

        return DB::transaction(function () use ($pelaku, $baru, $alasan): Uang {
            $lama = $this->batas->Ambil();
            PengaturanPlatform::query()->updateOrCreate(
                ['Kunci' => BatasRefundLangganan::KUNCI],
                ['Nilai' => $baru->KeString(), 'IdPenggunaPengelolaPengubah' => $pelaku->Id],
            );
            $this->audit->Catat(
                'tagihan.refund.batas-ubah',
                null,
                nilaiLama: ['Batas' => $lama->KeString()],
                nilaiBaru: ['Batas' => $baru->KeString()],
                alasan: trim($alasan),
                idPelaku: $pelaku->Id,
            );

            return $baru;
        });
    }
}
