import { Link, router, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import BidangJumlah from '@/Komponen/Katalog/BidangJumlah';
import PesanHanyaLihat from '@/Komponen/Katalog/PesanHanyaLihat';
import AksiMassalSederhana from '@/Komponen/TabelData/AksiMassalSederhana';
import TabelData, { type KonteksAksiMassal } from '@/Komponen/TabelData/TabelData';
import type { DefinisiSaring, KolomTabel } from '@/Komponen/TabelData/Tipe';
import PemilihTanggal from '@/Komponen/Tanggal/PemilihTanggal';
import DialogFormulir from '@/Komponen/Tindakan/DialogFormulir';
import { ItemAksiBaris } from '@/Komponen/Tindakan/MenuAksiBaris';
import { Button } from '@/Komponen/Ui/button';
import Tombol from '@/Komponen/Formulir/Tombol';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import type { BarisVoucher, PropsVoucherPromo } from '@/Tipe/Promo';

import { FormatTanggalPromo } from './Daftar';

type Isian = {
    Cara: 'Satu' | 'Massal';
    Kode: string;
    Jumlah: string;
    Awalan: string;
    MaksimalPakai: string;
    TanggalKedaluwarsa: string;
};

const isianAwal: Isian = {
    Cara: 'Massal',
    Kode: '',
    Jumlah: '100',
    Awalan: '',
    MaksimalPakai: '1',
    TanggalKedaluwarsa: '',
};

const opsiCara = [
    { Nilai: 'Massal', Label: 'Banyak kode acak (untuk dibagikan)' },
    { Nilai: 'Satu', Label: 'Satu kode pilihan (mis. MERDEKA17)' },
];

const saring: DefinisiSaring[] = [
    {
        id: 'Status',
        label: 'Status',
        jenis: 'pilihanBanyak',
        opsi: [
            { nilai: 'Aktif', label: 'Aktif' },
            { nilai: 'Nonaktif', label: 'Nonaktif' },
        ],
    },
];

/** `2026-10-31T17:00:00Z` (awal hari berikutnya) → tanggal terakhir berlaku `31 Okt 2026`. */
export function FormatBerlakuSampai(nilai: string | null): string {
    return nilai === null ? 'Ikut periode promo' : FormatTanggalPromo(new Date(Date.parse(nilai) - 1).toISOString());
}

const kolom: KolomTabel<BarisVoucher>[] = [
    {
        id: 'Kode',
        accessorKey: 'Kode',
        header: 'Kode',
        enableSorting: true,
        meta: { label: 'Kode', prioritas: 'utama', wajib: true },
        cell: ({ row }) => <span className="font-mono font-semibold text-teks-utama">{row.original.Kode}</span>,
    },
    {
        id: 'JumlahDipakai',
        accessorKey: 'JumlahDipakai',
        header: 'Dipakai',
        enableSorting: true,
        meta: { label: 'Dipakai', prioritas: 'penting', angka: true },
        cell: ({ row: { original: v } }) => {
            const pakai =
                v.MaksimalPakai === null
                    ? v.JumlahDipakai.toLocaleString('id-ID')
                    : `${v.JumlahDipakai.toLocaleString('id-ID')} / ${v.MaksimalPakai.toLocaleString('id-ID')}`;

            return v.Dipesan > 0 ? `${pakai} | ${v.Dipesan} sedang di kasir` : pakai;
        },
    },
    {
        id: 'KedaluwarsaPada',
        accessorKey: 'KedaluwarsaPada',
        header: 'Berlaku sampai',
        enableSorting: false,
        meta: { label: 'Berlaku sampai', prioritas: 'rendah' },
        cell: ({ row }) => FormatBerlakuSampai(row.original.KedaluwarsaPada),
    },
    {
        id: 'Status',
        accessorKey: 'Status',
        header: 'Status',
        enableSorting: false,
        meta: { label: 'Status', prioritas: 'penting' },
        cell: ({ row }) =>
            row.original.Status === 'Aktif' ? (
                <LabelStatus jenis="sukses" teks="Aktif" />
            ) : (
                <LabelStatus jenis="netral" teks="Nonaktif" />
            ),
    },
    {
        id: 'DibuatPada',
        accessorKey: 'DibuatPada',
        header: 'Dibuat',
        enableSorting: true,
        meta: { label: 'Dibuat', prioritas: 'rendah' },
        cell: ({ row }) => FormatTanggalPromo(row.original.DibuatPada),
    },
];

/**
 * F-16c bagian 2: kode voucher sebuah promo wajib voucher. Kasir memasukkan kode di keranjang (perlu online); kode
 * dipesan untuk transaksi itu sehingga voucher sekali pakai tidak bisa dipakai dua kali. Kode massal bisa diekspor CSV.
 */
export default function HalamanVoucherPromo({ Promo, Voucher, Ringkasan, JumlahMaksimal, Izin }: PropsVoucherPromo) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const galat = props.errors;
    const alamat = `/kelola/promo/${Promo.Uuid}/voucher`;
    const [isian, AturIsian] = useState<Isian | null>(null);
    const [memproses, AturMemproses] = useState(false);
    const Ubah = (ubah: Partial<Isian>) => isian !== null && AturIsian({ ...isian, ...ubah });
    const bisaTambah = Izin.Kelola && Promo.WajibVoucher && Promo.Status === 'Aktif';

    const Simpan = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();

        if (isian === null) {
            return;
        }

        const satu = isian.Cara === 'Satu';
        router.post(
            alamat,
            {
                Cara: isian.Cara,
                Kode: satu ? isian.Kode : null,
                Jumlah: satu ? null : Number(isian.Jumlah || '0'),
                Awalan: satu || isian.Awalan === '' ? null : isian.Awalan,
                MaksimalPakai: isian.MaksimalPakai === '' ? null : Number(isian.MaksimalPakai),
                TanggalKedaluwarsa: isian.TanggalKedaluwarsa === '' ? null : isian.TanggalKedaluwarsa,
            },
            {
                preserveScroll: true,
                onStart: () => AturMemproses(true),
                onFinish: () => AturMemproses(false),
                onSuccess: () => AturIsian(null),
            },
        );
    };

    return (
        <TataLetakAplikasi judul={`Voucher ${Promo.Nama}`}>
            <div>
                <Button asChild variant="outline">
                    <Link href="/kelola/promo">Kembali ke daftar promo</Link>
                </Button>
            </div>
            <p className="max-w-3xl text-isi text-teks-sekunder">
                Promo <span className="font-mono">{Promo.Kode}</span> ({Promo.LabelAksi}) hanya berlaku bila kasir
                memasukkan salah satu kode di bawah. Kasir perlu online saat memasukkan kode. {Ringkasan.Total} kode,{' '}
                {Ringkasan.Aktif} aktif, sudah dipakai {Ringkasan.Dipakai.toLocaleString('id-ID')} kali.
            </p>
            {Promo.WajibVoucher ? null : (
                <Pemberitahuan jenis="peringatan" judul="Promo ini diterapkan otomatis tanpa kode">
                    Centang &quot;Wajib kode voucher&quot; di formulir promo sebelum membuat voucher.
                </Pemberitahuan>
            )}
            {Izin.Kelola ? null : <PesanHanyaLihat izin="pelanggan.kelola" objek="voucher" />}

            {bisaTambah ? (
                <AksiHalaman
                    utama={<Button onClick={() => AturIsian(isianAwal)}>Tambah voucher</Button>}
                    lainnya={[
                        {
                            label: 'Nonaktifkan yang kedaluwarsa',
                            saatKlik: () =>
                                router.post(`${alamat}/nonaktifkan-kedaluwarsa`, {}, { preserveScroll: true }),
                        },
                    ]}
                />
            ) : null}

            <TabelData
                id="voucher-promo"
                label="Daftar voucher"
                kolom={kolom}
                sumber={{ mode: 'server', alamat, ...(Voucher ? { awal: Voucher } : {}) }}
                ambilIdBaris={(v) => v.Uuid}
                urutBawaan="-DibuatPada"
                cari="Cari kode voucher"
                saring={saring}
                labelBaris={(v) => `untuk voucher ${v.Kode}`}
                {...(Izin.Kelola
                    ? {
                          ekspor: { alamat: `${alamat}/ekspor`, label: 'Ekspor CSV' },
                          aksiMassal: (konteks: KonteksAksiMassal<BarisVoucher>) => (
                              <AksiMassalSederhana
                                  konteks={konteks}
                                  alamat={`${alamat}/massal`}
                                  ambilUuid={(v) => v.Uuid}
                                  tombol={[
                                      { aksi: 'Aktifkan', label: 'Aktifkan' },
                                      { aksi: 'Nonaktifkan', label: 'Nonaktifkan', varian: 'bahaya' },
                                  ]}
                                  maksimal={1000}
                                  objek="voucher"
                              />
                          ),
                          aksiBaris: (v: BarisVoucher) => (
                              <ItemAksiBaris
                                  aksi={[
                                      v.Status === 'Aktif'
                                          ? {
                                                label: 'Nonaktifkan voucher',
                                                bahaya: true,
                                                saatPilih: () =>
                                                    router.post(
                                                        `/kelola/promo/voucher/${v.Uuid}/nonaktifkan`,
                                                        {},
                                                        { preserveScroll: true },
                                                    ),
                                            }
                                          : {
                                                label: 'Aktifkan voucher',
                                                saatPilih: () =>
                                                    router.post(
                                                        `/kelola/promo/voucher/${v.Uuid}/aktifkan`,
                                                        {},
                                                        { preserveScroll: true },
                                                    ),
                                            },
                                  ]}
                              />
                          ),
                      }
                    : {})}
                kosong={{
                    ilustrasi: true,
                    judul: 'Belum ada voucher. Buat kode massal untuk dibagikan ke pelanggan, atau satu kode untuk kampanye.',
                }}
            />

            {isian !== null ? (
                <DialogFormulir judul="Tambah voucher" galatUmum={galat.Umum} saatTutup={() => AturIsian(null)}>
                    <form onSubmit={Simpan} className="flex flex-col gap-4" aria-label="Formulir voucher" noValidate>
                        <BidangPilihan
                            label="Cara membuat"
                            nilai={isian.Cara}
                            opsi={opsiCara}
                            saatBerubah={(nilai) => Ubah({ Cara: nilai === 'Satu' ? 'Satu' : 'Massal' })}
                            required
                        />
                        {isian.Cara === 'Satu' ? (
                            <BidangTeks
                                label="Kode voucher"
                                nilai={isian.Kode}
                                saatBerubah={(nilai) => Ubah({ Kode: nilai.toUpperCase() })}
                                keterangan="4–30 huruf, angka, garis bawah, atau tanda hubung."
                                galat={galat.Kode}
                                required
                            />
                        ) : (
                            <>
                                <BidangJumlah
                                    label="Jumlah kode"
                                    nilai={isian.Jumlah}
                                    saatBerubah={(nilai) => Ubah({ Jumlah: nilai })}
                                    desimal={0}
                                    digitBulat={4}
                                    keterangan={`Paling banyak ${JumlahMaksimal.toLocaleString('id-ID')} kode sekali buat.`}
                                    galat={galat.Jumlah}
                                    required
                                />
                                <BidangTeks
                                    label="Awalan kode (opsional)"
                                    nilai={isian.Awalan}
                                    saatBerubah={(nilai) => Ubah({ Awalan: nilai.toUpperCase() })}
                                    keterangan="Contoh HUT → HUT7K2M9QXA. Maksimal 12 karakter."
                                    galat={galat.Awalan}
                                />
                            </>
                        )}
                        <BidangJumlah
                            label="Batas pakai per kode"
                            nilai={isian.MaksimalPakai}
                            saatBerubah={(nilai) => Ubah({ MaksimalPakai: nilai })}
                            desimal={0}
                            digitBulat={7}
                            keterangan="1 = sekali pakai. Kosongkan untuk tanpa batas."
                            galat={galat.MaksimalPakai}
                        />
                        <PemilihTanggal
                            label="Berlaku sampai (opsional)"
                            nilai={isian.TanggalKedaluwarsa}
                            saatBerubah={(nilai) => Ubah({ TanggalKedaluwarsa: nilai })}
                            keterangan="Kosongkan untuk mengikuti periode promo."
                            galat={galat.TanggalKedaluwarsa}
                        />
                        <div className="flex flex-wrap justify-end gap-2">
                            <Tombol type="button" varian="sekunder" onClick={() => AturIsian(null)}>
                                Batal
                            </Tombol>
                            <Tombol type="submit" memproses={memproses}>
                                Buat voucher
                            </Tombol>
                        </div>
                    </form>
                </DialogFormulir>
            ) : null}
        </TataLetakAplikasi>
    );
}
