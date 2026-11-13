<?php

declare(strict_types=1);

namespace App\Domain\Laporan\Aksi;

use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Integrasi\Enum\JenisPesanWhatsapp;
use App\Domain\Integrasi\Layanan\PelaporKaryawanWhatsapp;
use App\Domain\Laporan\Kueri\RingkasanPenjualanHarianOutlet;
use App\Domain\Organisasi\Kueri\PetaUuidOutlet;
use App\Domain\Organisasi\Kueri\TanggalBisnisOutlet;
use App\Domain\Organisasi\Kueri\ZonaWaktuOutlet;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * D-299 tahap 3: penjualan hari ini per outlet ke penerima laporan WhatsApp, tiap jam selama 09.00 sampai 21.00 waktu
 * outlet dan hanya bila sudah ada transaksi. Satu pesan per outlet per jam (kunci cache), jadi penjadwal yang jalan
 * ganda tidak mengirim ganda. Dipanggil dengan konteks tenant aktif.
 */
final class KirimPenjualanPerJam
{
    private const JAM_MULAI = 9;

    private const JAM_AKHIR = 21;

    public function __construct(
        private readonly PelaporKaryawanWhatsapp $pelapor,
        private readonly RingkasanPenjualanHarianOutlet $ringkasan,
        private readonly TanggalBisnisOutlet $tanggalBisnis,
        private readonly ZonaWaktuOutlet $zona,
        private readonly PetaUuidOutlet $outlet,
    ) {}

    /** @return int jumlah pesan terkirim */
    public function Jalankan(int $idTenant, ?CarbonImmutable $sekarang = null): int
    {
        $sekarang ??= CarbonImmutable::now();
        if (! $this->pelapor->SakelarAktif(JenisPesanWhatsapp::LaporanPenjualanPerJam)) {
            return 0;
        }

        $terkirim = 0;
        $idOutlet = $this->pelapor->AmbilIdOutletPenerima();
        $nama = array_column($this->outlet->AmbilRingkas($idOutlet === [] ? [0] : $idOutlet), 'Nama', 'Id');
        $selisih = $this->zona->AmbilSelisihDetik($idOutlet);

        foreach ($idOutlet as $id) {
            $lokal = $sekarang->utc()->addSeconds((int) ($selisih[$id] ?? 0));

            if ($lokal->hour < self::JAM_MULAI || $lokal->hour > self::JAM_AKHIR) {
                continue;
            }

            $ringkas = $this->ringkasan->Ambil($id, $this->tanggalBisnis->Hitung($id, $sekarang));

            if ($ringkas['JumlahTransaksi'] === 0 || ! Cache::add("wa:penjualan-per-jam:{$idTenant}:{$id}:".$lokal->format('Ymd-H'), 1, 3000)) {
                continue;
            }

            $terkirim += $this->pelapor->Kirim(
                JenisPesanWhatsapp::LaporanPenjualanPerJam,
                $id,
                'Penjualan '.($nama[$id] ?? 'outlet').' hari ini sampai pukul '.$lokal->format('H').".00: {$ringkas['JumlahTransaksi']} transaksi, ".Uang::Dari($ringkas['Bersih'])->FormatRupiah().' (bersih).',
            ) > 0 ? 1 : 0;
        }

        return $terkirim;
    }
}
