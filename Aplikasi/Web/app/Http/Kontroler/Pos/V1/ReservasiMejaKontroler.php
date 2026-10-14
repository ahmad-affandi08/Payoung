<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Pos\V1;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Organisasi\Data\DataAnggotaOutlet;
use App\Domain\Organisasi\Enum\IzinTenant;
use App\Domain\Organisasi\Kueri\AnggotaOutlet;
use App\Domain\Organisasi\Kueri\ZonaWaktuOutlet;
use App\Domain\Organisasi\Model\Perangkat;
use App\Domain\Pemenuhan\Aksi\TempatkanTamuReservasiMeja;
use App\Domain\Pemenuhan\Aksi\UbahStatusReservasiMeja;
use App\Domain\Pemenuhan\Enum\StatusReservasiMeja;
use App\Domain\Pemenuhan\Kueri\ReservasiMejaPos;
use App\Domain\Pemenuhan\Model\ReservasiMeja;
use App\Domain\Penjualan\Aksi\SiapkanMetodeUangMuka;
use App\Http\Kontroler\Kontroler;
use App\Http\Perantara\AutentikasiPerangkat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * FnB reservasi meja + DP (D-145, perlu online): `GET /api/pos/v1/reservasi-meja?tanggal=` antrian reservasi meja
 * outlet perangkat (bawaan hari ini) beserta ringkasan uang muka; `POST /api/pos/v1/reservasi-meja/{uuid}/hadir`
 * `{UuidPengguna, UuidMeja?}` menandai tamu sudah datang (kasir ber-izin `penjualan.buat` atau `reservasi.kelola`;
 * idempoten bila sudah Hadir; `UuidMeja` menempatkan tamu di meja bila reservasi belum bermeja, kapasitas & bentrok
 * diperiksa). Pembayaran lewat `Penjualan.Buat` dengan `UuidReservasiMeja` + metode Uang Muka memotong DP dan
 * menyelesaikan reservasi.
 */
final class ReservasiMejaKontroler extends Kontroler
{
    public function Ambil(Request $permintaan, ReservasiMejaPos $kueri, SiapkanMetodeUangMuka $metodeUangMuka): JsonResponse
    {
        $valid = $permintaan->validate(['tanggal' => ['nullable', 'date_format:Y-m-d']]);
        $reservasi = $kueri->Ambil(AutentikasiPerangkat::AmbilPerangkat($permintaan)->IdOutlet, isset($valid['tanggal']) ? (string) $valid['tanggal'] : null);

        return response()->json(['Reservasi' => $reservasi, 'MetodeUangMuka' => $this->AmbilMetodeUangMuka($reservasi, $metodeUangMuka)]);
    }

    /**
     * Metode pembayaran sistem "Uang muka (DP)" untuk memotong DP di kasir; dibuat bila belum ada dan ada reservasi
     * yang masih memegang uang muka (tenant yang tidak memakai DP tidak mendapat metode tambahan).
     *
     * @param  list<array<string, mixed>>  $reservasi
     * @return array{Uuid: string, Nama: string}|null
     */
    private function AmbilMetodeUangMuka(array $reservasi, SiapkanMetodeUangMuka $metodeUangMuka): ?array
    {
        $adaDp = collect($reservasi)->contains(fn (array $r): bool => is_array($r['UangMuka'] ?? null) && ($r['UangMuka']['Dicatat'] ?? '0.00') !== '0.00');

        if (! $adaDp) {
            return null;
        }

        $metode = $metodeUangMuka->Jalankan();

        return ['Uuid' => $metode->Uuid, 'Nama' => $metode->Nama];
    }

    public function Hadir(Request $permintaan, string $reservasi, AnggotaOutlet $anggota, UbahStatusReservasiMeja $ubah, TempatkanTamuReservasiMeja $tempatkan, ReservasiMejaPos $kueri, ZonaWaktuOutlet $zona, SiapkanMetodeUangMuka $metodeUangMuka): JsonResponse
    {
        $perangkat = AutentikasiPerangkat::AmbilPerangkat($permintaan);
        $valid = $permintaan->validate([
            'UuidPengguna' => ['required', 'string', 'ulid'],
            'UuidMeja' => ['nullable', 'string', 'ulid'],
        ]);
        $pelaku = $this->CariPelaku($perangkat, (string) $valid['UuidPengguna'], $anggota);

        $r = ReservasiMeja::query()->where('Uuid', strtoupper($reservasi))->where('IdOutlet', $perangkat->IdOutlet)->first();
        abort_if($r === null, 404);

        if ($r->Status !== StatusReservasiMeja::Hadir) {
            if ($r->IdMeja === null && isset($valid['UuidMeja'])) {
                $r = $tempatkan->Jalankan($perangkat->IdTenant, $r, strtoupper((string) $valid['UuidMeja']), $pelaku->id);
            }

            $ubah->Jalankan($r, StatusReservasiMeja::Hadir, $pelaku->id);
        }

        $semua = $kueri->Ambil($perangkat->IdOutlet, $r->MulaiPada->copy()->setTimezone($zona->Ambil($perangkat->IdOutlet))->toDateString());
        $baris = collect($semua)->firstWhere('Uuid', $r->Uuid);

        return response()->json(['Reservasi' => $baris, 'MetodeUangMuka' => $this->AmbilMetodeUangMuka($baris === null ? [] : [$baris], $metodeUangMuka)]);
    }

    /** Kasir ber-izin `penjualan.buat` atau `reservasi.kelola` di outlet perangkat. */
    private function CariPelaku(Perangkat $perangkat, string $uuidPengguna, AnggotaOutlet $anggota): DataAnggotaOutlet
    {
        $pelaku = $anggota->Cari($perangkat->IdTenant, strtoupper($uuidPengguna), $perangkat->IdOutlet);

        if ($pelaku === null || (! $pelaku->CekIzin(IzinTenant::PenjualanBuat->value) && ! $pelaku->CekIzin(IzinTenant::ReservasiKelola->value))) {
            throw new PelanggaranAturanBisnis('TanpaIzin', 'Pengguna ini tidak boleh menerima tamu reservasi meja.', 'UuidPengguna', 403);
        }

        return $pelaku;
    }
}
