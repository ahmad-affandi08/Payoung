<?php

declare(strict_types=1);

namespace App\Domain\Kasir\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Tenant\Aksi\UbahPengaturanKasirTenant;
use App\Domain\Tenant\Data\DataPengaturanKasir;
use App\Domain\Tenant\Kueri\PengaturanKasirTenant;
use Illuminate\Support\Facades\DB;

/**
 * Mengubah pengaturan kasir tenant: batas kas keluar tanpa persetujuan (BR-06.4), mode shift bersama (BR-06.2),
 * batas diskon manual kasir & penyetuju (BR-07.3), pembulatan tunai (BR-08.6), serta tutup shift buta & toleransi
 * selisih kas (F-11), batas hari retur (F-09), dan PIN buka laci manual (cetak struk bagian 4). Batas kas 0 = setiap kas keluar
 * butuh persetujuan; batas diskon 0 = setiap diskon manual butuh penyetuju. Perubahan berlaku untuk transaksi
 * berikutnya setelah perangkat memperbarui data; transaksi yang sudah diterima tidak dinilai ulang. Tanpa perubahan =
 * tidak ada yang ditulis. Audit `kasir.pengaturan.ubah`.
 */
final class UbahPengaturanKasir
{
    /** Batas atas wajar agar tidak melampaui DECIMAL(18,2). */
    private const BATAS_MAKSIMAL = '1000000000000.00';

    private const KELIPATAN_MAKSIMAL = 1000;

    public function __construct(
        private readonly PengaturanKasirTenant $pengaturan,
        private readonly UbahPengaturanKasirTenant $ubahTenant,
        private readonly PencatatAudit $audit,
    ) {}

    public function Jalankan(DataPengaturanKasir $baru): void
    {
        $this->Validasi($baru);

        DB::transaction(function () use ($baru): void {
            $lama = $this->pengaturan->Ambil();
            $nilaiLama = self::KeLarik($lama);
            $nilaiBaru = self::KeLarik($baru);

            if ($nilaiLama === $nilaiBaru) {
                return;
            }

            $this->ubahTenant->Jalankan($baru);
            $this->audit->Catat('kasir.pengaturan.ubah', nilaiLama: $nilaiLama, nilaiBaru: $nilaiBaru);
        });
    }

    private function Validasi(DataPengaturanKasir $data): void
    {
        if ($data->batasKasKeluar->BernilaiNegatif() || $data->batasKasKeluar->Bandingkan(Uang::Dari(self::BATAS_MAKSIMAL)) > 0) {
            throw new PelanggaranAturanBisnis('BatasKasKeluarTidakValid', 'Batas kas keluar harus antara Rp 0 dan Rp 1 triliun.', 'BatasKasKeluar');
        }

        foreach (['BatasDiskonManual' => $data->batasDiskonManual, 'BatasDiskonPenyetuju' => $data->batasDiskonPenyetuju] as $bidang => $persen) {
            if ($persen->isNegative() || $persen->isGreaterThan(100)) {
                throw new PelanggaranAturanBisnis('BatasDiskonTidakValid', 'Batas diskon harus antara 0% dan 100%.', $bidang);
            }
        }

        if ($data->batasDiskonPenyetuju->isLessThan($data->batasDiskonManual)) {
            throw new PelanggaranAturanBisnis('BatasDiskonTidakValid', 'Batas diskon dengan persetujuan tidak boleh lebih kecil dari batas diskon kasir.', 'BatasDiskonPenyetuju');
        }

        if ($data->toleransiSelisihKas->BernilaiNegatif() || $data->toleransiSelisihKas->Bandingkan(Uang::Dari(self::BATAS_MAKSIMAL)) > 0) {
            throw new PelanggaranAturanBisnis('ToleransiSelisihTidakValid', 'Toleransi selisih kas harus antara Rp 0 dan Rp 1 triliun.', 'ToleransiSelisihKas');
        }

        if ($data->batasReturTanpaStrukHarian->BernilaiNegatif() || $data->batasReturTanpaStrukHarian->Bandingkan(Uang::Dari(self::BATAS_MAKSIMAL)) > 0) {
            throw new PelanggaranAturanBisnis('BatasReturTanpaStrukTidakValid', 'Batas retur tanpa struk harus antara Rp 0 dan Rp 1 triliun.', 'BatasReturTanpaStrukHarian');
        }

        if ($data->batasNotifikasiTransaksi->BernilaiNegatif() || $data->batasNotifikasiTransaksi->Bandingkan(Uang::Dari(self::BATAS_MAKSIMAL)) > 0) {
            throw new PelanggaranAturanBisnis('BatasNotifikasiTidakValid', 'Batas notifikasi transaksi harus antara Rp 0 dan Rp 1 triliun.', 'BatasNotifikasiTransaksi');
        }

        if ($data->batasHariRetur < 0 || $data->batasHariRetur > DataPengaturanKasir::BATAS_HARI_RETUR_MAKSIMAL) {
            throw new PelanggaranAturanBisnis('BatasHariReturTidakValid', 'Batas hari retur harus antara 0 dan '.DataPengaturanKasir::BATAS_HARI_RETUR_MAKSIMAL.' hari.', 'BatasHariRetur');
        }

        if ($data->batasHariLewatJatuhTempo < 0 || $data->batasHariLewatJatuhTempo > DataPengaturanKasir::BATAS_HARI_RETUR_MAKSIMAL) {
            throw new PelanggaranAturanBisnis('BatasHariLewatTidakValid', 'Batas hari lewat jatuh tempo harus antara 0 dan '.DataPengaturanKasir::BATAS_HARI_RETUR_MAKSIMAL.' hari.', 'BatasHariLewatJatuhTempo');
        }

        $kelipatan = $data->pembulatanTunai['Kelipatan'] ?? null;

        if ($kelipatan !== null && ($kelipatan <= 0 || $kelipatan > self::KELIPATAN_MAKSIMAL)) {
            throw new PelanggaranAturanBisnis('PembulatanTidakValid', 'Kelipatan pembulatan tunai harus antara Rp 1 dan Rp 1.000.', 'PembulatanTunai.Kelipatan');
        }
    }

    /**
     * @return array{BatasKasKeluar: string, ShiftBersama: bool, BatasDiskonManual: string, BatasDiskonPenyetuju: string, PembulatanTunai: array{Kelipatan: int, Arah: string}|null, TutupShiftButa: bool, ToleransiSelisihKas: string, BatasHariRetur: int, BatasHariLewatJatuhTempo: int, BukaLaciPerluPin: bool, BatasReturTanpaStrukHarian: string, BatasNotifikasiTransaksi: string}
     */
    private static function KeLarik(DataPengaturanKasir $data): array
    {
        return [
            'BatasKasKeluar' => $data->batasKasKeluar->KeString(),
            'ShiftBersama' => $data->shiftBersama,
            'BatasDiskonManual' => (string) $data->batasDiskonManual,
            'BatasDiskonPenyetuju' => (string) $data->batasDiskonPenyetuju,
            'PembulatanTunai' => $data->AmbilPembulatanTunaiLarik(),
            'TutupShiftButa' => $data->tutupShiftButa,
            'ToleransiSelisihKas' => $data->toleransiSelisihKas->KeString(),
            'BatasHariRetur' => $data->batasHariRetur,
            'BatasHariLewatJatuhTempo' => $data->batasHariLewatJatuhTempo,
            'BukaLaciPerluPin' => $data->bukaLaciPerluPin,
            'BatasReturTanpaStrukHarian' => $data->batasReturTanpaStrukHarian->KeString(),
            'BatasNotifikasiTransaksi' => $data->batasNotifikasiTransaksi->KeString(),
        ];
    }
}
