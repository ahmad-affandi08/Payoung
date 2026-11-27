<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Penjualan\Layanan\KesiapanQrisOnline;
use App\Domain\Penjualan\Model\PengaturanTokoOnline;
use Illuminate\Support\Facades\DB;

/**
 * D-338: menghidupkan atau mematikan "Bayar sekarang lewat QRIS" dari halaman meja, tanpa membuka pengaturan toko
 * online. Sakelarnya satu untuk seluruh usaha (toko online, pesan dari meja, dan kios), jadi pelakunya wajib berizin
 * mengelola toko online. Menghidupkan ditolak dengan alasan jelas selama QRIS belum siap (gerbang atau metode bayar
 * QRIS dinamis belum aktif), mematikan selalu boleh. Audit `toko-online.pengaturan`.
 */
final class AturQrisPesananOnline
{
    public function __construct(
        private readonly KesiapanQrisOnline $kesiapan,
        private readonly PencatatAudit $audit,
    ) {}

    public function Jalankan(bool $aktif, int $idPengguna): bool
    {
        $hambatan = $aktif ? $this->kesiapan->CariHambatan() : [];

        if ($hambatan !== []) {
            throw new PelanggaranAturanBisnis($hambatan[0]['Kode'], $hambatan[0]['Pesan']);
        }

        return DB::transaction(function () use ($aktif, $idPengguna): bool {
            $p = PengaturanTokoOnline::query()->lockForUpdate()->first() ?? new PengaturanTokoOnline;
            $lama = (bool) $p->QrisAktif;

            if ($lama !== $aktif) {
                $p->QrisAktif = $aktif;
                $p->save();
                $this->audit->Catat('toko-online.pengaturan', $p, ['QrisAktif' => $lama], ['QrisAktif' => $aktif], idPengguna: $idPengguna);
            }

            return $aktif;
        });
    }
}
