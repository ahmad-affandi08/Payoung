<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola\Invoice;

use App\Domain\Bersama\Tabel\Data\DataPermintaanTabel;
use App\Domain\Organisasi\Enum\IzinTenant;
use App\Domain\Organisasi\Kueri\AksesPengguna;
use App\Domain\Organisasi\Kueri\PetaUuidOutlet;
use App\Domain\Organisasi\Kueri\TanggalBisnisOutlet;
use App\Domain\Pajak\Kueri\DaftarKelompokPajak;
use App\Domain\Penjualan\Aksi\SimpanInvoiceBerulang;
use App\Domain\Penjualan\Aksi\UbahStatusInvoiceBerulang;
use App\Domain\Penjualan\Enum\FrekuensiInvoiceBerulang;
use App\Domain\Penjualan\Enum\ModeInvoiceBerulang;
use App\Domain\Penjualan\Enum\StatusInvoiceBerulang;
use App\Domain\Penjualan\Kueri\DaftarInvoiceBerulang;
use App\Domain\Penjualan\Kueri\DetailInvoiceBerulang;
use App\Domain\Penjualan\Kueri\IsianFormInvoice;
use App\Domain\Penjualan\Model\InvoiceBerulang;
use App\Http\Kontroler\Kelola\DasarKelolaKontroler;
use App\Http\Permintaan\Kelola\Invoice\SimpanInvoiceBerulangPermintaan;
use App\Http\Respons\ResponsTabel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Invoice berulang (F-21, `/kelola/invoice/berulang`): daftar, form, detail, serta jeda, lanjutkan, dan hentikan templat.
 * Templat di outlet di luar akses pelaku = 404. Yang diterbitkan templat adalah invoice biasa (`InvoiceKontroler`).
 */
final class InvoiceBerulangKontroler extends DasarKelolaKontroler
{
    public function Daftar(Request $permintaan, DaftarInvoiceBerulang $daftar): Response|JsonResponse
    {
        $tabel = DataPermintaanTabel::Dari($permintaan->query(), DaftarInvoiceBerulang::KOLOM_URUT, DaftarInvoiceBerulang::URUT_BAWAAN, DaftarInvoiceBerulang::KOLOM_SARING);

        return ResponsTabel::Kirim($permintaan, 'Kelola/Invoice/Berulang/Daftar', 'Templat', fn (): array => $daftar->Ambil($tabel, $this->IdOutletBoleh()), fn (): array => [
            'OpsiStatus' => array_map(fn (StatusInvoiceBerulang $s): array => ['Nilai' => $s->value, 'Label' => $s->AmbilLabel()], StatusInvoiceBerulang::cases()),
            'Izin' => $this->AmbilIzin(),
        ]);
    }

    public function Buat(IsianFormInvoice $isian): Response
    {
        return $this->RenderForm(null, $isian);
    }

    public function Simpan(SimpanInvoiceBerulangPermintaan $permintaan, SimpanInvoiceBerulang $simpan): RedirectResponse
    {
        $outlet = $this->CariOutlet((string) $permintaan->validated('UuidOutlet'));
        $templat = $simpan->Jalankan($permintaan->AmbilData($outlet->Id), $this->Pelaku()->Id);

        return to_route('kelola.invoice.berulang.detail', ['templat' => $templat->Uuid])
            ->with('Kilat', "Templat \"{$templat->Nama}\" disimpan. Invoice pertama terbit pada {$templat->BerikutnyaPada?->format('d/m/Y')}.");
    }

    public function Detail(string $templat, DetailInvoiceBerulang $detail): Response
    {
        $model = $this->CariTemplat($templat);
        $izin = $this->AmbilIzin();

        return Inertia::render('Kelola/Invoice/Berulang/Detail', [
            ...$detail->Ambil($model),
            'Izin' => $izin,
            'Tindakan' => [
                'Ubah' => $izin['Kelola'] && $model->Status !== StatusInvoiceBerulang::Selesai,
                'Jeda' => $izin['Kelola'] && $model->Status === StatusInvoiceBerulang::Aktif,
                'Lanjut' => $izin['Kelola'] && $model->Status === StatusInvoiceBerulang::Dijeda,
                'Hentikan' => $izin['Kelola'] && $model->Status !== StatusInvoiceBerulang::Selesai,
            ],
        ]);
    }

    public function Ubah(string $templat, DetailInvoiceBerulang $detail, IsianFormInvoice $isian): Response|RedirectResponse
    {
        $model = $this->CariTemplat($templat);

        if ($model->Status === StatusInvoiceBerulang::Selesai) {
            return to_route('kelola.invoice.berulang.detail', ['templat' => $model->Uuid])
                ->withErrors(['Umum' => 'Templat yang sudah selesai tidak bisa diubah. Buat templat baru.']);
        }

        return $this->RenderForm($detail->Isian($model), $isian);
    }

    public function Perbarui(SimpanInvoiceBerulangPermintaan $permintaan, string $templat, SimpanInvoiceBerulang $simpan): RedirectResponse
    {
        $model = $this->CariTemplat($templat);
        $outlet = $this->CariOutlet((string) $permintaan->validated('UuidOutlet'));
        $simpan->Jalankan($permintaan->AmbilData($outlet->Id), $this->Pelaku()->Id, $model->Uuid);

        return to_route('kelola.invoice.berulang.detail', ['templat' => $model->Uuid])->with('Kilat', 'Templat disimpan. Perubahan berlaku mulai invoice berikutnya.');
    }

    public function Jeda(string $templat, UbahStatusInvoiceBerulang $ubah): RedirectResponse
    {
        return $this->UbahStatus($templat, StatusInvoiceBerulang::Dijeda, $ubah, 'Templat dijeda. Tidak ada invoice yang terbit sampai dilanjutkan.');
    }

    public function Lanjut(string $templat, UbahStatusInvoiceBerulang $ubah): RedirectResponse
    {
        return $this->UbahStatus($templat, StatusInvoiceBerulang::Aktif, $ubah, 'Templat dilanjutkan. Siklus yang terlewat selama jeda tidak diterbitkan.');
    }

    public function Hentikan(string $templat, UbahStatusInvoiceBerulang $ubah): RedirectResponse
    {
        return $this->UbahStatus($templat, StatusInvoiceBerulang::Selesai, $ubah, 'Templat dihentikan. Invoice yang sudah terbit tidak berubah.');
    }

    private function UbahStatus(string $uuid, StatusInvoiceBerulang $tujuan, UbahStatusInvoiceBerulang $ubah, string $pesan): RedirectResponse
    {
        $model = $this->CariTemplat($uuid);
        $ubah->Jalankan($model->Uuid, $tujuan, $this->Pelaku()->Id);

        return to_route('kelola.invoice.berulang.detail', ['templat' => $model->Uuid])->with('Kilat', $pesan);
    }

    private function CariTemplat(string $uuid): InvoiceBerulang
    {
        $model = InvoiceBerulang::query()->where('Uuid', $uuid)->firstOrFail();
        $boleh = $this->IdOutletBoleh();
        abort_if($boleh !== null && ! in_array($model->IdOutlet, $boleh, true), 404);

        return $model;
    }

    /**
     * @return array{Kelola: bool}
     */
    private function AmbilIzin(): array
    {
        return ['Kelola' => app(AksesPengguna::class)->CekIzin($this->IdTenant(), $this->Pelaku()->Id, IzinTenant::InvoiceKelola)];
    }

    /**
     * @param  array<string, mixed>|null  $isian
     */
    private function RenderForm(?array $isian, IsianFormInvoice $kueri): Response
    {
        $peta = app(PetaUuidOutlet::class);
        $ringkas = $peta->AmbilRingkas($this->IdOutletBoleh(), true);
        $identitas = $peta->AmbilIdentitas(array_column($ringkas, 'Id'));

        return Inertia::render('Kelola/Invoice/Berulang/Form', [
            'Isian' => $isian,
            'Kebiasaan' => $isian === null ? $kueri->Kebiasaan() : null,
            'OpsiOutlet' => array_map(fn (array $o): array => [...$o, 'Kode' => $identitas[$o['Id']]['Kode'] ?? ''], $ringkas),
            'OpsiKelompokPajak' => array_map(fn (array $k): array => ['Uuid' => $k['Uuid'], 'Nama' => $k['Nama']], app(DaftarKelompokPajak::class)->AmbilOpsi()),
            'OpsiFrekuensi' => array_map(fn (FrekuensiInvoiceBerulang $f): array => ['Nilai' => $f->value, 'Label' => $f->value], FrekuensiInvoiceBerulang::cases()),
            'OpsiMode' => array_map(fn (ModeInvoiceBerulang $m): array => ['Nilai' => $m->value, 'Label' => $m->AmbilLabel()], ModeInvoiceBerulang::cases()),
            'HariIni' => app(TanggalBisnisOutlet::class)->Hitung(null)->format('Y-m-d'),
        ]);
    }
}
