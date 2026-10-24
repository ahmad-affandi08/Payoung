<?php

declare(strict_types=1);

namespace App\Domain\Bersama\Tindakan\Kueri;

use App\Domain\Bersama\Tindakan\Data\DataButirTindakan;
use App\Domain\Bersama\Tindakan\Data\DataKonteksTindakan;
use App\Domain\Bersama\Tindakan\Kontrak\PenyediaTindakan;
use App\Domain\Bersama\Tindakan\Kontrak\TerbatasSektor;
use Illuminate\Contracts\Container\Container;

/**
 * Kotak Tindakan (D-23 C): mengumpulkan butir dari semua penyedia domain (tag `PenyediaTindakan::TAG`), hanya yang
 * `jumlah` > 0, urut tingkat (Penting → Perhatian → Info) lalu jumlah terbanyak.
 */
final class KotakTindakan
{
    public function __construct(private readonly Container $wadah) {}

    /**
     * @return list<DataButirTindakan>
     */
    public function Ambil(DataKonteksTindakan $konteks): array
    {
        $butir = [];

        foreach ($this->AmbilPenyedia() as $penyedia) {
            // D-183: penyedia khusus sektor dilewati untuk tenant yang tidak memakai sektornya.
            if ($penyedia instanceof TerbatasSektor && ! $konteks->CekSesuaiSektor($penyedia->AmbilSektor(), $penyedia->AmbilKecualiSektor())) {
                continue;
            }

            foreach ($penyedia->Kumpulkan($konteks) as $b) {
                if ($b->jumlah > 0) {
                    $butir[] = $b;
                }
            }
        }

        usort($butir, fn (DataButirTindakan $a, DataButirTindakan $b): int => [$a->tingkat->AmbilUrutan(), -$a->jumlah, $a->kunci] <=> [$b->tingkat->AmbilUrutan(), -$b->jumlah, $b->kunci]);

        return $butir;
    }

    /** Penyedia yang menangani [jenisDokumen]; null bila tidak ada. */
    public function CariPenyedia(string $jenisDokumen): ?PenyediaTindakan
    {
        foreach ($this->AmbilPenyedia() as $penyedia) {
            if (in_array($jenisDokumen, $penyedia->AmbilJenisDokumen(), true)) {
                return $penyedia;
            }
        }

        return null;
    }

    /**
     * @return list<PenyediaTindakan>
     */
    private function AmbilPenyedia(): array
    {
        $hasil = [];

        foreach ($this->wadah->tagged(PenyediaTindakan::TAG) as $p) {
            if ($p instanceof PenyediaTindakan) {
                $hasil[] = $p;
            }
        }

        return $hasil;
    }
}
