<?php

declare(strict_types=1);

namespace App\Console\Perintah;

use App\Domain\Pengelola\Tenant\Aksi\HitungSkorKesehatanTenant;
use Illuminate\Console\Command;

/** P-07 PGL-09: skor kesehatan tenant harian dan tugas otomatis tim (jadwal di routes/console.php). */
final class HitungSkorKesehatanTenantPerintah extends Command
{
    protected $signature = 'tenant:hitung-skor-kesehatan';

    protected $description = 'Menghitung skor kesehatan harian tenant dan memelihara tugas otomatis tim (P-07, PGL-09).';

    public function handle(HitungSkorKesehatanTenant $hitung): int
    {
        $hasil = $hitung->Jalankan();
        $this->info("{$hasil['Dinilai']} tenant dinilai, {$hasil['TugasDibuka']} tugas dibuka, {$hasil['TugasDitutup']} tugas ditutup.");

        return self::SUCCESS;
    }
}
