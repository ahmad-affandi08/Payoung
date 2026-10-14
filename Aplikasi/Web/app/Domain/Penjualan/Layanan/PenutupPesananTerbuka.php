<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Layanan;

use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Organisasi\Aksi\AturKebersihanMeja;
use App\Domain\Organisasi\Kueri\MejaOutlet;
use App\Domain\Penjualan\Enum\KodeAlasanTinjauan;
use App\Domain\Penjualan\Enum\StatusPesananTerbuka;
use App\Domain\Penjualan\Model\Penjualan;
use App\Domain\Penjualan\Model\PesananTerbuka;
use Carbon\CarbonImmutable;

/**
 * Penutupan pesanan terbuka oleh pembayarannya (`Penjualan.Buat` + `UuidPesananTerbuka`, F-07 mode meja fase 1).
 * Uang yang sudah diterima tidak pernah ditolak karena pesanan (§18.3): pesanan tidak dikenal → tinjauan
 * `PesananTidakDikenal`; pesanan sudah dibayar/dibatalkan (bayar ganda offline) → tinjauan `PesananDibayarGanda`.
 * Meja pesanan yang dibayar ditandai "perlu dibersihkan" sejak waktu bayar perangkat (K-12).
 */
final class PenutupPesananTerbuka
{
    public function __construct(
        private readonly AturKebersihanMeja $kebersihan,
        private readonly MejaOutlet $meja,
    ) {}

    /**
     * @return array{0: PesananTerbuka|null, 1: array<string, string>} pesanan (dikunci) dan alasan tinjauan
     */
    public function Cari(?string $uuidPesanan, int $idOutlet): array
    {
        if ($uuidPesanan === null) {
            return [null, []];
        }

        $pesanan = PesananTerbuka::query()->where('Uuid', $uuidPesanan)->lockForUpdate()->first();

        if ($pesanan === null || $pesanan->IdOutlet !== $idOutlet) {
            return [null, [KodeAlasanTinjauan::PesananTidakDikenal->value => KodeAlasanTinjauan::PesananTidakDikenal->value.': pesanan terbuka belum diterima server saat penjualan diterima']];
        }

        if ($pesanan->Status !== StatusPesananTerbuka::Terbuka) {
            return [$pesanan, [KodeAlasanTinjauan::PesananDibayarGanda->value => KodeAlasanTinjauan::PesananDibayarGanda->value.": pesanan {$pesanan->Nomor} sudah {$pesanan->Status->AmbilLabel()} sebelum penjualan ini diterima"]];
        }

        return [$pesanan, []];
    }

    /**
     * FnB minimum charge (D-144): nilai belanja (subtotal setelah diskon, sebelum biaya layanan dan pajak) pesanan meja
     * dibandingkan dengan minimum meja/areanya. Kurang = penjualan tetap diterima + tinjauan (uang sudah diterima,
     * §18.3); kasir yang benar menagih selisihnya lewat baris produk minimum charge sehingga nilainya cukup.
     *
     * @return array<string, string> alasan tinjauan
     */
    public function PeriksaMinimumCharge(?PesananTerbuka $pesanan, Uang $nilaiBelanja): array
    {
        if ($pesanan === null || $pesanan->IdMeja === null) {
            return [];
        }

        $minimum = $this->meja->AmbilMinimumCharge($pesanan->IdMeja);

        if ($minimum === null || $nilaiBelanja->Bandingkan(Uang::Dari($minimum)) >= 0) {
            return [];
        }

        $kode = KodeAlasanTinjauan::MinimumChargeTidakTerpenuhi->value;

        return [$kode => "{$kode}: belanja {$nilaiBelanja->FormatRupiah()} kurang dari minimum charge ".Uang::Dari($minimum)->FormatRupiah().' dan selisihnya tidak ditagih'];
    }

    public function Tutup(?PesananTerbuka $pesanan, Penjualan $penjualan): void
    {
        if ($pesanan === null || $pesanan->Status !== StatusPesananTerbuka::Terbuka) {
            return;
        }

        $pesanan->update([
            'Status' => StatusPesananTerbuka::Dibayar,
            'IdPenjualan' => $penjualan->Id,
            'DitutupPada' => $penjualan->DibuatOfflinePada,
            'IdPerangkatKunciBayar' => null,
            'KunciBayarSampai' => null,
        ]);

        if ($pesanan->IdMeja !== null) {
            $this->kebersihan->TandaiPerluDibersihkan($pesanan->IdMeja, CarbonImmutable::instance($penjualan->DibuatOfflinePada));
        }
    }
}
