<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola\Grosir;

use App\Domain\Bersama\Tabel\Data\DataPermintaanTabel;
use App\Domain\Organisasi\Kueri\PetaUuidOutlet;
use App\Domain\Penjualan\Aksi\BatalkanPesananGrosir;
use App\Domain\Penjualan\Aksi\KirimPesananGrosir;
use App\Domain\Penjualan\Aksi\KonfirmasiPesananGrosir;
use App\Domain\Penjualan\Aksi\SimpanPesananGrosir;
use App\Domain\Penjualan\Enum\StatusPesananGrosir;
use App\Domain\Penjualan\Kueri\DaftarDokumenGrosir;
use App\Domain\Penjualan\Kueri\DetailGrosir;
use App\Domain\Penjualan\Kueri\DokumenCetakGrosir;
use App\Domain\Penjualan\Kueri\IsianFormGrosir;
use App\Domain\Penjualan\Model\Armada;
use App\Domain\Penjualan\Model\PesananGrosir;
use App\Domain\Tenant\Kueri\ProfilTenant;
use App\Http\Permintaan\Kelola\Grosir\AlasanGrosirPermintaan;
use App\Http\Permintaan\Kelola\Grosir\KirimPesananGrosirPermintaan;
use App\Http\Permintaan\Kelola\Grosir\KonfirmasiPesananGrosirPermintaan;
use App\Http\Permintaan\Kelola\Grosir\SimpanPesananGrosirPermintaan;
use App\Http\Respons\ResponsTabel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pesanan grosir (F-12, §9.7, `/kelola/grosir/pesanan`): daftar, form draf, detail, konfirmasi (BR-12.6 limit kredit),
 * kirim (surat jalan, BR-12.2), dan pembatalan. SO di outlet di luar akses pelaku = 404.
 *
 * Harga tidak ada di formulirnya: server mengambilnya dari price engine saat draf disimpan, lalu operator memeriksanya
 * di halaman draf sebelum mengonfirmasi.
 */
final class PesananGrosirKontroler extends DasarGrosirKontroler
{
    public function Daftar(Request $permintaan, DaftarDokumenGrosir $daftar): Response|JsonResponse
    {
        $tabel = DataPermintaanTabel::Dari($permintaan->query(), DaftarDokumenGrosir::KOLOM_URUT, DaftarDokumenGrosir::URUT_BAWAAN, DaftarDokumenGrosir::KOLOM_SARING);

        return ResponsTabel::Kirim($permintaan, 'Kelola/Grosir/Pesanan/Daftar', 'Pesanan', fn (): array => $daftar->Pesanan($tabel, $this->IdOutletBoleh()), fn (): array => [
            'OpsiStatus' => self::Opsi(StatusPesananGrosir::class),
            'Izin' => $this->AmbilIzinGrosir(),
        ]);
    }

    public function Buat(): Response
    {
        return $this->RenderForm(null);
    }

    public function Simpan(SimpanPesananGrosirPermintaan $permintaan, SimpanPesananGrosir $simpan): RedirectResponse
    {
        $outlet = $this->CariOutlet((string) $permintaan->validated('UuidOutlet'));
        $pesanan = $simpan->Jalankan($permintaan->AmbilData($outlet->Id), $this->Pelaku()->Id);

        return to_route('kelola.grosir.pesanan.detail', ['pesanan' => $pesanan->Uuid])
            ->with('Kilat', "Draf {$pesanan->Nomor} disimpan. Periksa harga & totalnya, lalu konfirmasi.");
    }

    public function Detail(string $pesanan, DetailGrosir $detail): Response
    {
        $dokumen = $this->CariDokumen(PesananGrosir::class, $pesanan);
        $izin = $this->AmbilIzinGrosir();

        return Inertia::render('Kelola/Grosir/Pesanan/Detail', [
            ...$detail->Pesanan($dokumen),
            'Izin' => $izin,
            'OpsiGudang' => $this->AmbilOpsiGudang(),
            // D-225: memilih armada di dialog kirim mengisi nama pengemudi & nomor kendaraan.
            'OpsiArmada' => array_values(Armada::query()->where('Aktif', true)->orderBy('Nama')->get()->map(fn (Armada $a): array => ['Uuid' => $a->Uuid, 'Nama' => $a->Nama, 'NomorKendaraan' => $a->NomorKendaraan, 'NamaPengemudi' => $a->NamaPengemudi])->all()),
            'HariIni' => $this->HariIni(),
            'Tindakan' => [
                'Ubah' => $izin['Kelola'] && $dokumen->Status->CekBolehDiubah(),
                'Konfirmasi' => $izin['Kelola'] && $dokumen->Status === StatusPesananGrosir::Draf,
                'Kirim' => $izin['Kelola'] && $dokumen->Status->CekBolehDikirim(),
                'Batalkan' => $izin['Kelola'] && in_array($dokumen->Status, [StatusPesananGrosir::Draf, StatusPesananGrosir::Dikonfirmasi], true),
            ],
        ]);
    }

    public function Ubah(string $pesanan, IsianFormGrosir $isian): Response|RedirectResponse
    {
        $dokumen = $this->CariDokumen(PesananGrosir::class, $pesanan);

        if (! $dokumen->Status->CekBolehDiubah()) {
            return to_route('kelola.grosir.pesanan.detail', ['pesanan' => $dokumen->Uuid])
                ->withErrors(['Umum' => "Pesanan berstatus {$dokumen->Status->AmbilLabel()} tidak bisa diubah."]);
        }

        return $this->RenderForm($isian->Pesanan($dokumen));
    }

    public function Perbarui(SimpanPesananGrosirPermintaan $permintaan, string $pesanan, SimpanPesananGrosir $simpan): RedirectResponse
    {
        $dokumen = $this->CariDokumen(PesananGrosir::class, $pesanan);
        $outlet = $this->CariOutlet((string) $permintaan->validated('UuidOutlet'));
        $simpan->Jalankan($permintaan->AmbilData($outlet->Id), $this->Pelaku()->Id, $dokumen->Uuid);

        return to_route('kelola.grosir.pesanan.detail', ['pesanan' => $dokumen->Uuid])->with('Kilat', "Draf {$dokumen->Nomor} disimpan.");
    }

    /** BR-12.6: paparan kredit diperiksa di Aksi; izin `grosir.setujui-kredit` yang boleh menembusnya. */
    public function Konfirmasi(KonfirmasiPesananGrosirPermintaan $permintaan, string $pesanan, KonfirmasiPesananGrosir $konfirmasi): RedirectResponse
    {
        $dokumen = $this->CariDokumen(PesananGrosir::class, $pesanan);
        $hasil = $konfirmasi->Jalankan(
            $dokumen->Uuid,
            $this->Pelaku()->Id,
            $this->AmbilIzinGrosir()['SetujuiKredit'],
            $permintaan->AmbilAlasan(),
        );

        return to_route('kelola.grosir.pesanan.detail', ['pesanan' => $hasil->Uuid])
            ->with('Kilat', "{$hasil->Nomor} dikonfirmasi. Barangnya sudah bisa dikirim lewat surat jalan.");
    }

    /** BR-12.2: surat jalan = penyerahan barang, dan di situlah HPP, pendapatan, & PPN diakui (J-12.1). */
    public function Kirim(KirimPesananGrosirPermintaan $permintaan, string $pesanan, KirimPesananGrosir $kirim): RedirectResponse
    {
        $dokumen = $this->CariDokumen(PesananGrosir::class, $pesanan);
        $gudang = $this->CariGudangBoleh((string) $permintaan->validated('UuidGudang'));
        $suratJalan = $kirim->Jalankan($permintaan->AmbilData($dokumen->Uuid, $gudang->id), $this->Pelaku()->Id);

        return to_route('kelola.grosir.surat-jalan.detail', ['suratJalan' => $suratJalan->Uuid])
            ->with('Kilat', "Surat jalan {$suratJalan->Nomor} diposting: stok keluar dan penjualannya diakui.");
    }

    /**
     * Cetak A4 daftar ambil barang: sisa yang belum dikirim, tanpa harga (`DokumenCetakGrosir`). Dokumen tersendiri,
     * bukan status draf surat jalan — §9.7 sudah memutuskan surat jalan tidak punya draf.
     */
    public function CetakAmbil(string $pesanan, DokumenCetakGrosir $cetak, ProfilTenant $profil): Response
    {
        return Inertia::render('Kelola/Grosir/Pesanan/CetakAmbil', [
            ...$cetak->DaftarAmbil($this->CariDokumen(PesananGrosir::class, $pesanan)),
            'Usaha' => $this->Usaha($profil),
        ]);
    }

    public function Batalkan(AlasanGrosirPermintaan $permintaan, string $pesanan, BatalkanPesananGrosir $batalkan): RedirectResponse
    {
        $dokumen = $this->CariDokumen(PesananGrosir::class, $pesanan);
        $hasil = $batalkan->Jalankan($dokumen->Uuid, $permintaan->AmbilAlasan(), $this->Pelaku()->Id);

        return to_route('kelola.grosir.pesanan.detail', ['pesanan' => $hasil->Uuid])->with('Kilat', "{$hasil->Nomor} dibatalkan.");
    }

    /**
     * @param  array<string, mixed>|null  $isian
     */
    private function RenderForm(?array $isian): Response
    {
        return Inertia::render('Kelola/Grosir/Pesanan/Form', [
            'Isian' => $isian,
            'OpsiOutlet' => $this->AmbilOpsiOutlet(),
            'OpsiGudang' => $this->AmbilOpsiGudang(),
            'HariIni' => $this->HariIni(),
            'Izin' => $this->AmbilIzinGrosir(),
        ]);
    }

    /**
     * Outlet aktif yang boleh diakses pelaku, dengan `Kode` (label dropdown "Nama (KODE)"; tanpa kode label jadi "Nama (undefined)").
     *
     * @return list<array{Id: int, Uuid: string, Nama: string, Kode: string}>
     */
    private function AmbilOpsiOutlet(): array
    {
        $peta = app(PetaUuidOutlet::class);
        $ringkas = $peta->AmbilRingkas($this->IdOutletBoleh(), true);
        $identitas = $peta->AmbilIdentitas(array_column($ringkas, 'Id'));

        return array_map(fn (array $o): array => [...$o, 'Kode' => $identitas[$o['Id']]['Kode'] ?? ''], $ringkas);
    }
}
