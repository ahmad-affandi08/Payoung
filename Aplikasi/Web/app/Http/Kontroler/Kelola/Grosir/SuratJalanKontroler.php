<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola\Grosir;

use App\Domain\Bersama\Dokumen\Enum\StatusDokumenTerposting;
use App\Domain\Bersama\Tabel\Data\DataPermintaanTabel;
use App\Domain\Penjualan\Aksi\BatalkanSuratJalan;
use App\Domain\Penjualan\Aksi\CatatPengirimanSuratJalan;
use App\Domain\Penjualan\Enum\StatusPengirimanGrosir;
use App\Domain\Penjualan\Kueri\DaftarDokumenGrosir;
use App\Domain\Penjualan\Kueri\DetailGrosir;
use App\Domain\Penjualan\Kueri\DokumenCetakGrosir;
use App\Domain\Penjualan\Model\Armada;
use App\Domain\Penjualan\Model\SuratJalan;
use App\Domain\Tenant\Kueri\ProfilTenant;
use App\Http\Permintaan\Kelola\Grosir\AlasanGrosirPermintaan;
use App\Http\Permintaan\Kelola\Grosir\CatatPengirimanPermintaan;
use App\Http\Respons\ResponsTabel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Surat jalan grosir (F-12, §9.7, `/kelola/grosir/surat-jalan`): daftar (termasuk saringan **Belum difakturkan** yang
 * dituju butir Kotak Tindakan BR-12.4), detail, cetak, dan pembatalan (J-12.3). Surat jalannya dibuat dari halaman SO,
 * karena yang diserahkan selalu barang milik satu pesanan.
 */
final class SuratJalanKontroler extends DasarGrosirKontroler
{
    public function Daftar(Request $permintaan, DaftarDokumenGrosir $daftar): Response|JsonResponse
    {
        $tabel = DataPermintaanTabel::Dari($permintaan->query(), DaftarDokumenGrosir::KOLOM_URUT, DaftarDokumenGrosir::URUT_BAWAAN, DaftarDokumenGrosir::KOLOM_SARING);

        return ResponsTabel::Kirim($permintaan, 'Kelola/Grosir/SuratJalan/Daftar', 'SuratJalan', fn (): array => $daftar->SuratJalan($tabel, $this->IdOutletBoleh()), fn (): array => [
            'OpsiStatus' => self::Opsi(StatusDokumenTerposting::class),
            'OpsiStatusPengiriman' => self::Opsi(StatusPengirimanGrosir::class),
            'HariIni' => $this->HariIni(),
            'Izin' => $this->AmbilIzinGrosir(),
        ]);
    }

    public function Detail(string $suratJalan, DetailGrosir $detail): Response
    {
        $dokumen = $this->CariDokumen(SuratJalan::class, $suratJalan);
        $izin = $this->AmbilIzinGrosir();

        return Inertia::render('Kelola/Grosir/SuratJalan/Detail', [
            ...$detail->SuratJalan($dokumen),
            'Izin' => $izin,
            'Tindakan' => [
                'Batalkan' => $izin['Kelola'] && $dokumen->Status === StatusDokumenTerposting::Diposting && $dokumen->IdFakturPenjualan === null
                    && $dokumen->StatusPengiriman !== StatusPengirimanGrosir::Diterima,
                'Pengiriman' => $izin['Kelola'] && $dokumen->Status === StatusDokumenTerposting::Diposting,
            ],
            'OpsiArmada' => array_values(Armada::query()->where('Aktif', true)->orderBy('Nama')->get()->map(fn (Armada $a): array => ['Uuid' => $a->Uuid, 'Nama' => $a->Nama, 'NomorKendaraan' => $a->NomorKendaraan, 'NamaPengemudi' => $a->NamaPengemudi])->all()),
        ]);
    }

    /** Cetak A4 (surat jalan yang dibawa sopir); tanpa harga, lihat `DokumenCetakGrosir`. */
    public function Cetak(string $suratJalan, DokumenCetakGrosir $cetak, ProfilTenant $profil): Response
    {
        return Inertia::render('Kelola/Grosir/SuratJalan/Cetak', [
            ...$cetak->SuratJalan($this->CariDokumen(SuratJalan::class, $suratJalan)),
            'Usaha' => $this->Usaha($profil),
        ]);
    }

    /** Kabar pengiriman: berangkat, diterima, gagal, atau batal berangkat (`langkah`). */
    public function Pengiriman(CatatPengirimanPermintaan $permintaan, string $suratJalan, string $langkah, CatatPengirimanSuratJalan $catat): RedirectResponse
    {
        abort_unless(array_key_exists($langkah, CatatPengirimanPermintaan::LANGKAH), 404);
        $dokumen = $this->CariDokumen(SuratJalan::class, $suratJalan);
        $hasil = $catat->Jalankan($permintaan->AmbilData($dokumen->Uuid, $langkah), $this->Pelaku()->Id);

        return to_route('kelola.grosir.surat-jalan.detail', ['suratJalan' => $hasil->Uuid])
            ->with('Kilat', "Pengiriman {$hasil->Nomor}: {$hasil->StatusPengiriman->AmbilLabel()}.");
    }

    public function Batalkan(AlasanGrosirPermintaan $permintaan, string $suratJalan, BatalkanSuratJalan $batalkan): RedirectResponse
    {
        $dokumen = $this->CariDokumen(SuratJalan::class, $suratJalan);
        $hasil = $batalkan->Jalankan($dokumen->Uuid, $permintaan->AmbilAlasan(), $this->Pelaku()->Id);

        return to_route('kelola.grosir.surat-jalan.detail', ['suratJalan' => $hasil->Uuid])
            ->with('Kilat', "{$hasil->Nomor} dibatalkan: stok kembali dan pengakuan penjualannya dibalik.");
    }
}
