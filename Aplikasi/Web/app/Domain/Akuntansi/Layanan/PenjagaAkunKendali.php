<?php

declare(strict_types=1);

namespace App\Domain\Akuntansi\Layanan;

use App\Domain\Akuntansi\Enum\PeranAkun;
use App\Domain\Akuntansi\Model\Akun;
use App\Domain\Akuntansi\Model\PemetaanAkun;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;

/**
 * Penjaga akun kendali untuk jurnal umum manual (FIN-05, D-131, aturan #9). Akun kendali = akun yang dipetakan (tingkat
 * tenant maupun override outlet) ke peran yang saldonya turunan buku pembantu (`PeranAkun::CekAkunKendali()`): persediaan,
 * piutang, hutang, deposit/uang muka, giro, aset tetap, dan sejenisnya. Menyentuhnya lewat jurnal bebas membuat neraca
 * berselisih dari buku pembantunya tanpa jejak, jadi ditolak dengan petunjuk dokumen yang benar.
 */
final class PenjagaAkunKendali
{
    /**
     * Id akun kendali tenant konteks dan nama perannya.
     *
     * @return array<int, string> Id akun => label peran
     */
    public function AmbilPeta(): array
    {
        $peta = [];

        foreach (PemetaanAkun::query()->get(['Kunci', 'IdAkun']) as $pemetaan) {
            $peran = PeranAkun::DariKunci($pemetaan->Kunci);

            if ($peran instanceof PeranAkun && $peran->CekAkunKendali()) {
                $peta[$pemetaan->IdAkun] = $peran->AmbilLabel();
            }
        }

        return $peta;
    }

    /**
     * @throws PelanggaranAturanBisnis AkunKendali pada bidang `$bidang`
     */
    public function Pastikan(Akun $akun, string $bidang): void
    {
        $peran = $this->AmbilPeta()[$akun->Id] ?? null;

        if ($peran !== null) {
            throw new PelanggaranAturanBisnis(
                'AkunKendali',
                "Akun {$akun->AmbilLabel()} adalah akun kendali ({$peran}). Saldonya mengikuti dokumen modulnya dan tidak bisa dijurnal manual. Ubah lewat dokumen yang sesuai (penyesuaian stok, hapus-buku piutang, pembayaran hutang, dsb.).",
                $bidang,
            );
        }
    }

    /**
     * Penjagaan banyak akun sekaligus tanpa membaca pemetaan berulang.
     *
     * @param  array<string, Akun>  $akunPerBidang  bidang => akun
     *
     * @throws PelanggaranAturanBisnis AkunKendali pada bidang akun kendali pertama
     */
    public function PastikanBanyak(array $akunPerBidang): void
    {
        $peta = $this->AmbilPeta();

        foreach ($akunPerBidang as $bidang => $akun) {
            if (isset($peta[$akun->Id])) {
                throw new PelanggaranAturanBisnis(
                    'AkunKendali',
                    "Akun {$akun->AmbilLabel()} adalah akun kendali ({$peta[$akun->Id]}). Saldonya mengikuti dokumen modulnya dan tidak bisa dijurnal manual. Ubah lewat dokumen yang sesuai (penyesuaian stok, hapus-buku piutang, pembayaran hutang, dsb.).",
                    $bidang,
                );
            }
        }
    }
}
