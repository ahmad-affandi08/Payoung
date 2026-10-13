<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola\Akuntansi;

use App\Domain\Akuntansi\Enum\JenisSumberJurnal;
use App\Domain\Akuntansi\Kueri\DaftarJurnal;
use App\Domain\Akuntansi\Kueri\DetailJurnal;
use App\Domain\Bersama\Laporan\JenisKolom;
use App\Domain\Bersama\Laporan\KolomLaporan;
use App\Domain\Bersama\Tabel\Data\DataPermintaanTabel;
use App\Http\Respons\ResponsTabel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

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

    /**
     * Ekspor jurnal ke software akuntansi (FIN-12, F28): satu baris per baris jurnal, memakai cari & saringan tabel
     * yang sedang aktif; format Excel, CSV, atau cetak (D-43).
     */
    public function Ekspor(Request $permintaan): SymfonyResponse
    {
        $tabel = DataPermintaanTabel::Dari($permintaan->query(), DaftarJurnal::KOLOM_URUT, DaftarJurnal::URUT_BAWAAN, DaftarJurnal::KOLOM_SARING);
        $tanggal = $tabel->AmbilRentangTanggal('Tanggal');
        $saringan = [];

        if ($tanggal['Dari'] !== null || $tanggal['Sampai'] !== null) {
            $saringan[] = ['Periode', trim(($tanggal['Dari'] ?? '...').' s.d. '.($tanggal['Sampai'] ?? '...'))];
        }

        if ($tabel->cari !== '') {
            $saringan[] = ['Pencarian', $tabel->cari];
        }

        $uang = static fn (string $judul): KolomLaporan => new KolomLaporan($judul, JenisKolom::Uang, jumlahkan: true);

        return $this->SajikanLaporan(
            $permintaan,
            'Laporan Keuangan | Jurnal',
            'jurnal-'.now()->format('Ymd'),
            [
                new KolomLaporan('Tanggal', JenisKolom::Tanggal), new KolomLaporan('Nomor jurnal', JenisKolom::Teks, 20), new KolomLaporan('Sumber', JenisKolom::Teks, 20),
                new KolomLaporan('Nomor sumber', JenisKolom::Teks, 22), new KolomLaporan('Keterangan', JenisKolom::Teks, 38), new KolomLaporan('Kode akun', JenisKolom::Teks, 12),
                new KolomLaporan('Nama akun', JenisKolom::Teks, 32), new KolomLaporan('Outlet', JenisKolom::Teks, 22), new KolomLaporan('Memo', JenisKolom::Teks, 28),
                $uang('Debit'), $uang('Kredit'),
            ],
            // Closure: dibaca dua kali (ringkasan lalu penulisan), jadi tiap panggilan membuat generator baru.
            fn (): iterable => $this->daftar->AlirkanBarisEkspor($tabel, $this->IdOutletBoleh()),
            $saringan,
            null,
            $this->LabelCakupan(),
        );
    }

    public function Detail(string $jurnal): Response
    {
        $props = $this->detail->Ambil($jurnal, $this->IdOutletBoleh());
        abort_if($props === null, 404);

        return Inertia::render('Kelola/Akuntansi/Jurnal/Detail', $props);
    }
}
