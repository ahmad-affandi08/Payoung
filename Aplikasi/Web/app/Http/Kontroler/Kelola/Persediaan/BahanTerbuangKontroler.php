<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola\Persediaan;

use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Bersama\Tabel\Data\DataPermintaanTabel;
use App\Domain\Katalog\Kueri\InfoProdukStok;
use App\Domain\Organisasi\Enum\IzinTenant;
use App\Domain\Organisasi\Kueri\TanggalBisnisOutlet;
use App\Domain\Persediaan\Aksi\BatalkanBahanTerbuang;
use App\Domain\Persediaan\Aksi\CatatBahanTerbuang;
use App\Domain\Persediaan\Data\DataBahanTerbuang;
use App\Domain\Persediaan\Enum\AlasanBahanTerbuang;
use App\Domain\Persediaan\Enum\StatusBahanTerbuang;
use App\Domain\Persediaan\Kueri\DaftarBahanTerbuang;
use App\Domain\Persediaan\Model\BahanTerbuang;
use App\Http\Respons\ResponsTabel;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Response;

/**
 * F-05f bahan terbuang di back-office (routes/PersediaanDokumen.php): daftar + ringkasan food cost periode (lihat:
 * `persediaan.lihat`), catat (`persediaan.terbuang.catat`), batalkan (`persediaan.kelola`). Lokasi di luar outlet
 * pelaku = 404.
 */
final class BahanTerbuangKontroler extends DasarDokumenPersediaanKontroler
{
    private const ALAMAT = 'kelola.persediaan.bahan-terbuang.daftar';

    public function Daftar(Request $permintaan, DaftarBahanTerbuang $daftar): Response|JsonResponse
    {
        $tabel = DataPermintaanTabel::Dari($permintaan->query(), DaftarBahanTerbuang::KOLOM_URUT, DaftarBahanTerbuang::URUT_BAWAAN, DaftarBahanTerbuang::KOLOM_SARING);
        $hariIni = CarbonImmutable::parse($this->HariIni());
        $dari = self::AmbilTanggal($permintaan->query('dari')) ?? $hariIni->startOfMonth()->format('Y-m-d');
        $sampai = self::AmbilTanggal($permintaan->query('sampai')) ?? $hariIni->format('Y-m-d');
        $periode = DataPermintaanTabel::Dari(['saring' => ['Tanggal' => "{$dari}..{$sampai}"]], DaftarBahanTerbuang::KOLOM_URUT, DaftarBahanTerbuang::URUT_BAWAAN, DaftarBahanTerbuang::KOLOM_SARING);

        return ResponsTabel::Kirim($permintaan, 'Kelola/Persediaan/BahanTerbuang/Daftar', 'BahanTerbuang', fn (): array => $daftar->AmbilTabel($tabel, $this->IdOutletBoleh()), fn (): array => [
            'Ringkasan' => [...$daftar->AmbilRingkasan($periode, $this->IdOutletBoleh()), 'Dari' => $dari, 'Sampai' => $sampai],
            'OpsiGudang' => $this->AmbilOpsiGudangDokumen(hanyaAktif: false),
            // VoidItem hanya dibuat otomatis dari pembatalan item pesanan meja (BR-07.5), bukan dipilih manual.
            'OpsiAlasan' => array_values(array_map(fn (AlasanBahanTerbuang $a): array => ['Nilai' => $a->value, 'Label' => $a->AmbilLabel()], array_filter(AlasanBahanTerbuang::cases(), fn (AlasanBahanTerbuang $a): bool => $a !== AlasanBahanTerbuang::VoidItem))),
            'OpsiStatus' => array_map(fn (StatusBahanTerbuang $s): array => ['Nilai' => $s->value, 'Label' => $s->AmbilLabel()], StatusBahanTerbuang::cases()),
            'HariIni' => $hariIni->format('Y-m-d'),
            'Izin' => [
                'Catat' => $this->CekIzin(IzinTenant::PersediaanTerbuangCatat),
                'Batalkan' => $this->CekIzin(IzinTenant::PersediaanKelola),
            ],
        ]);
    }

    public function Catat(Request $permintaan, CatatBahanTerbuang $catat, InfoProdukStok $infoProduk, TanggalBisnisOutlet $tanggalBisnis): RedirectResponse
    {
        $valid = $permintaan->validate([
            'Uuid' => ['nullable', 'ulid'],
            'UuidGudang' => ['required', 'ulid'],
            'UuidProduk' => ['required', 'ulid'],
            'Jumlah' => ['required', 'regex:/^\d{1,14}(\.\d{1,4})?$/'],
            'Alasan' => ['required', Rule::enum(AlasanBahanTerbuang::class)],
            'Catatan' => ['nullable', 'string', 'max:255'],
        ], ['Jumlah.regex' => 'Jumlah maksimal 4 angka desimal.'], ['UuidGudang' => 'lokasi stok', 'UuidProduk' => 'produk', 'Jumlah' => 'jumlah', 'Alasan' => 'alasan']);
        $gudang = $this->CariGudangBoleh((string) $valid['UuidGudang']);
        $produk = $infoProduk->AmbilDariUuid([(string) $valid['UuidProduk']])[(string) $valid['UuidProduk']] ?? null;
        abort_if($produk === null, 404);

        DB::transaction(fn () => $catat->Jalankan(new DataBahanTerbuang(
            uuid: strtoupper((string) ($valid['Uuid'] ?? Str::ulid())),
            idOutlet: $gudang->idOutlet,
            idGudang: $gudang->id,
            idPerangkat: null,
            idProduk: $produk->id,
            jumlah: Kuantitas::Dari((string) $valid['Jumlah']),
            alasan: AlasanBahanTerbuang::from((string) $valid['Alasan']),
            catatan: isset($valid['Catatan']) ? (string) $valid['Catatan'] : null,
            idPengguna: $this->Pelaku()->Id,
            sumber: BahanTerbuang::SUMBER_BACK_OFFICE,
            dibuatOfflinePada: null,
            tanggalBisnis: $tanggalBisnis->Hitung($gudang->idOutlet),
        )));

        return redirect()->route(self::ALAMAT)->with('Kilat', "{$produk->nama} dicatat terbuang. Stok dan jurnal susut sudah dicatat.");
    }

    public function Batalkan(Request $permintaan, string $bahanTerbuang, BatalkanBahanTerbuang $batalkan): RedirectResponse
    {
        $alasan = (string) ($permintaan->validate(['Alasan' => ['required', 'string', 'max:255']], attributes: ['Alasan' => 'alasan'])['Alasan'] ?? '');
        $catatan = BahanTerbuang::query()->where('Uuid', $bahanTerbuang)->firstOrFail();
        abort_unless($this->CekBolehOutlet($catatan->IdOutlet), 404);
        $batalkan->Jalankan($catatan, $alasan, $this->Pelaku()->Id);

        return back()->with('Kilat', 'Catatan bahan terbuang dibatalkan. Stok dikembalikan dan jurnal dibalik.');
    }

    private static function AmbilTanggal(mixed $nilai): ?string
    {
        return is_string($nilai) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $nilai) === 1 ? $nilai : null;
    }
}
