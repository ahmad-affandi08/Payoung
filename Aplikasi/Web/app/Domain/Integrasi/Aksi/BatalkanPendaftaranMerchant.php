<?php

declare(strict_types=1);

namespace App\Domain\Integrasi\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Integrasi\Merchant\PenyimpanBerkasKyc;
use App\Domain\Integrasi\Model\PendaftaranMerchantPembayaran;
use Illuminate\Support\Facades\DB;

/**
 * Tenant membatalkan pendaftaran yang belum diproses (`Draf`, `Gagal`, `Ditolak`): foto sementara dihapus dan seluruh
 * data pribadi (NIK, rekening) dibuang bersama barisnya. Pendaftaran yang sedang dikirim, ditinjau, atau disetujui
 * tidak bisa dibatalkan dari sini.
 */
final class BatalkanPendaftaranMerchant
{
    public const AKSI_AUDIT = 'merchant-pembayaran.batal';

    public function __construct(
        private readonly PenyimpanBerkasKyc $berkas,
        private readonly PencatatAudit $audit,
    ) {}

    public function Jalankan(): void
    {
        $paths = DB::transaction(function (): array {
            $pendaftaran = PendaftaranMerchantPembayaran::UntukPenyediaAktif()->lockForUpdate()->first();

            if ($pendaftaran === null) {
                return [];
            }

            if (! $pendaftaran->Status->CekBisaDiubah()) {
                throw new PelanggaranAturanBisnis('PendaftaranTidakBisaDibatalkan', 'Pendaftaran sedang diproses atau sudah disetujui, jadi tidak bisa dibatalkan.', statusHttp: 409);
            }

            $this->audit->Catat(self::AKSI_AUDIT, $pendaftaran, ['Status' => $pendaftaran->Status->value], null);
            $paths = [$pendaftaran->PathKtp, $pendaftaran->PathSwafoto, $pendaftaran->PathBuktiUsaha];
            $pendaftaran->delete();

            return $paths;
        });

        foreach ($paths as $path) {
            $this->berkas->Hapus($path);
        }
    }
}
