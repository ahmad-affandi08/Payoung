<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola;

use App\Domain\Integrasi\ApiPublik\Aksi\BuatWebhook;
use App\Domain\Integrasi\ApiPublik\Aksi\HapusWebhook;
use App\Domain\Integrasi\ApiPublik\Aksi\KirimUlangKirimanWebhook;
use App\Domain\Integrasi\ApiPublik\Aksi\UbahStatusWebhook;
use App\Domain\Integrasi\ApiPublik\Enum\PeristiwaWebhook;
use App\Domain\Integrasi\ApiPublik\Kueri\DaftarWebhook;
use App\Domain\Integrasi\ApiPublik\Model\KirimanWebhook;
use App\Domain\Integrasi\ApiPublik\Model\WebhookTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * X7 bagian 2: Pengaturan › Webhook (`/kelola/pengaturan/webhook`, izin `integrasi.api.kelola` khusus Owner). Rahasia
 * penandatangan hanya ditampilkan sekali lewat flash `RahasiaWebhookBaru` setelah dibuat.
 */
final class WebhookKontroler extends DasarKelolaKontroler
{
    private const KUNCI_RAHASIA_BARU = 'RahasiaWebhookBaru';

    public function Daftar(Request $permintaan, DaftarWebhook $daftar): Response
    {
        return Inertia::render('Kelola/Pengaturan/Webhook', [
            'Webhook' => $daftar->Ambil(),
            'Kiriman' => $daftar->AmbilKiriman(),
            'OpsiPeristiwa' => DaftarWebhook::AmbilOpsiPeristiwa(),
            'RahasiaBaru' => $permintaan->session()->get(self::KUNCI_RAHASIA_BARU),
        ]);
    }

    public function Buat(Request $permintaan, BuatWebhook $buat): RedirectResponse
    {
        $valid = $permintaan->validate([
            'Nama' => ['required', 'string', 'max:60'],
            'Url' => ['required', 'string', 'max:500', 'url:https'],
            'Peristiwa' => ['required', 'array', 'min:1'],
            'Peristiwa.*' => ['string', Rule::in(PeristiwaWebhook::AmbilSemuaNilai())],
        ], attributes: ['Nama' => 'nama webhook', 'Url' => 'alamat URL', 'Peristiwa' => 'peristiwa']);
        $hasil = $buat->Jalankan($this->IdTenant(), $this->Pelaku()->Id, (string) $valid['Nama'], (string) $valid['Url'], array_values(array_map('strval', (array) $valid['Peristiwa'])));

        return redirect()->route('kelola.pengaturan.webhook')
            ->with(self::KUNCI_RAHASIA_BARU, ['Nama' => $hasil['Model']->Nama, 'Rahasia' => $hasil['Rahasia']]);
    }

    public function UbahStatus(Request $permintaan, string $uuidWebhook, UbahStatusWebhook $ubah): RedirectResponse
    {
        $valid = $permintaan->validate(['Aktif' => ['required', 'boolean']]);
        $webhook = WebhookTenant::query()->where('Uuid', $uuidWebhook)->firstOrFail();
        $ubah->Jalankan($webhook, (bool) $valid['Aktif']);

        return back()->with('Kilat', $webhook->Aktif ? "Webhook {$webhook->Nama} aktif." : "Webhook {$webhook->Nama} dinonaktifkan.");
    }

    public function Hapus(string $uuidWebhook, HapusWebhook $hapus): RedirectResponse
    {
        $webhook = WebhookTenant::query()->where('Uuid', $uuidWebhook)->firstOrFail();
        $hapus->Jalankan($webhook);

        return back()->with('Kilat', "Webhook {$webhook->Nama} dihapus.");
    }

    public function KirimUlang(string $uuidKiriman, KirimUlangKirimanWebhook $kirimUlang): RedirectResponse
    {
        $kiriman = KirimanWebhook::query()->where('Uuid', $uuidKiriman)->firstOrFail();
        $kirimUlang->Jalankan($kiriman);

        return back()->with('Kilat', 'Kiriman dijadwalkan ulang.');
    }
}
