<?php

declare(strict_types=1);

namespace App\Http\Respons;

use Inertia\Inertia;
use Inertia\Response;

/**
 * Halaman "pilih dokumen asal" yang seragam (D-287): saat sebuah dokumen turunan (retur, nota debit, biaya tambahan,
 * faktur) dibuka tanpa dokumen asalnya, pengguna memilih dari daftar dokumen hulu yang nyata, bukan dilempar ke daftar
 * dengan pesan galat. Satu halaman FE (`Kelola/Bersama/PilihDokumen`) melayani semuanya; kontroler hanya menyerahkan
 * teks, butir dari kueri hulu, dan nama rute form tujuan. Tautan tiap butir dibuat di sini supaya FE tidak merakit URL.
 */
final class HalamanPilihDokumen
{
    /**
     * @param  array{Judul: string, Petunjuk: string, JudulDaftar: string, LabelCari: string, LabelAksi: string, Kosong: string}  $teks
     * @param  list<array{Uuid: string, Nomor: string, Pihak: string, Tanggal: string, Rincian: string, Lencana: array{Jenis: string, Teks: string}|null}>  $butir
     * @param  string  $namaRute  rute form tujuan (misal `kelola.pembelian.retur.buat`)
     * @param  string  $kunci  nama parameter query yang membawa Uuid dokumen asal (misal `penerimaan`)
     * @param  array{Judul: string, Teks: string, Tautan: list<array{Teks: string, Href: string}>}|null  $alternatif  jalan lain yang sah (misal terima tanpa pesanan)
     */
    public static function Kirim(array $teks, array $butir, string $namaRute, string $kunci, ?array $alternatif = null): Response
    {
        return Inertia::render('Kelola/Bersama/PilihDokumen', [
            'Teks' => $teks,
            'Butir' => self::SertakanTautan($butir, fn (string $uuid): string => route($namaRute, [$kunci => $uuid], false)),
            'Alternatif' => $alternatif,
        ]);
    }

    /**
     * Menambahkan `Tautan` ke tiap butir. Dipakai juga oleh saran dokumen hulu di dalam form (PO, transfer stok,
     * invoice) yang tujuannya bukan selalu form buat, misal halaman rincian permintaan.
     *
     * @param  list<array{Uuid: string, Nomor: string, Pihak: string, Tanggal: string, Rincian: string, Lencana: array{Jenis: string, Teks: string}|null}>  $butir
     * @param  \Closure(string): string  $tautan  Uuid dokumen hulu menjadi alamat tujuan
     * @return list<array{Uuid: string, Nomor: string, Pihak: string, Tanggal: string, Rincian: string, Lencana: array{Jenis: string, Teks: string}|null, Tautan: string}>
     */
    public static function SertakanTautan(array $butir, \Closure $tautan): array
    {
        return array_map(fn (array $b): array => [...$b, 'Tautan' => $tautan($b['Uuid'])], $butir);
    }
}
