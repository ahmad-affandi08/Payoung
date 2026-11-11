<?php

declare(strict_types=1);

namespace App\Domain\Organisasi\Layanan;

use App\Domain\Bersama\Tindakan\Data\DataButirTindakan;
use App\Domain\Bersama\Tindakan\Data\DataKonteksTindakan;
use App\Domain\Bersama\Tindakan\Data\DataRincianTindakan;
use App\Domain\Bersama\Tindakan\Enum\TingkatTindakan;
use App\Domain\Bersama\Tindakan\Kontrak\PenyediaTindakan;
use App\Domain\Bersama\Tindakan\Kontrak\TerbatasSektor;
use App\Domain\Bersama\Tindakan\Layanan\PembuatButirTinjauan;
use App\Domain\Organisasi\Kueri\PerangkatBermasalah;
use App\Domain\Organisasi\Model\ItemSinkronPemulihan;
use App\Domain\Organisasi\Model\Outlet;
use App\Domain\Organisasi\Model\Perangkat;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Kotak Tindakan perangkat, dibatasi outlet akses. Tiga butir:
 * - data kasir yang diterima lewat jalur pemulihan (audit P0 F-01, izin `perangkat.kelola`): dikirim setelah perangkat
 *   dicabut, atau oleh perangkat lain atas nama perangkat asal. Data tetap tercatat, pemilik mengecek bahwa transaksinya
 *   wajar lalu menandainya sudah dicek,
 * - perangkat tidak terhubung ke server saat outlet buka (D-269, izin `perangkat.lihat`),
 * - antrean kirim perangkat macet (D-269, izin `perangkat.lihat`).
 * Dua butir terakhir pengingat yang selesai sendiri saat perangkat kembali terhubung atau antreannya terkirim.
 */
final class PenyediaTindakanPerangkat implements PenyediaTindakan, TerbatasSektor
{
    public const JENIS_DOKUMEN = 'ItemSinkronPemulihan';

    public function __construct(private readonly PerangkatBermasalah $bermasalah) {}

    public function AmbilSektor(): ?array
    {
        return null;
    }

    public function AmbilKecualiSektor(): array
    {
        return ['BIZ'];
    }

    public function Kumpulkan(DataKonteksTindakan $konteks): array
    {
        $butir = [];

        if ($konteks->CekIzin('perangkat.kelola')) {
            $butir[] = $this->ButirPemulihan($konteks);
        }

        if ($konteks->CekIzin('perangkat.lihat')) {
            array_push($butir, ...$this->ButirKoneksi($konteks));
        }

        return $butir;
    }

    private function ButirPemulihan(DataKonteksTindakan $konteks): DataButirTindakan
    {
        $nama = Perangkat::query()->pluck('Nama', 'Id')->all();

        return PembuatButirTinjauan::Buat(
            $this->Kueri($konteks->idOutletBoleh),
            self::JENIS_DOKUMEN,
            'ItemSinkronPemulihan',
            'perangkat.sinkron-pemulihan',
            'Perangkat',
            'Data kasir dari perangkat dicabut perlu dicek',
            'Transaksi yang dibuat offline sebelum perangkat dicabut, atau dikirim perangkat lain setelah aktivasi ulang. Tetap tercatat atas nama perangkat pembuatnya.',
            '/kelola/perangkat',
            fn (ItemSinkronPemulihan $i): DataRincianTindakan => new DataRincianTindakan(
                $i->Uuid,
                $i->Jenis.' dari '.($nama[$i->IdPerangkat] ?? 'perangkat'),
                $i->Alasan->AmbilLabel(),
                $i->DibuatPadaKlien->setTimezone('Asia/Jakarta')->toDateString(),
                '/kelola/perangkat',
            ),
        );
    }

    /**
     * @return list<DataButirTindakan>
     */
    private function ButirKoneksi(DataKonteksTindakan $konteks): array
    {
        $sekarang = CarbonImmutable::now();
        $tidakTerhubung = $this->bermasalah->AmbilTidakTerhubung($konteks->idOutletBoleh, $sekarang);
        $macet = $this->bermasalah->AmbilAntreanMacet($konteks->idOutletBoleh);
        $outlet = Outlet::query()->pluck('Nama', 'Id')->all();
        $jam = PerangkatBermasalah::BatasMacetJam();

        return [
            new DataButirTindakan(
                'perangkat.antrean-macet',
                'Perangkat',
                TingkatTindakan::Penting,
                'Data kasir belum terkirim ke server',
                "Transaksi sudah tercatat di perangkat tetapi belum sampai ke server lebih dari {$jam} jam. Buka aplikasi Kasir di perangkat itu dan pastikan internetnya hidup. Jangan hapus data atau pasang ulang aplikasi sebelum terkirim.",
                count($macet),
                '/kelola/perangkat',
                'Lihat perangkat',
                array_map(fn (array $p): DataRincianTindakan => new DataRincianTindakan(
                    $p['Kode'],
                    $p['Nama'],
                    "{$p['JumlahOutbox']} data tertunda sejak ".$p['TertundaSejak']->setTimezone('Asia/Jakarta')->format('d/m H:i').' | '.($outlet[$p['IdOutlet']] ?? 'outlet'),
                    null,
                    '/kelola/perangkat',
                ), array_slice($macet, 0, DataButirTindakan::BATAS_RINCIAN)),
            ),
            new DataButirTindakan(
                'perangkat.tidak-terhubung',
                'Perangkat',
                TingkatTindakan::Perhatian,
                'Perangkat kasir tidak terhubung',
                'Perangkat tidak menghubungi server lebih dari '.PerangkatBermasalah::MENIT_TIDAK_TERHUBUNG.' menit saat outlet buka. Kasir masih bisa berjualan offline, transaksinya masuk setelah perangkat online. Isi jam operasional outlet supaya perangkat yang sengaja dimatikan saat toko tutup tidak dihitung.',
                count($tidakTerhubung),
                '/kelola/perangkat',
                'Lihat perangkat',
                array_map(fn (array $p): DataRincianTindakan => new DataRincianTindakan(
                    $p['Kode'],
                    $p['Nama'],
                    PerangkatBermasalah::TulisTerakhirTerhubung($p['TerakhirAktifPada'], $sekarang).' | '.($outlet[$p['IdOutlet']] ?? 'outlet'),
                    null,
                    '/kelola/perangkat',
                ), array_slice($tidakTerhubung, 0, DataButirTindakan::BATAS_RINCIAN)),
            ),
        ];
    }

    public function AmbilJenisDokumen(): array
    {
        return [self::JENIS_DOKUMEN];
    }

    public function SaringDokumen(string $jenisDokumen, array $uuid): array
    {
        return $jenisDokumen === self::JENIS_DOKUMEN ? PembuatButirTinjauan::Saring($this->Kueri(null), 'ItemSinkronPemulihan', $uuid) : [];
    }

    /**
     * @param  list<int>|null  $idOutlet
     * @return Builder<ItemSinkronPemulihan>
     */
    private function Kueri(?array $idOutlet): Builder
    {
        return ItemSinkronPemulihan::query()->when($idOutlet !== null, fn (Builder $k) => $k->whereIn('ItemSinkronPemulihan.IdOutlet', $idOutlet ?: [0]));
    }
}
