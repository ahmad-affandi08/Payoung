<?php

declare(strict_types=1);

namespace App\Domain\Akuntansi\Kueri;

use App\Domain\Akuntansi\Enum\PeranAkun;
use App\Domain\Akuntansi\Layanan\PenentuAkun;
use App\Domain\Akuntansi\Model\JurnalDetail;
use App\Domain\Bersama\Nilai\Uang;

/**
 * D-256: saldo akun penampung `KliringBelanjaLaci` = uang laci kasir yang sudah dicatat keluar untuk belanja stok tetapi
 * barangnya belum dicatat lewat Belanja stok. Debit (kas keluar kasir) − kredit (Belanja stok dari uang laci), dihitung
 * per outlet karena laci milik outlet. Saldo negatif tidak mungkin lewat jalur resmi, tetapi dilewati (dianggap nol)
 * supaya tampilan tidak menyesatkan bila ada jurnal koreksi.
 */
final class SaldoUangLaci
{
    public function __construct(private readonly PenentuAkun $penentu) {}

    /**
     * Saldo positif per outlet.
     *
     * @return array<int, Uang> Id outlet => saldo
     */
    public function PerOutlet(): array
    {
        $idAkun = $this->penentu->CariIdAkun(PeranAkun::KliringBelanjaLaci, null);

        if ($idAkun === null) {
            return [];
        }

        $hasil = [];

        foreach (JurnalDetail::query()
            ->where('IdAkun', $idAkun)
            ->whereNotNull('IdOutlet')
            ->selectRaw('`IdOutlet`, CAST(SUM(`Debit` - `Kredit`) AS DECIMAL(20,2)) AS `Saldo`')
            ->groupBy('IdOutlet')
            ->toBase()
            ->get() as $baris) {
            $saldo = Uang::Dari((string) $baris->Saldo);

            if (! $saldo->BernilaiNegatif() && ! $saldo->BernilaiNol()) {
                $hasil[(int) $baris->IdOutlet] = $saldo;
            }
        }

        return $hasil;
    }

    public function Outlet(?int $idOutlet): Uang
    {
        return $idOutlet === null ? Uang::Nol() : ($this->PerOutlet()[$idOutlet] ?? Uang::Nol());
    }

    public function Total(): Uang
    {
        $total = Uang::Nol();

        foreach ($this->PerOutlet() as $saldo) {
            $total = $total->Tambah($saldo);
        }

        return $total;
    }
}
