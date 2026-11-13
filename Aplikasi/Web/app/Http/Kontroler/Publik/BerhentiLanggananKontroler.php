<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Publik;

use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Integrasi\Whatsapp\KetersediaanWhatsapp;
use App\Domain\Organisasi\Kueri\NamaTampilUsaha;
use App\Domain\Pelanggan\Aksi\HentikanPemasaranPelanggan;
use App\Domain\Pelanggan\Layanan\TautanBerhentiLangganan;
use App\Domain\Pelanggan\Model\Pelanggan;
use App\Domain\Tenant\Kueri\ProfilTenant;
use App\Http\Kontroler\Kontroler;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * CRM-07 berhenti menerima pesan promosi (UU PDP): `/berhenti-langganan/{kode}` dari tautan bertanda tangan di pesan
 * kampanye. GET hanya menampilkan konfirmasi (pratinjau tautan di WhatsApp/klien email tidak mengubah apa pun); POST
 * (tanda tangan yang sama) menjalankan `HentikanPemasaranPelanggan`. Tautan rusak/tak dikenal → 404 tanpa membedakan
 * alasannya. Nama pelanggan tidak ditampilkan (tautan bisa diteruskan ke orang lain).
 */
final class BerhentiLanggananKontroler extends Kontroler
{
    public function Tampilkan(Request $permintaan, string $kode, KonteksTenant $konteks, ProfilTenant $profil): SymfonyResponse
    {
        return $this->DenganPelanggan($permintaan, $kode, $konteks, $profil, fn (Pelanggan $p, string $toko): SymfonyResponse => Inertia::render('Publik/BerhentiLangganan', [
            'Ditemukan' => true,
            'WhatsappTersedia' => $this->WhatsappTersedia($p),
            'NamaToko' => $toko,
            'SudahBerhenti' => ! $p->SetujuPemasaran,
            'AlamatKirim' => $permintaan->fullUrl(),
        ])->toResponse($permintaan));
    }

    public function Kirim(Request $permintaan, string $kode, KonteksTenant $konteks, ProfilTenant $profil, HentikanPemasaranPelanggan $hentikan): SymfonyResponse
    {
        return $this->DenganPelanggan($permintaan, $kode, $konteks, $profil, function (Pelanggan $p, string $toko) use ($hentikan, $permintaan): SymfonyResponse {
            $hentikan->Jalankan($p);

            return Inertia::render('Publik/BerhentiLangganan', [
                'Ditemukan' => true,
                'WhatsappTersedia' => $this->WhatsappTersedia($p),
                'NamaToko' => $toko,
                'SudahBerhenti' => true,
                'AlamatKirim' => null,
            ])->toResponse($permintaan);
        });
    }

    /**
     * @param  Closure(Pelanggan, string): SymfonyResponse  $kerja
     */
    private function DenganPelanggan(Request $permintaan, string $kode, KonteksTenant $konteks, ProfilTenant $profil, Closure $kerja): SymfonyResponse
    {
        $urai = TautanBerhentiLangganan::Urai($kode);

        if ($urai === null || ! $permintaan->hasValidSignature() || ! $profil->CekAda($urai['IdTenant'])) {
            return $this->TidakDitemukan($permintaan);
        }

        $konteks->Atur($urai['IdTenant']);

        try {
            $pelanggan = Pelanggan::query()->where('Uuid', $urai['Uuid'])->first();

            return $pelanggan === null ? $this->TidakDitemukan($permintaan) : $kerja($pelanggan, app(NamaTampilUsaha::class)->UntukTenant($urai['IdTenant']));
        } finally {
            $konteks->Kosongkan();
        }
    }

    /**
     * D-299: halaman berhenti berlangganan tetap berfungsi tanpa WhatsApp (hak menarik persetujuan, UU PDP), tetapi
     * kalimatnya tidak menyebut WhatsApp bila layanan itu tidak tersedia. Konteks tenant sudah dipasang pemanggil.
     */
    private function WhatsappTersedia(Pelanggan $pelanggan): bool
    {
        return app(KetersediaanWhatsapp::class)->TersediaUntuk($pelanggan->IdTenant);
    }

    private function TidakDitemukan(Request $permintaan): SymfonyResponse
    {
        return Inertia::render('Publik/BerhentiLangganan', ['Ditemukan' => false, 'WhatsappTersedia' => false, 'NamaToko' => null, 'SudahBerhenti' => false, 'AlamatKirim' => null])
            ->toResponse($permintaan)
            ->setStatusCode(404);
    }
}
