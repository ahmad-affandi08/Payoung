<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola\Pembelian;

use App\Domain\Bersama\Tabel\Data\DataPermintaanTabel;
use App\Domain\Pembelian\Aksi\AjukanPermintaanPembelian;
use App\Domain\Pembelian\Aksi\BatalkanPermintaanPembelian;
use App\Domain\Pembelian\Aksi\JadikanPesananPembelian;
use App\Domain\Pembelian\Aksi\PutuskanPermintaanPembelian;
use App\Domain\Pembelian\Aksi\SimpanPermintaanPembelian;
use App\Domain\Pembelian\Aksi\SimpanPesananPembelian;
use App\Domain\Pembelian\Enum\StatusPermintaanPembelian;
use App\Domain\Pembelian\Kueri\DaftarPemasok;
use App\Domain\Pembelian\Kueri\TampilanPermintaanPembelian;
use App\Domain\Pembelian\Model\PermintaanPembelian;
use App\Http\Permintaan\Kelola\Pembelian\AlasanPembelianPermintaan;
use App\Http\Permintaan\Kelola\Pembelian\JadikanPesananPembelianPermintaan;
use App\Http\Permintaan\Kelola\Pembelian\SimpanPermintaanPembelianPermintaan;
use App\Http\Respons\ResponsTabel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Permintaan pembelian (F-04 PR, `/kelola/pembelian/permintaan`): outlet meminta barang dibelikan; diajukan, disetujui
 * pemegang `pembelian.po.setujui` (bukan pembuatnya), lalu dijadikan draf PO oleh pemegang `pembelian.kelola`.
 * Permintaan di outlet di luar akses pelaku = 404.
 */
final class PermintaanPembelianKontroler extends DasarPembelianKontroler
{
    public function Daftar(Request $permintaan, TampilanPermintaanPembelian $tampilan): Response|JsonResponse
    {
        $tabel = DataPermintaanTabel::Dari($permintaan->query(), TampilanPermintaanPembelian::KOLOM_URUT, TampilanPermintaanPembelian::URUT_BAWAAN, TampilanPermintaanPembelian::KOLOM_SARING);

        return ResponsTabel::Kirim($permintaan, 'Kelola/Pembelian/Permintaan/Daftar', 'Permintaan', fn (): array => $tampilan->Daftar($tabel, $this->IdOutletBoleh()), fn (): array => [
            'OpsiStatus' => self::Opsi(StatusPermintaanPembelian::class),
            'Izin' => $this->AmbilIzinPembelian(),
        ]);
    }

    public function Buat(): Response
    {
        return $this->RenderForm('Buat', null);
    }

    public function Simpan(SimpanPermintaanPembelianPermintaan $permintaan, SimpanPermintaanPembelian $simpan): RedirectResponse
    {
        $gudang = $this->CariGudangBoleh((string) $permintaan->validated('UuidGudang'));
        $baru = $simpan->Jalankan($permintaan->AmbilData($gudang->id, $this->Pelaku()->Id));
        $hasil = to_route('kelola.pembelian.permintaan.detail', ['permintaan' => $baru->Uuid])->with('Kilat', "Draf {$baru->Nomor} disimpan. Ajukan bila sudah benar.");

        return $this->LanjutkanSetelahSimpan($permintaan, $hasil, fn () => $this->Ajukan($baru->Uuid, app(AjukanPermintaanPembelian::class)), 'kelola.pembelian.permintaan.detail', ['permintaan' => $baru->Uuid]);
    }

    public function Detail(string $permintaan, TampilanPermintaanPembelian $tampilan): Response
    {
        $dokumen = $this->CariDokumen(PermintaanPembelian::class, $permintaan);
        $izin = $this->AmbilIzinPembelian();
        $idPelaku = $this->Pelaku()->Id;
        $status = $dokumen->Status;

        return Inertia::render('Kelola/Pembelian/Permintaan/Detail', [
            ...$tampilan->Detail($dokumen),
            'Izin' => $izin,
            'OpsiPemasok' => $izin['Kelola'] && $status === StatusPermintaanPembelian::Disetujui ? app(DaftarPemasok::class)->AmbilPilihan() : [],
            'Tindakan' => [
                'Ubah' => $izin['Kelola'] && $status === StatusPermintaanPembelian::Draf,
                'Ajukan' => $izin['Kelola'] && $status === StatusPermintaanPembelian::Draf,
                'Putuskan' => $izin['Setujui'] && $status === StatusPermintaanPembelian::Diajukan && $dokumen->DibuatOleh !== $idPelaku && $dokumen->DiajukanOleh !== $idPelaku,
                'JadikanPo' => $izin['Kelola'] && $status === StatusPermintaanPembelian::Disetujui,
                'Batalkan' => $izin['Kelola'] && $status->BisaBerubahKe(StatusPermintaanPembelian::Dibatalkan),
            ],
        ]);
    }

    public function Ubah(string $permintaan, TampilanPermintaanPembelian $tampilan): Response|RedirectResponse
    {
        $dokumen = $this->CariDokumen(PermintaanPembelian::class, $permintaan);

        if ($dokumen->Status !== StatusPermintaanPembelian::Draf) {
            return to_route('kelola.pembelian.permintaan.detail', ['permintaan' => $dokumen->Uuid])->withErrors(['Umum' => "Permintaan berstatus {$dokumen->Status->AmbilLabel()} tidak bisa diubah."]);
        }

        return $this->RenderForm('Ubah', $tampilan->Isian($dokumen));
    }

    public function Perbarui(SimpanPermintaanPembelianPermintaan $permintaan, string $permintaanUuid, SimpanPermintaanPembelian $simpan): RedirectResponse
    {
        $dokumen = $this->CariDokumen(PermintaanPembelian::class, $permintaanUuid);
        $gudang = $this->CariGudangBoleh((string) $permintaan->validated('UuidGudang'));
        $dokumen = $simpan->Jalankan($permintaan->AmbilData($gudang->id, $this->Pelaku()->Id), $dokumen);
        $hasil = to_route('kelola.pembelian.permintaan.detail', ['permintaan' => $dokumen->Uuid])->with('Kilat', "Draf {$dokumen->Nomor} disimpan.");

        return $this->LanjutkanSetelahSimpan($permintaan, $hasil, fn () => $this->Ajukan($dokumen->Uuid, app(AjukanPermintaanPembelian::class)), 'kelola.pembelian.permintaan.detail', ['permintaan' => $dokumen->Uuid]);
    }

    public function Ajukan(string $permintaan, AjukanPermintaanPembelian $ajukan): RedirectResponse
    {
        $dokumen = $ajukan->Jalankan($this->CariDokumen(PermintaanPembelian::class, $permintaan), $this->Pelaku()->Id);
        $pesan = $dokumen->Status === StatusPermintaanPembelian::Disetujui
            ? "{$dokumen->Nomor} disetujui. Jadikan pesanan pembelian bila sudah siap."
            : "{$dokumen->Nomor} menunggu persetujuan pemegang izin persetujuan pembelian.";

        return to_route('kelola.pembelian.permintaan.detail', ['permintaan' => $dokumen->Uuid])->with('Kilat', $pesan);
    }

    public function Setujui(string $permintaan, PutuskanPermintaanPembelian $putuskan): RedirectResponse
    {
        $dokumen = $putuskan->Jalankan($this->CariDokumen(PermintaanPembelian::class, $permintaan), $this->Pelaku()->Id);

        return to_route('kelola.pembelian.permintaan.detail', ['permintaan' => $dokumen->Uuid])->with('Kilat', "{$dokumen->Nomor} disetujui.");
    }

    public function Tolak(AlasanPembelianPermintaan $permintaan, string $permintaanUuid, PutuskanPermintaanPembelian $putuskan): RedirectResponse
    {
        $dokumen = $putuskan->Jalankan($this->CariDokumen(PermintaanPembelian::class, $permintaanUuid), $this->Pelaku()->Id, false, $permintaan->AmbilAlasan());

        return to_route('kelola.pembelian.permintaan.detail', ['permintaan' => $dokumen->Uuid])->with('Kilat', "{$dokumen->Nomor} ditolak dan kembali ke draf.");
    }

    public function Batalkan(AlasanPembelianPermintaan $permintaan, string $permintaanUuid, BatalkanPermintaanPembelian $batalkan): RedirectResponse
    {
        $dokumen = $batalkan->Jalankan($this->CariDokumen(PermintaanPembelian::class, $permintaanUuid), $this->Pelaku()->Id, $permintaan->AmbilAlasan());

        return to_route('kelola.pembelian.permintaan.detail', ['permintaan' => $dokumen->Uuid])->with('Kilat', "{$dokumen->Nomor} dibatalkan.");
    }

    public function JadikanPo(JadikanPesananPembelianPermintaan $permintaan, string $permintaanUuid, JadikanPesananPembelian $jadikan): RedirectResponse
    {
        $dokumen = $this->CariDokumen(PermintaanPembelian::class, $permintaanUuid);
        $po = $jadikan->Jalankan($dokumen, $this->Pelaku()->Id, $permintaan->AmbilUuidPemasok());

        return to_route('kelola.pembelian.pesanan.detail', ['pesanan' => $po->Uuid])->with('Kilat', "Draf {$po->Nomor} dibuat dari {$dokumen->Nomor}. Periksa harga lalu ajukan.");
    }

    /**
     * @param  array<string, mixed>|null  $permintaan
     */
    private function RenderForm(string $mode, ?array $permintaan): Response
    {
        return Inertia::render('Kelola/Pembelian/Permintaan/Form', [
            'Mode' => $mode,
            'Permintaan' => $permintaan,
            'OpsiPemasok' => app(DaftarPemasok::class)->AmbilPilihan(),
            'OpsiGudang' => $this->AmbilOpsiGudang(),
            'HariIni' => $this->HariIni(),
            'MaksimalBaris' => SimpanPesananPembelian::MAKS_BARIS,
        ]);
    }
}
