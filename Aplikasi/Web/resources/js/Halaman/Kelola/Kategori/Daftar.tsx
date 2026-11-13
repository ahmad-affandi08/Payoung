import { router, useForm, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import Tombol from '@/Komponen/Formulir/Tombol';
import DaftarGalatServer from '@/Komponen/Katalog/DaftarGalatServer';
import PesanHanyaLihat from '@/Komponen/Katalog/PesanHanyaLihat';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import DialogKonfirmasi from '@/Komponen/Tindakan/DialogKonfirmasi';
import { Button } from '@/Komponen/Ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/Komponen/Ui/dialog';
import { DropdownMenuItem } from '@/Komponen/Ui/dropdown-menu';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import type { PropsDaftarKategori } from '@/Tipe/Katalog';

type Kategori = PropsDaftarKategori['Kategori'][number];

/** Kedalaman maksimal kategori (config katalog.Kategori.MaksimalKedalaman). */
export const MaksimalKedalamanKategori = 3;

/** Uuid kategori beserta seluruh turunannya (tidak boleh dipilih sebagai induk barunya sendiri). */
export function AmbilTurunanKategori(kategori: Kategori[], uuid: string): Set<string> {
    const hasil = new Set([uuid]);
    let bertambah = true;

    while (bertambah) {
        bertambah = false;

        for (const item of kategori) {
            if (item.UuidInduk !== null && hasil.has(item.UuidInduk) && !hasil.has(item.Uuid)) {
                hasil.add(item.Uuid);
                bertambah = true;
            }
        }
    }

    return hasil;
}

/** Indentasi baris menurut tingkat kategori (1–3). */
function KelasIndentasi(kedalaman: number): string {
    return kedalaman >= 3 ? 'pl-10' : kedalaman === 2 ? 'pl-5' : '';
}

function FormKategori({
    kategori,
    semua,
    opsiStasiun,
    saatSelesai,
}: {
    kategori: Kategori | null;
    semua: Kategori[];
    opsiStasiun: PropsDaftarKategori['OpsiStasiunDapur'];
    saatSelesai: () => void;
}) {
    // F-10a: stasiun dapur hanya dikirim bila tenant punya stasiun (form lama tetap tidak mengubah rujukan).
    const adaStasiun = opsiStasiun.length > 0 || kategori?.UuidStasiunDapur != null;
    const formulir = useForm<{
        Nama: string;
        UuidInduk: string | null;
        Urutan: string;
        UuidStasiunDapur?: string | null;
    }>({
        Nama: kategori?.Nama ?? '',
        UuidInduk: kategori?.UuidInduk ?? null,
        Urutan: kategori ? String(kategori.Urutan) : '0',
        ...(adaStasiun ? { UuidStasiunDapur: kategori?.UuidStasiunDapur ?? null } : {}),
    });
    const opsiStasiunForm =
        kategori?.UuidStasiunDapur && !opsiStasiun.some((o) => o.Nilai === kategori.UuidStasiunDapur)
            ? [
                  ...opsiStasiun,
                  { Nilai: kategori.UuidStasiunDapur, Label: `${kategori.NamaStasiunDapur ?? 'Stasiun'} (diarsipkan)` },
              ]
            : opsiStasiun;
    const galat = formulir.errors as Record<string, string | undefined>;
    const terlarang = kategori ? AmbilTurunanKategori(semua, kategori.Uuid) : new Set<string>();
    const opsiInduk = semua.filter((item) => item.Kedalaman < MaksimalKedalamanKategori && !terlarang.has(item.Uuid));

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        const opsi = { preserveScroll: true, onSuccess: saatSelesai };

        if (kategori === null) {
            formulir.post('/kelola/kategori', opsi);
        } else {
            formulir.put(`/kelola/kategori/${kategori.Uuid}`, opsi);
        }
    };

    return (
        <form
            onSubmit={Kirim}
            noValidate
            aria-label={kategori ? `Ubah kategori ${kategori.Nama}` : 'Tambah kategori'}
            className="flex flex-col gap-4"
        >
            <BidangTeks
                label="Nama kategori"
                nilai={formulir.data.Nama}
                saatBerubah={(nilai) => formulir.setData('Nama', nilai)}
                galat={galat.Nama}
                maxLength={100}
                autoFocus
                required
            />
            <BidangPilihan
                label="Induk kategori"
                nilai={formulir.data.UuidInduk ?? ''}
                kosong="Tanpa induk (tingkat teratas)"
                opsi={opsiInduk.map((item) => ({ Nilai: item.Uuid, Label: item.Jalur }))}
                saatBerubah={(nilai) => formulir.setData('UuidInduk', nilai === '' ? null : nilai)}
                galat={galat.UuidInduk}
            />
            <BidangTeks
                label="Urutan tampil"
                nilai={formulir.data.Urutan}
                saatBerubah={(nilai) => formulir.setData('Urutan', nilai.replace(/\D/g, ''))}
                galat={galat.Urutan}
                inputMode="numeric"
                maxLength={4}
                keterangan="Angka kecil tampil lebih dulu di kasir."
            />
            {adaStasiun ? (
                <BidangPilihan
                    label="Stasiun dapur"
                    nilai={formulir.data.UuidStasiunDapur ?? ''}
                    kosong="Stasiun bawaan"
                    opsi={opsiStasiunForm}
                    saatBerubah={(nilai) => formulir.setData('UuidStasiunDapur', nilai === '' ? null : nilai)}
                    galat={galat.UuidStasiunDapur}
                />
            ) : null}
            <DialogFooter>
                <Tombol type="button" varian="sekunder" onClick={saatSelesai}>
                    Batal
                </Tombol>
                <Tombol type="submit" memproses={formulir.processing}>
                    Simpan kategori
                </Tombol>
            </DialogFooter>
        </form>
    );
}

const kolom: KolomTabel<Kategori>[] = [
    {
        id: 'Jalur',
        accessorKey: 'Jalur',
        header: 'Kategori',
        meta: { label: 'Kategori', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: item } }) => (
            <>
                <span className={`block font-semibold break-words text-teks-utama ${KelasIndentasi(item.Kedalaman)}`}>
                    {item.Nama}
                </span>
                {item.Kedalaman > 1 ? (
                    <span className={`block text-keterangan text-teks-sekunder ${KelasIndentasi(item.Kedalaman)}`}>
                        {item.Jalur}
                    </span>
                ) : null}
            </>
        ),
    },
    {
        id: 'Urutan',
        accessorKey: 'Urutan',
        header: 'Urutan',
        meta: { label: 'Urutan tampil', angka: true, prioritas: 'rendah', kelasSel: 'text-teks-sekunder' },
    },
    {
        id: 'NamaStasiunDapur',
        accessorFn: (item) => item.NamaStasiunDapur ?? '',
        header: 'Stasiun dapur',
        meta: { label: 'Stasiun dapur', prioritas: 'rendah', kelasSel: 'text-teks-sekunder' },
        cell: ({ row: { original: item } }) => item.NamaStasiunDapur ?? '—',
    },
    {
        id: 'JumlahProduk',
        accessorKey: 'JumlahProduk',
        header: 'Produk',
        meta: { label: 'Jumlah produk', angka: true, prioritas: 'penting' },
    },
];

/** F-03 kategori bertingkat (maks 3 tingkat). Hapus hanya bila tanpa sub-kategori dan tanpa produk. */
export default function HalamanDaftarKategori({ Kategori, OpsiStasiunDapur, Izin }: PropsDaftarKategori) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const [sunting, AturSunting] = useState<Kategori | 'baru' | null>(null);
    const [hapus, AturHapus] = useState<Kategori | null>(null);
    const CekPunyaAnak = (uuid: string) => Kategori.some((item) => item.UuidInduk === uuid);

    return (
        <TataLetakAplikasi judul="Kategori produk">
            <p className="text-keterangan text-teks-sekunder">
                Kelompokkan produk sampai 3 tingkat, misal Minuman › Kopi › Kopi susu.
            </p>
            {!Izin.Kelola ? <PesanHanyaLihat izin="produk.kelola" objek="kategori" /> : null}
            <DaftarGalatServer
                galat={props.errors}
                kecuali={sunting !== null ? ['Nama', 'UuidInduk', 'Urutan', 'UuidStasiunDapur'] : []}
            />
            <AksiHalaman>
                {Izin.Kelola ? (
                    <Button type="button" onClick={() => AturSunting('baru')}>
                        Tambah kategori
                    </Button>
                ) : null}
            </AksiHalaman>
            <Dialog open={sunting !== null} onOpenChange={(buka) => (buka ? undefined : AturSunting(null))}>
                {sunting !== null ? (
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>
                                {sunting === 'baru' ? 'Tambah kategori' : `Ubah kategori ${sunting.Nama}`}
                            </DialogTitle>
                            <DialogDescription>
                                Kategori bisa bertingkat sampai 3 tingkat, misal Minuman › Kopi › Kopi susu.
                            </DialogDescription>
                        </DialogHeader>
                        <FormKategori
                            key={sunting === 'baru' ? 'baru' : sunting.Uuid}
                            kategori={sunting === 'baru' ? null : sunting}
                            semua={Kategori}
                            opsiStasiun={OpsiStasiunDapur}
                            saatSelesai={() => AturSunting(null)}
                        />
                    </DialogContent>
                ) : null}
            </Dialog>
            {hapus !== null ? (
                <DialogKonfirmasi
                    judul={`Hapus kategori ${hapus.Nama}?`}
                    labelAksi="Hapus kategori"
                    saatBatal={() => AturHapus(null)}
                    saatKonfirmasi={() =>
                        router.delete(`/kelola/kategori/${hapus.Uuid}`, {
                            preserveScroll: true,
                            onFinish: () => AturHapus(null),
                        })
                    }
                >
                    Kategori ini tidak punya sub-kategori dan tidak dipakai produk mana pun.
                </DialogKonfirmasi>
            ) : null}
            <TabelData
                id="katalog-kategori"
                label="Daftar kategori"
                kolom={kolom}
                sumber={{ mode: 'lokal', data: Kategori }}
                ambilIdBaris={(item) => item.Uuid}
                cari="Cari nama kategori"
                labelBaris={(item) => item.Nama}
                {...(Izin.Kelola
                    ? {
                          aksiBaris: (item: Kategori) => (
                              <>
                                  <DropdownMenuItem onSelect={() => AturSunting(item)}>Ubah kategori</DropdownMenuItem>
                                  {item.JumlahProduk === 0 && !CekPunyaAnak(item.Uuid) ? (
                                      <DropdownMenuItem variant="destructive" onSelect={() => AturHapus(item)}>
                                          Hapus kategori
                                      </DropdownMenuItem>
                                  ) : null}
                              </>
                          ),
                      }
                    : {})}
                kosong={{
                    ilustrasi: true,
                    judul: 'Belum ada kategori. Tambah kategori agar produk mudah dicari di kasir.',
                }}
            />
        </TataLetakAplikasi>
    );
}
