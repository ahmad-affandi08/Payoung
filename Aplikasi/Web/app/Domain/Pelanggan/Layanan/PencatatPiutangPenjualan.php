<?php

declare(strict_types=1);

namespace App\Domain\Pelanggan\Layanan;

use App\Domain\Bersama\Dokumen\Layanan\PencatatRiwayatStatus;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Pelanggan\Enum\StatusPembayaranPiutang;
use App\Domain\Pelanggan\Enum\StatusPiutang;
use App\Domain\Pelanggan\Model\Pelanggan;
use App\Domain\Pelanggan\Model\PembayaranPiutang;
use App\Domain\Pelanggan\Model\PembayaranPiutangAlokasi;
use App\Domain\Pelanggan\Model\Piutang;
use Carbon\CarbonImmutable;
use LogicException;

/**
 * Layanan publik domain Pelanggan untuk domain Penjualan (F-12): piutang dicatat, dibatalkan (void), dan dikurangi
 * (retur) di transaksi DB yang sama dengan dokumen sumbernya. Jatuh tempo = tanggal dokumen + termin pelanggan (bawaan
 * 30 hari). Idempoten per dokumen sumber.
 *
 * Dua sumber (BR-12.5): **penjualan tempo** di kasir (`Catat*`) dan **faktur penjualan grosir** (`*Faktur`). Keduanya
 * memakai satu tabel `Piutang` yang sama, sehingga aging, pengingat, dan pelunasan tidak perlu tahu asalnya.
 */
final class PencatatPiutangPenjualan
{
    public const TERMIN_BAWAAN = 30;

    public function __construct(private readonly PencatatRiwayatStatus $riwayat) {}

    public function Catat(?int $idPelanggan, int $idPenjualan, int $idOutlet, string $nomor, CarbonImmutable $tanggalBisnis, Uang $jumlah): Piutang
    {
        $ada = Piutang::query()->where('IdPenjualan', $idPenjualan)->first();

        if ($ada !== null) {
            return $ada;
        }

        $termin = $idPelanggan === null ? self::TERMIN_BAWAAN : (int) (Pelanggan::query()->whereKey($idPelanggan)->value('TerminHari') ?? self::TERMIN_BAWAAN);
        $piutang = Piutang::query()->create([
            'IdPelanggan' => $idPelanggan,
            'IdPenjualan' => $idPenjualan,
            'IdOutlet' => $idOutlet,
            'Nomor' => $nomor,
            'TanggalBisnis' => $tanggalBisnis->toDateString(),
            'JatuhTempo' => $tanggalBisnis->addDays($termin)->toDateString(),
            'Jumlah' => $jumlah->KeString(),
            'Status' => StatusPiutang::BelumLunas,
        ]);
        $this->riwayat->Catat(Piutang::JENIS_DOKUMEN, $piutang->Id, null, StatusPiutang::BelumLunas->value, null);

        return $piutang;
    }

    /**
     * Piutang dari faktur penjualan grosir (BR-12.5): jatuh tempo = tanggal faktur + `Pelanggan.TerminHari`. Terminnya
     * dikirim pemanggil karena faktur sudah men-snapshot nilainya di kolomnya sendiri, sehingga jatuh tempo dokumen
     * tidak bergeser bila termin pelanggan diubah kemudian.
     */
    public function CatatFaktur(int $idPelanggan, int $idFakturPenjualan, int $idOutlet, string $nomor, CarbonImmutable $tanggal, Uang $jumlah, int $terminHari): Piutang
    {
        $ada = Piutang::query()->where('IdFakturPenjualan', $idFakturPenjualan)->first();

        if ($ada !== null) {
            return $ada;
        }

        $piutang = Piutang::query()->create([
            'IdPelanggan' => $idPelanggan,
            'IdFakturPenjualan' => $idFakturPenjualan,
            'IdOutlet' => $idOutlet,
            'Nomor' => $nomor,
            'TanggalBisnis' => $tanggal->toDateString(),
            'JatuhTempo' => $tanggal->addDays(max(0, $terminHari))->toDateString(),
            'Jumlah' => $jumlah->KeString(),
            'Status' => StatusPiutang::BelumLunas,
        ]);
        $this->riwayat->Catat(Piutang::JENIS_DOKUMEN, $piutang->Id, null, StatusPiutang::BelumLunas->value, null);

        return $piutang;
    }

    /** Pembatalan faktur hanya boleh bila piutangnya belum dibayar sedikit pun (null = boleh). */
    public function PeriksaBisaBatalFaktur(int $idFakturPenjualan): ?string
    {
        $piutang = Piutang::query()->where('IdFakturPenjualan', $idFakturPenjualan)->first();

        if ($piutang?->Status === StatusPiutang::DihapusBuku) {
            return self::PesanDihapusBuku($piutang);
        }

        return $piutang !== null && ! Uang::Dari($piutang->JumlahDibayar)->BernilaiNol()
            ? "Piutang {$piutang->Nomor} sudah dibayar sebagian. Batalkan pelunasannya dulu atau terbitkan nota kredit."
            : null;
    }

    /** E8: piutang yang sudah dihapus-buku tidak boleh dibatalkan/diretur/dikreditkan sebelum hapus-bukunya dibatalkan. */
    private static function PesanDihapusBuku(Piutang $piutang): string
    {
        return "Piutang {$piutang->Nomor} sudah dihapus-buku. Batalkan hapus-bukunya dulu di halaman Piutang.";
    }

    /** Pembatalan faktur: sisa piutangnya dikurangi habis dan statusnya Dibatalkan. */
    public function BatalkanFaktur(int $idFakturPenjualan, int $idPengguna): void
    {
        $piutang = Piutang::query()->where('IdFakturPenjualan', $idFakturPenjualan)->lockForUpdate()->first();

        if ($piutang === null || $piutang->Status === StatusPiutang::Dibatalkan) {
            return;
        }

        $asal = $piutang->Status;
        $piutang->JumlahDikurangi = Uang::Dari($piutang->JumlahDikurangi)->Tambah($piutang->AmbilSisa())->KeString();
        $piutang->Status = StatusPiutang::Dibatalkan;
        $piutang->save();
        $this->riwayat->Catat(Piutang::JENIS_DOKUMEN, $piutang->Id, $asal->value, StatusPiutang::Dibatalkan->value, $idPengguna, 'Faktur penjualan dibatalkan');
    }

    /** Sisa piutang faktur (null = fakturnya belum berpiutang). */
    public function AmbilSisaFaktur(int $idFakturPenjualan): ?Uang
    {
        return Piutang::query()->where('IdFakturPenjualan', $idFakturPenjualan)->first()?->AmbilSisa();
    }

    /**
     * Sisa & status piutang beberapa faktur sekaligus, untuk daftar faktur grosir di back-office.
     *
     * @param  array<mixed>  $idFakturPenjualan  nilai bukan int diabaikan
     * @return array<int, array{Sisa: string, Status: string, LabelStatus: string, JatuhTempo: string}>
     */
    public function AmbilPiutangBanyakFaktur(array $idFakturPenjualan): array
    {
        $id = array_values(array_unique(array_filter($idFakturPenjualan, 'is_int')));

        if ($id === []) {
            return [];
        }

        $hasil = [];

        foreach (Piutang::query()->whereIn('IdFakturPenjualan', $id)->get() as $piutang) {
            $hasil[(int) $piutang->IdFakturPenjualan] = [
                'Sisa' => $piutang->AmbilSisa()->KeString(),
                'Status' => $piutang->Status->value,
                'LabelStatus' => $piutang->Status->AmbilLabel(),
                'JatuhTempo' => $piutang->JatuhTempo->toDateString(),
            ];
        }

        return $hasil;
    }

    /**
     * Nota kredit (BR-12.7): sisa piutang faktur grosir dikurangi sebesar nilai retur. Mengembalikan pesan penolakan
     * bila sisanya tidak cukup, dan **tidak** mengubah apa pun dalam kasus itu.
     *
     * Kenapa ditolak alih-alih dipotong sebisanya: piutang bersisa negatif berarti toko berutang ke pembeli, dan itu
     * refund kas — bukan nota kredit. Memotong sebisanya akan menyembunyikan kewajiban itu di dokumen yang salah.
     */
    public function KurangiFaktur(int $idFakturPenjualan, Uang $jumlah, int $idPengguna, string $alasan): ?string
    {
        $piutang = Piutang::query()->where('IdFakturPenjualan', $idFakturPenjualan)->lockForUpdate()->first();

        if ($piutang === null) {
            return 'Faktur ini belum punya piutang, jadi tidak ada tagihan yang bisa dikurangi.';
        }

        if ($piutang->Status === StatusPiutang::Dibatalkan) {
            return "Piutang {$piutang->Nomor} sudah dibatalkan.";
        }

        if ($piutang->Status === StatusPiutang::DihapusBuku) {
            return self::PesanDihapusBuku($piutang);
        }

        if ($jumlah->Bandingkan($piutang->AmbilSisa()) > 0) {
            return "Nilai retur {$jumlah->FormatRupiah()} melebihi sisa tagihan {$piutang->AmbilSisa()->FormatRupiah()} di piutang {$piutang->Nomor}. "
                .'Fakturnya sudah (hampir) dibayar, jadi pengembaliannya berupa refund uang, bukan pengurangan tagihan.';
        }

        $asal = $piutang->Status;
        $piutang->JumlahDikurangi = Uang::Dari($piutang->JumlahDikurangi)->Tambah($jumlah)->KeString();
        $piutang->SelaraskanStatus();
        $piutang->save();

        if ($asal !== $piutang->Status) {
            $this->riwayat->Catat(Piutang::JENIS_DOKUMEN, $piutang->Id, $asal->value, $piutang->Status->value, $idPengguna, $alasan);
        }

        return null;
    }

    /**
     * Nota kredit dengan pengembalian uang (BR-12.7): mengurangi sisa tagihan faktur sebanyak yang masih ada (paling
     * banyak [jumlah]) dan mengembalikan **bagian yang dikurangi**. Selisihnya (`jumlah − dikurangi`) adalah uang yang
     * harus dikembalikan ke pembeli oleh pemanggil. Piutang yang tidak ada/dibatalkan/dihapus buku tetap ditolak
     * dengan pesan karena tidak ada tagihan yang bisa dikurangi.
     *
     * @return array{0: Uang, 1: string|null} [bagian yang mengurangi tagihan, pesan penolakan bila tidak bisa]
     */
    public function KurangiFakturSebisanya(int $idFakturPenjualan, Uang $jumlah, int $idPengguna, string $alasan): array
    {
        $piutang = Piutang::query()->where('IdFakturPenjualan', $idFakturPenjualan)->lockForUpdate()->first();

        if ($piutang === null) {
            return [Uang::Nol(), 'Faktur ini belum punya piutang, jadi tidak ada tagihan yang bisa dikurangi.'];
        }

        if ($piutang->Status === StatusPiutang::Dibatalkan) {
            return [Uang::Nol(), "Piutang {$piutang->Nomor} sudah dibatalkan."];
        }

        if ($piutang->Status === StatusPiutang::DihapusBuku) {
            return [Uang::Nol(), self::PesanDihapusBuku($piutang)];
        }

        $sisa = $piutang->AmbilSisa();
        $dikurangi = $jumlah->Bandingkan($sisa) > 0 ? ($sisa->BernilaiNegatif() ? Uang::Nol() : $sisa) : $jumlah;

        if ($dikurangi->BernilaiNol()) {
            return [$dikurangi, null];
        }

        $asal = $piutang->Status;
        $piutang->JumlahDikurangi = Uang::Dari($piutang->JumlahDikurangi)->Tambah($dikurangi)->KeString();
        $piutang->SelaraskanStatus();
        $piutang->save();

        if ($asal !== $piutang->Status) {
            $this->riwayat->Catat(Piutang::JENIS_DOKUMEN, $piutang->Id, $asal->value, $piutang->Status->value, $idPengguna, $alasan);
        }

        return [$dikurangi, null];
    }

    /** Pembatalan nota kredit: pengurangan tagihan dikembalikan sebesar nilai retur yang dibatalkan. */
    public function BatalkanPenguranganFaktur(int $idFakturPenjualan, Uang $jumlah, int $idPengguna, string $alasan): void
    {
        $piutang = Piutang::query()->where('IdFakturPenjualan', $idFakturPenjualan)->lockForUpdate()->first();

        // Piutang yang sudah dibatalkan (fakturnya dibatalkan) tidak dihidupkan kembali dari sini: yang membatalkannya
        // sudah menghapus seluruh sisanya, dan menambahkannya kembali akan membuat tagihan yang tidak ada dokumennya.
        if ($piutang === null || $jumlah->BernilaiNol() || $piutang->Status === StatusPiutang::Dibatalkan) {
            return;
        }

        $asal = $piutang->Status;
        $sisaPengurangan = Uang::Dari($piutang->JumlahDikurangi)->Kurangi($jumlah);
        $piutang->JumlahDikurangi = ($sisaPengurangan->BernilaiNegatif() ? Uang::Nol() : $sisaPengurangan)->KeString();
        $piutang->SelaraskanStatus();
        $piutang->save();

        if ($asal !== $piutang->Status) {
            $this->riwayat->Catat(Piutang::JENIS_DOKUMEN, $piutang->Id, $asal->value, $piutang->Status->value, $idPengguna, $alasan);
        }
    }

    /**
     * Piutang dari invoice (F-21, D-166): jumlahnya `Invoice.Tagihan` (total dikurangi potongan uang muka), jatuh tempo
     * = tanggal invoice + termin yang di-snapshot invoice. Idempoten per invoice.
     */
    public function CatatInvoice(int $idPelanggan, int $idInvoice, int $idOutlet, string $nomor, CarbonImmutable $tanggal, Uang $jumlah, int $terminHari): Piutang
    {
        $ada = Piutang::query()->where('IdInvoice', $idInvoice)->first();

        if ($ada !== null) {
            return $ada;
        }

        $piutang = Piutang::query()->create([
            'IdPelanggan' => $idPelanggan,
            'IdInvoice' => $idInvoice,
            'IdOutlet' => $idOutlet,
            'Nomor' => $nomor,
            'TanggalBisnis' => $tanggal->toDateString(),
            'JatuhTempo' => $tanggal->addDays(max(0, $terminHari))->toDateString(),
            'Jumlah' => $jumlah->KeString(),
            'Status' => StatusPiutang::BelumLunas,
        ]);
        $this->riwayat->Catat(Piutang::JENIS_DOKUMEN, $piutang->Id, null, StatusPiutang::BelumLunas->value, null);

        return $piutang;
    }

    /** Pembatalan invoice hanya boleh bila piutangnya belum dibayar sedikit pun (null = boleh). */
    public function PeriksaBisaBatalInvoice(int $idInvoice): ?string
    {
        $piutang = Piutang::query()->where('IdInvoice', $idInvoice)->first();

        if ($piutang?->Status === StatusPiutang::DihapusBuku) {
            return self::PesanDihapusBuku($piutang);
        }

        return $piutang !== null && ! Uang::Dari($piutang->JumlahDibayar)->BernilaiNol()
            ? "Piutang {$piutang->Nomor} sudah dibayar sebagian. Batalkan pelunasannya dulu."
            : null;
    }

    /** Pembatalan invoice: sisa piutangnya dikurangi habis dan statusnya Dibatalkan. */
    public function BatalkanInvoice(int $idInvoice, int $idPengguna): void
    {
        $piutang = Piutang::query()->where('IdInvoice', $idInvoice)->lockForUpdate()->first();

        if ($piutang === null || $piutang->Status === StatusPiutang::Dibatalkan) {
            return;
        }

        $asal = $piutang->Status;
        $piutang->JumlahDikurangi = Uang::Dari($piutang->JumlahDikurangi)->Tambah($piutang->AmbilSisa())->KeString();
        $piutang->Status = StatusPiutang::Dibatalkan;
        $piutang->save();
        $this->riwayat->Catat(Piutang::JENIS_DOKUMEN, $piutang->Id, $asal->value, StatusPiutang::Dibatalkan->value, $idPengguna, 'Invoice dibatalkan');
    }

    /**
     * Piutang beberapa invoice sekaligus (uuid piutang, sisa, status, jatuh tempo, PPh yang sudah dipotong), untuk daftar
     * dan detail invoice di back-office. Dibaca dari `Piutang`, tidak disalin ke invoice (satu sumber kebenaran).
     *
     * @param  array<mixed>  $idInvoice  nilai bukan int diabaikan
     * @return array<int, array{UuidPiutang: string, Sisa: string, Dibayar: string, Status: string, LabelStatus: string, JatuhTempo: string, Terbuka: bool}>
     */
    public function AmbilPiutangBanyakInvoice(array $idInvoice): array
    {
        $id = array_values(array_unique(array_filter($idInvoice, 'is_int')));

        if ($id === []) {
            return [];
        }

        $hasil = [];

        foreach (Piutang::query()->whereIn('IdInvoice', $id)->get() as $piutang) {
            $hasil[(int) $piutang->IdInvoice] = [
                'UuidPiutang' => $piutang->Uuid,
                'Sisa' => $piutang->AmbilSisa()->KeString(),
                'Dibayar' => $piutang->JumlahDibayar,
                'Status' => $piutang->Status->value,
                'LabelStatus' => $piutang->Status->AmbilLabel(),
                'JatuhTempo' => $piutang->JatuhTempo->toDateString(),
                'Terbuka' => $piutang->Status->CekTerbuka(),
            ];
        }

        return $hasil;
    }

    /**
     * Pelunasan atas satu piutang (alokasinya, bukan total pelunasan yang boleh mencakup piutang lain), terbaru dulu.
     * Untuk detail & kuitansi invoice (F-21).
     *
     * @return list<array{Uuid: string, Nomor: string, Tanggal: string, Jumlah: string, PotonganPph: string, NomorBuktiPotong: string|null, Status: string, LabelStatus: string}>
     */
    public function AmbilPelunasan(int $idPiutang): array
    {
        $alokasi = PembayaranPiutangAlokasi::query()->where('IdPiutang', $idPiutang)->get();

        if ($alokasi->isEmpty()) {
            return [];
        }

        $pembayaran = PembayaranPiutang::query()->whereIn('Id', $alokasi->pluck('IdPembayaranPiutang')->all())->orderByDesc('Tanggal')->orderByDesc('Id')->get();

        return array_values($pembayaran->map(function (PembayaranPiutang $p) use ($alokasi): array {
            $bagian = $alokasi->firstWhere('IdPembayaranPiutang', $p->Id) ?? throw new LogicException('Alokasi pelunasan piutang tidak ditemukan.');

            return [
                'Uuid' => $p->Uuid,
                'Nomor' => $p->Nomor,
                'Tanggal' => $p->Tanggal->toDateString(),
                'Jumlah' => (string) $bagian->Jumlah,
                'PotonganPph' => (string) $bagian->PotonganPph,
                'NomorBuktiPotong' => $p->NomorBuktiPotong,
                'Status' => $p->Status->value,
                'LabelStatus' => $p->Status->AmbilLabel(),
            ];
        })->all());
    }

    /**
     * Satu pelunasan atas piutang invoice untuk **kuitansi** (F-21 bagian 5): bagian yang dialokasikan ke piutang itu
     * (pelunasan boleh mencakup beberapa piutang), uang yang benar-benar masuk (dilunasi dikurangi PPh yang dipotong
     * pelanggan), dan sisa tagihan **sesudah** pembayaran ini (urut tanggal lalu Id; pelunasan dibatalkan tidak dihitung).
     * Null bila pelunasan itu tidak mengalokasikan apa pun ke piutang ini.
     *
     * @return array{Uuid: string, Nomor: string, Tanggal: string, Status: string, LabelStatus: string, Jumlah: string, PotonganPph: string, UangDiterima: string, NomorBuktiPotong: string|null, SisaSetelah: string, AlasanBatal: string|null}|null
     */
    public function AmbilPelunasanKuitansi(int $idPiutang, string $uuidPembayaran): ?array
    {
        $piutang = Piutang::query()->whereKey($idPiutang)->first();
        $pembayaran = PembayaranPiutang::query()->where('Uuid', $uuidPembayaran)->first();
        $bagian = $pembayaran === null ? null : PembayaranPiutangAlokasi::query()->where('IdPembayaranPiutang', $pembayaran->Id)->where('IdPiutang', $idPiutang)->first();

        if ($piutang === null || $pembayaran === null || $bagian === null) {
            return null;
        }

        $dibayarSampai = Uang::Nol();
        $diposting = PembayaranPiutang::query()
            ->where('Status', StatusPembayaranPiutang::Diposting->value)
            ->where(fn ($q) => $q->where('Tanggal', '<', $pembayaran->Tanggal->toDateString())
                ->orWhere(fn ($sama) => $sama->where('Tanggal', $pembayaran->Tanggal->toDateString())->where('Id', '<=', $pembayaran->Id)))
            ->pluck('Id');

        foreach (PembayaranPiutangAlokasi::query()->where('IdPiutang', $idPiutang)->whereIn('IdPembayaranPiutang', $diposting->all())->get() as $a) {
            $dibayarSampai = $dibayarSampai->Tambah(Uang::Dari((string) $a->Jumlah));
        }

        $dilunasi = Uang::Dari((string) $bagian->Jumlah);
        $pph = Uang::Dari((string) $bagian->PotonganPph);

        return [
            'Uuid' => $pembayaran->Uuid,
            'Nomor' => $pembayaran->Nomor,
            'Tanggal' => $pembayaran->Tanggal->toDateString(),
            'Status' => $pembayaran->Status->value,
            'LabelStatus' => $pembayaran->Status->AmbilLabel(),
            'Jumlah' => $dilunasi->KeString(),
            'PotonganPph' => $pph->KeString(),
            'UangDiterima' => $dilunasi->Kurangi($pph)->KeString(),
            'NomorBuktiPotong' => $pembayaran->NomorBuktiPotong,
            'SisaSetelah' => Uang::Dari((string) $piutang->Jumlah)->Kurangi($dibayarSampai)->KeString(),
            'AlasanBatal' => $pembayaran->Status === StatusPembayaranPiutang::Dibatalkan ? $pembayaran->AlasanBatal : null,
        ];
    }

    /**
     * Angka piutang bersumber invoice untuk ringkasan penagihan (F-21): piutang beredar (terbuka), yang sudah lewat jatuh
     * tempo per `$hariIni`, dan uang yang diterima (alokasi pelunasan berstatus diposting) dalam rentang `$dari`–`$sampai`
     * (inklusif, `YYYY-MM-DD`). Nilai uang string desimal; `$idOutlet` null = semua outlet.
     *
     * @param  list<int>|null  $idOutlet
     * @return array{Beredar: array{Jumlah: int, Sisa: string}, LewatTempo: array{Jumlah: int, Sisa: string}, Diterima: string}
     */
    public function RingkasanInvoice(?array $idOutlet, string $dari, string $sampai, string $hariIni): array
    {
        $terbuka = Piutang::query()
            ->whereNotNull('IdInvoice')
            ->whereIn('Status', [StatusPiutang::BelumLunas->value, StatusPiutang::DibayarSebagian->value])
            ->when($idOutlet !== null, fn ($q) => $q->whereIn('IdOutlet', $idOutlet ?? []))
            ->get();
        $beredar = Uang::Nol();
        $lewat = Uang::Nol();
        $jumlahLewat = 0;

        foreach ($terbuka as $p) {
            $sisa = $p->AmbilSisa();
            $beredar = $beredar->Tambah($sisa);

            if ($p->JatuhTempo->toDateString() < $hariIni) {
                $lewat = $lewat->Tambah($sisa);
                $jumlahLewat++;
            }
        }

        $diterima = Uang::Nol();
        $idPiutangInvoice = Piutang::query()
            ->whereNotNull('IdInvoice')
            ->when($idOutlet !== null, fn ($q) => $q->whereIn('IdOutlet', $idOutlet ?? []))
            ->pluck('Id');

        if ($idPiutangInvoice->isNotEmpty()) {
            $idPembayaran = PembayaranPiutang::query()
                ->where('Status', StatusPembayaranPiutang::Diposting->value)
                ->whereBetween('Tanggal', [$dari, $sampai])
                ->pluck('Id');

            foreach (PembayaranPiutangAlokasi::query()->whereIn('IdPembayaranPiutang', $idPembayaran->all())->whereIn('IdPiutang', $idPiutangInvoice->all())->pluck('Jumlah') as $jumlah) {
                $diterima = $diterima->Tambah(Uang::Dari((string) $jumlah));
            }
        }

        return [
            'Beredar' => ['Jumlah' => $terbuka->count(), 'Sisa' => $beredar->KeString()],
            'LewatTempo' => ['Jumlah' => $jumlahLewat, 'Sisa' => $lewat->KeString()],
            'Diterima' => $diterima->KeString(),
        ];
    }

    /** Sisa piutang penjualan (null = bukan penjualan tempo). */
    public function AmbilSisa(int $idPenjualan): ?Uang
    {
        return Piutang::query()->where('IdPenjualan', $idPenjualan)->first()?->AmbilSisa();
    }

    /** Void hanya boleh bila piutangnya belum dibayar (null = boleh). */
    public function PeriksaBisaBatal(int $idPenjualan): ?string
    {
        $piutang = Piutang::query()->where('IdPenjualan', $idPenjualan)->first();

        if ($piutang?->Status === StatusPiutang::DihapusBuku) {
            return self::PesanDihapusBuku($piutang);
        }

        return $piutang !== null && ! Uang::Dari($piutang->JumlahDibayar)->BernilaiNol()
            ? "Piutang {$piutang->Nomor} sudah dibayar sebagian. Gunakan retur."
            : null;
    }

    /** Retur penjualan tempo ditolak selama piutangnya dihapus-buku (null = boleh). */
    public function PeriksaBisaDiretur(int $idPenjualan): ?string
    {
        $piutang = Piutang::query()->where('IdPenjualan', $idPenjualan)->first();

        return $piutang?->Status === StatusPiutang::DihapusBuku ? self::PesanDihapusBuku($piutang) : null;
    }

    /** Void: sisa piutang dikurangi habis dan status Dibatalkan. */
    public function Batalkan(int $idPenjualan, int $idPengguna): void
    {
        $piutang = Piutang::query()->where('IdPenjualan', $idPenjualan)->lockForUpdate()->first();

        if ($piutang === null || $piutang->Status === StatusPiutang::Dibatalkan) {
            return;
        }

        $asal = $piutang->Status;
        $piutang->JumlahDikurangi = Uang::Dari($piutang->JumlahDikurangi)->Tambah($piutang->AmbilSisa())->KeString();
        $piutang->Status = StatusPiutang::Dibatalkan;
        $piutang->save();
        $this->riwayat->Catat(Piutang::JENIS_DOKUMEN, $piutang->Id, $asal->value, StatusPiutang::Dibatalkan->value, $idPengguna, 'Penjualan di-void');
    }

    /** Retur: sisa piutang dikurangi [jumlah] (≤ sisa, diperiksa pemanggil). */
    public function Kurangi(int $idPenjualan, Uang $jumlah, int $idPengguna): void
    {
        $piutang = Piutang::query()->where('IdPenjualan', $idPenjualan)->lockForUpdate()->first();

        if ($piutang === null || $jumlah->BernilaiNol()) {
            return;
        }

        $asal = $piutang->Status;
        $piutang->JumlahDikurangi = Uang::Dari($piutang->JumlahDikurangi)->Tambah($jumlah)->KeString();
        $piutang->SelaraskanStatus();
        $piutang->save();

        if ($asal !== $piutang->Status) {
            $this->riwayat->Catat(Piutang::JENIS_DOKUMEN, $piutang->Id, $asal->value, $piutang->Status->value, $idPengguna, 'Retur penjualan');
        }
    }
}
