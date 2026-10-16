<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola\Invoice;

use App\Domain\Akuntansi\Kueri\DaftarAkunPilihan;
use App\Domain\Bersama\Laporan\JenisKolom;
use App\Domain\Bersama\Laporan\KolomLaporan;
use App\Domain\Bersama\Tabel\Data\DataPermintaanTabel;
use App\Domain\Katalog\Kueri\CariProdukInvoice;
use App\Domain\Organisasi\Enum\IzinTenant;
use App\Domain\Organisasi\Kueri\AksesPengguna;
use App\Domain\Organisasi\Kueri\PetaUuidOutlet;
use App\Domain\Organisasi\Kueri\TanggalBisnisOutlet;
use App\Domain\Pajak\Kueri\DaftarKelompokPajak;
use App\Domain\Pelanggan\Kueri\DaftarPilihanPelanggan;
use App\Domain\Penjualan\Aksi\AntrekanPengirimanInvoice;
use App\Domain\Penjualan\Aksi\BatalkanInvoice;
use App\Domain\Penjualan\Aksi\CatatPembayaranInvoice;
use App\Domain\Penjualan\Aksi\ProsesInvoiceMassal;
use App\Domain\Penjualan\Aksi\SimpanInvoice;
use App\Domain\Penjualan\Aksi\TerbitkanInvoice;
use App\Domain\Penjualan\Aksi\TolakKonfirmasiPembayaranInvoice;
use App\Domain\Penjualan\Enum\JenisInvoice;
use App\Domain\Penjualan\Enum\JenisPengirimanInvoice;
use App\Domain\Penjualan\Enum\StatusInvoice;
use App\Domain\Penjualan\Kueri\DaftarInvoice;
use App\Domain\Penjualan\Kueri\DetailInvoice;
use App\Domain\Penjualan\Kueri\DokumenInvoice;
use App\Domain\Penjualan\Kueri\IsianFormInvoice;
use App\Domain\Penjualan\Kueri\RingkasanInvoice;
use App\Domain\Penjualan\Model\Invoice;
use App\Http\Kontroler\Kelola\DasarKelolaKontroler;
use App\Http\Permintaan\Kelola\Invoice\AlasanInvoicePermintaan;
use App\Http\Permintaan\Kelola\Invoice\CatatPembayaranInvoicePermintaan;
use App\Http\Permintaan\Kelola\Invoice\SimpanInvoicePermintaan;
use App\Http\Respons\ResponsTabel;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Invoice (F-21, D-166, `/kelola/invoice`): daftar, form draf (jasa & barang tanpa stok), detail, terbit, batal,
 * pembayaran (termasuk PPh yang dipotong pelanggan), kirim WhatsApp, dan cetak A4. Invoice di outlet di luar akses
 * pelaku = 404. Harga barisnya dikirim klien (kesepakatan dengan pelanggan), tetapi total, pajak, PPh, dan potongan uang
 * muka dihitung server.
 */
final class InvoiceKontroler extends DasarKelolaKontroler
{
    public function Daftar(Request $permintaan, DaftarInvoice $daftar, RingkasanInvoice $ringkasan): Response|JsonResponse
    {
        $tabel = DataPermintaanTabel::Dari($permintaan->query(), DaftarInvoice::KOLOM_URUT, DaftarInvoice::URUT_BAWAAN, DaftarInvoice::KOLOM_SARING);
        $hariIni = $this->HariIni();

        return ResponsTabel::Kirim($permintaan, 'Kelola/Invoice/Daftar', 'Invoice', fn (): array => $daftar->Ambil($tabel, $this->IdOutletBoleh(), $hariIni), fn (): array => [
            'OpsiStatus' => array_map(fn (StatusInvoice $s): array => ['Nilai' => $s->value, 'Label' => $s->AmbilLabel()], StatusInvoice::cases()),
            'OpsiJenis' => array_map(fn (JenisInvoice $j): array => ['Nilai' => $j->value, 'Label' => $j->AmbilLabel()], JenisInvoice::cases()),
            'Izin' => $this->AmbilIzinInvoice(),
            'HariIni' => $hariIni,
            'Ringkasan' => $ringkasan->Ambil(CarbonImmutable::parse($hariIni), $this->IdOutletBoleh()),
        ]);
    }

    /** Ekspor daftar invoice sesuai saringan & cari yang aktif di tabel (Excel, CSV, atau cetak; maks. 5.000 baris). */
    public function Ekspor(Request $permintaan, DaftarInvoice $daftar): SymfonyResponse
    {
        $tabel = DataPermintaanTabel::Dari($permintaan->query(), DaftarInvoice::KOLOM_URUT, DaftarInvoice::URUT_BAWAAN, DaftarInvoice::KOLOM_SARING);
        $hariIni = $this->HariIni();
        $idOutlet = $this->IdOutletBoleh();
        $baris = $this->AmbilSemuaBarisTabel($tabel, fn (DataPermintaanTabel $t): array => $daftar->Ambil($t, $idOutlet, $hariIni));
        $kolom = [
            new KolomLaporan('Nomor', JenisKolom::Teks, 24), new KolomLaporan('Jenis', JenisKolom::Teks, 16),
            new KolomLaporan('Pelanggan', JenisKolom::Teks, 30), new KolomLaporan('Perihal', JenisKolom::Teks, 30),
            new KolomLaporan('Tanggal', JenisKolom::Tanggal), new KolomLaporan('Jatuh tempo', JenisKolom::Tanggal),
            new KolomLaporan('Status', JenisKolom::Teks, 18), new KolomLaporan('Total', JenisKolom::Uang, jumlahkan: true),
            new KolomLaporan('Tagihan', JenisKolom::Uang, jumlahkan: true), new KolomLaporan('Sisa', JenisKolom::Uang, jumlahkan: true),
        ];
        $isi = array_map(fn (array $b): array => [
            $b['Nomor'] ?? 'Draf', $b['LabelJenis'], $b['NamaPelanggan'], $b['Perihal'] ?? '', $b['Tanggal'], $b['JatuhTempo'], $b['LabelStatusBayar'], $b['Total'], $b['Tagihan'], $b['Sisa'] ?? '0.00',
        ], $baris);

        return $this->SajikanLaporan($permintaan, 'Daftar Invoice', 'daftar-invoice', $kolom, $isi, [['Per tanggal', CarbonImmutable::parse($hariIni)->format('d/m/Y')]]);
    }

    public function Buat(Request $permintaan, IsianFormInvoice $isian): Response
    {
        $jenis = JenisInvoice::tryFrom((string) $permintaan->query('jenis', JenisInvoice::Penjualan->value)) ?? JenisInvoice::Penjualan;

        return $this->RenderForm(null, $jenis, $isian);
    }

    public function Simpan(SimpanInvoicePermintaan $permintaan, SimpanInvoice $simpan): RedirectResponse
    {
        $outlet = $this->CariOutlet((string) $permintaan->validated('UuidOutlet'));
        $invoice = $simpan->Jalankan($permintaan->AmbilData($outlet->Id), $this->Pelaku()->Id);
        $hasil = to_route('kelola.invoice.detail', ['invoice' => $invoice->Uuid])->with('Kilat', 'Draf invoice disimpan. Periksa angkanya, lalu terbitkan.');

        return $this->LanjutkanSetelahSimpan($permintaan, $hasil, fn () => $this->Terbitkan($invoice->Uuid, app(TerbitkanInvoice::class)), 'kelola.invoice.detail', ['invoice' => $invoice->Uuid]);
    }

    public function Detail(string $invoice, DetailInvoice $detail, DaftarAkunPilihan $akun): Response
    {
        $dokumen = $this->CariInvoice($invoice);
        $izin = $this->AmbilIzinInvoice();
        $hariIni = $this->HariIni();
        $isi = $detail->Ambil($dokumen, $hariIni);
        $terbit = $dokumen->Status === StatusInvoice::Terbit;
        $terbuka = $terbit && ($isi['Invoice']['Sisa'] ?? null) !== null && $isi['Invoice']['Sisa'] !== '0.00';

        return Inertia::render('Kelola/Invoice/Detail', [
            ...$isi,
            'Izin' => $izin,
            'HariIni' => $hariIni,
            'OpsiAkun' => array_map(fn (array $a): array => ['Uuid' => $a['Uuid'], 'Kode' => $a['Kode'], 'Nama' => $a['Nama']], $izin['Kelola'] && $terbuka ? $akun->AmbilKasBank() : []),
            'Tindakan' => [
                'Ubah' => $izin['Kelola'] && $dokumen->Status->CekBolehDiubah(),
                'Terbitkan' => $izin['Kelola'] && $dokumen->Status === StatusInvoice::Draf,
                'Batalkan' => $izin['Kelola'] && $dokumen->Status !== StatusInvoice::Dibatalkan,
                'CatatPembayaran' => $izin['Kelola'] && $terbuka,
                'Kirim' => $izin['Kelola'] && $terbit,
                'Ingatkan' => $izin['Kelola'] && $terbuka,
            ],
        ]);
    }

    public function Ubah(string $invoice, IsianFormInvoice $isian): Response|RedirectResponse
    {
        $dokumen = $this->CariInvoice($invoice);

        if (! $dokumen->Status->CekBolehDiubah()) {
            return to_route('kelola.invoice.detail', ['invoice' => $dokumen->Uuid])
                ->withErrors(['Umum' => "Invoice berstatus {$dokumen->Status->AmbilLabel()} tidak bisa diubah. Batalkan lalu buat invoice baru."]);
        }

        return $this->RenderForm($isian->Draf($dokumen), $dokumen->Jenis, $isian);
    }

    public function Perbarui(SimpanInvoicePermintaan $permintaan, string $invoice, SimpanInvoice $simpan): RedirectResponse
    {
        $dokumen = $this->CariInvoice($invoice);
        $outlet = $this->CariOutlet((string) $permintaan->validated('UuidOutlet'));
        $simpan->Jalankan($permintaan->AmbilData($outlet->Id), $this->Pelaku()->Id, $dokumen->Uuid);
        $hasil = to_route('kelola.invoice.detail', ['invoice' => $dokumen->Uuid])->with('Kilat', 'Draf invoice disimpan.');

        return $this->LanjutkanSetelahSimpan($permintaan, $hasil, fn () => $this->Terbitkan($dokumen->Uuid, app(TerbitkanInvoice::class)), 'kelola.invoice.detail', ['invoice' => $dokumen->Uuid]);
    }

    public function TerbitkanDraf(string $invoice, TerbitkanInvoice $terbitkan): RedirectResponse
    {
        return $this->Terbitkan($this->CariInvoice($invoice)->Uuid, $terbitkan);
    }

    public function Batalkan(AlasanInvoicePermintaan $permintaan, string $invoice, BatalkanInvoice $batalkan): RedirectResponse
    {
        $dokumen = $this->CariInvoice($invoice);
        $hasil = $batalkan->Jalankan($dokumen->Uuid, $permintaan->AmbilAlasan(), $this->Pelaku()->Id);

        return to_route('kelola.invoice.detail', ['invoice' => $hasil->Uuid])->with('Kilat', "Invoice {$hasil->Nomor} dibatalkan.");
    }

    public function CatatPembayaran(CatatPembayaranInvoicePermintaan $permintaan, string $invoice, CatatPembayaranInvoice $catat): RedirectResponse
    {
        $dokumen = $this->CariInvoice($invoice);
        $pembayaran = $catat->Jalankan(
            $dokumen->Uuid,
            (string) $permintaan->validated('UuidAkun'),
            $permintaan->AmbilTanggal(),
            $permintaan->AmbilJumlah(),
            $permintaan->AmbilPotonganPph(),
            is_string($permintaan->validated('NomorBuktiPotong')) && trim((string) $permintaan->validated('NomorBuktiPotong')) !== '' ? trim((string) $permintaan->validated('NomorBuktiPotong')) : null,
            is_string($permintaan->validated('Catatan')) && trim((string) $permintaan->validated('Catatan')) !== '' ? trim((string) $permintaan->validated('Catatan')) : null,
            $this->Pelaku()->Id,
            is_string($permintaan->validated('UuidKonfirmasi')) && $permintaan->validated('UuidKonfirmasi') !== '' ? (string) $permintaan->validated('UuidKonfirmasi') : null,
        );

        return to_route('kelola.invoice.detail', ['invoice' => $dokumen->Uuid])->with('Kilat', "Pembayaran {$pembayaran->Nomor} dicatat.");
    }

    /** Menolak klaim "sudah bayar" dari pelanggan (uangnya tidak ditemukan, jumlah tidak cocok, dan sebagainya). */
    public function TolakKonfirmasi(AlasanInvoicePermintaan $permintaan, string $invoice, string $konfirmasi, TolakKonfirmasiPembayaranInvoice $tolak): RedirectResponse
    {
        $dokumen = $this->CariInvoice($invoice);
        $tolak->Jalankan($dokumen->Uuid, $konfirmasi, $permintaan->AmbilAlasan(), $this->Pelaku()->Id);

        return to_route('kelola.invoice.detail', ['invoice' => $dokumen->Uuid])->with('Kilat', 'Konfirmasi pembayaran ditolak.');
    }

    /** Kirim invoice (atau pengingatnya, `?jenis=pengingat`) ke WhatsApp pelanggan; kiriman berjalan di antrean. */
    public function Kirim(Request $permintaan, string $invoice, AntrekanPengirimanInvoice $antrekan): RedirectResponse
    {
        $dokumen = $this->CariInvoice($invoice);
        $jenis = $permintaan->input('Jenis') === JenisPengirimanInvoice::Pengingat->value ? JenisPengirimanInvoice::Pengingat : JenisPengirimanInvoice::Invoice;
        $antrekan->Jalankan($this->IdTenant(), $dokumen, $this->Pelaku()->Id, $jenis);

        return to_route('kelola.invoice.detail', ['invoice' => $dokumen->Uuid])
            ->with('Kilat', $jenis === JenisPengirimanInvoice::Pengingat ? 'Pengingat dikirim ke WhatsApp pelanggan.' : 'Invoice dikirim ke WhatsApp pelanggan.');
    }

    /** Aksi massal daftar: terbitkan draf, kirim WhatsApp, atau kirim pengingat; yang tidak bisa diproses dilewati dengan alasannya. */
    public function ProsesMassal(Request $permintaan, string $aksi, ProsesInvoiceMassal $proses): RedirectResponse
    {
        $valid = $permintaan->validate([
            'Uuid' => ['required', 'array', 'min:1', 'max:'.ProsesInvoiceMassal::MAKS],
            'Uuid.*' => ['required', 'ulid'],
        ], attributes: ['Uuid' => 'invoice terpilih']);
        /** @var list<string> $uuid */
        $uuid = array_values($valid['Uuid']);
        $hasil = $proses->Jalankan($aksi, $this->IdTenant(), $uuid, $this->Pelaku()->Id, $this->IdOutletBoleh());
        $rincian = trim(implode(' ', array_map(fn (string $alasan, int $jumlah): string => "{$jumlah} dilewati: {$alasan}", array_keys($hasil['Dilewati']), array_values($hasil['Dilewati']))));
        $kata = match ($aksi) {
            ProsesInvoiceMassal::AKSI_TERBITKAN => 'invoice diterbitkan',
            ProsesInvoiceMassal::AKSI_KIRIM => 'invoice sedang dikirim lewat WhatsApp',
            default => 'pengingat sedang dikirim lewat WhatsApp',
        };

        if ($hasil['Berhasil'] === 0) {
            return back()->withErrors(['Umum' => trim("Tidak ada invoice yang diproses. {$rincian}")]);
        }

        return back()->with('Kilat', trim("{$hasil['Berhasil']} {$kata}. {$rincian}"));
    }

    /** Cetak A4: satu keluaran dengan halaman publik, jadi yang dicetak sama dengan yang dilihat pelanggan. */
    public function Cetak(string $invoice, DokumenInvoice $dokumen): Response
    {
        $model = $this->CariInvoice($invoice);
        abort_if($model->Status === StatusInvoice::Draf && ! $this->CekIzinInvoice(IzinTenant::InvoiceKelola), 404);

        return Inertia::render('Kelola/Invoice/Cetak', [...$dokumen->Ambil($model, $this->HariIni()), 'Uuid' => $model->Uuid]);
    }

    public function CariProduk(Request $permintaan, CariProdukInvoice $cari): JsonResponse
    {
        return response()->json(['Data' => $cari->Cari((string) $permintaan->query('kata', ''), (int) $permintaan->query('batas', '20'))]);
    }

    public function CariPelanggan(Request $permintaan, DaftarPilihanPelanggan $cari): JsonResponse
    {
        return response()->json(['Data' => $cari->Cari((string) $permintaan->query('kata', ''))]);
    }

    public function UangMukaTersedia(Request $permintaan, IsianFormInvoice $isian): JsonResponse
    {
        $untuk = $permintaan->query('untuk');

        return response()->json(['Data' => $isian->UangMukaTersedia((string) $permintaan->query('pelanggan', ''), is_string($untuk) && $untuk !== '' ? $untuk : null)]);
    }

    private function Terbitkan(string $uuid, TerbitkanInvoice $terbitkan): RedirectResponse
    {
        $hasil = $terbitkan->Jalankan($uuid, $this->Pelaku()->Id);

        return to_route('kelola.invoice.detail', ['invoice' => $hasil->Uuid])->with('Kilat', "Invoice {$hasil->Nomor} diterbitkan. Kirim ke pelanggan lewat WhatsApp atau bagikan tautannya.");
    }

    private function CariInvoice(string $uuid): Invoice
    {
        $dokumen = Invoice::query()->where('Uuid', $uuid)->firstOrFail();
        $boleh = $this->IdOutletBoleh();
        abort_if($boleh !== null && ! in_array($dokumen->IdOutlet, $boleh, true), 404);

        return $dokumen;
    }

    private function HariIni(): string
    {
        return app(TanggalBisnisOutlet::class)->Hitung(null)->format('Y-m-d');
    }

    private function CekIzinInvoice(IzinTenant $izin): bool
    {
        return app(AksesPengguna::class)->CekIzin($this->IdTenant(), $this->Pelaku()->Id, $izin);
    }

    /**
     * @return array{Kelola: bool, LihatJurnal: bool}
     */
    private function AmbilIzinInvoice(): array
    {
        return [
            'Kelola' => $this->CekIzinInvoice(IzinTenant::InvoiceKelola),
            'LihatJurnal' => $this->CekIzinInvoice(IzinTenant::LaporanKeuanganLihat),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $isian
     */
    private function RenderForm(?array $isian, JenisInvoice $jenis, IsianFormInvoice $kueri): Response
    {
        $peta = app(PetaUuidOutlet::class);
        $ringkas = $peta->AmbilRingkas($this->IdOutletBoleh(), true);
        $identitas = $peta->AmbilIdentitas(array_column($ringkas, 'Id'));

        return Inertia::render('Kelola/Invoice/Form', [
            'Isian' => $isian,
            'Jenis' => $jenis->value,
            'Kebiasaan' => $isian === null ? $kueri->Kebiasaan() : null,
            'OpsiOutlet' => array_map(fn (array $o): array => [...$o, 'Kode' => $identitas[$o['Id']]['Kode'] ?? ''], $ringkas),
            'OpsiKelompokPajak' => array_map(fn (array $k): array => ['Uuid' => $k['Uuid'], 'Nama' => $k['Nama']], app(DaftarKelompokPajak::class)->AmbilOpsi()),
            'UangMukaAwal' => $isian === null ? [] : $kueri->UangMukaTersedia((string) $isian['UuidPelanggan'], (string) $isian['Uuid']),
            'HariIni' => $this->HariIni(),
            'Izin' => $this->AmbilIzinInvoice(),
        ]);
    }
}
