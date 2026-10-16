<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Aksi;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Pelanggan\Enum\StatusPiutang;
use App\Domain\Pelanggan\Kueri\PengaturanPengingatPiutangTenant;
use App\Domain\Pelanggan\Model\Piutang;
use App\Domain\Penjualan\Enum\JenisPengirimanInvoice;
use App\Domain\Penjualan\Enum\StatusInvoice;
use App\Domain\Penjualan\Model\Invoice;
use Carbon\CarbonImmutable;

/**
 * F-21: putaran harian pengingat **invoice** otomatis tenant aktif. Memakai pengaturan yang sama dengan pengingat piutang
 * (D-23 D bagian 4b: aktif, berapa hari sebelum jatuh tempo, ingatkan setelah lewat) supaya pemilik hanya punya satu
 * saklar tagihan, tetapi pesannya khusus invoice: memuat nomor invoice dan tautan publik untuk melihat dan membayar.
 * Putaran pengingat piutang umum (`KirimPengingatPiutangOtomatis`) melewatkan piutang bersumber invoice, jadi pelanggan
 * tidak menerima dua pesan untuk tagihan yang sama.
 *
 * Dua tahap, masing-masing sekali per invoice (dijaga `AntrekanPengirimanInvoice` lewat `Tahap`): `Sebelum` saat jatuh
 * tempo dalam `HariSebelum` hari ke depan (termasuk hari ini), dan `Lewat` saat sudah lewat 1–`JENDELA_LEWAT` hari bila
 * `IngatkanSaatLewat`. Pelanggan tanpa nomor sah atau WhatsApp mati dilewati diam-diam (masih bisa ditagih manual).
 */
final class KirimPengingatInvoiceOtomatis
{
    public const JENDELA_LEWAT = 7;

    public const TAHAP_SEBELUM = 'Sebelum';

    public const TAHAP_LEWAT = 'Lewat';

    public function __construct(
        private readonly PengaturanPengingatPiutangTenant $pengaturan,
        private readonly AntrekanPengirimanInvoice $antrekan,
    ) {}

    /** @return int jumlah pengingat diantrekan */
    public function Jalankan(int $idTenant, CarbonImmutable $hariIni): int
    {
        $atur = $this->pengaturan->Ambil();

        if (! $atur['Aktif']) {
            return 0;
        }

        $kelompok = [[self::TAHAP_SEBELUM, $hariIni->toDateString(), $hariIni->addDays($atur['HariSebelum'])->toDateString()]];

        if ($atur['IngatkanSaatLewat']) {
            $kelompok[] = [self::TAHAP_LEWAT, $hariIni->subDays(self::JENDELA_LEWAT)->toDateString(), $hariIni->subDay()->toDateString()];
        }

        $antre = 0;

        foreach ($kelompok as [$tahap, $dari, $sampai]) {
            $idInvoice = Piutang::query()
                ->whereNotNull('IdInvoice')
                ->whereIn('Status', [StatusPiutang::BelumLunas->value, StatusPiutang::DibayarSebagian->value])
                ->whereBetween('JatuhTempo', [$dari, $sampai])
                ->pluck('IdInvoice');

            foreach (Invoice::query()->whereIn('Id', $idInvoice)->where('Status', StatusInvoice::Terbit->value)->orderBy('JatuhTempo')->orderBy('Id')->get() as $invoice) {
                try {
                    $this->antrekan->Jalankan($idTenant, $invoice, null, JenisPengirimanInvoice::Pengingat, $tahap);
                    $antre++;
                } catch (PelanggaranAturanBisnis) {
                    // Tanpa kontak, WhatsApp mati, atau tahap ini sudah dikirim: dilewati, tetap bisa ditagih manual.
                }
            }
        }

        return $antre;
    }
}
