<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola\Pelanggan;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Integrasi\Aksi\HapusNomorPengirimWhatsapp;
use App\Domain\Integrasi\Aksi\HapusPenerimaLaporanWhatsapp;
use App\Domain\Integrasi\Aksi\MintaQrNomorPengirimWhatsapp;
use App\Domain\Integrasi\Aksi\SegarkanStatusNomorPengirimWhatsapp;
use App\Domain\Integrasi\Aksi\TambahNomorPengirimWhatsapp;
use App\Domain\Integrasi\Aksi\TambahPenerimaLaporanWhatsapp;
use App\Domain\Integrasi\Aksi\UbahNomorPengirimWhatsapp;
use App\Domain\Integrasi\Enum\FungsiNomorWhatsapp;
use App\Domain\Integrasi\Enum\JenisPesanWhatsapp;
use App\Domain\Integrasi\Kueri\HalamanPusatWhatsapp;
use App\Domain\Integrasi\Layanan\PengaturPesanWhatsapp;
use App\Domain\Integrasi\Model\NomorPengirimWhatsapp;
use App\Domain\Integrasi\Model\PenerimaLaporanWhatsapp;
use App\Domain\Organisasi\Kueri\PetaUuidOutlet;
use App\Http\Kontroler\Kelola\DasarKelolaKontroler;
use App\Http\Permintaan\Kelola\Pelanggan\SimpanNomorPengirimWhatsappPermintaan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * D-299: Pusat WhatsApp (`/kelola/pelanggan/whatsapp`, izin `pelanggan.kelola`). Tahap 1: nomor pengirim per outlet,
 * dipasang dengan memindai QR. Tenant tidak mengisi token atau nama templat apa pun.
 */
final class PusatWhatsappKontroler extends DasarKelolaKontroler
{
    public function Tampilkan(HalamanPusatWhatsapp $kueri): Response
    {
        return Inertia::render('Kelola/Pelanggan/PusatWhatsapp', $kueri->Ambil());
    }

    public function Tambah(SimpanNomorPengirimWhatsappPermintaan $permintaan, TambahNomorPengirimWhatsapp $tambah, PetaUuidOutlet $peta): RedirectResponse
    {
        $tambah->Jalankan(
            $this->CariIdOutlet($permintaan->input('UuidOutlet'), $peta),
            $permintaan->string('Label')->toString(),
            FungsiNomorWhatsapp::from($permintaan->string('Fungsi')->toString()),
            $permintaan->string('NomorTelepon')->toString(),
        );

        return to_route('kelola.pelanggan.whatsapp')->with('Kilat', 'Nomor ditambahkan. Pindai QR dengan WhatsApp di ponsel untuk menyambungkannya.');
    }

    public function Ubah(string $uuidNomor, SimpanNomorPengirimWhatsappPermintaan $permintaan, UbahNomorPengirimWhatsapp $ubah): RedirectResponse
    {
        $ubah->Jalankan(
            $this->CariNomor($uuidNomor),
            $permintaan->string('Label')->toString(),
            FungsiNomorWhatsapp::from($permintaan->string('Fungsi')->toString()),
            $permintaan->boolean('Aktif'),
        );

        return to_route('kelola.pelanggan.whatsapp')->with('Kilat', 'Nomor pengirim diperbarui.');
    }

    public function Hapus(string $uuidNomor, HapusNomorPengirimWhatsapp $hapus): RedirectResponse
    {
        $hapus->Jalankan($this->CariNomor($uuidNomor));

        return to_route('kelola.pelanggan.whatsapp')->with('Kilat', 'Nomor pengirim dihapus.');
    }

    /** Minta QR baru. Dipanggil berulang dari dialog pindai sampai nomor terhubung. */
    public function MintaQr(string $uuidNomor, MintaQrNomorPengirimWhatsapp $minta): JsonResponse
    {
        $hasil = $minta->Jalankan($this->CariNomor($uuidNomor));

        return response()->json(['Berhasil' => $hasil->berhasil, 'Terhubung' => $hasil->terhubung, 'KodeQr' => $hasil->kodeQr, 'Pesan' => $hasil->pesan]);
    }

    /** Periksa status sambungan (polling ringan saat dialog pindai terbuka). */
    public function Status(string $uuidNomor, SegarkanStatusNomorPengirimWhatsapp $segarkan): JsonResponse
    {
        $nomor = $segarkan->Jalankan($this->CariNomor($uuidNomor));

        return response()->json(['Status' => $nomor->Status->value, 'Terhubung' => $nomor->Status->value === 'Terhubung', 'Pesan' => $nomor->PesanStatus]);
    }

    /** Menyalakan atau mematikan satu jenis pesan WhatsApp otomatis. */
    public function UbahPesanOtomatis(string $jenis, Request $permintaan, PengaturPesanWhatsapp $pengatur): RedirectResponse
    {
        $jenisPesan = JenisPesanWhatsapp::tryFrom($jenis) ?? abort(404);
        $pengatur->Atur($jenisPesan, $permintaan->boolean('Aktif'), $this->IdTenant());

        return to_route('kelola.pelanggan.whatsapp')->with('Kilat', $jenisPesan->AmbilLabel().($permintaan->boolean('Aktif') ? ' dinyalakan.' : ' dimatikan.'));
    }

    public function TambahPenerima(Request $permintaan, TambahPenerimaLaporanWhatsapp $tambah, PetaUuidOutlet $peta): RedirectResponse
    {
        $data = $permintaan->validate([
            'UuidOutlet' => ['required', 'string', 'size:26'],
            'Nama' => ['required', 'string', 'min:2', 'max:60'],
            'NomorTelepon' => ['required', 'string', 'max:20'],
        ], [], ['UuidOutlet' => 'outlet', 'Nama' => 'nama', 'NomorTelepon' => 'nomor WhatsApp']);
        $idOutlet = $this->CariIdOutlet($data['UuidOutlet'], $peta)
            ?? throw new PelanggaranAturanBisnis('OutletTidakDikenal', 'Outlet tidak ditemukan.', 'UuidOutlet');
        $tambah->Jalankan($idOutlet, $data['Nama'], $data['NomorTelepon']);

        return to_route('kelola.pelanggan.whatsapp')->with('Kilat', 'Penerima laporan ditambahkan.');
    }

    public function HapusPenerima(string $uuidPenerima, HapusPenerimaLaporanWhatsapp $hapus): RedirectResponse
    {
        $hapus->Jalankan(PenerimaLaporanWhatsapp::query()->where('Uuid', $uuidPenerima)->firstOrFail());

        return to_route('kelola.pelanggan.whatsapp')->with('Kilat', 'Penerima laporan dihapus.');
    }

    private function CariNomor(string $uuid): NomorPengirimWhatsapp
    {
        return NomorPengirimWhatsapp::query()->where('Uuid', $uuid)->firstOrFail();
    }

    private function CariIdOutlet(mixed $uuid, PetaUuidOutlet $peta): ?int
    {
        if (! is_string($uuid) || $uuid === '') {
            return null;
        }

        return $peta->AmbilIdDariUuid([$uuid])[$uuid]
            ?? throw new PelanggaranAturanBisnis('OutletTidakDikenal', 'Outlet tidak ditemukan.', 'UuidOutlet');
    }
}
