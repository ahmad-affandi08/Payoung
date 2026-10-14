<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola\Reservasi;

use App\Domain\Akuntansi\Kueri\DaftarAkunPilihan;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Bersama\Tabel\Data\DataPermintaanTabel;
use App\Domain\Organisasi\Kueri\MejaOutlet;
use App\Domain\Organisasi\Kueri\PetaUuidOutlet;
use App\Domain\Organisasi\Kueri\TanggalBisnisOutlet;
use App\Domain\Pemenuhan\Aksi\CatatUangMukaReservasiMeja;
use App\Domain\Pemenuhan\Aksi\SelesaikanUangMukaReservasiMeja;
use App\Domain\Pemenuhan\Aksi\SimpanReservasiMeja;
use App\Domain\Pemenuhan\Aksi\UbahStatusReservasiMeja;
use App\Domain\Pemenuhan\Data\DataReservasiMeja;
use App\Domain\Pemenuhan\Enum\CaraSelesaiUangMukaMeja;
use App\Domain\Pemenuhan\Enum\StatusReservasiMeja;
use App\Domain\Pemenuhan\Enum\SumberReservasi;
use App\Domain\Pemenuhan\Kueri\DaftarReservasiMeja;
use App\Domain\Pemenuhan\Model\ReservasiMeja;
use App\Domain\Tenant\Kueri\ProfilTenant;
use App\Http\Kontroler\Kelola\DasarKelolaKontroler;
use App\Http\Respons\ResponsTabel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Response;

/**
 * FnB reservasi meja + DP (§9.1, D-145) di back-office (`/kelola/reservasi-meja`, izin `reservasi.kelola`, dibatasi
 * outlet akses): daftar (TabelData), catat/ubah reservasi, ubah status, catat uang muka (DP), dan selesaikan sisa DP
 * (kembalikan/hanguskan). Parameter rute berupa string karena konteks tenant ditetapkan setelah binding.
 */
final class ReservasiMejaKontroler extends DasarKelolaKontroler
{
    private const POLA_TANGGAL = 'date_format:Y-m-d';

    private const POLA_JAM = 'regex:/^([01]\d|2[0-3]):[0-5]\d$/';

    private const POLA_UANG = 'regex:/^\d{1,13}(\.\d{1,2})?$/';

    public function Daftar(Request $permintaan, DaftarReservasiMeja $daftar, ProfilTenant $profil, PetaUuidOutlet $outlet, MejaOutlet $meja, DaftarAkunPilihan $akun, TanggalBisnisOutlet $tanggal): Response|JsonResponse
    {
        $tabel = DataPermintaanTabel::Dari($permintaan->query(), DaftarReservasiMeja::KOLOM_URUT, DaftarReservasiMeja::URUT_BAWAAN, DaftarReservasiMeja::KOLOM_SARING);
        $zona = $profil->Ambil($this->IdTenant())['ZonaWaktu'];

        return ResponsTabel::Kirim($permintaan, 'Kelola/Reservasi/DaftarMeja', 'Reservasi', fn (): array => $daftar->AmbilTabel($tabel, $this->IdOutletBoleh(), $zona), fn (): array => [
            'OpsiStatus' => array_map(fn (StatusReservasiMeja $s): array => ['Nilai' => $s->value, 'Label' => $s->AmbilLabel()], StatusReservasiMeja::cases()),
            'OpsiOutlet' => array_map(fn (array $o): array => ['Uuid' => $o['Uuid'], 'Nama' => $o['Nama']], $outlet->AmbilRingkas($this->IdOutletBoleh(), hanyaAktif: true)),
            'PilihanMeja' => $meja->AmbilPilihanReservasi($this->IdOutletBoleh()),
            'AkunKasBank' => array_map(fn (array $a): array => ['Uuid' => $a['Uuid'], 'Nama' => $a['Kode'].' | '.$a['Nama']], $akun->AmbilKasBank()),
            'HariIni' => $tanggal->Hitung(null)->toDateString(),
        ]);
    }

    public function Simpan(Request $permintaan, SimpanReservasiMeja $simpan): RedirectResponse
    {
        $r = $simpan->Jalankan($this->IdTenant(), $this->AmbilData($permintaan));

        return back()->with('Kilat', "Reservasi {$r->Nomor} dicatat.");
    }

    public function Perbarui(Request $permintaan, string $reservasi, DaftarReservasiMeja $daftar, SimpanReservasiMeja $simpan): RedirectResponse
    {
        $r = $simpan->Jalankan($this->IdTenant(), $this->AmbilData($permintaan), $this->Cari($daftar, $reservasi));

        return back()->with('Kilat', "Reservasi {$r->Nomor} disimpan.");
    }

    public function UbahStatus(Request $permintaan, string $reservasi, DaftarReservasiMeja $daftar, UbahStatusReservasiMeja $ubah): RedirectResponse
    {
        $r = $this->Cari($daftar, $reservasi);
        $valid = $permintaan->validate([
            'Status' => ['required', Rule::enum(StatusReservasiMeja::class)],
            'Alasan' => ['nullable', 'string', 'max:255'],
            'CaraDp' => ['nullable', Rule::enum(CaraSelesaiUangMukaMeja::class)],
            'UuidAkun' => ['nullable', 'string', 'max:26'],
        ]);
        $status = StatusReservasiMeja::from((string) $valid['Status']);
        $ubah->Jalankan(
            $r,
            $status,
            $this->Pelaku()->Id,
            isset($valid['Alasan']) ? (string) $valid['Alasan'] : null,
            isset($valid['CaraDp']) && $valid['CaraDp'] !== '' ? CaraSelesaiUangMukaMeja::from((string) $valid['CaraDp']) : null,
            isset($valid['UuidAkun']) && $valid['UuidAkun'] !== '' ? strtoupper((string) $valid['UuidAkun']) : null,
        );

        return back()->with('Kilat', "Reservasi {$r->Nomor}: {$status->AmbilLabel()}.");
    }

    public function CatatUangMuka(Request $permintaan, string $reservasi, DaftarReservasiMeja $daftar, CatatUangMukaReservasiMeja $catat): RedirectResponse
    {
        $r = $this->Cari($daftar, $reservasi);
        $valid = $permintaan->validate([
            'JumlahDp' => ['required', 'string', self::POLA_UANG],
            'UuidAkun' => ['required', 'string', 'max:26'],
        ], ['JumlahDp.regex' => 'Uang muka berupa angka rupiah tanpa titik ribuan, misal 100000.'], ['JumlahDp' => 'uang muka', 'UuidAkun' => 'akun kas/bank']);
        $baru = $catat->Jalankan($r, Uang::Dari((string) $valid['JumlahDp']), strtoupper((string) $valid['UuidAkun']), $this->Pelaku()->Id);

        return back()->with('Kilat', 'Uang muka '.Uang::Dari($baru->JumlahDp)->FormatRupiah()." untuk {$baru->Nomor} dicatat.");
    }

    public function SelesaikanUangMuka(Request $permintaan, string $reservasi, DaftarReservasiMeja $daftar, SelesaikanUangMukaReservasiMeja $selesaikan): RedirectResponse
    {
        $r = $this->Cari($daftar, $reservasi);
        $valid = $permintaan->validate([
            'Cara' => ['required', Rule::enum(CaraSelesaiUangMukaMeja::class)],
            'UuidAkun' => ['nullable', 'string', 'max:26'],
            'Alasan' => ['required', 'string', 'min:5', 'max:255'],
        ], attributes: ['UuidAkun' => 'akun kas/bank']);
        $cara = CaraSelesaiUangMukaMeja::from((string) $valid['Cara']);
        $selesaikan->Jalankan($r, $cara, isset($valid['UuidAkun']) && $valid['UuidAkun'] !== '' ? strtoupper((string) $valid['UuidAkun']) : null, (string) $valid['Alasan'], $this->Pelaku()->Id);

        return back()->with('Kilat', "Sisa uang muka {$r->Nomor}: {$cara->AmbilLabel()}.");
    }

    private function AmbilData(Request $permintaan): DataReservasiMeja
    {
        $valid = $permintaan->validate([
            'Outlet' => ['required', 'string', 'max:26'],
            'UuidMeja' => ['nullable', 'string', 'max:26'],
            'UuidArea' => ['nullable', 'string', 'max:26'],
            'NamaPemesan' => ['required', 'string', 'max:100'],
            'NoHp' => ['required', 'string', 'max:20'],
            'JumlahTamu' => ['required', 'integer', 'min:1', 'max:200'],
            'Tanggal' => ['required', self::POLA_TANGGAL],
            'Jam' => ['required', self::POLA_JAM],
            'DurasiMenit' => ['required', 'integer', 'min:30', 'max:480'],
            'Catatan' => ['nullable', 'string', 'max:255'],
        ], attributes: ['NamaPemesan' => 'nama pemesan', 'NoHp' => 'nomor HP', 'JumlahTamu' => 'jumlah tamu', 'DurasiMenit' => 'durasi']);

        return new DataReservasiMeja(
            idOutlet: $this->CariIdOutlet((string) $valid['Outlet']),
            uuidMeja: isset($valid['UuidMeja']) ? (string) $valid['UuidMeja'] : null,
            uuidArea: isset($valid['UuidArea']) ? (string) $valid['UuidArea'] : null,
            namaPemesan: (string) $valid['NamaPemesan'],
            noHp: (string) $valid['NoHp'],
            jumlahTamu: (int) $valid['JumlahTamu'],
            tanggal: (string) $valid['Tanggal'],
            jam: (string) $valid['Jam'],
            durasiMenit: (int) $valid['DurasiMenit'],
            catatan: isset($valid['Catatan']) ? (string) $valid['Catatan'] : null,
            sumber: SumberReservasi::BackOffice,
            idPengguna: $this->Pelaku()->Id,
        );
    }

    private function Cari(DaftarReservasiMeja $daftar, string $uuid): ReservasiMeja
    {
        $r = $daftar->Cari(strtoupper($uuid), $this->IdOutletBoleh());
        abort_unless($r instanceof ReservasiMeja, 404);

        return $r;
    }

    private function CariIdOutlet(string $uuid): int
    {
        $uuid = strtoupper($uuid);
        $id = app(PetaUuidOutlet::class)->AmbilIdDariUuid([$uuid])[$uuid] ?? null;
        $boleh = $this->IdOutletBoleh();
        abort_if($id === null || ($boleh !== null && ! in_array($id, $boleh, true)), 404);

        return $id;
    }
}
