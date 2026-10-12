<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Pengelola\Tagihan;

use App\Domain\Bersama\Tabel\Data\DataPermintaanTabel;
use App\Domain\Pengelola\Tagihan\Aksi\BukaBuktiPembayaran;
use App\Domain\Pengelola\Tagihan\Aksi\TerimaPembayaranLangganan;
use App\Domain\Pengelola\Tagihan\Aksi\TerimaPembayaranLanggananMassal;
use App\Domain\Pengelola\Tagihan\Aksi\TolakPembayaranLangganan;
use App\Domain\Pengelola\Tagihan\Kueri\DaftarTagihanPlatform;
use App\Domain\Pengelola\Tagihan\Kueri\LaporanLanggananPlatform;
use App\Domain\Pengelola\Tagihan\Layanan\BatasRefundLangganan;
use App\Domain\Pengelola\TimInternal\Enum\IzinPengelola;
use App\Domain\Pengelola\TimInternal\Model\PenggunaPengelola;
use App\Domain\Tenant\Enum\StatusTagihanLangganan;
use App\Domain\Tenant\Kueri\TagihanLanggananTenant;
use App\Domain\Tenant\Model\PembayaranLangganan;
use App\Http\Kontroler\Kontroler;
use App\Http\Kontroler\Pengelola\PelakuPengelola;
use App\Http\Permintaan\Pengelola\Tagihan\TerimaPembayaranPermintaan;
use App\Http\Permintaan\Pengelola\Tagihan\TolakPembayaranPermintaan;
use App\Http\Respons\ResponsTabel;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Tagihan langganan & antrean verifikasi transfer manual (P-08, §19.3: Keuangan & Super Admin).
 */
final class TagihanKontroler extends Kontroler
{
    use PelakuPengelola;

    public function __construct(private readonly DaftarTagihanPlatform $kueri, private readonly BatasRefundLangganan $batasRefund) {}

    public function Daftar(Request $permintaan): Response|JsonResponse
    {
        $tabel = DataPermintaanTabel::Dari($permintaan->query(), DaftarTagihanPlatform::KOLOM_URUT, '-TerbitPada', DaftarTagihanPlatform::KOLOM_SARING);

        return ResponsTabel::Kirim($permintaan, 'Pengelola/Tagihan/Daftar', 'Tagihan', fn (): array => $this->kueri->AmbilTabel($tabel), fn (): array => [
            'Antrean' => $this->kueri->AmbilAntrean(),
            'Ringkasan' => $this->kueri->HitungRingkasan(),
            'BolehVerifikasi' => $this->AmbilPelaku()->PunyaIzin(IzinPengelola::TagihanVerifikasi),
            'RefundMenunggu' => $this->kueri->AmbilRefundMenunggu(),
            'BatasRefund' => $this->batasRefund->Ambil()->KeString(),
            'BolehSetujuiRefund' => $this->AmbilPelaku()->PunyaIzin(IzinPengelola::TagihanRefundSetujui),
            'OpsiStatus' => array_map(fn (StatusTagihanLangganan $pilihan): array => ['Nilai' => $pilihan->value, 'Label' => $pilihan->AmbilLabel()], StatusTagihanLangganan::cases()),
        ]);
    }

    /**
     * P-08 langkah 7 (PRD v4.09): MRR, ARR, churn, piutang & umur, pendapatan per paket/sektor. Periode bawaan bulan
     * berjalan (WIB); tanggal tidak sah kembali ke bawaan, rentang paling panjang 366 hari.
     */
    public function Laporan(Request $permintaan, LaporanLanggananPlatform $laporan): Response
    {
        $hariIni = CarbonImmutable::now('Asia/Jakarta')->startOfDay();
        $sampai = self::BacaTanggal($permintaan->query('sampai')) ?? $hariIni;
        $dari = self::BacaTanggal($permintaan->query('dari')) ?? $sampai->startOfMonth();

        if ($dari->greaterThan($sampai) || $dari->diffInDays($sampai) > 366) {
            $dari = $sampai->startOfMonth();
        }

        return Inertia::render('Pengelola/Tagihan/Laporan', [
            'Saring' => ['Dari' => $dari->toDateString(), 'Sampai' => $sampai->toDateString()],
            'HariMasaTenggang' => (int) config('tagihan.HariMasaTenggang'),
            ...$laporan->Ambil($dari->setTimezone('Asia/Jakarta'), $sampai->setTimezone('Asia/Jakarta')),
        ]);
    }

    private static function BacaTanggal(mixed $nilai): ?CarbonImmutable
    {
        if (! is_string($nilai) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $nilai) !== 1) {
            return null;
        }

        $tanggal = CarbonImmutable::createFromFormat('!Y-m-d', $nilai, 'Asia/Jakarta');

        return $tanggal instanceof CarbonImmutable && $tanggal->toDateString() === $nilai ? $tanggal : null;
    }

    public function Tampilkan(string $tagihan): Response
    {
        $data = $this->kueri->CariTagihan($tagihan);
        abort_if($data === null, 404);
        $pelaku = $this->AmbilPelaku();
        $pembayaran = $this->kueri->AmbilPembayaranTagihan($data);
        $namaVerifikator = PenggunaPengelola::query()
            ->whereKey(array_values(array_filter(array_map(fn (PembayaranLangganan $baris): ?int => $baris->IdPenggunaPengelolaVerifikator, $pembayaran))))
            ->pluck('Nama', 'Id')
            ->all();

        return Inertia::render('Pengelola/Tagihan/Detail', [
            'Tagihan' => [
                ...TagihanLanggananTenant::PetakanTagihan($data),
                'NamaTenant' => $this->kueri->AmbilNamaTenant([$data->IdTenant])[$data->IdTenant] ?? '—',
            ],
            'Pembayaran' => array_map(fn (PembayaranLangganan $baris): array => [
                ...TagihanLanggananTenant::PetakanPembayaran($baris),
                'JumlahDiterima' => $baris->JumlahDiterima,
                'Verifikator' => $baris->IdPenggunaPengelolaVerifikator === null ? null : ($namaVerifikator[$baris->IdPenggunaPengelolaVerifikator] ?? null),
            ], $pembayaran),
            'Refund' => $this->kueri->AmbilRefundTagihan($data),
            'Izin' => [
                'Terbitkan' => $pelaku->PunyaIzin(IzinPengelola::TagihanTerbitkan),
                'Refund' => $pelaku->PunyaIzin(IzinPengelola::TagihanRefund),
                'SetujuiRefund' => $pelaku->PunyaIzin(IzinPengelola::TagihanRefundSetujui),
            ],
            'BatasRefundTanpaPersetujuan' => $this->batasRefund->Ambil()->KeString(),
        ]);
    }

    public function LihatBukti(string $pembayaran, BukaBuktiPembayaran $buka): StreamedResponse
    {
        $data = $buka->Jalankan($this->AmbilPelaku(), $pembayaran);
        abort_if($data === null || $data->PathBukti === null, 404);

        return Storage::disk((string) config('tagihan.DiskBukti'))->response($data->PathBukti, 'bukti-transfer.'.pathinfo($data->PathBukti, PATHINFO_EXTENSION), [
            'Content-Type' => $data->MimeBukti ?? 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function Terima(string $pembayaran, TerimaPembayaranPermintaan $permintaan, TerimaPembayaranLangganan $terima): RedirectResponse
    {
        $terima->Jalankan($this->AmbilPelaku(), $pembayaran, $permintaan->AmbilJumlah(), $permintaan->AmbilCatatan());

        return back()->with('Kilat', 'Pembayaran diterima. Tagihan lunas dan langganan tenant aktif.');
    }

    /** Aksi massal antrean verifikasi: terima banyak bukti transfer yang sudah dicocokkan dengan mutasi rekening. */
    public function TerimaMassal(Request $permintaan, TerimaPembayaranLanggananMassal $terima): RedirectResponse
    {
        $valid = $permintaan->validate([
            'Uuid' => ['required', 'array', 'min:1', 'max:'.TerimaPembayaranLanggananMassal::MAKS],
            'Uuid.*' => ['required', 'string', 'size:26'],
            'SudahDicocokkan' => ['required', 'accepted'],
            'Catatan' => ['nullable', 'string', 'max:500'],
        ], [
            'SudahDicocokkan.*' => 'Centang bahwa setiap bukti sudah dicocokkan dengan mutasi rekening.',
        ], ['Uuid' => 'pembayaran terpilih']);
        /** @var list<string> $uuid */
        $uuid = array_values($valid['Uuid']);
        $catatan = is_string($valid['Catatan'] ?? null) && trim($valid['Catatan']) !== '' ? trim($valid['Catatan']) : null;
        $hasil = $terima->Jalankan($this->AmbilPelaku(), $uuid, true, $catatan);
        $rincian = [];

        foreach ($hasil['Dilewati'] as $sebab => $jumlah) {
            $rincian[] = "{$jumlah} dilewati: {$sebab}";
        }

        $pesan = trim("{$hasil['Diterima']} pembayaran diterima, tagihan lunas dan langganan aktif. ".implode(' ', $rincian));

        return $hasil['Diterima'] === 0 ? back()->withErrors(['Umum' => "Tidak ada pembayaran yang diterima. {$pesan}"]) : back()->with('Kilat', $pesan);
    }

    public function Tolak(string $pembayaran, TolakPembayaranPermintaan $permintaan, TolakPembayaranLangganan $tolak): RedirectResponse
    {
        $tolak->Jalankan($this->AmbilPelaku(), $pembayaran, $permintaan->AmbilAlasan());

        return back()->with('Kilat', 'Pembayaran ditolak. Pemilik usaha diberi tahu lewat email.');
    }
}
