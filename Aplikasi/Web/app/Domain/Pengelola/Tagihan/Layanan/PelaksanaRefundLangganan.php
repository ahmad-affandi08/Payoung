<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Tagihan\Layanan;

use App\Domain\Pengelola\Mitra\Aksi\BatalkanKomisiMitra;
use App\Domain\Pengelola\Tagihan\Kueri\DaftarTagihanPlatform;
use App\Domain\Pengelola\TimInternal\Model\PenggunaPengelola;
use App\Domain\Tenant\Enum\JenisTagihanLangganan;
use App\Domain\Tenant\Enum\StatusKomisiMitra;
use App\Domain\Tenant\Enum\StatusRefundLangganan;
use App\Domain\Tenant\Enum\StatusTagihanLangganan;
use App\Domain\Tenant\Model\KomisiMitra;
use App\Domain\Tenant\Model\Langganan;
use App\Domain\Tenant\Model\LanggananAddon;
use App\Domain\Tenant\Model\RefundLangganan;
use App\Domain\Tenant\Model\TagihanLangganan;
use Carbon\CarbonImmutable;

/**
 * Akibat refund yang disetujui (P-08, BR-P08.2, D-112): tagihan `Lunas → Dikembalikan`, layanan yang dibelinya
 * diakhiri bila masih berlaku, dan komisi mitra tertunda atas tagihan itu dibatalkan (clawback BR-P12.1).
 * Dipakai `AjukanRefundLangganan` (di bawah batas atau diajukan Super Admin) dan `PutuskanRefundLangganan` supaya
 * akibatnya tidak bisa berbeda antar jalur. Pemanggil mengunci `Langganan`, `TagihanLangganan`, dan `RefundLangganan`
 * dengan urutan itu di dalam transaksi.
 *
 * Aturan layanan per jenis tagihan (hanya bila tagihan itu masih yang menentukan keadaan langganan sekarang):
 * - Add-on: add-on yang dibelinya berakhir saat ini.
 * - Naik paket: paket langganan kembali ke paket asal, kecuali sudah ada tagihan paket lain yang lunas sesudahnya
 *   (perpanjangan paket baru): paket itu sudah dibayar, jadi refund hanya mencatat uang kembali.
 * - Perpanjangan: akhir periode kembali ke akhir periode sebelumnya (awal periode tagihan itu), dan add-on yang ikut
 *   diperpanjang tagihan itu dipangkas ke akhir yang sama. Aktivasi: layanan berakhir saat ini. Bila ada pembayaran lebih baru yang sudah memperpanjang periode, layanan dibiarkan dan refund
 *   hanya mencatat uang kembali.
 * Pengembalian uangnya sendiri dilakukan Keuangan di luar sistem; `CatatanPengembalian` menyimpan referensinya.
 */
final class PelaksanaRefundLangganan
{
    public function __construct(private readonly BatalkanKomisiMitra $komisi) {}

    /**
     * @return bool true bila layanan tenant diakhiri/diturunkan akibat refund ini
     */
    public function Laksanakan(RefundLangganan $refund, TagihanLangganan $tagihan, Langganan $langganan, PenggunaPengelola $pemutus, CarbonImmutable $sekarang): bool
    {
        $diakhiri = $this->AkhiriLayanan($tagihan, $langganan, $sekarang);

        $tagihan->update(['Status' => StatusTagihanLangganan::Dikembalikan, 'DikembalikanPada' => $sekarang]);
        $refund->update([
            'Status' => StatusRefundLangganan::Disetujui,
            'IdPenggunaPengelolaPemutus' => $pemutus->Id,
            'DiputuskanPada' => $sekarang,
            'LayananDiakhiri' => $diakhiri,
        ]);

        foreach (KomisiMitra::query()->where('IdTagihanLangganan', $tagihan->Id)->where('Status', StatusKomisiMitra::Tertunda->value)->get() as $komisi) {
            $this->komisi->Jalankan($pemutus, $komisi, "Tagihan {$tagihan->Nomor} dikembalikan (refund).");
        }

        return $diakhiri;
    }

    private function AkhiriLayanan(TagihanLangganan $tagihan, Langganan $langganan, CarbonImmutable $sekarang): bool
    {
        if ($tagihan->Jenis === JenisTagihanLangganan::Addon) {
            $diakhiri = false;

            foreach (LanggananAddon::query()->where('IdTenant', $tagihan->IdTenant)->where('IdTagihanLanggananAsal', $tagihan->Id)->get() as $milik) {
                if ($milik->SelesaiPada->greaterThan($sekarang)) {
                    $milik->update(['SelesaiPada' => $sekarang, 'PerpanjangOtomatis' => false, 'BerhentiPada' => $sekarang]);
                    $diakhiri = true;
                }
            }

            return $diakhiri;
        }

        if ($tagihan->Jenis === JenisTagihanLangganan::NaikPaket) {
            if ($tagihan->IdPaketAsal === null || $langganan->IdPaket !== $tagihan->IdPaket || $this->CekAdaPembayaranPaketLebihBaru($tagihan)) {
                return false;
            }

            $langganan->update(['IdPaket' => $tagihan->IdPaketAsal]);

            return true;
        }

        if (
            $tagihan->PeriodeSelesai === null
            || $langganan->PeriodeSelesai === null
            || $langganan->IdPaket !== $tagihan->IdPaket
            || ! $langganan->PeriodeSelesai->equalTo($tagihan->PeriodeSelesai)
        ) {
            return false;
        }

        // Perpanjangan menyambung periode yang sudah dibayar sebelumnya, jadi layanan kembali ke akhir periode itu
        // (boleh masih di masa depan). Aktivasi tidak punya periode sebelumnya, jadi layanan berakhir sekarang.
        $akhirBaru = $tagihan->Jenis === JenisTagihanLangganan::Perpanjangan && $tagihan->PeriodeMulai !== null
            ? CarbonImmutable::instance($tagihan->PeriodeMulai)
            : $sekarang;
        // BR-P04.4: perpanjangan ke paket lebih murah (turun paket) yang dikembalikan juga mengembalikan paket sebelumnya.
        $langganan->update(['PeriodeSelesai' => $akhirBaru] + ($tagihan->Jenis === JenisTagihanLangganan::Perpanjangan && $tagihan->IdPaketAsal !== null ? ['IdPaket' => $tagihan->IdPaketAsal] : []));
        $this->PangkasAddonTagihan($tagihan, $akhirBaru, $sekarang);

        return true;
    }

    /**
     * BR-P08.16: ada tagihan paket (aktivasi, naik paket, perpanjangan) yang lunas sesudah tagihan ini berarti paket sekarang
     * dibayar oleh tagihan itu. Refund tagihan lama hanya mengembalikan uang, tidak menurunkan paket yang sudah dibayar penuh.
     */
    private function CekAdaPembayaranPaketLebihBaru(TagihanLangganan $tagihan): bool
    {
        if ($tagihan->DibayarPada === null) {
            return false;
        }

        // Jalur konsol tanpa tenant aktif: kueri lintas tenant resmi milik domain Pengelola (bukan scope MilikTenant).
        return DaftarTagihanPlatform::KueriTagihan()
            ->where('IdTenant', $tagihan->IdTenant)
            ->where('Status', StatusTagihanLangganan::Lunas->value)
            ->where('Jenis', '!=', JenisTagihanLangganan::Addon->value)
            // Lebih baru menurut waktu bayar; pada waktu yang sama (satu detik) urutan nomor tagihan yang menentukan.
            ->where(fn ($kueri) => $kueri->where('DibayarPada', '>', $tagihan->DibayarPada)
                ->orWhere(fn ($sama) => $sama->where('DibayarPada', $tagihan->DibayarPada)->where('Id', '>', $tagihan->Id)))
            ->exists();
    }

    /**
     * Add-on yang diperpanjang oleh tagihan ini (ikut ditagih di perpanjangan) berakhir bersama periode yang dibatalkan,
     * bukan tetap aktif sampai akhir periode yang uangnya sudah dikembalikan.
     */
    private function PangkasAddonTagihan(TagihanLangganan $tagihan, CarbonImmutable $akhirBaru, CarbonImmutable $sekarang): void
    {
        foreach (LanggananAddon::query()->where('IdTenant', $tagihan->IdTenant)->where('IdTagihanLanggananAsal', $tagihan->Id)->get() as $milik) {
            if (! $milik->SelesaiPada->greaterThan($akhirBaru)) {
                continue;
            }

            $milik->update(
                ['SelesaiPada' => $akhirBaru]
                + (! $akhirBaru->greaterThan($sekarang) ? ['PerpanjangOtomatis' => false, 'BerhentiPada' => $sekarang] : []),
            );
        }
    }
}
