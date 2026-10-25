<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Pengelola\Tenant;

use App\Domain\Pengelola\Tenant\Aksi\AksiTenantMassal;
use App\Domain\Pengelola\Tenant\Aksi\PerpanjangTrial;
use App\Domain\Pengelola\Tenant\Enum\KategoriPenangguhan;
use App\Http\Kontroler\Kontroler;
use App\Http\Kontroler\Pengelola\PelakuPengelola;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Aksi massal di daftar tenant konsol (D-208). Izin & 2FA baru per aksi ditegakkan di rute, sama dengan aksi satuan;
 * kontroler hanya memvalidasi masukan dan merangkum hasil (yang berubah dan yang dilewati beserta alasannya).
 */
final class TenantMassalKontroler extends Kontroler
{
    use PelakuPengelola;

    public function PerpanjangTrial(Request $permintaan, AksiTenantMassal $massal): RedirectResponse
    {
        $valid = $permintaan->validate([
            ...$this->AturanUuid(),
            'Hari' => ['required', 'integer', 'min:1', 'max:'.PerpanjangTrial::MAKS_HARI],
            'Alasan' => ['required', 'string', 'min:10', 'max:500'],
        ], attributes: ['Uuid' => 'tenant terpilih']);

        return $this->Rangkum($massal->PerpanjangTrial($this->AmbilPelaku(), $this->Uuid($valid), (int) $valid['Hari'], trim((string) $valid['Alasan'])), 'trialnya diperpanjang');
    }

    public function Tangguhkan(Request $permintaan, AksiTenantMassal $massal): RedirectResponse
    {
        $valid = $permintaan->validate([
            ...$this->AturanUuid(),
            'Kategori' => ['required', 'string', Rule::enum(KategoriPenangguhan::class)],
            'Catatan' => ['required', 'string', 'min:10', 'max:450'],
        ], attributes: ['Uuid' => 'tenant terpilih']);

        return $this->Rangkum($massal->Tangguhkan($this->AmbilPelaku(), $this->Uuid($valid), KategoriPenangguhan::from((string) $valid['Kategori']), trim((string) $valid['Catatan'])), 'ditangguhkan');
    }

    public function Aktifkan(Request $permintaan, AksiTenantMassal $massal): RedirectResponse
    {
        $valid = $permintaan->validate([
            ...$this->AturanUuid(),
            'Alasan' => ['required', 'string', 'min:10', 'max:500'],
        ], attributes: ['Uuid' => 'tenant terpilih']);

        return $this->Rangkum($massal->Aktifkan($this->AmbilPelaku(), $this->Uuid($valid), trim((string) $valid['Alasan'])), 'diaktifkan kembali');
    }

    public function TulisCatatan(Request $permintaan, AksiTenantMassal $massal): RedirectResponse
    {
        $valid = $permintaan->validate([
            ...$this->AturanUuid(),
            'Isi' => ['required', 'string', 'min:3', 'max:2000'],
        ], attributes: ['Uuid' => 'tenant terpilih', 'Isi' => 'catatan']);

        return $this->Rangkum($massal->TulisCatatan($this->AmbilPelaku(), $this->Uuid($valid), trim((string) $valid['Isi'])), 'diberi catatan');
    }

    /**
     * @return array<string, list<string>>
     */
    private function AturanUuid(): array
    {
        return [
            'Uuid' => ['required', 'array', 'min:1', 'max:'.AksiTenantMassal::MAKS],
            'Uuid.*' => ['required', 'string', 'size:26'],
        ];
    }

    /**
     * @param  array<string, mixed>  $valid
     * @return list<string>
     */
    private function Uuid(array $valid): array
    {
        /** @var list<string> $uuid */
        $uuid = array_values((array) $valid['Uuid']);

        return $uuid;
    }

    /**
     * @param  array{Diubah: int, Dilewati: array<string, int>}  $hasil
     */
    private function Rangkum(array $hasil, string $kata): RedirectResponse
    {
        $rincian = [];

        foreach ($hasil['Dilewati'] as $sebab => $jumlah) {
            $rincian[] = "{$jumlah} dilewati: {$sebab}";
        }

        $pesan = trim("{$hasil['Diubah']} tenant {$kata}. ".implode(' ', $rincian));

        return $hasil['Diubah'] === 0 ? back()->withErrors(['Umum' => "Tidak ada tenant yang berubah. {$pesan}"]) : back()->with('Kilat', $pesan);
    }
}
