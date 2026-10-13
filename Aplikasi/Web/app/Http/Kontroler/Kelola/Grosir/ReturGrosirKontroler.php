<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola\Grosir;

use App\Domain\Akuntansi\Kueri\DaftarAkunPilihan;
use App\Domain\Bersama\Dokumen\Enum\StatusDokumenTerposting;
use App\Domain\Bersama\Tabel\Data\DataPermintaanTabel;
use App\Domain\Penjualan\Aksi\BatalkanReturGrosir;
use App\Domain\Penjualan\Aksi\BuatReturGrosir;
use App\Domain\Penjualan\Enum\KondisiBarangRetur;
use App\Domain\Penjualan\Kueri\DaftarDokumenGrosir;
use App\Domain\Penjualan\Kueri\DetailGrosir;
use App\Domain\Penjualan\Kueri\DokumenCetakGrosir;
use App\Domain\Penjualan\Model\ReturGrosir;
use App\Domain\Penjualan\Model\SuratJalan;
use App\Domain\Tenant\Kueri\ProfilTenant;
use App\Http\Permintaan\Kelola\Grosir\AlasanGrosirPermintaan;
use App\Http\Permintaan\Kelola\Grosir\BuatReturGrosirPermintaan;
use App\Http\Respons\ResponsTabel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Retur grosir & nota kredit (F-12, §9.7, BR-12.7, `/kelola/grosir/retur`): daftar, form retur atas satu surat jalan,
 * detail, dan pembatalan. Returnya dibuat dari surat jalannya, karena yang dikembalikan selalu barang dari satu
 * penyerahan tertentu — itulah dokumen yang punya harga, HPP, dan tarif pajak untuk dibalik.
 */
final class ReturGrosirKontroler extends DasarGrosirKontroler
{
    public function Daftar(Request $permintaan, DaftarDokumenGrosir $daftar): Response|JsonResponse
    {
        $tabel = DataPermintaanTabel::Dari($permintaan->query(), DaftarDokumenGrosir::KOLOM_URUT, DaftarDokumenGrosir::URUT_BAWAAN, DaftarDokumenGrosir::KOLOM_SARING);

        return ResponsTabel::Kirim($permintaan, 'Kelola/Grosir/Retur/Daftar', 'Retur', fn (): array => $daftar->Retur($tabel, $this->IdOutletBoleh()), fn (): array => [
            'OpsiStatus' => self::Opsi(StatusDokumenTerposting::class),
            'Izin' => $this->AmbilIzinGrosir(),
        ]);
    }

    /** Form retur atas satu surat jalan; sisa yang masih bisa diretur per baris datang dari server. */
    public function Buat(string $suratJalan, DetailGrosir $detail, DaftarAkunPilihan $akun): Response|RedirectResponse
    {
        $dokumen = $this->CariDokumen(SuratJalan::class, $suratJalan);

        if ($dokumen->Status !== StatusDokumenTerposting::Diposting) {
            return to_route('kelola.grosir.surat-jalan.detail', ['suratJalan' => $dokumen->Uuid])
                ->withErrors(['Umum' => 'Surat jalan yang sudah dibatalkan tidak punya penyerahan untuk diretur.']);
        }

        return Inertia::render('Kelola/Grosir/Retur/Buat', [
            ...$detail->SuratJalan($dokumen),
            'OpsiKondisi' => self::Opsi(KondisiBarangRetur::class),
            'OpsiAkunRefund' => array_map(fn (array $a): array => ['Uuid' => $a['Uuid'], 'Kode' => $a['Kode'], 'Nama' => $a['Nama']], $akun->AmbilKasBank()),
            'HariIni' => $this->HariIni(),
            'Izin' => $this->AmbilIzinGrosir(),
        ]);
    }

    public function Simpan(BuatReturGrosirPermintaan $permintaan, BuatReturGrosir $buat): RedirectResponse
    {
        // Batas outlet tetap ditegakkan: surat jalan di luar akses pelaku = 404 sebelum aksinya jalan.
        $this->CariDokumen(SuratJalan::class, (string) $permintaan->validated('UuidSuratJalan'));
        $retur = $buat->Jalankan($permintaan->AmbilData(), $this->Pelaku()->Id);

        return to_route('kelola.grosir.retur.detail', ['retur' => $retur->Uuid])
            ->with('Kilat', "Retur {$retur->Nomor} diposting: stok kembali dan pengakuan penjualannya dibalik.");
    }

    public function Detail(string $retur, DetailGrosir $detail): Response
    {
        $dokumen = $this->CariDokumen(ReturGrosir::class, $retur);
        $izin = $this->AmbilIzinGrosir();

        return Inertia::render('Kelola/Grosir/Retur/Detail', [
            ...$detail->Retur($dokumen),
            'Izin' => $izin,
            'Tindakan' => ['Batalkan' => $izin['Kelola'] && $dokumen->Status === StatusDokumenTerposting::Diposting],
        ]);
    }

    /** Cetak A4 nota kredit untuk pembeli. */
    public function Cetak(string $retur, DokumenCetakGrosir $cetak, ProfilTenant $profil): Response
    {
        return Inertia::render('Kelola/Grosir/Retur/Cetak', [
            ...$cetak->Retur($this->CariDokumen(ReturGrosir::class, $retur)),
            'Usaha' => $this->Usaha($profil),
        ]);
    }

    public function Batalkan(AlasanGrosirPermintaan $permintaan, string $retur, BatalkanReturGrosir $batalkan): RedirectResponse
    {
        $dokumen = $this->CariDokumen(ReturGrosir::class, $retur);
        $hasil = $batalkan->Jalankan($dokumen->Uuid, $permintaan->AmbilAlasan(), $this->Pelaku()->Id);

        return to_route('kelola.grosir.retur.detail', ['retur' => $hasil->Uuid])
            ->with('Kilat', "{$hasil->Nomor} dibatalkan: barang keluar kembali dan nota kreditnya dicabut.");
    }
}
