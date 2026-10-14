<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola\Penjualan;

use App\Domain\Bersama\Laporan\JenisKolom;
use App\Domain\Bersama\Laporan\KolomLaporan;
use App\Domain\Bersama\Tabel\Data\DataPermintaanTabel;
use App\Domain\Organisasi\Enum\IzinTenant;
use App\Domain\Organisasi\Kueri\AksesPengguna;
use App\Domain\Organisasi\Kueri\PetaUuidOutlet;
use App\Domain\Penjualan\Enum\KanalPenjualan;
use App\Domain\Penjualan\Enum\StatusPenjualan;
use App\Domain\Penjualan\Kueri\DaftarPenjualan;
use App\Domain\Penjualan\Kueri\DaftarVoidRetur;
use App\Domain\Penjualan\Kueri\DetailPenjualan;
use App\Domain\Penjualan\Kueri\DetailReturPenjualan;
use App\Http\Kontroler\Kelola\DasarKelolaKontroler;
use App\Http\Respons\ResponsTabel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Halaman penjualan back-office (baca saja, F-07b), izin `laporan.penjualan.lihat`: daftar & detail penjualan, serta
 * (F-09) daftar Void & Retur dan detail retur. Dokumen tenant lain atau di outlet di luar akses pelaku = 404.
 */
final class PenjualanKontroler extends DasarKelolaKontroler
{
    public function Daftar(Request $permintaan, DaftarPenjualan $daftar, PetaUuidOutlet $outlet): Response|JsonResponse
    {
        $tabel = DataPermintaanTabel::Dari($permintaan->query(), DaftarPenjualan::KOLOM_URUT, DaftarPenjualan::URUT_BAWAAN, DaftarPenjualan::KOLOM_SARING);

        return ResponsTabel::Kirim(
            $permintaan,
            'Kelola/Penjualan/Daftar',
            'Penjualan',
            fn (): array => $daftar->AmbilTabel($tabel, $this->IdOutletBoleh(), $this->IdTenant()),
            fn (): array => [
                'OpsiOutlet' => array_map(fn (array $o): array => ['Uuid' => $o['Uuid'], 'Nama' => $o['Nama']], $outlet->AmbilRingkas($this->IdOutletBoleh())),
                'OpsiStatus' => array_map(fn (StatusPenjualan $s): array => ['Nilai' => $s->value, 'Label' => $s->AmbilLabel()], StatusPenjualan::cases()),
                'OpsiKanal' => array_map(fn (KanalPenjualan $k): array => ['Nilai' => $k->value, 'Label' => $k->AmbilLabel()], KanalPenjualan::cases()),
            ],
        );
    }

    /**
     * Ekspor daftar penjualan (Excel/CSV/cetak lewat `?format=`) sesuai saringan & cari yang aktif, maks. 5.000 baris.
     * Dengan `uuid=a,b,c` (aksi massal "Ekspor terpilih") hanya penjualan itu yang diekspor.
     */
    public function Ekspor(Request $permintaan, DaftarPenjualan $daftar): SymfonyResponse
    {
        $tabel = DataPermintaanTabel::Dari($permintaan->query(), DaftarPenjualan::KOLOM_URUT, DaftarPenjualan::URUT_BAWAAN, DaftarPenjualan::KOLOM_SARING);
        $baris = $this->AmbilSemuaBarisTabel($tabel, fn (DataPermintaanTabel $t): array => $daftar->AmbilTabel($t, $this->IdOutletBoleh(), $this->IdTenant()));
        $terpilih = array_values(array_filter(explode(',', (string) $permintaan->query('uuid')), fn (string $u): bool => preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/i', $u) === 1));

        if ($terpilih !== []) {
            $baris = array_values(array_filter($baris, fn (array $b): bool => in_array($b['Uuid'], $terpilih, true)));
        }

        $kolom = [
            new KolomLaporan('Nomor', JenisKolom::Teks, 26), new KolomLaporan('Waktu', JenisKolom::TanggalWaktu),
            new KolomLaporan('Tanggal bisnis', JenisKolom::Tanggal), new KolomLaporan('Outlet', JenisKolom::Teks, 24),
            new KolomLaporan('Kasir', JenisKolom::Teks, 22), new KolomLaporan('Kanal', JenisKolom::Teks, 16),
            new KolomLaporan('Metode bayar', JenisKolom::Teks, 26), new KolomLaporan('Status', JenisKolom::Teks, 18),
            new KolomLaporan('Total', JenisKolom::Uang, jumlahkan: true),
        ];
        $isi = array_map(fn (array $b): array => [
            $b['Nomor'], $b['DibuatOfflinePada'], $b['TanggalBisnis'], $b['NamaOutlet'], $b['NamaKasir'], $b['LabelKanal'], implode(', ', $b['Metode']), $b['LabelStatus'], $b['TotalAkhir'],
        ], $baris);
        $saringan = $terpilih === [] ? [] : [['Cakupan', count($baris).' penjualan terpilih']];

        return $this->SajikanLaporan($permintaan, 'Daftar Penjualan', 'daftar-penjualan', $kolom, $isi, $saringan);
    }

    public function Detail(string $penjualan, DetailPenjualan $detail): Response
    {
        $props = $detail->Ambil($penjualan, $this->IdOutletBoleh());
        abort_if($props === null, 404);

        // TAX-07: menerbitkan/membatalkan Faktur Pajak butuh izin akuntansi (termasuk pajak); halaman hanya menampilkan tombolnya.
        $props['Izin'] = ['KelolaFakturPajak' => app(AksesPengguna::class)->CekIzin($this->IdTenant(), $this->Pelaku()->Id, IzinTenant::AkuntansiKelola)];

        return Inertia::render('Kelola/Penjualan/Detail', $props);
    }

    public function VoidRetur(Request $permintaan, DaftarVoidRetur $daftar, PetaUuidOutlet $outlet): Response|JsonResponse
    {
        $tabel = DataPermintaanTabel::Dari($permintaan->query(), DaftarVoidRetur::KOLOM_URUT, DaftarVoidRetur::URUT_BAWAAN, DaftarVoidRetur::KOLOM_SARING);

        return ResponsTabel::Kirim(
            $permintaan,
            'Kelola/Penjualan/VoidRetur',
            'VoidRetur',
            fn (): array => $daftar->AmbilTabel($tabel, $this->IdOutletBoleh(), $this->IdTenant()),
            fn (): array => [
                'OpsiOutlet' => array_map(fn (array $o): array => ['Uuid' => $o['Uuid'], 'Nama' => $o['Nama']], $outlet->AmbilRingkas($this->IdOutletBoleh())),
            ],
        );
    }

    public function DetailRetur(string $retur, DetailReturPenjualan $detail): Response
    {
        $props = $detail->Ambil($retur, $this->IdOutletBoleh());
        abort_if($props === null, 404);

        return Inertia::render('Kelola/Penjualan/Retur', $props);
    }
}
