<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola\Pelanggan;

use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Integrasi\Aksi\AlokasikanKreditBroadcast;
use App\Domain\Integrasi\Aksi\MintaKodeAlokasiKredit;
use App\Domain\Integrasi\Aksi\MulaiTopUpSaldoWhatsapp;
use App\Domain\Organisasi\Kueri\PetaUuidOutlet;
use App\Http\Kontroler\Kelola\DasarKelolaKontroler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * D-300: saldo WhatsApp (`/kelola/pelanggan/whatsapp/saldo/*`, izin `integrasi.api.kelola`): isi saldo lewat gerbang
 * billing, alokasi kredit broadcast per outlet dengan OTP, dan tarik kredit outlet kembali ke saldo utama.
 */
final class SaldoWhatsappKontroler extends DasarKelolaKontroler
{
    public function TopUp(Request $permintaan, MulaiTopUpSaldoWhatsapp $mulai): Response
    {
        $data = $permintaan->validate(['Jumlah' => ['required', 'numeric', 'min:1', 'max:100000000']], [], ['Jumlah' => 'jumlah']);
        $pelaku = $this->Pelaku();
        $url = $mulai->Jalankan(
            Uang::Dari((string) $data['Jumlah']),
            $pelaku->Id,
            $pelaku->Nama,
            (string) $pelaku->Email,
            route('kelola.pelanggan.whatsapp', ['tab' => 'saldo']),
        );

        return Inertia::location($url);
    }

    /** Minta kode OTP; jawaban JSON supaya dialog tetap terbuka. */
    public function MintaKode(Request $permintaan, MintaKodeAlokasiKredit $minta, PetaUuidOutlet $peta): JsonResponse
    {
        [$idOutlet, $jumlah] = $this->BacaAlokasi($permintaan, $peta);
        $tujuan = $minta->Jalankan($this->Pelaku(), $idOutlet, $jumlah);

        return response()->json(['Tujuan' => $tujuan]);
    }

    public function Alokasikan(Request $permintaan, AlokasikanKreditBroadcast $alokasi, PetaUuidOutlet $peta): RedirectResponse
    {
        [$idOutlet, $jumlah] = $this->BacaAlokasi($permintaan, $peta);
        $kode = $permintaan->validate(['Kode' => ['required', 'string', 'max:10']], [], ['Kode' => 'kode'])['Kode'];
        $alokasi->Jalankan($this->Pelaku(), $idOutlet, $jumlah, $kode);

        return to_route('kelola.pelanggan.whatsapp', ['tab' => 'saldo'])->with('Kilat', 'Kredit broadcast outlet bertambah '.$jumlah->FormatRupiah().'.');
    }

    public function Tarik(Request $permintaan, AlokasikanKreditBroadcast $alokasi, PetaUuidOutlet $peta): RedirectResponse
    {
        $uuid = $permintaan->validate(['UuidOutlet' => ['required', 'string', 'size:26']])['UuidOutlet'];
        $idOutlet = $peta->AmbilIdDariUuid([$uuid])[$uuid] ?? abort(404);
        $sisa = $alokasi->Tarik($this->Pelaku(), $idOutlet);

        return to_route('kelola.pelanggan.whatsapp', ['tab' => 'saldo'])->with('Kilat', 'Kredit outlet ditarik kembali ke saldo utama ('.$sisa->FormatRupiah().').');
    }

    /** @return array{0: int, 1: Uang} */
    private function BacaAlokasi(Request $permintaan, PetaUuidOutlet $peta): array
    {
        $data = $permintaan->validate([
            'UuidOutlet' => ['required', 'string', 'size:26'],
            'Jumlah' => ['required', 'numeric', 'min:1', 'max:100000000'],
        ], [], ['UuidOutlet' => 'outlet', 'Jumlah' => 'jumlah']);
        $idOutlet = $peta->AmbilIdDariUuid([$data['UuidOutlet']])[$data['UuidOutlet']] ?? abort(404);

        return [$idOutlet, Uang::Dari((string) $data['Jumlah'])];
    }
}
