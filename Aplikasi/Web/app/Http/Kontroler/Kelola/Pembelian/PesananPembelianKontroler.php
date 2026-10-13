<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola\Pembelian;

use App\Domain\Bersama\Tabel\Data\DataPermintaanTabel;
use App\Domain\Organisasi\Kueri\TanggalBisnisOutlet;
use App\Domain\Pembelian\Aksi\AjukanPesananPembelian;
use App\Domain\Pembelian\Aksi\AntrekanPengirimanPesananPembelian;
use App\Domain\Pembelian\Aksi\BatalkanPesananPembelian;
use App\Domain\Pembelian\Aksi\BuatDrafPoOtomatis;
use App\Domain\Pembelian\Aksi\SetujuiPesananPembelian;
use App\Domain\Pembelian\Aksi\SimpanPesananPembelian;
use App\Domain\Pembelian\Enum\StatusPesananPembelian;
use App\Domain\Pembelian\Kueri\DaftarDokumenPembelian;
use App\Domain\Pembelian\Kueri\DaftarPemasok;
use App\Domain\Pembelian\Kueri\DetailPembelian;
use App\Domain\Pembelian\Kueri\IsianFormPembelian;
use App\Domain\Pembelian\Model\PesananPembelian;
use App\Domain\Tenant\Data\DataPengaturanPembelian;
use App\Domain\Tenant\Kueri\PengaturanPembelianTenant;
use App\Domain\Tenant\Kueri\ProfilTenant;
use App\Http\Permintaan\Kelola\Pembelian\AlasanPembelianPermintaan;
use App\Http\Permintaan\Kelola\Pembelian\SimpanPesananPembelianPermintaan;
use App\Http\Respons\ResponsTabel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pesanan pembelian (F-04 fase 1, `/kelola/pembelian/pesanan`): daftar, form draf, detail, ajukan, setujui/tolak
 * (`pembelian.po.setujui`), batalkan, tutup, dan tampilan cetak. PO di outlet di luar akses pelaku = 404.
 */
final class PesananPembelianKontroler extends DasarPembelianKontroler
{
    public function Daftar(Request $permintaan, DaftarDokumenPembelian $daftar, DaftarPemasok $pemasok): Response|JsonResponse
    {
        $tabel = DataPermintaanTabel::Dari($permintaan->query(), DaftarDokumenPembelian::KOLOM_URUT, DaftarDokumenPembelian::URUT_BAWAAN, DaftarDokumenPembelian::KOLOM_SARING);

        return ResponsTabel::Kirim($permintaan, 'Kelola/Pembelian/Pesanan/Daftar', 'Pesanan', fn (): array => $daftar->Pesanan($tabel, $this->IdOutletBoleh()), fn (): array => [
            'OpsiStatus' => self::Opsi(StatusPesananPembelian::class),
            'OpsiPemasok' => $pemasok->AmbilPilihan(),
            'Izin' => $this->AmbilIzinPembelian(),
        ]);
    }

    public function Buat(): Response
    {
        return $this->RenderForm('Buat', null);
    }

    public function Simpan(SimpanPesananPembelianPermintaan $permintaan, SimpanPesananPembelian $simpan): RedirectResponse
    {
        $gudang = $this->CariGudangBoleh((string) $permintaan->validated('UuidGudang'));
        $po = $simpan->Jalankan($permintaan->AmbilData($gudang->id, $this->Pelaku()->Id));
        $hasil = to_route('kelola.pembelian.pesanan.detail', ['pesanan' => $po->Uuid])->with('Kilat', "Draf {$po->Nomor} disimpan. Ajukan PO bila sudah benar.");

        return $this->LanjutkanSetelahSimpan($permintaan, $hasil, fn () => $this->Ajukan($po->Uuid, app(AjukanPesananPembelian::class)), 'kelola.pembelian.pesanan.detail', ['pesanan' => $po->Uuid]);
    }

    /** D-23 D: draf PO sekarang juga (tanpa menunggu jadwal pagi) untuk stok di bawah minimum di outlet pelaku. */
    public function BuatDrafOtomatis(BuatDrafPoOtomatis $buat, TanggalBisnisOutlet $tanggal, PengaturanPembelianTenant $pengaturan): RedirectResponse
    {
        $atur = $pengaturan->Ambil();
        $hasil = $buat->Jalankan($this->Pelaku()->Id, $tanggal->Hitung(null), $this->IdOutletBoleh(), $atur->sumberDrafPo, $atur->hariCakupanDrafPo);
        $pesan = $hasil['JumlahPo'] === 0
            ? ($atur->sumberDrafPo === DataPengaturanPembelian::SUMBER_DRAF_PERKIRAAN
                ? 'Tidak ada draf baru: stok cukup untuk perkiraan pemakaian atau sudah ada pesanan yang belum diterima.'
                : 'Tidak ada draf baru: stok di atas minimum atau sudah ada pesanan yang belum diterima.')
            : "{$hasil['JumlahPo']} draf PO ({$hasil['JumlahBaris']} barang) disiapkan. Periksa lalu ajukan.";

        if ($hasil['TanpaPemasok'] !== []) {
            $pesan .= ' Belum pernah dibeli, jadi belum ada pemasoknya: '.implode(', ', array_slice($hasil['TanpaPemasok'], 0, 5)).(count($hasil['TanpaPemasok']) > 5 ? ', dan lainnya.' : '.');
        }

        return to_route('kelola.pembelian.pesanan.daftar')->with('Kilat', $pesan);
    }

    public function Detail(string $pesanan, DetailPembelian $detail): Response
    {
        $po = $this->CariDokumen(PesananPembelian::class, $pesanan);
        $izin = $this->AmbilIzinPembelian();
        $idPelaku = $this->Pelaku()->Id;

        return Inertia::render('Kelola/Pembelian/Pesanan/Detail', [
            ...$detail->Pesanan($po),
            'Izin' => $izin,
            'Tindakan' => [
                'Ubah' => $izin['Kelola'] && $po->Status === StatusPesananPembelian::Draf,
                'Ajukan' => $izin['Kelola'] && $po->Status === StatusPesananPembelian::Draf,
                'Setujui' => $izin['Setujui'] && $po->Status === StatusPesananPembelian::MenungguPersetujuan && $po->DibuatOleh !== $idPelaku,
                'Terima' => $izin['Kelola'] && $po->Status->CekBolehDiterima(),
                'Batalkan' => $izin['Kelola'] && in_array($po->Status, [StatusPesananPembelian::Draf, StatusPesananPembelian::MenungguPersetujuan, StatusPesananPembelian::Disetujui], true),
                'KirimWhatsapp' => $izin['Kelola'] && in_array($po->Status, [StatusPesananPembelian::Disetujui, StatusPesananPembelian::DiterimaSebagian, StatusPesananPembelian::Diterima], true),
                'Tutup' => $izin['Kelola'] && in_array($po->Status, [StatusPesananPembelian::DiterimaSebagian, StatusPesananPembelian::Diterima], true),
            ],
        ]);
    }

    public function Ubah(string $pesanan): Response|RedirectResponse
    {
        $po = $this->CariDokumen(PesananPembelian::class, $pesanan);

        if ($po->Status !== StatusPesananPembelian::Draf) {
            return to_route('kelola.pembelian.pesanan.detail', ['pesanan' => $po->Uuid])->withErrors(['Umum' => "Pesanan berstatus {$po->Status->AmbilLabel()} tidak bisa diubah."]);
        }

        return $this->RenderForm('Ubah', app(IsianFormPembelian::class)->Pesanan($po));
    }

    public function Perbarui(SimpanPesananPembelianPermintaan $permintaan, string $pesanan, SimpanPesananPembelian $simpan): RedirectResponse
    {
        $po = $this->CariDokumen(PesananPembelian::class, $pesanan);
        $gudang = $this->CariGudangBoleh((string) $permintaan->validated('UuidGudang'));
        $po = $simpan->Jalankan($permintaan->AmbilData($gudang->id, $this->Pelaku()->Id), $po);
        $hasil = to_route('kelola.pembelian.pesanan.detail', ['pesanan' => $po->Uuid])->with('Kilat', "Draf {$po->Nomor} disimpan.");

        return $this->LanjutkanSetelahSimpan($permintaan, $hasil, fn () => $this->Ajukan($po->Uuid, app(AjukanPesananPembelian::class)), 'kelola.pembelian.pesanan.detail', ['pesanan' => $po->Uuid]);
    }

    public function Ajukan(string $pesanan, AjukanPesananPembelian $ajukan): RedirectResponse
    {
        $po = $ajukan->Jalankan($this->CariDokumen(PesananPembelian::class, $pesanan), $this->Pelaku()->Id);
        $pesan = $po->Status === StatusPesananPembelian::Disetujui
            ? "{$po->Nomor} disetujui. Barang bisa diterima."
            : "{$po->Nomor} menunggu persetujuan pemegang izin persetujuan PO.";

        return to_route('kelola.pembelian.pesanan.detail', ['pesanan' => $po->Uuid])->with('Kilat', $pesan);
    }

    /**
     * Kirim PO yang sudah disetujui ke pemasok lewat WhatsApp (F-e): diantrekan, hasilnya tampil di riwayat pengiriman.
     */
    public function KirimWhatsapp(string $pesanan, AntrekanPengirimanPesananPembelian $antrekan): RedirectResponse
    {
        $po = $this->CariDokumen(PesananPembelian::class, $pesanan);
        $antrekan->Jalankan($this->IdTenant(), $po, $this->Pelaku()->Id);

        return to_route('kelola.pembelian.pesanan.detail', ['pesanan' => $po->Uuid])->with('Kilat', "{$po->Nomor} sedang dikirim ke WhatsApp {$po->Pemasok->Nama}.");
    }

    public function Setujui(string $pesanan, SetujuiPesananPembelian $setujui): RedirectResponse
    {
        $po = $setujui->Jalankan($this->CariDokumen(PesananPembelian::class, $pesanan), $this->Pelaku()->Id);

        return to_route('kelola.pembelian.pesanan.detail', ['pesanan' => $po->Uuid])->with('Kilat', "{$po->Nomor} disetujui.");
    }

    public function Tolak(AlasanPembelianPermintaan $permintaan, string $pesanan, SetujuiPesananPembelian $setujui): RedirectResponse
    {
        $po = $setujui->Jalankan($this->CariDokumen(PesananPembelian::class, $pesanan), $this->Pelaku()->Id, false, $permintaan->AmbilAlasan());

        return to_route('kelola.pembelian.pesanan.detail', ['pesanan' => $po->Uuid])->with('Kilat', "{$po->Nomor} ditolak dan kembali ke draf.");
    }

    public function Batalkan(AlasanPembelianPermintaan $permintaan, string $pesanan, BatalkanPesananPembelian $batalkan): RedirectResponse
    {
        $po = $batalkan->Jalankan($this->CariDokumen(PesananPembelian::class, $pesanan), $this->Pelaku()->Id, false, $permintaan->AmbilAlasan());

        return to_route('kelola.pembelian.pesanan.detail', ['pesanan' => $po->Uuid])->with('Kilat', "{$po->Nomor} dibatalkan.");
    }

    public function Tutup(string $pesanan, BatalkanPesananPembelian $batalkan): RedirectResponse
    {
        $po = $batalkan->Jalankan($this->CariDokumen(PesananPembelian::class, $pesanan), $this->Pelaku()->Id, true);

        return to_route('kelola.pembelian.pesanan.detail', ['pesanan' => $po->Uuid])->with('Kilat', "{$po->Nomor} ditutup; sisa pesanan tidak ditunggu lagi.");
    }

    public function Cetak(string $pesanan, DetailPembelian $detail, ProfilTenant $profil): Response
    {
        $po = $this->CariDokumen(PesananPembelian::class, $pesanan);

        return Inertia::render('Kelola/Pembelian/Pesanan/Cetak', [
            ...$detail->Pesanan($po),
            'Usaha' => array_intersect_key($profil->Ambil($this->IdTenant()), array_flip(['Nama', 'Npwp'])),
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $pesanan
     */
    private function RenderForm(string $mode, ?array $pesanan): Response
    {
        return Inertia::render('Kelola/Pembelian/Pesanan/Form', [
            'Mode' => $mode,
            'Pesanan' => $pesanan,
            'OpsiPemasok' => app(DaftarPemasok::class)->AmbilPilihan(),
            'OpsiGudang' => $this->AmbilOpsiGudang(),
            'HariIni' => $this->HariIni(),
            'BatasPersetujuanPo' => app(PengaturanPembelianTenant::class)->Ambil()->batasPersetujuanPo->KeString(),
            'MaksimalBaris' => SimpanPesananPembelian::MAKS_BARIS,
        ]);
    }
}
