<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola;

use App\Domain\Integrasi\ApiPublik\Aksi\BuatTokenApi;
use App\Domain\Integrasi\ApiPublik\Aksi\CabutTokenApi;
use App\Domain\Integrasi\ApiPublik\Enum\CakupanApi;
use App\Domain\Integrasi\ApiPublik\Kueri\DaftarTokenApi;
use App\Domain\Integrasi\ApiPublik\Model\TokenApiTenant;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * X7 Open API v1 bagian 1: Pengaturan › Token API (`/kelola/pengaturan/api`, izin `integrasi.api.kelola` khusus Owner).
 * Token asli hanya ditampilkan sekali lewat flash `TokenApiBaru` setelah dibuat.
 */
final class TokenApiKontroler extends DasarKelolaKontroler
{
    private const KUNCI_TOKEN_BARU = 'TokenApiBaru';

    public function Daftar(Request $permintaan, DaftarTokenApi $daftar): Response
    {
        return Inertia::render('Kelola/Pengaturan/Api', [
            'Token' => $daftar->Ambil(),
            'OpsiCakupan' => DaftarTokenApi::AmbilOpsiCakupan(),
            'TokenBaru' => $permintaan->session()->get(self::KUNCI_TOKEN_BARU),
            'AlamatApi' => url('/api/v1'),
        ]);
    }

    public function Buat(Request $permintaan, BuatTokenApi $buat): RedirectResponse
    {
        $valid = $permintaan->validate([
            'Nama' => ['required', 'string', 'max:60'],
            'Cakupan' => ['required', 'array', 'min:1'],
            'Cakupan.*' => ['string', Rule::in(CakupanApi::AmbilSemuaNilai())],
            'KedaluwarsaPada' => ['nullable', 'date_format:Y-m-d', 'after:today'],
        ], attributes: ['Nama' => 'nama token', 'Cakupan' => 'akses', 'KedaluwarsaPada' => 'tanggal kedaluwarsa']);
        $kedaluwarsa = is_string($valid['KedaluwarsaPada'] ?? null)
            ? CarbonImmutable::createFromFormat('!Y-m-d', $valid['KedaluwarsaPada'], 'Asia/Jakarta')?->endOfDay()
            : null;
        $hasil = $buat->Jalankan($this->IdTenant(), $this->Pelaku()->Id, (string) $valid['Nama'], array_values(array_map('strval', (array) $valid['Cakupan'])), $kedaluwarsa ?: null);

        return redirect()->route('kelola.pengaturan.api')
            ->with(self::KUNCI_TOKEN_BARU, ['Nama' => $hasil['Model']->Nama, 'Token' => $hasil['Token']]);
    }

    public function Cabut(string $uuidToken, CabutTokenApi $cabut): RedirectResponse
    {
        $token = TokenApiTenant::query()->where('Uuid', $uuidToken)->firstOrFail();
        $cabut->Jalankan($token, $this->Pelaku()->Id);

        return back()->with('Kilat', "Token {$token->Nama} dicabut. Aplikasi yang memakainya langsung ditolak.");
    }
}
