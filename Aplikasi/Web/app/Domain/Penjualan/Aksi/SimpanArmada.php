<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Penjualan\Data\DataArmada;
use App\Domain\Penjualan\Model\Armada;
use Illuminate\Support\Facades\DB;

/**
 * Membuat atau mengubah armada pengiriman (F-12 §9.7). Nomor kendaraan dinormalkan (huruf besar, spasi tunggal) dan unik
 * per tenant. Audit `armada.buat` / `armada.ubah`.
 */
final class SimpanArmada
{
    private const KOLOM_AUDIT = ['Nama', 'NomorKendaraan', 'NamaPengemudi', 'Keterangan', 'Aktif'];

    public function __construct(private readonly PencatatAudit $audit) {}

    /**
     * @throws PelanggaranAturanBisnis ArmadaTidakLengkap, NomorKendaraanDipakai
     */
    public function Jalankan(DataArmada $data, ?Armada $armada, int $idPengguna): Armada
    {
        $nama = trim($data->nama);
        $nomor = mb_strtoupper(trim((string) preg_replace('/\s+/', ' ', $data->nomorKendaraan)));

        if ($nama === '' || $nomor === '') {
            throw new PelanggaranAturanBisnis('ArmadaTidakLengkap', 'Isi nama armada dan nomor kendaraannya.', $nama === '' ? 'Nama' : 'NomorKendaraan');
        }

        return DB::transaction(function () use ($data, $armada, $nama, $nomor, $idPengguna): Armada {
            if ($armada !== null) {
                $armada = Armada::query()->whereKey($armada->Id)->lockForUpdate()->firstOrFail();
            }

            $dipakai = Armada::query()->where('NomorKendaraan', $nomor)->when($armada !== null, fn ($k) => $k->whereKeyNot($armada?->Id))->exists();

            if ($dipakai) {
                throw new PelanggaranAturanBisnis('NomorKendaraanDipakai', "Nomor kendaraan {$nomor} sudah terdaftar sebagai armada lain.", 'NomorKendaraan');
            }

            $isi = [
                'Nama' => $nama,
                'NomorKendaraan' => $nomor,
                'NamaPengemudi' => $data->namaPengemudi === null || trim($data->namaPengemudi) === '' ? null : trim($data->namaPengemudi),
                'Keterangan' => $data->keterangan === null || trim($data->keterangan) === '' ? null : trim($data->keterangan),
            ];

            if ($armada === null) {
                $armada = Armada::query()->create($isi);
                $this->audit->Catat('armada.buat', $armada, null, $armada->only(self::KOLOM_AUDIT), idPengguna: $idPengguna);

                return $armada;
            }

            $lama = $armada->only(self::KOLOM_AUDIT);
            $armada->fill($isi);

            if ($armada->isDirty()) {
                $armada->save();
                $this->audit->Catat('armada.ubah', $armada, $lama, $armada->only(self::KOLOM_AUDIT), idPengguna: $idPengguna);
            }

            return $armada;
        });
    }
}
