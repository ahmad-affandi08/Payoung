<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola;

use App\Domain\Bersama\Tindakan\Aksi\TandaiDokumenDitinjau;
use App\Domain\Bersama\Tindakan\Aksi\UbahLanggananRingkasanTindakan;
use App\Domain\Bersama\Tindakan\Data\DataButirTindakan;
use App\Domain\Bersama\Tindakan\Kueri\KotakTindakan;
use App\Domain\Bersama\Tindakan\Kueri\LanggananRingkasan;
use App\Domain\Integrasi\Whatsapp\PesanWhatsapp;
use App\Domain\Organisasi\Enum\IzinTenant;
use App\Domain\Organisasi\Kueri\AksesPengguna;
use App\Domain\Organisasi\Kueri\KonteksTindakanPengguna;
use App\Domain\Organisasi\Kueri\RingkasanTindakanPengguna;
use App\Domain\Organisasi\Kueri\TanggalBisnisOutlet;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Kotak Tindakan back-office (D-23 C): semua yang perlu perhatian di satu halaman, disaring izin & outlet akses pelaku.
 * Menandai dokumen "sudah dicek": izin `tindakan.tinjau`. Tiap pengguna ber-email memilih sendiri menerima ringkasan
 * pagi lewat email (D-23 D bagian 4; Owner bawaan berlangganan).
 */
final class TindakanKontroler extends DasarKelolaKontroler
{
    public function Daftar(KotakTindakan $kotak, AksesPengguna $akses, KonteksTindakanPengguna $konteks, TanggalBisnisOutlet $tanggal, LanggananRingkasan $langganan, RingkasanTindakanPengguna $ringkasan): Response
    {
        $pelaku = $this->Pelaku();
        $bolehTandai = $akses->CekIzin($this->IdTenant(), $pelaku->Id, IzinTenant::TindakanTinjau);
        $pemilik = ($akses->Ambil($this->IdTenant(), $pelaku->Id)['Pemilik'] ?? false) === true;

        $butir = $kotak->Ambil($konteks->Buat($this->IdTenant(), $pelaku->Id, $tanggal->Hitung(null)));
        // Bacaan langsung ini juga memperbarui simpanan lencana menu samping supaya angkanya sama dengan halaman ini.
        $ringkasan->Simpan($this->IdTenant(), $pelaku->Id, $butir);

        return Inertia::render('Kelola/Tindakan', [
            'Butir' => array_map(fn (DataButirTindakan $b): array => $b->KeLarik($bolehTandai), $butir),
            'Izin' => ['Tandai' => $bolehTandai],
            // D-33: ringkasan pagi dikirim lewat WhatsApp ke nomor HP akun.
            'RingkasanWhatsapp' => [
                'BisaWhatsapp' => PesanWhatsapp::AmbilNomorSah($pelaku->NoHp) !== null,
                'Aktif' => PesanWhatsapp::AmbilNomorSah($pelaku->NoHp) !== null && $langganan->CekAktif($pelaku->Id, $pemilik),
            ],
        ]);
    }

    public function UbahRingkasanWhatsapp(Request $permintaan, UbahLanggananRingkasanTindakan $ubah): RedirectResponse
    {
        $aktif = (bool) $permintaan->validate(['Aktif' => ['required', 'boolean']], attributes: ['Aktif' => 'ringkasan WhatsApp'])['Aktif'];

        if ($aktif && PesanWhatsapp::AmbilNomorSah($this->Pelaku()->NoHp) === null) {
            return back()->withErrors(['Aktif' => 'Akun Anda belum punya nomor HP. Tambahkan nomor HP dulu untuk menerima ringkasan.']);
        }

        $ubah->Jalankan($this->Pelaku()->Id, $aktif);

        return back()->with('Kilat', $aktif ? 'Ringkasan dikirim ke WhatsApp Anda setiap pagi.' : 'Ringkasan pagi lewat WhatsApp dimatikan.');
    }

    public function Tandai(Request $permintaan, TandaiDokumenDitinjau $tandai, KotakTindakan $kotak, KonteksTindakanPengguna $konteks, TanggalBisnisOutlet $tanggal, RingkasanTindakanPengguna $ringkasan): RedirectResponse
    {
        $valid = $permintaan->validate([
            'Jenis' => ['required', 'string', 'max:40'],
            'Uuid' => ['required', 'array', 'min:1', 'max:'.TandaiDokumenDitinjau::MAKS],
            'Uuid.*' => ['string', 'ulid'],
            'Catatan' => ['nullable', 'string', 'max:255'],
        ], attributes: ['Uuid' => 'dokumen', 'Catatan' => 'catatan']);
        $jenis = (string) $valid['Jenis'];
        $uuid = array_values(array_map('strval', (array) $valid['Uuid']));

        // Dokumen di luar outlet akses pelaku dianggap tidak ada.
        $idOutlet = $this->IdOutletBoleh();

        if ($idOutlet !== null) {
            $boleh = [];

            foreach ($kotak->Ambil($konteks->Buat($this->IdTenant(), $this->Pelaku()->Id, $tanggal->Hitung(null))) as $b) {
                if ($b->jenisDokumen === $jenis) {
                    foreach ($b->rincian as $r) {
                        $boleh[] = $r->uuid;
                    }
                }
            }

            $uuid = array_values(array_intersect(array_map('strtoupper', $uuid), $boleh));
            abort_if($uuid === [], 404);
        }

        $jumlah = $tandai->Jalankan($jenis, $uuid, $this->Pelaku()->Id, isset($valid['Catatan']) ? (string) $valid['Catatan'] : null);

        // Lencana menu samping dihitung ulang (simpanan 60 detik pengguna ini dibuang; pengguna lain menyusul sendiri).
        $ringkasan->Lupakan($this->IdTenant(), $this->Pelaku()->Id);

        return back()->with('Kilat', $jumlah === 0 ? 'Semua dokumen yang dipilih sudah ditandai sebelumnya.' : "{$jumlah} dokumen ditandai sudah dicek.");
    }
}
