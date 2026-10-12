<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola\Akuntansi;

use App\Domain\Akuntansi\Enum\JenisSumberJurnal;
use App\Domain\Akuntansi\Kueri\DaftarJurnal;
use App\Domain\Akuntansi\Kueri\DetailJurnal;
use App\Domain\Bersama\Tabel\Data\DataPermintaanTabel;
use App\Http\Respons\ResponsTabel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Halaman jurnal (baca saja) `laporan.keuangan.lihat` (DesainF05a D, H-13); tombol "Catat jurnal umum" (FIN-05) hanya
 * bagi pemegang `akuntansi.kelola`. Jurnal tenant lain atau yang memuat baris di luar outlet akses pengguna = 404.
 */
final class JurnalKontroler extends DasarAkuntansiKontroler
{
    public function __construct(
        private readonly DaftarJurnal $daftar,
        private readonly DetailJurnal $detail,
    ) {}

    public function Daftar(Request $permintaan): Response|JsonResponse
    {
        $tabel = DataPermintaanTabel::Dari($permintaan->query(), DaftarJurnal::KOLOM_URUT, DaftarJurnal::URUT_BAWAAN, DaftarJurnal::KOLOM_SARING);

        return ResponsTabel::Kirim($permintaan, 'Kelola/Akuntansi/Jurnal/Daftar', 'Jurnal', fn (): array => $this->daftar->AmbilTabel($tabel, $this->IdOutletBoleh()), fn (): array => [
            'OpsiJenisSumber' => array_map(
                fn (JenisSumberJurnal $j): array => ['Nilai' => $j->value, 'Label' => $j->AmbilLabel()],
                JenisSumberJurnal::cases(),
            ),
            'Izin' => ['Kelola' => $this->CekIzinKelola()],
        ]);
    }

    public function Detail(string $jurnal): Response
    {
        $props = $this->detail->Ambil($jurnal, $this->IdOutletBoleh());
        abort_if($props === null, 404);

        return Inertia::render('Kelola/Akuntansi/Jurnal/Detail', $props);
    }
}
