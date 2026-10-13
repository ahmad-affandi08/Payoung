<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola;

use App\Domain\Organisasi\Aksi\TandaiNotifikasiDibaca;
use App\Domain\Organisasi\Aksi\UbahPengaturanNotifikasi;
use App\Domain\Organisasi\Enum\JenisNotifikasiPengguna;
use App\Domain\Organisasi\Kueri\PusatNotifikasiWeb;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pusat notifikasi back-office (F31, PLT-08): notifikasi milik pengguna yang sedang masuk di tenant aktif, tanda sudah
 * dibaca, dan jenis yang ingin dibisukan. Hanya data pribadi pelaku sendiri, jadi tanpa izin tenant khusus.
 */
final class NotifikasiKontroler extends DasarKelolaKontroler
{
    public function Daftar(Request $permintaan, PusatNotifikasiWeb $pusat): Response
    {
        $hanyaBelum = $permintaan->query('tampil') === 'belum-dibaca';

        return Inertia::render('Kelola/Notifikasi', [
            ...$pusat->Ambil($this->Pelaku()->Id, $hanyaBelum),
            'Saring' => ['Tampil' => $hanyaBelum ? 'belum-dibaca' : 'semua'],
        ]);
    }

    public function TandaiDibaca(Request $permintaan, TandaiNotifikasiDibaca $tandai): RedirectResponse
    {
        $data = $permintaan->validate([
            'Uuid' => ['nullable', 'array', 'max:100'],
            'Uuid.*' => ['string', 'ulid'],
            'Semua' => ['sometimes', 'boolean'],
        ]);
        $semua = (bool) ($data['Semua'] ?? false);
        $uuid = array_values(array_map('strval', (array) ($data['Uuid'] ?? [])));

        if (! $semua && $uuid === []) {
            return back();
        }

        $tandai->Jalankan($this->Pelaku()->Id, $uuid, $semua);

        return back();
    }

    public function UbahPengaturan(Request $permintaan, UbahPengaturanNotifikasi $ubah): RedirectResponse
    {
        $data = $permintaan->validate([
            'Dibisukan' => ['present', 'array', 'max:'.count(JenisNotifikasiPengguna::cases())],
            'Dibisukan.*' => ['string', Rule::enum(JenisNotifikasiPengguna::class)],
        ], attributes: ['Dibisukan' => 'jenis notifikasi']);
        $ubah->Jalankan($this->Pelaku()->Id, array_values(array_map('strval', (array) $data['Dibisukan'])));

        return back()->with('Kilat', 'Pengaturan notifikasi disimpan.');
    }
}
