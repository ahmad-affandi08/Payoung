<?php

declare(strict_types=1);

namespace App\Domain\Pemenuhan\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Dokumen\Enum\JenisDokumenBernomor;
use App\Domain\Bersama\Dokumen\Layanan\PencatatRiwayatStatus;
use App\Domain\Bersama\Dokumen\Layanan\PenomorDokumen;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Organisasi\Kueri\MejaOutlet;
use App\Domain\Organisasi\Kueri\ZonaWaktuOutlet;
use App\Domain\Pelanggan\Kueri\IdentitasPelanggan;
use App\Domain\Pelanggan\Layanan\NomorHp;
use App\Domain\Pemenuhan\Data\DataReservasiMeja;
use App\Domain\Pemenuhan\Enum\StatusReservasiMeja;
use App\Domain\Pemenuhan\Model\ReservasiMeja;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * FnB reservasi meja (§9.1, D-145): buat atau ubah reservasi (hanya selagi Menunggu/Dikonfirmasi). Aturan:
 * - nama pemesan wajib, nomor HP sah (dinormalisasi `62…`), tamu 1–200, durasi 30–480 menit;
 * - meja (bila dipilih) aktif di outlet itu dan kapasitasnya cukup untuk jumlah tamu;
 * - **tidak bentrok** dengan reservasi lain di meja yang sama (Menunggu/Dikonfirmasi/Hadir yang waktunya beririsan),
 *   diperiksa di dalam kunci per meja supaya dua pemesan bersamaan tidak sama-sama lolos;
 * - waktu mulai tidak lebih dari 5 menit di masa lalu (kecuali `$abaikanWaktuLewat`, saat kasir menempatkan tamu yang sudah datang).
 * Reservasi dicatat staf langsung `Dikonfirmasi`. Pelanggan ditautkan bila nomor HP sudah terdaftar. Nomor `RM/YYYY/MM/NNNN`.
 * Audit `reservasi-meja.buat|ubah`.
 */
final class SimpanReservasiMeja
{
    public const DETIK_KUNCI = 30;

    private const DETIK_TUNGGU_KUNCI = 10;

    public function __construct(
        private readonly MejaOutlet $meja,
        private readonly ZonaWaktuOutlet $zona,
        private readonly IdentitasPelanggan $pelanggan,
        private readonly PenomorDokumen $penomor,
        private readonly PencatatRiwayatStatus $riwayat,
        private readonly PencatatAudit $audit,
    ) {}

    public function Jalankan(int $idTenant, DataReservasiMeja $data, ?ReservasiMeja $ada = null, bool $abaikanWaktuLewat = false): ReservasiMeja
    {
        $nama = trim($data->namaPemesan);

        if ($nama === '') {
            throw new PelanggaranAturanBisnis('NamaWajib', 'Nama pemesan wajib diisi.', 'NamaPemesan');
        }

        $noHp = NomorHp::Normalisasi($data->noHp)
            ?? throw new PelanggaranAturanBisnis('NoHpTidakValid', 'Nomor HP tidak valid. Contoh: 0812-3456-7890.', 'NoHp');

        if ($data->jumlahTamu < 1 || $data->jumlahTamu > 200) {
            throw new PelanggaranAturanBisnis('JumlahTamuTidakValid', 'Jumlah tamu 1–200 orang.', 'JumlahTamu');
        }

        if ($data->durasiMenit < 30 || $data->durasiMenit > 480) {
            throw new PelanggaranAturanBisnis('DurasiTidakValid', 'Durasi reservasi 30–480 menit.', 'DurasiMenit');
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $data->tanggal) !== 1 || preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $data->jam) !== 1) {
            throw new PelanggaranAturanBisnis('WaktuTidakValid', 'Tanggal atau jam tidak valid.', 'Jam');
        }

        if ($ada !== null && ! $ada->Status->CekBolehDiubah()) {
            throw new PelanggaranAturanBisnis('ReservasiTidakBisaDiubah', "Reservasi {$ada->Nomor} berstatus {$ada->Status->AmbilLabel()} dan tidak bisa diubah lagi.", 'Status');
        }

        $mulai = CarbonImmutable::parse("{$data->tanggal} {$data->jam}", $this->zona->Ambil($data->idOutlet))->utc();

        if (! $abaikanWaktuLewat && $mulai->lt(CarbonImmutable::now()->subMinutes(5))) {
            throw new PelanggaranAturanBisnis('WaktuLewat', 'Waktu reservasi sudah lewat.', 'Jam');
        }

        $meja = $data->uuidMeja === null || $data->uuidMeja === ''
            ? null
            : ($this->meja->CariAktifRinci($data->idOutlet, strtoupper($data->uuidMeja))
                ?? throw new PelanggaranAturanBisnis('MejaTidakDitemukan', 'Meja tidak ditemukan atau sudah diarsipkan di outlet ini.', 'UuidMeja'));
        $area = $data->uuidArea === null || $data->uuidArea === ''
            ? null
            : ($this->meja->CariAreaAktif($data->idOutlet, strtoupper($data->uuidArea))
                ?? throw new PelanggaranAturanBisnis('AreaTidakDitemukan', 'Area tidak ditemukan atau sudah diarsipkan di outlet ini.', 'UuidArea'));

        if ($meja !== null && $meja['Kapasitas'] < $data->jumlahTamu) {
            throw new PelanggaranAturanBisnis('KapasitasKurang', "Meja {$meja['Nama']} berkapasitas {$meja['Kapasitas']} orang, tamu {$data->jumlahTamu}. Pilih meja lain atau gabungkan lewat kasir.", 'UuidMeja');
        }

        if ($meja === null) {
            return $this->Simpan($idTenant, $data, $ada, $nama, $noHp, $mulai, null, $area['Id'] ?? null);
        }

        try {
            return Cache::lock("reservasi-meja:{$idTenant}:{$meja['Id']}", self::DETIK_KUNCI)->block(
                self::DETIK_TUNGGU_KUNCI,
                fn (): ReservasiMeja => $this->Simpan($idTenant, $data, $ada, $nama, $noHp, $mulai, $meja, $meja['IdAreaMeja'] ?? $area['Id'] ?? null),
            );
        } catch (LockTimeoutException) {
            throw new PelanggaranAturanBisnis('SedangDiproses', "Meja {$meja['Nama']} sedang dipesan orang lain. Coba lagi sebentar.", 'UuidMeja', 429);
        }
    }

    /**
     * @param  array{Id: int, Nama: string, Kapasitas: int, IdAreaMeja: int|null}|null  $meja
     */
    private function Simpan(int $idTenant, DataReservasiMeja $data, ?ReservasiMeja $ada, string $nama, string $noHp, CarbonImmutable $mulai, ?array $meja, ?int $idArea): ReservasiMeja
    {
        return DB::transaction(function () use ($data, $ada, $nama, $noHp, $mulai, $meja, $idArea): ReservasiMeja {
            if ($meja !== null) {
                $bentrok = $this->CariBentrok($meja['Id'], $mulai, $data->durasiMenit, $ada?->Id);

                if ($bentrok !== null) {
                    $zona = $this->zona->Ambil($data->idOutlet);

                    throw new PelanggaranAturanBisnis(
                        'MejaBentrok',
                        "Meja {$meja['Nama']} sudah dipesan {$bentrok->NamaPemesan} pukul {$bentrok->MulaiPada->copy()->setTimezone($zona)->format('H:i')}–{$bentrok->AmbilSelesaiPada()->setTimezone($zona)->format('H:i')} ({$bentrok->Nomor}). Pilih meja atau jam lain.",
                        'UuidMeja',
                        409,
                    );
                }
            }

            $isian = [
                'IdOutlet' => $data->idOutlet,
                'IdMeja' => $meja['Id'] ?? null,
                'IdAreaMeja' => $idArea,
                'IdPelanggan' => $this->pelanggan->CariIdDariNoHp($noHp),
                'NamaPemesan' => mb_substr($nama, 0, 100),
                'NoHp' => $noHp,
                'JumlahTamu' => $data->jumlahTamu,
                'MulaiPada' => $mulai,
                'DurasiMenit' => $data->durasiMenit,
                'Catatan' => $data->catatan === null || trim($data->catatan) === '' ? null : mb_substr(trim($data->catatan), 0, 255),
            ];

            if ($ada === null) {
                $periode = CarbonImmutable::now();
                $nomor = $this->penomor->AmbilNomorBerikutnya(JenisDokumenBernomor::ReservasiMeja, $periode->format('Y-m'));
                $baru = ReservasiMeja::query()->create([
                    ...$isian,
                    'Nomor' => $nomor,
                    'Status' => StatusReservasiMeja::Dikonfirmasi,
                    'Sumber' => $data->sumber,
                    'DibuatOleh' => $data->idPengguna,
                ]);
                $this->riwayat->Catat(ReservasiMeja::JENIS_DOKUMEN, $baru->Id, null, StatusReservasiMeja::Dikonfirmasi->value, $data->idPengguna);
                $this->audit->Catat('reservasi-meja.buat', $baru, nilaiBaru: [
                    'Nomor' => $baru->Nomor,
                    'IdMeja' => $baru->IdMeja,
                    'JumlahTamu' => $baru->JumlahTamu,
                    'MulaiPada' => $mulai->toIso8601ZuluString(),
                ], idPengguna: $data->idPengguna);

                return $baru;
            }

            $reservasi = ReservasiMeja::query()->whereKey($ada->Id)->lockForUpdate()->firstOrFail();

            if (! $reservasi->Status->CekBolehDiubah()) {
                throw new PelanggaranAturanBisnis('ReservasiTidakBisaDiubah', "Reservasi {$reservasi->Nomor} berstatus {$reservasi->Status->AmbilLabel()} dan tidak bisa diubah lagi.", 'Status');
            }

            $lama = $reservasi->only(['IdMeja', 'JumlahTamu', 'MulaiPada', 'DurasiMenit']);
            $reservasi->fill($isian)->save();
            $this->audit->Catat('reservasi-meja.ubah', $reservasi, nilaiLama: $lama, nilaiBaru: [
                'IdMeja' => $reservasi->IdMeja,
                'JumlahTamu' => $reservasi->JumlahTamu,
                'MulaiPada' => $mulai->toIso8601ZuluString(),
                'DurasiMenit' => $reservasi->DurasiMenit,
            ], idPengguna: $data->idPengguna);

            return $reservasi;
        });
    }

    /** Reservasi lain di meja yang sama yang waktunya beririsan dengan [mulai, mulai + durasi). */
    private function CariBentrok(int $idMeja, CarbonImmutable $mulai, int $durasiMenit, ?int $kecualiId): ?ReservasiMeja
    {
        $selesai = $mulai->addMinutes($durasiMenit);

        return ReservasiMeja::query()
            ->where('IdMeja', $idMeja)
            ->whereIn('Status', StatusReservasiMeja::AmbilNilaiMemakaiMeja())
            ->when($kecualiId !== null, fn ($kueri) => $kueri->whereKeyNot($kecualiId))
            ->where('MulaiPada', '<', $selesai)
            ->whereRaw('DATE_ADD(`MulaiPada`, INTERVAL `DurasiMenit` MINUTE) > ?', [$mulai->format('Y-m-d H:i:s')])
            ->orderBy('MulaiPada')
            ->first();
    }
}
