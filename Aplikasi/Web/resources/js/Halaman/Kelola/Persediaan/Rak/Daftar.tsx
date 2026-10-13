import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import Tombol from '@/Komponen/Formulir/Tombol';
import DaftarGalatServer from '@/Komponen/Katalog/DaftarGalatServer';
import PesanHanyaLihat from '@/Komponen/Katalog/PesanHanyaLihat';
import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import DialogFormulir from '@/Komponen/Tindakan/DialogFormulir';
import { ItemAksiBaris } from '@/Komponen/Tindakan/MenuAksiBaris';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import { FormatLabelGudang } from '@/Pustaka/FormatPersediaan';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import type { BarisRakGudang, PropsDaftarRakGudang } from '@/Tipe/DokumenPersediaan';

export const AlamatRak = '/kelola/persediaan/rak';

const kolom: KolomTabel<BarisRakGudang>[] = [
    {
        id: 'Nama',
        accessorFn: (r) => `${r.Kode} ${r.Nama}`,
        header: 'Rak',
        meta: { label: 'Rak', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: r } }) => (
            <>
                <Link href={`${AlamatRak}/${r.Uuid}`} className="font-semibold break-words text-brand underline">
                    {r.Nama}
                </Link>
                <span className="block font-mono text-keterangan text-teks-sekunder">{r.Kode}</span>
            </>
        ),
    },
    {
        id: 'NamaGudang',
        accessorKey: 'NamaGudang',
        header: 'Lokasi stok',
        meta: { label: 'Lokasi stok', prioritas: 'penting' },
        cell: ({ row: { original: r } }) => (
            <>
                <span className="block break-words text-teks-utama">{r.NamaGudang}</span>
                {r.NamaOutlet ? <span className="block text-keterangan text-teks-sekunder">{r.NamaOutlet}</span> : null}
            </>
        ),
    },
    {
        id: 'JumlahProduk',
        accessorKey: 'JumlahProduk',
        header: 'Produk',
        meta: { label: 'Jumlah produk', angka: true, prioritas: 'penting' },
        cell: ({ row }) => row.original.JumlahProduk.toLocaleString('id-ID'),
    },
    {
        id: 'Status',
        header: 'Status',
        enableSorting: false,
        meta: { label: 'Status', prioritas: 'penting' },
        cell: ({ row: { original: r } }) => (
            <span className="flex flex-col gap-1">
                <LabelStatus jenis={r.Aktif ? 'sukses' : 'netral'} teks={r.Aktif ? 'Aktif' : 'Nonaktif'} />
                {r.OpnameBerjalan ? (
                    <span className="font-mono text-keterangan text-teks-sekunder">Diopname {r.OpnameBerjalan}</span>
                ) : null}
            </span>
        ),
    },
];

type IsianRak = { Uuid: string | null; UuidGudang: string; Kode: string; Nama: string; Urutan: string };

/**
 * F-05c INV-07: daftar rak per lokasi stok. Rak menentukan cakupan opname per rak, jadi beberapa penghitung bisa
 * menghitung rak berbeda berbarengan. Halaman ini hidup di Pengaturan (diatur sekali, jarang disentuh).
 */
export default function HalamanDaftarRakGudang({ Rak, OpsiGudang, Saring, Izin }: PropsDaftarRakGudang) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const galat = props.errors;
    const [isian, AturIsian] = useState<IsianRak | null>(null);
    const [periksa, AturPeriksa] = useState(false);
    const [memproses, AturMemproses] = useState(false);

    const BukaBaru = () => {
        AturPeriksa(false);
        AturIsian({ Uuid: null, UuidGudang: Saring.Gudang, Kode: '', Nama: '', Urutan: '0' });
    };

    const Simpan = () => {
        AturPeriksa(true);

        if (isian === null || isian.Kode.trim() === '' || isian.Nama.trim() === '' || isian.UuidGudang === '') {
            return;
        }

        const data = {
            UuidGudang: isian.UuidGudang,
            Kode: isian.Kode.trim(),
            Nama: isian.Nama.trim(),
            Urutan: Number(isian.Urutan) || 0,
        };
        const opsi = {
            preserveScroll: true,
            onStart: () => AturMemproses(true),
            onFinish: () => AturMemproses(false),
            onSuccess: () => AturIsian(null),
        };

        if (isian.Uuid === null) {
            router.post(AlamatRak, data, opsi);
        } else {
            router.put(`${AlamatRak}/${isian.Uuid}`, data, opsi);
        }
    };

    const tombolTambah = Izin.Kelola ? <Tombol onClick={BukaBaru}>Tambah rak</Tombol> : null;

    return (
        <TataLetakAplikasi judul="Rak gudang">
            <p className="max-w-2xl text-isi text-teks-sekunder">
                Bagi lokasi stok menjadi rak atau zona lalu tempatkan produknya. Opname per rak hanya menghitung produk
                di rak itu, jadi beberapa orang bisa menghitung rak berbeda di waktu yang sama. Rak tidak menyimpan
                saldo: stok tetap dicatat per lokasi stok.
            </p>
            {!Izin.Kelola ? <PesanHanyaLihat izin="persediaan.kelola" objek="rak gudang" /> : null}
            {isian === null ? <DaftarGalatServer galat={galat} /> : null}
            <AksiHalaman>{tombolTambah}</AksiHalaman>
            <div className="max-w-sm">
                <BidangPilihan
                    label="Tampilkan lokasi stok"
                    nilai={Saring.Gudang}
                    kosong="Semua lokasi stok"
                    opsi={OpsiGudang.map((g) => ({ Nilai: g.Uuid, Label: FormatLabelGudang(g) }))}
                    saatBerubah={(nilai) =>
                        router.get(AlamatRak, nilai === '' ? {} : { gudang: nilai }, {
                            preserveScroll: true,
                            preserveState: true,
                        })
                    }
                />
            </div>
            <TabelData
                id="persediaan-rak"
                label="Daftar rak gudang"
                kolom={kolom}
                sumber={{ mode: 'lokal', data: Rak }}
                ambilIdBaris={(r) => r.Uuid}
                cari="Cari kode atau nama rak"
                alamatDetail={(r) => `${AlamatRak}/${r.Uuid}`}
                kosong={{
                    ilustrasi: true,
                    judul: 'Belum ada rak.',
                    ...(tombolTambah ? {} : { aksi: <span>Minta pengelola persediaan menambah rak.</span> }),
                }}
                {...(Izin.Kelola
                    ? {
                          aksiBaris: (r: BarisRakGudang) => (
                              <ItemAksiBaris
                                  aksi={[
                                      {
                                          label: 'Isi rak',
                                          saatPilih: () => router.visit(`${AlamatRak}/${r.Uuid}`),
                                      },
                                      {
                                          label: 'Ubah nama atau kode',
                                          saatPilih: () => {
                                              AturPeriksa(false);
                                              AturIsian({
                                                  Uuid: r.Uuid,
                                                  UuidGudang: r.UuidGudang,
                                                  Kode: r.Kode,
                                                  Nama: r.Nama,
                                                  Urutan: String(r.Urutan),
                                              });
                                          },
                                      },
                                      r.Aktif
                                          ? {
                                                label: 'Nonaktifkan',
                                                saatPilih: () =>
                                                    router.post(
                                                        `${AlamatRak}/${r.Uuid}/nonaktifkan`,
                                                        {},
                                                        { preserveScroll: true },
                                                    ),
                                            }
                                          : {
                                                label: 'Aktifkan kembali',
                                                saatPilih: () =>
                                                    router.post(
                                                        `${AlamatRak}/${r.Uuid}/aktifkan`,
                                                        {},
                                                        { preserveScroll: true },
                                                    ),
                                            },
                                  ]}
                              />
                          ),
                      }
                    : {})}
            />

            {isian !== null ? (
                <DialogFormulir
                    judul={isian.Uuid === null ? 'Tambah rak' : 'Ubah rak'}
                    keterangan="Kode dipakai di label dan lembar hitung, misalnya A-01."
                    galatUmum={galat.Umum}
                    saatTutup={() => AturIsian(null)}
                >
                    <div className="flex flex-col gap-3">
                        <DaftarGalatServer galat={galat} kecuali={['Umum', 'UuidGudang', 'Kode', 'Nama', 'Urutan']} />
                        {isian.Uuid === null ? (
                            <BidangPilihan
                                label="Lokasi stok"
                                nilai={isian.UuidGudang}
                                kosong="Pilih lokasi stok"
                                opsi={OpsiGudang.filter((g) => g.Aktif).map((g) => ({
                                    Nilai: g.Uuid,
                                    Label: FormatLabelGudang(g),
                                }))}
                                saatBerubah={(nilai) => AturIsian({ ...isian, UuidGudang: nilai })}
                                required
                                galat={
                                    galat.UuidGudang ??
                                    (periksa && isian.UuidGudang === '' ? 'Pilih lokasi stok.' : undefined)
                                }
                            />
                        ) : null}
                        <BidangTeks
                            label="Kode rak"
                            nilai={isian.Kode}
                            saatBerubah={(nilai) => AturIsian({ ...isian, Kode: nilai })}
                            kode
                            maxLength={30}
                            required
                            galat={galat.Kode ?? (periksa && isian.Kode.trim() === '' ? 'Isi kode rak.' : undefined)}
                        />
                        <BidangTeks
                            label="Nama rak"
                            nilai={isian.Nama}
                            saatBerubah={(nilai) => AturIsian({ ...isian, Nama: nilai })}
                            maxLength={100}
                            required
                            galat={galat.Nama ?? (periksa && isian.Nama.trim() === '' ? 'Isi nama rak.' : undefined)}
                        />
                        <BidangTeks
                            label="Urutan tampil"
                            nilai={isian.Urutan}
                            saatBerubah={(nilai) => AturIsian({ ...isian, Urutan: nilai.replace(/\D/g, '') })}
                            inputMode="numeric"
                            keterangan="Angka kecil tampil lebih dulu."
                            galat={galat.Urutan}
                        />
                        <div className="flex flex-wrap justify-end gap-2">
                            <Tombol varian="sekunder" onClick={() => AturIsian(null)} disabled={memproses}>
                                Kembali
                            </Tombol>
                            <Tombol memproses={memproses} onClick={Simpan}>
                                Simpan rak
                            </Tombol>
                        </div>
                    </div>
                </DialogFormulir>
            ) : null}
        </TataLetakAplikasi>
    );
}
