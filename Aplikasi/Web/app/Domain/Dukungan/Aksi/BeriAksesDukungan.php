<?php

declare(strict_types=1);

namespace App\Domain\Dukungan\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Dukungan\Enum\CakupanAksesDukungan;
use App\Domain\Dukungan\Enum\JenisAksesDukungan;
use App\Domain\Dukungan\Model\AksesDukungan;
use Illuminate\Support\Facades\DB;

/**
 * Owner memberi izin akses tim dukungan Payoung ke usahanya (PGL-16, P-09 langkah 1, izin `dukungan.akses.kelola`
 * khusus Owner): durasi 1/24/72 jam dan cakupan Baca saja atau Baca & ubah. Hanya satu izin yang berlaku pada satu
 * waktu; untuk mengganti cakupan atau durasi, cabut izin yang lama dulu. Data pribadi pelanggan tetap tersamar kecuali
 * Owner membukanya secara sadar. Dicatat di `LogAudit` (`dukungan.akses.beri`).
 */
final class BeriAksesDukungan
{
    /** @var list<int> */
    public const DURASI_JAM = [1, 24, 72];

    public function __construct(private readonly PencatatAudit $audit) {}

    /**
     * @throws PelanggaranAturanBisnis DurasiTidakValid, AksesSudahAktif, CatatanTerlaluPanjang
     */
    public function Jalankan(int $idPemberi, string $namaPemberi, int $durasiJam, CakupanAksesDukungan $cakupan, bool $dataPribadiTerbuka, ?int $idTiketDukungan, ?string $catatan): AksesDukungan
    {
        if (! in_array($durasiJam, self::DURASI_JAM, true)) {
            throw new PelanggaranAturanBisnis('DurasiTidakValid', 'Pilih durasi 1 jam, 24 jam, atau 72 jam.', 'DurasiJam');
        }

        $catatan = $catatan === null ? null : trim($catatan);

        if ($catatan !== null && mb_strlen($catatan) > 500) {
            throw new PelanggaranAturanBisnis('CatatanTerlaluPanjang', 'Catatan paling panjang 500 karakter.', 'Catatan');
        }

        return DB::transaction(function () use ($idPemberi, $namaPemberi, $durasiJam, $cakupan, $dataPribadiTerbuka, $idTiketDukungan, $catatan): AksesDukungan {
            // Kunci baris izin tenant ini supaya dua klik bersamaan tidak membuat dua izin yang berlaku.
            $aktif = AksesDukungan::query()
                ->whereNull('DicabutPada')
                ->where('BerakhirPada', '>', now())
                ->lockForUpdate()
                ->exists();

            if ($aktif) {
                throw new PelanggaranAturanBisnis('AksesSudahAktif', 'Sudah ada izin akses dukungan yang berlaku. Cabut dulu bila ingin mengganti durasi atau cakupannya.');
            }

            $akses = AksesDukungan::query()->create([
                'Jenis' => JenisAksesDukungan::Berizin,
                'DiizinkanOleh' => $idPemberi,
                'NamaPemberi' => mb_substr($namaPemberi, 0, 150),
                'IdTiketDukungan' => $idTiketDukungan,
                'Cakupan' => $cakupan,
                'DataPribadiTerbuka' => $dataPribadiTerbuka,
                'Alasan' => $catatan === '' ? null : $catatan,
                'MulaiPada' => now(),
                'BerakhirPada' => now()->addHours($durasiJam),
            ]);

            $this->audit->Catat('dukungan.akses.beri', $akses, nilaiBaru: [
                'DurasiJam' => $durasiJam,
                'Cakupan' => $cakupan->value,
                'DataPribadiTerbuka' => $dataPribadiTerbuka,
                'IdTiketDukungan' => $idTiketDukungan,
                'BerakhirPada' => $akses->BerakhirPada->toIso8601String(),
            ], idPengguna: $idPemberi);

            return $akses;
        });
    }
}
