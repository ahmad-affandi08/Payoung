<?php

declare(strict_types=1);

/*
 * Pekerja proses terpisah untuk test konkurensi (audit F-07): setiap pekerja mem-boot aplikasi sendiri sehingga memakai
 * koneksi MySQL & kunci cache (store database) sendiri, menunggu sampai detik mulai yang sama, lalu menjalankan satu
 * skenario dan mencetak hasil JSON. Dipanggil dari `KonkurensiTes.php`, bukan oleh Pest langsung.
 *
 * Pemakaian: php PekerjaKonkurensi.php {skenario} {mulaiMikrodetik} {berkasJson}
 */

use App\Domain\Akuntansi\Aksi\PostingJurnal;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Bersama\Sinkron\Data\DataKonteksSinkron;
use App\Domain\Bersama\Sinkron\Data\HasilItemSinkron;
use App\Domain\Bersama\Sinkron\Layanan\PemrosesSinkron;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Pemenuhan\Aksi\BuatReservasi;
use App\Domain\Pemenuhan\Data\DataReservasi;
use App\Domain\Pemenuhan\Enum\SumberReservasi;
use App\Domain\Penjualan\Aksi\KonfirmasiPesananGrosir;
use App\Domain\Persediaan\Aksi\CatatBahanTerbuang;
use App\Domain\Persediaan\Data\DataBahanTerbuang;
use App\Domain\Persediaan\Enum\AlasanBahanTerbuang;
use App\Domain\Persediaan\Model\BahanTerbuang;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Pendukung\Akuntansi\BantuanJurnal;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $skenario, $mulai, $berkas] = $argv;
$data = json_decode((string) file_get_contents($berkas), true, flags: JSON_THROW_ON_ERROR);
app(KonteksTenant::class)->Atur((int) $data['IdTenant']);

while (microtime(true) < (float) $mulai) {
    usleep(200);
}

try {
    $hasil = match ($skenario) {
        'sinkron' => array_map(
            fn (HasilItemSinkron $h): string => $h->status->value,
            app(PemrosesSinkron::class)->Proses($data['Item'], new DataKonteksSinkron((int) $data['IdTenant'], (int) $data['IdPerangkat'], (int) $data['IdOutlet'])),
        ),
        'bahan-terbuang' => DB::transaction(fn (): string => app(CatatBahanTerbuang::class)->Jalankan(new DataBahanTerbuang(
            uuid: (string) $data['Uuid'],
            idOutlet: (int) $data['IdOutlet'],
            idGudang: (int) $data['IdGudang'],
            idPerangkat: null,
            idProduk: (int) $data['IdProduk'],
            jumlah: Kuantitas::Dari((string) $data['Jumlah']),
            alasan: AlasanBahanTerbuang::Rusak,
            catatan: null,
            idPengguna: (int) $data['IdPengguna'],
            sumber: BahanTerbuang::SUMBER_BACK_OFFICE,
            dibuatOfflinePada: null,
            tanggalBisnis: CarbonImmutable::now('Asia/Jakarta')->startOfDay(),
        ))->Uuid),
        'reservasi' => app(BuatReservasi::class)->Jalankan((int) $data['IdTenant'], new DataReservasi(
            idOutlet: (int) $data['IdOutlet'],
            uuidLayanan: (string) $data['UuidLayanan'],
            tanggal: (string) $data['Tanggal'],
            jam: (string) $data['Jam'],
            uuidStaf: (string) $data['UuidStaf'],
            namaPelanggan: (string) $data['Nama'],
            noHp: (string) $data['NoHp'],
            catatan: null,
            sumber: SumberReservasi::BackOffice,
            idPengguna: (int) $data['IdPengguna'],
        ))->Uuid,
        'konfirmasi-so' => app(KonfirmasiPesananGrosir::class)->Jalankan((string) $data['UuidPesanan'], (int) $data['IdPengguna'], false)->Uuid,
        'posting-jurnal' => (function () use ($data): array {
            // Dua pekerja memposting jurnal untuk sumber BERBEDA yang idnya berselang-seling (gap indeks unik yang sama).
            $hasil = ['Diposting' => 0, 'Deadlock' => 0, 'LainLain' => 0];

            for ($i = 0; $i < (int) $data['Jumlah']; $i++) {
                try {
                    app(PostingJurnal::class)->Jalankan(BantuanJurnal::DataStokAwal(idSumber: (int) $data['MulaiIdSumber'] + $i * 2, nilai: '1000.00'));
                    $hasil['Diposting']++;
                } catch (QueryException $galat) {
                    in_array((int) ($galat->errorInfo[1] ?? 0), [1213, 1205], true) ? $hasil['Deadlock']++ : $hasil['LainLain']++;
                }
            }

            return $hasil;
        })(),
        default => throw new InvalidArgumentException("Skenario {$skenario} tidak dikenal."),
    };

    echo json_encode(['Hasil' => $hasil], JSON_THROW_ON_ERROR);
} catch (PelanggaranAturanBisnis $galat) {
    echo json_encode(['Galat' => $galat->kode], JSON_THROW_ON_ERROR);
}
