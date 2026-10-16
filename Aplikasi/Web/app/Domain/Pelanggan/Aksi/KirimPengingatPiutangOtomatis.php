<?php

declare(strict_types=1);

namespace App\Domain\Pelanggan\Aksi;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Pelanggan\Enum\JenisPengingatPiutang;
use App\Domain\Pelanggan\Enum\StatusPiutang;
use App\Domain\Pelanggan\Kueri\PengaturanPengingatPiutangTenant;
use App\Domain\Pelanggan\Model\Piutang;
use Carbon\CarbonImmutable;

/**
 * D-23 D bagian 4b: putaran harian pengingat piutang otomatis tenant aktif (bila diaktifkan). Piutang terbuka
 * berpelanggan yang jatuh tempo dalam `HariSebelum` hari ke depan (termasuk hari ini) diingatkan sekali "akan jatuh
 * tempo"; yang lewat jatuh tempo 1–`JENDELA_LEWAT` hari diingatkan sekali "sudah lewat" (bila `IngatkanSaatLewat`).
 * Pelanggan tanpa kontak dilewati diam-diam (masih bisa ditagih manual). Piutang bersumber invoice (F-21) tidak ikut:
 * pengingatnya dikirim terpisah oleh `invoice:kirim-pengingat` dengan pengaturan yang sama.
 */
final class KirimPengingatPiutangOtomatis
{
    public const JENDELA_LEWAT = 7;

    public function __construct(
        private readonly PengaturanPengingatPiutangTenant $pengaturan,
        private readonly AntrekanPengingatPiutang $antrekan,
    ) {}

    /** @return int jumlah pengingat diantrekan */
    public function Jalankan(int $idTenant, CarbonImmutable $hariIni): int
    {
        $atur = $this->pengaturan->Ambil();

        if (! $atur['Aktif']) {
            return 0;
        }

        $hari = $hariIni->toDateString();
        $terbuka = fn () => Piutang::query()
            ->whereIn('Status', [StatusPiutang::BelumLunas->value, StatusPiutang::DibayarSebagian->value])
            ->whereNotNull('IdPelanggan')
            // Piutang bersumber invoice diingatkan `KirimPengingatInvoiceOtomatis` (pesan dengan tautan invoice).
            ->whereNull('IdInvoice')
            ->orderBy('Id');
        $antre = 0;
        $kelompok = [[JenisPengingatPiutang::SebelumJatuhTempo, $terbuka()->whereBetween('JatuhTempo', [$hari, $hariIni->addDays($atur['HariSebelum'])->toDateString()])]];

        if ($atur['IngatkanSaatLewat']) {
            $kelompok[] = [JenisPengingatPiutang::LewatJatuhTempo, $terbuka()->whereBetween('JatuhTempo', [$hariIni->subDays(self::JENDELA_LEWAT)->toDateString(), $hariIni->subDay()->toDateString()])];
        }

        foreach ($kelompok as [$jenis, $kueri]) {
            foreach ($kueri->get() as $piutang) {
                try {
                    if ($this->antrekan->Jalankan($idTenant, $piutang, $jenis) !== null) {
                        $antre++;
                    }
                } catch (PelanggaranAturanBisnis) {
                    // Tanpa kontak / kanal mati: dilewati, tetap bisa ditagih manual.
                }
            }
        }

        return $antre;
    }
}
