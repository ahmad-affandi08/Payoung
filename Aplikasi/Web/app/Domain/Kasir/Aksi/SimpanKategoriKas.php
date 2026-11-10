<?php

declare(strict_types=1);

namespace App\Domain\Kasir\Aksi;

use App\Domain\Akuntansi\Enum\PeranAkun;
use App\Domain\Akuntansi\Enum\TipeAkun;
use App\Domain\Akuntansi\Kueri\DaftarAkunPilihan;
use App\Domain\Akuntansi\Layanan\PenjagaAkunKendali;
use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Kasir\Data\DataKategoriKas;
use App\Domain\Kasir\Enum\JenisKategoriKas;
use App\Domain\Kasir\Model\KategoriKas;
use Illuminate\Support\Facades\DB;

/**
 * Membuat atau mengubah kategori kas (back-office, F-06). Nama unik per jenis. Akun lawan: kategori Keluar ke akun
 * Beban/Aset/HPP/Kewajiban (misal kasbon karyawan = aset, bayar hutang kecil = kewajiban); kategori Masuk ke akun
 * Pendapatan/Ekuitas/Kewajiban/Aset (misal tambahan modal = ekuitas). Akun kendali (persediaan, piutang, hutang,
 * deposit, dst.) ditolak karena saldonya turunan dokumen modulnya (aturan #9); satu-satunya pengecualian adalah akun
 * penampung belanja stok dari uang laci (D-256). Jenis tidak bisa diubah setelah dibuat agar riwayat mutasi tetap
 * konsisten. Audit `kas.kategori.simpan`.
 */
final class SimpanKategoriKas
{
    public function __construct(
        private readonly DaftarAkunPilihan $akun,
        private readonly PenjagaAkunKendali $penjaga,
        private readonly PencatatAudit $audit,
    ) {}

    /**
     * @return list<TipeAkun>
     */
    public static function AmbilTipeAkunBoleh(JenisKategoriKas $jenis): array
    {
        return match ($jenis) {
            JenisKategoriKas::Keluar => [TipeAkun::Beban, TipeAkun::Hpp, TipeAkun::Aset, TipeAkun::Kewajiban],
            JenisKategoriKas::Masuk => [TipeAkun::Pendapatan, TipeAkun::Ekuitas, TipeAkun::Kewajiban, TipeAkun::Aset],
        };
    }

    public function Jalankan(DataKategoriKas $data, ?KategoriKas $kategori = null): KategoriKas
    {
        $nama = trim($data->nama);

        if ($nama === '' || mb_strlen($nama) > 100) {
            throw new PelanggaranAturanBisnis('NamaTidakValid', 'Nama kategori wajib diisi, maksimal 100 karakter.', 'Nama');
        }

        if ($kategori !== null && $kategori->Jenis !== $data->jenis) {
            throw new PelanggaranAturanBisnis('JenisTidakBisaDiubah', 'Jenis kategori tidak bisa diubah. Buat kategori baru untuk jenis lain.', 'Jenis');
        }

        $akun = $this->akun->CariDariUuid($data->uuidAkun, self::AmbilTipeAkunBoleh($data->jenis));

        if ($akun === null) {
            throw new PelanggaranAturanBisnis('AkunTidakValid', 'Pilih akun yang sesuai untuk kategori '.mb_strtolower($data->jenis->AmbilLabel()).'.', 'UuidAkun');
        }

        $this->penjaga->PastikanBukanKendali($akun['Id'], 'UuidAkun', PeranAkun::KliringBelanjaLaci);

        return DB::transaction(function () use ($data, $kategori, $nama, $akun): KategoriKas {
            $ganda = KategoriKas::query()
                ->where('Jenis', $data->jenis->value)
                ->where('Nama', $nama)
                ->when($kategori !== null, fn ($kueri) => $kueri->whereKeyNot($kategori?->Id))
                ->lockForUpdate()
                ->exists();

            if ($ganda) {
                throw new PelanggaranAturanBisnis('NamaSudahAda', "Kategori \"{$nama}\" sudah ada.", 'Nama');
            }

            $lama = $kategori?->only(['Nama', 'IdAkun', 'Urutan']);
            $kategori ??= new KategoriKas(['Jenis' => $data->jenis]);
            $kategori->fill(['Nama' => $nama, 'IdAkun' => $akun['Id'], 'Urutan' => max(0, $data->urutan)]);

            if (! $kategori->exists || $kategori->isDirty()) {
                $kategori->save();
                $this->audit->Catat('kas.kategori.simpan', $kategori, nilaiLama: $lama, nilaiBaru: [
                    'Nama' => $kategori->Nama,
                    'Jenis' => $kategori->Jenis->value,
                    'IdAkun' => $kategori->IdAkun,
                    'Urutan' => $kategori->Urutan,
                ]);
            }

            return $kategori;
        });
    }
}
