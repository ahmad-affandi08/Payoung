<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola\Persediaan;

use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Bersama\Tabel\Data\DataPermintaanTabel;
use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Katalog\Enum\PelacakanProduk;
use App\Domain\Katalog\Kueri\InfoProdukStok;
use App\Domain\Persediaan\Aksi\BatalkanOrderProduksi;
use App\Domain\Persediaan\Aksi\PostingOrderProduksi;
use App\Domain\Persediaan\Aksi\SimpanOrderProduksi;
use App\Domain\Persediaan\Enum\StatusOrderProduksi;
use App\Domain\Persediaan\Kueri\DaftarOrderProduksi;
use App\Domain\Persediaan\Kueri\DetailOrderProduksi;
use App\Domain\Persediaan\Layanan\PenyusunBahanProduksi;
use App\Domain\Persediaan\Model\OrderProduksi;
use App\Http\Permintaan\Kelola\Persediaan\SimpanOrderProduksiPermintaan;
use App\Http\Respons\ResponsTabel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Halaman & aksi order produksi F-05e (routes/PersediaanDokumen.php): daftar, form draf (bahan dari resep), detail,
 * posting (stok + jurnal J-05.6), batalkan (draf, atau tercatat dengan pembalik). Lokasi di luar outlet pelaku = 404.
 */
final class OrderProduksiKontroler extends DasarDokumenPersediaanKontroler
{
    private const ALAMAT = 'kelola.persediaan.produksi.detail';

    public function Daftar(Request $permintaan, DaftarOrderProduksi $daftar): Response|JsonResponse
    {
        $tabel = DataPermintaanTabel::Dari($permintaan->query(), DaftarOrderProduksi::KOLOM_URUT, DaftarOrderProduksi::URUT_BAWAAN, DaftarOrderProduksi::KOLOM_SARING);

        return ResponsTabel::Kirim($permintaan, 'Kelola/Persediaan/Produksi/Daftar', 'Order', fn (): array => $daftar->AmbilTabel($tabel, $this->IdOutletBoleh()), fn (): array => [
            'OpsiGudang' => $this->AmbilOpsiGudangDokumen(hanyaAktif: false),
            'OpsiStatus' => array_map(fn (StatusOrderProduksi $s): array => ['Nilai' => $s->value, 'Label' => $s->AmbilLabel()], StatusOrderProduksi::cases()),
            'Izin' => $this->AmbilIzinDokumen(),
        ]);
    }

    /**
     * Formulir baru. Query `produk`, `gudang`, `jumlah`, `tanggal` (dari tab Rencana produksi, §9.11) mengisi formulir
     * lebih dulu; nilai yang tidak sah atau di luar akses diabaikan diam-diam dan formulir tampil kosong seperti biasa.
     */
    public function Buat(Request $permintaan, InfoProdukStok $infoProduk): Response
    {
        return $this->RenderForm('Buat', null, $this->AmbilAwal($permintaan, $infoProduk));
    }

    /** Kebutuhan bahan standar dari resep versi terbaru untuk jumlah hasil (dipakai formulir, JSON). */
    public function Resep(Request $permintaan, InfoProdukStok $infoProduk, PenyusunBahanProduksi $penyusun): JsonResponse
    {
        $valid = $permintaan->validate([
            'produk' => ['required', 'ulid'],
            'jumlah' => ['required', 'regex:/^\d{1,14}(\.\d{1,4})?$/'],
        ]);
        $produk = $infoProduk->AmbilDariUuid([(string) $valid['produk']])[(string) $valid['produk']] ?? null;
        abort_if($produk === null, 404);
        $susunan = $penyusun->Susun($produk->id, Kuantitas::Dari((string) $valid['jumlah']), null);

        return response()->json([
            'VersiResep' => $susunan['resep']?->versi,
            'Bahan' => array_map(fn (array $b): array => [
                'UuidProduk' => $b['info']->uuid,
                'NamaProduk' => $b['info']->nama,
                'Sku' => $b['info']->sku,
                'SimbolSatuan' => $b['info']->simbolSatuan,
                'BolehDesimal' => $b['info']->bolehDesimal,
                'JumlahStandar' => $b['standar']->KeString(),
                'Jumlah' => $b['jumlah']->KeDesimal()->strippedOfTrailingZeros()->__toString(),
            ], $susunan['bahan']),
        ]);
    }

    public function Simpan(SimpanOrderProduksiPermintaan $permintaan, SimpanOrderProduksi $simpan): RedirectResponse
    {
        $gudang = $this->CariGudangBoleh((string) $permintaan->validated('UuidGudang'));
        $order = $simpan->Jalankan($permintaan->AmbilData($gudang->id), null);

        return redirect()->route(self::ALAMAT, ['orderProduksi' => $order->Uuid])
            ->with('Kilat', 'Draf order produksi disimpan. Periksa bahan, lalu catat supaya stok dan jurnal tercatat.');
    }

    public function Detail(string $orderProduksi, DetailOrderProduksi $detail): Response
    {
        $o = $this->CariOrder($orderProduksi);
        $izin = $this->AmbilIzinDokumen();
        $draf = $o->Status === StatusOrderProduksi::Draf;

        return Inertia::render('Kelola/Persediaan/Produksi/Detail', [
            ...$detail->Ambil($o),
            'Tindakan' => [
                'Ubah' => $izin['Kelola'] && $draf,
                'Posting' => $izin['Kelola'] && $draf,
                'Batalkan' => $izin['Kelola'] && $o->Status !== StatusOrderProduksi::Dibatalkan,
                'WajibAlasanBatal' => $o->Status === StatusOrderProduksi::Diposting,
            ],
            'Izin' => $izin,
        ]);
    }

    public function Ubah(string $orderProduksi, DetailOrderProduksi $detail): Response|RedirectResponse
    {
        $o = $this->CariOrder($orderProduksi);

        if ($o->Status !== StatusOrderProduksi::Draf) {
            return redirect()->route(self::ALAMAT, ['orderProduksi' => $o->Uuid])->withErrors(['Umum' => "Order produksi berstatus {$o->Status->AmbilLabel()} tidak bisa diubah."]);
        }

        return $this->RenderForm('Ubah', $detail->AmbilForm($o));
    }

    public function Perbarui(SimpanOrderProduksiPermintaan $permintaan, string $orderProduksi, SimpanOrderProduksi $simpan): RedirectResponse
    {
        $o = $this->CariOrder($orderProduksi);
        $gudang = $this->CariGudangBoleh((string) $permintaan->validated('UuidGudang'));
        $o = $simpan->Jalankan($permintaan->AmbilData($gudang->id), $o);

        return redirect()->route(self::ALAMAT, ['orderProduksi' => $o->Uuid])->with('Kilat', 'Draf order produksi disimpan.');
    }

    public function Posting(string $orderProduksi, PostingOrderProduksi $posting): RedirectResponse
    {
        $o = $posting->Jalankan($this->CariOrder($orderProduksi), $this->Pelaku()->Id);

        return redirect()->route(self::ALAMAT, ['orderProduksi' => $o->Uuid])->with('Kilat', "Produksi {$o->Nomor} dicatat. Bahan berkurang, hasil bertambah, dan jurnal sudah dicatat.");
    }

    public function Batalkan(Request $permintaan, string $orderProduksi, BatalkanOrderProduksi $batalkan): RedirectResponse
    {
        $alasan = $permintaan->validate(['Alasan' => ['nullable', 'string', 'max:255']])['Alasan'] ?? null;
        $o = $batalkan->Jalankan($this->CariOrder($orderProduksi), is_string($alasan) ? $alasan : null, $this->Pelaku()->Id);
        $pesan = $o->Nomor === null ? 'Draf order produksi dibatalkan. Stok tidak berubah.' : "Produksi {$o->Nomor} dibatalkan. Stok dan jurnal sudah dibalik.";

        return redirect()->route(self::ALAMAT, ['orderProduksi' => $o->Uuid])->with('Kilat', $pesan);
    }

    private function CariOrder(string $uuid): OrderProduksi
    {
        $o = OrderProduksi::query()->where('Uuid', $uuid)->firstOrFail();
        abort_unless($this->CekBolehOutlet($o->IdOutlet), 404);

        return $o;
    }

    /**
     * @return array{UuidGudang: string, Tanggal: string|null, JumlahHasil: string, Produk: array<string, mixed>}|null
     */
    private function AmbilAwal(Request $permintaan, InfoProdukStok $infoProduk): ?array
    {
        $valid = validator($permintaan->query(), [
            'produk' => ['required', 'ulid'],
            'gudang' => ['required', 'ulid'],
            'jumlah' => ['required', 'regex:/^\d{1,14}(\.\d{1,4})?$/'],
            'tanggal' => ['nullable', 'date_format:Y-m-d'],
        ]);

        if ($valid->fails()) {
            return null;
        }

        $uuidProduk = (string) $valid->validated()['produk'];
        $produk = $infoProduk->AmbilDariUuid([$uuidProduk])[$uuidProduk] ?? null;
        $gudangBoleh = array_values(array_filter($this->AmbilOpsiGudangDokumen(), fn (array $g): bool => $g['Uuid'] === $valid->validated()['gudang']));

        if ($produk === null || $produk->jenis !== JenisProduk::Produksi || $produk->diarsipkan || $produk->dihapus || $produk->pelacakan === PelacakanProduk::Seri || $gudangBoleh === []) {
            return null;
        }

        return [
            'UuidGudang' => (string) $valid->validated()['gudang'],
            'Tanggal' => isset($valid->validated()['tanggal']) ? (string) $valid->validated()['tanggal'] : null,
            'JumlahHasil' => Kuantitas::Dari((string) $valid->validated()['jumlah'])->KeDesimal()->strippedOfTrailingZeros()->__toString(),
            'Produk' => [
                'Uuid' => $produk->uuid,
                'Nama' => $produk->nama,
                'Sku' => $produk->sku,
                'SimbolSatuan' => $produk->simbolSatuan,
                'BolehDesimal' => $produk->bolehDesimal,
                'Pelacakan' => $produk->pelacakan->value,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $order
     * @param  array<string, mixed>|null  $awal
     */
    private function RenderForm(string $mode, ?array $order, ?array $awal = null): Response
    {
        return Inertia::render('Kelola/Persediaan/Produksi/Form', [
            'Mode' => $mode,
            'Order' => $order,
            'Awal' => $awal,
            'OpsiGudang' => $this->AmbilOpsiGudangDokumen(),
            'HariIni' => $this->HariIni(),
            'MaksBahan' => PenyusunBahanProduksi::MAKS_BAHAN,
            'WajibKedaluwarsaBatch' => (bool) config('persediaan.StokAwal.WajibKedaluwarsaBatch', true),
        ]);
    }
}
