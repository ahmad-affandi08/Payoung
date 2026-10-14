<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Dokumen\Enum\StatusDokumenTerposting;
use App\Domain\Bersama\Dokumen\Layanan\PencatatRiwayatStatus;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Penjualan\Model\FakturPajakPenjualan;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Membatalkan Faktur Pajak per transaksi kasir (TAX-07), mis. salah pelanggan atau penjualannya di-void. Tidak ada
 * jurnal untuk dibalik (dokumen ini tidak membukukan apa pun); penjualannya tidak tersentuh dan boleh diterbitkan
 * faktur baru. Faktur yang sudah bernomor Coretax tetap bisa dibatalkan di sini, tetapi pembatalannya di Coretax
 * adalah urusan pengguna: karena itu pesannya mengingatkan.
 */
final class BatalkanFakturPajakPenjualan
{
    public const PANJANG_ALASAN_MINIMAL = 5;

    public function __construct(
        private readonly PencatatRiwayatStatus $riwayat,
        private readonly PencatatAudit $audit,
    ) {}

    /**
     * @param  list<int>|null  $idOutletBoleh
     *
     * @throws PelanggaranAturanBisnis AlasanBatalWajib, FakturPajakTidakDitemukan
     */
    public function Jalankan(string $uuid, string $alasan, int $idPengguna, ?array $idOutletBoleh): FakturPajakPenjualan
    {
        $alasan = trim($alasan);

        if (mb_strlen($alasan) < self::PANJANG_ALASAN_MINIMAL || mb_strlen($alasan) > 255) {
            throw new PelanggaranAturanBisnis('AlasanBatalWajib', 'Alasan pembatalan wajib diisi, '.self::PANJANG_ALASAN_MINIMAL.' sampai 255 karakter.', 'Alasan');
        }

        return DB::transaction(function () use ($uuid, $alasan, $idPengguna, $idOutletBoleh): FakturPajakPenjualan {
            $faktur = FakturPajakPenjualan::query()->where('Uuid', $uuid)->lockForUpdate()->first();

            if ($faktur === null || ($idOutletBoleh !== null && ! in_array($faktur->IdOutlet, $idOutletBoleh, true))) {
                throw new PelanggaranAturanBisnis('FakturPajakTidakDitemukan', 'Faktur Pajak tidak ditemukan.', 'Umum');
            }

            if ($faktur->Status === StatusDokumenTerposting::Dibatalkan) {
                return $faktur;
            }

            $faktur->UbahStatus(StatusDokumenTerposting::Dibatalkan);
            $faktur->fill([
                'AlasanBatal' => $alasan,
                'DibatalkanOleh' => $idPengguna,
                'DibatalkanPada' => CarbonImmutable::now(),
                'DiubahOleh' => $idPengguna,
            ])->save();
            $this->riwayat->Catat(FakturPajakPenjualan::JENIS_DOKUMEN, $faktur->Id, StatusDokumenTerposting::Diposting->value, StatusDokumenTerposting::Dibatalkan->value, $idPengguna, $alasan);
            $this->audit->Catat('penjualan.faktur-pajak.batal', $faktur, ['Status' => StatusDokumenTerposting::Diposting->value], [
                'Status' => StatusDokumenTerposting::Dibatalkan->value,
                'NomorFakturPajak' => $faktur->NomorFakturPajak,
                'Alasan' => $alasan,
            ], idPengguna: $idPengguna);

            return $faktur;
        });
    }
}
