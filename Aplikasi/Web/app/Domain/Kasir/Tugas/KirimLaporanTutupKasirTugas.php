<?php

declare(strict_types=1);

namespace App\Domain\Kasir\Tugas;

use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Integrasi\Enum\JenisPesanWhatsapp;
use App\Domain\Integrasi\Layanan\PelaporKaryawanWhatsapp;
use App\Domain\Kasir\Kueri\LaporanShift;
use App\Domain\Kasir\Model\Shift;
use App\Domain\Organisasi\Kueri\PetaUuidOutlet;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * D-299 tahap 3: ringkasan tutup kasir (transaksi, penjualan bersih, kas seharusnya/aktual, selisih) ke penerima
 * laporan WhatsApp outlet. Setelah commit, lewat antrean (aturan #10), tanpa coba ulang.
 */
final class KirimLaporanTutupKasirTugas implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $idTenant, public readonly int $idShift) {}

    public function handle(KonteksTenant $konteks, PelaporKaryawanWhatsapp $pelapor, LaporanShift $laporan, PetaUuidOutlet $outlet): void
    {
        $sebelumnya = $konteks->Ambil();
        $konteks->Atur($this->idTenant);

        try {
            $shift = Shift::query()->find($this->idShift);

            if ($shift === null || $shift->DitutupPada === null || ! $pelapor->AdaPenerima($shift->IdOutlet)) {
                return;
            }

            $ringkasan = $laporan->Hitung($shift)->penjualan;
            $namaOutlet = $outlet->AmbilRingkas([$shift->IdOutlet])[0]['Nama'] ?? 'outlet';
            $selisih = Uang::Dari((string) ($shift->Selisih ?? '0'));
            $keteranganSelisih = $selisih->BernilaiNol() ? 'kas pas' : ($selisih->BernilaiNegatif() ? 'kas kurang '.Uang::Nol()->Kurangi($selisih)->FormatRupiah() : 'kas lebih '.$selisih->FormatRupiah());

            $pelapor->Kirim(JenisPesanWhatsapp::LaporanTutupKasir, $shift->IdOutlet, implode("\n", [
                "Kasir ditutup di {$namaOutlet}.",
                "Transaksi: {$ringkasan->jumlahTransaksi}",
                'Penjualan bersih: '.$ringkasan->penjualanBersih->FormatRupiah(),
                'Kas seharusnya: '.Uang::Dari((string) ($shift->KasSeharusnya ?? '0'))->FormatRupiah(),
                'Kas dihitung: '.Uang::Dari((string) ($shift->KasAktual ?? '0'))->FormatRupiah()." ({$keteranganSelisih})",
            ]));
        } finally {
            $sebelumnya === null ? $konteks->Kosongkan() : $konteks->Atur($sebelumnya);
        }
    }
}
