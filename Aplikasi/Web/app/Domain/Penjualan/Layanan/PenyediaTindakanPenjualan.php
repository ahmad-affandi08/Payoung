<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Layanan;

use App\Domain\Bersama\Dokumen\Enum\StatusDokumenTerposting;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Bersama\Tindakan\Data\DataButirTindakan;
use App\Domain\Bersama\Tindakan\Data\DataKonteksTindakan;
use App\Domain\Bersama\Tindakan\Data\DataRincianTindakan;
use App\Domain\Bersama\Tindakan\Enum\TingkatTindakan;
use App\Domain\Bersama\Tindakan\Kontrak\PenyediaTindakan;
use App\Domain\Bersama\Tindakan\Layanan\PembuatButirTinjauan;
use App\Domain\Penjualan\Enum\StatusPesananOnline;
use App\Domain\Penjualan\Enum\SumberTagihanQris;
use App\Domain\Penjualan\Model\IsiDeposit;
use App\Domain\Penjualan\Model\Penjualan;
use App\Domain\Penjualan\Model\PesananOnline;
use App\Domain\Penjualan\Model\ReturPenjualan;
use App\Domain\Penjualan\Model\SuratJalan;
use App\Domain\Penjualan\Model\TagihanQris;
use Illuminate\Database\Eloquent\Builder;

/**
 * Kotak Tindakan domain Penjualan (D-23 C): penjualan, retur, dan isi deposit dari kasir yang diterima dengan
 * `PerluTinjauan` dan belum ditandai dicek (dibatasi outlet akses). Lihat: `laporan.penjualan.lihat` (isi deposit:
 * `pelanggan.lihat`; surat jalan grosir: `grosir.kelola`). Audit P0 F-02: uang QRIS dinamis yang masuk setelah tagihan berstatus akhir/tidak pasti dan belum
 * dipakai penjualan mana pun. BR-12.4: surat jalan grosir yang sudah diserahkan tetapi belum difakturkan (butir
 * pengingat yang selesai sendiri saat fakturnya dibuat, bukan butir "sudah dicek"). F-17: pesanan toko online yang
 * masih menunggu konfirmasi staf (`toko-online.kelola`), juga butir pengingat karena selesai sendiri begitu pesanan
 * dikonfirmasi, ditolak, atau hangus.
 */
final class PenyediaTindakanPenjualan implements PenyediaTindakan
{
    /** Jam sejak pembayaran pesanan online sebelum belum-ditagihnya dianggap perlu perhatian (E8). */
    private const JAM_BELUM_DITAGIH = 12;

    public function Kumpulkan(DataKonteksTindakan $konteks): array
    {
        $butir = [];

        if ($konteks->CekIzin('laporan.penjualan.lihat')) {
            $butir[] = PembuatButirTinjauan::Buat(
                $this->KueriPenjualan($konteks->idOutletBoleh),
                'Penjualan',
                'Penjualan',
                'penjualan.tinjauan',
                'Penjualan',
                'Penjualan perlu dicek',
                'Diterima dari kasir dengan catatan (misal stok minus, diskon/izin berubah, dikirim setelah shift ditutup).',
                '/kelola/penjualan?saring[PerluTinjauan]=Ya',
                fn (Penjualan $p): DataRincianTindakan => new DataRincianTindakan($p->Uuid, $p->Nomor, $p->AlasanTinjauan, $p->TanggalBisnis->toDateString(), '/kelola/penjualan/'.$p->Uuid),
            );
            $butir[] = PembuatButirTinjauan::Buat(
                $this->KueriRetur($konteks->idOutletBoleh),
                'ReturPenjualan',
                'ReturPenjualan',
                'retur-penjualan.tinjauan',
                'Penjualan',
                'Retur penjualan perlu dicek',
                'Retur dari kasir yang diterima dengan catatan.',
                '/kelola/penjualan/retur',
                fn (ReturPenjualan $r): DataRincianTindakan => new DataRincianTindakan($r->Uuid, $r->Nomor, $r->AlasanTinjauan, $r->TanggalBisnis->toDateString(), '/kelola/penjualan/retur/'.$r->Uuid),
            );
        }

        if ($konteks->CekIzin('pelanggan.lihat')) {
            $butir[] = PembuatButirTinjauan::Buat(
                $this->KueriIsiDeposit($konteks->idOutletBoleh),
                'IsiDeposit',
                'IsiDeposit',
                'isi-deposit.tinjauan',
                'Pelanggan',
                'Isi deposit perlu dicek',
                'Isi deposit dari kasir dengan catatan (misal pelanggan belum dikenal server).',
                '/kelola/pelanggan/isi-deposit?saring[PerluTinjauan]=Ya',
                fn (IsiDeposit $i): DataRincianTindakan => new DataRincianTindakan($i->Uuid, $i->Nomor, $i->AlasanTinjauan, $i->TanggalBisnis->toDateString(), '/kelola/pelanggan/isi-deposit?cari='.rawurlencode($i->Nomor)),
            );
        }

        if ($konteks->CekIzin('toko-online.kelola')) {
            $butirPesanan = $this->ButirPesananOnlineBaru($konteks->idOutletBoleh);

            if ($butirPesanan !== null) {
                $butir[] = $butirPesanan;
            }

            $butirBelumDitagih = $this->ButirPesananOnlineBerbayarBelumDitagih($konteks->idOutletBoleh);

            if ($butirBelumDitagih !== null) {
                $butir[] = $butirBelumDitagih;
            }
        }

        if ($konteks->CekIzin('akuntansi.kelola')) {
            $butir[] = PembuatButirTinjauan::Buat(
                $this->KueriTagihanQris($konteks->idOutletBoleh),
                'TagihanQris',
                'TagihanQris',
                'tagihan-qris.tinjauan',
                'Penjualan',
                'Uang QRIS masuk tanpa penjualan',
                'Pelanggan membayar QRIS dinamis setelah tagihannya kedaluwarsa, dibatalkan, atau tidak pasti. Kembalikan dananya ke pelanggan atau akui sebagai pendapatan lain.',
                '/kelola/akuntansi/qris-tanpa-penjualan?saring[Penutupan]=Terbuka',
                fn (TagihanQris $t): DataRincianTindakan => new DataRincianTindakan($t->Uuid, $t->NomorPesanan, $t->AlasanTinjauan, $t->LunasPada?->setTimezone('Asia/Jakarta')->toDateString(), '/kelola/akuntansi/qris-tanpa-penjualan?cari='.$t->Uuid),
            );
            $butirRefund = $this->ButirUangMukaOnlineBelumKembali($konteks->idOutletBoleh);

            if ($butirRefund !== null) {
                $butir[] = $butirRefund;
            }
        }

        if ($konteks->CekIzin('grosir.kelola')) {
            $butirGrosir = $this->ButirSuratJalanBelumDifakturkan($konteks->idOutletBoleh);

            if ($butirGrosir !== null) {
                $butir[] = $butirGrosir;
            }
        }

        return $butir;
    }

    /**
     * BR-12.4: surat jalan tercatat yang **belum difakturkan** dan bulan penyerahannya berjalan atau sudah lewat.
     *
     * Ini **peringatan kepatuhan, bukan syarat pembukuan**: pendapatan dan PPN-nya sudah dibukukan saat penyerahan
     * (BR-12.2), jadi tutup bulan tidak diblokir olehnya. Yang terancam adalah batas waktu Faktur Pajak — UU PPN Pasal
     * 13 ayat (2a) meminta faktur gabungan dibuat paling lama akhir bulan penyerahan — dan uang toko yang menganggur di
     * `PiutangBelumDifakturkan` karena pembeli belum pernah ditagih.
     *
     * @param  list<int>|null  $idOutlet
     */
    private function ButirSuratJalanBelumDifakturkan(?array $idOutlet): ?DataButirTindakan
    {
        $kueri = SuratJalan::query()
            ->where('Status', StatusDokumenTerposting::Diposting->value)
            ->whereNull('IdFakturPenjualan')
            ->where('Tanggal', '<=', now('Asia/Jakarta')->endOfMonth()->toDateString())
            ->when($idOutlet !== null, fn ($k) => $k->whereIn('IdOutlet', $idOutlet));
        $jumlah = (clone $kueri)->count();

        if ($jumlah === 0) {
            return null;
        }

        $total = Uang::Nol();

        foreach ((clone $kueri)->get(['Total']) as $sj) {
            $total = $total->Tambah($sj->AmbilTotal());
        }

        return new DataButirTindakan(
            'surat-jalan.belum-difakturkan',
            'Penjualan',
            TingkatTindakan::Perhatian,
            'Surat jalan grosir belum difakturkan',
            'Nilai '.$total->FormatRupiah().' sudah diserahkan tetapi belum ditagihkan. Faktur Pajak gabungan dibuat paling lama akhir bulan penyerahan (UU PPN Pasal 13 ayat 2a).',
            $jumlah,
            '/kelola/grosir/surat-jalan?saring[Difakturkan]=Belum',
            'Buat faktur',
            array_values((clone $kueri)->orderBy('Tanggal')->limit(DataButirTindakan::BATAS_RINCIAN)->get()->map(
                fn (SuratJalan $sj): DataRincianTindakan => new DataRincianTindakan(
                    $sj->Uuid,
                    $sj->Nomor,
                    $sj->AmbilTotal()->FormatRupiah().', diserahkan '.$sj->Tanggal->toDateString(),
                    $sj->Tanggal->toDateString(),
                    '/kelola/grosir/surat-jalan/'.$sj->Uuid,
                ),
            )->all()),
        );
    }

    /**
     * F-17: pesanan toko online yang masih `MenungguKonfirmasi`. Pelanggan sudah menunggu kabar, jadi butirnya
     * `Penting` begitu ada yang menunggu lebih dari sepuluh menit dan `Perhatian` selama masih segar.
     *
     * @param  list<int>|null  $idOutlet
     */
    private function ButirPesananOnlineBaru(?array $idOutlet): ?DataButirTindakan
    {
        $kueri = PesananOnline::query()
            ->where('Status', StatusPesananOnline::MenungguKonfirmasi->value)
            ->when($idOutlet !== null, fn ($k) => $k->whereIn('IdOutlet', $idOutlet));
        $jumlah = (clone $kueri)->count();

        if ($jumlah === 0) {
            return null;
        }

        $tertua = (clone $kueri)->min('DibuatPada');
        $menit = $tertua === null ? 0 : (int) now()->diffInMinutes($tertua, true);

        return new DataButirTindakan(
            'pesanan-online.menunggu-konfirmasi',
            'Penjualan',
            $menit >= 10 ? TingkatTindakan::Penting : TingkatTindakan::Perhatian,
            'Pesanan toko online menunggu konfirmasi',
            $jumlah.' pesanan belum dijawab; yang tertua sudah '.$menit.' menit. Pesanan yang tidak dikonfirmasi sampai batas waktu toko akan hangus sendiri.',
            $jumlah,
            '/kelola/toko-online',
            'Buka pesanan',
            array_values((clone $kueri)->orderBy('DibuatPada')->limit(DataButirTindakan::BATAS_RINCIAN)->get()->map(
                fn (PesananOnline $p): DataRincianTindakan => new DataRincianTindakan(
                    $p->Uuid,
                    $p->Nomor,
                    $p->JenisPemenuhan->AmbilLabel().', '.$p->AmbilTotal()->FormatRupiah(),
                    $p->DibuatPada?->setTimezone('Asia/Jakarta')->toDateString(),
                    '/kelola/toko-online?cari='.rawurlencode($p->Nomor),
                ),
            )->all()),
        );
    }

    /**
     * E8: pesanan online yang **sudah dibayar di muka** tetapi belum ditagih di kasir (`IdPenjualan` kosong) lebih dari
     * [JAM_BELUM_DITAGIH] jam sejak dibayar. Penjualan, stok keluar, dan pendapatannya baru terbentuk saat kasir
     * menagihnya (BR-17.1), jadi selama itu uangnya hanya tercatat sebagai uang muka pelanggan dan stoknya belum keluar.
     * Butir `Perhatian`: pesanan masih berjalan (dikonfirmasi/diproses/siap); yang ditolak atau dibatalkan punya butir
     * pengembalian uang sendiri.
     *
     * @param  list<int>|null  $idOutlet
     */
    private function ButirPesananOnlineBerbayarBelumDitagih(?array $idOutlet): ?DataButirTindakan
    {
        $kueri = PesananOnline::query()
            ->whereNotNull('DibayarPada')
            ->whereNull('IdPenjualan')
            ->whereIn('Status', [StatusPesananOnline::Dikonfirmasi->value, StatusPesananOnline::Diproses->value, StatusPesananOnline::Siap->value])
            ->where('DibayarPada', '<=', now()->subHours(self::JAM_BELUM_DITAGIH))
            ->when($idOutlet !== null, fn ($k) => $k->whereIn('IdOutlet', $idOutlet));
        $jumlah = (clone $kueri)->count();

        if ($jumlah === 0) {
            return null;
        }

        return new DataButirTindakan(
            'pesanan-online.berbayar-belum-ditagih',
            'Penjualan',
            TingkatTindakan::Perhatian,
            'Pesanan online berbayar belum ditagih di kasir',
            $jumlah.' pesanan sudah dibayar pelanggan lebih dari '.self::JAM_BELUM_DITAGIH.' jam lalu tetapi belum ditagih di kasir, jadi penjualan dan stok keluarnya belum tercatat. Buka layar Pesanan toko online di kasir lalu tagih.',
            $jumlah,
            '/kelola/toko-online',
            'Buka pesanan',
            array_values((clone $kueri)->orderBy('DibayarPada')->limit(DataButirTindakan::BATAS_RINCIAN)->get()->map(
                fn (PesananOnline $p): DataRincianTindakan => new DataRincianTindakan(
                    $p->Uuid,
                    $p->Nomor,
                    $p->JenisPemenuhan->AmbilLabel().', '.$p->AmbilTotal()->FormatRupiah().', '.$p->Status->AmbilLabel(),
                    $p->DibayarPada?->setTimezone('Asia/Jakarta')->toDateString(),
                    '/kelola/toko-online?cari='.rawurlencode($p->Nomor),
                ),
            )->all()),
        );
    }

    /**
     * F-17 bagian 2: pesanan online yang **sudah dibayar** tetapi tidak akan pernah diserahkan (ditolak, dibatalkan,
     * atau hangus) dan uangnya belum dikembalikan. Butir `Penting`, bukan `Perhatian`: ini uang pelanggan yang
     * ditahan toko tanpa dasar, dan saldo `Uang Muka Pelanggan` akan terus menunjukkannya sampai dikembalikan.
     *
     * @param  list<int>|null  $idOutlet
     */
    private function ButirUangMukaOnlineBelumKembali(?array $idOutlet): ?DataButirTindakan
    {
        $kueri = PesananOnline::query()
            ->whereNotNull('DibayarPada')
            ->whereNull('DikembalikanPada')
            ->whereIn('Status', [StatusPesananOnline::Ditolak->value, StatusPesananOnline::Dibatalkan->value, StatusPesananOnline::Kedaluwarsa->value])
            ->when($idOutlet !== null, fn ($k) => $k->whereIn('IdOutlet', $idOutlet));
        $pesanan = (clone $kueri)->orderBy('DibayarPada')->get();
        $perlu = $pesanan->filter(fn (PesananOnline $p): bool => $p->AmbilSisaUangMuka()->Bandingkan(Uang::Nol()) > 0)->values();

        if ($perlu->isEmpty()) {
            return null;
        }

        $total = Uang::Nol();

        foreach ($perlu as $satu) {
            $total = $total->Tambah($satu->AmbilSisaUangMuka());
        }

        return new DataButirTindakan(
            'pesanan-online.uang-muka-belum-kembali',
            'Penjualan',
            TingkatTindakan::Penting,
            'Uang pelanggan belum dikembalikan',
            'Nilai '.$total->FormatRupiah().' sudah dibayar pelanggan untuk pesanan yang tidak jadi. Kembalikan uangnya lalu catat pengembaliannya.',
            $perlu->count(),
            '/kelola/toko-online',
            'Catat pengembalian',
            array_values($perlu->take(DataButirTindakan::BATAS_RINCIAN)->map(
                fn (PesananOnline $p): DataRincianTindakan => new DataRincianTindakan(
                    $p->Uuid,
                    $p->Nomor,
                    $p->AmbilSisaUangMuka()->FormatRupiah().', '.$p->Status->AmbilLabel(),
                    $p->DibayarPada?->setTimezone('Asia/Jakarta')->toDateString(),
                    '/kelola/toko-online?cari='.rawurlencode($p->Nomor),
                ),
            )->all()),
        );
    }

    public function AmbilJenisDokumen(): array
    {
        return ['Penjualan', 'ReturPenjualan', 'IsiDeposit', 'TagihanQris'];
    }

    public function SaringDokumen(string $jenisDokumen, array $uuid): array
    {
        return match ($jenisDokumen) {
            'Penjualan' => PembuatButirTinjauan::Saring($this->KueriPenjualan(null), 'Penjualan', $uuid),
            'ReturPenjualan' => PembuatButirTinjauan::Saring($this->KueriRetur(null), 'ReturPenjualan', $uuid),
            'IsiDeposit' => PembuatButirTinjauan::Saring($this->KueriIsiDeposit(null), 'IsiDeposit', $uuid),
            'TagihanQris' => PembuatButirTinjauan::Saring($this->KueriTagihanQris(null), 'TagihanQris', $uuid),
            default => [],
        };
    }

    /**
     * @param  list<int>|null  $idOutlet
     * @return Builder<Penjualan>
     */
    private function KueriPenjualan(?array $idOutlet): Builder
    {
        return Penjualan::query()->where('Penjualan.PerluTinjauan', true)->when($idOutlet !== null, fn ($k) => $k->whereIn('Penjualan.IdOutlet', $idOutlet));
    }

    /**
     * @param  list<int>|null  $idOutlet
     * @return Builder<ReturPenjualan>
     */
    private function KueriRetur(?array $idOutlet): Builder
    {
        return ReturPenjualan::query()->where('ReturPenjualan.PerluTinjauan', true)->when($idOutlet !== null, fn ($k) => $k->whereIn('ReturPenjualan.IdOutlet', $idOutlet));
    }

    /**
     * @param  list<int>|null  $idOutlet
     * @return Builder<TagihanQris>
     */
    private function KueriTagihanQris(?array $idOutlet): Builder
    {
        return TagihanQris::query()->where('TagihanQris.PerluTinjauan', true)->whereNull('TagihanQris.UuidPenjualan')->where('TagihanQris.Sumber', SumberTagihanQris::Pos->value)
            ->when($idOutlet !== null, fn ($k) => $k->whereIn('TagihanQris.IdOutlet', $idOutlet));
    }

    /**
     * @param  list<int>|null  $idOutlet
     * @return Builder<IsiDeposit>
     */
    private function KueriIsiDeposit(?array $idOutlet): Builder
    {
        return IsiDeposit::query()->where('IsiDeposit.PerluTinjauan', true)->when($idOutlet !== null, fn ($k) => $k->whereIn('IsiDeposit.IdOutlet', $idOutlet));
    }
}
