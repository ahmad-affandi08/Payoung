import { router, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import KotakCentang from '@/Komponen/Formulir/KotakCentang';
import PesanHanyaLihat from '@/Komponen/Katalog/PesanHanyaLihat';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import DialogFormulir from '@/Komponen/Tindakan/DialogFormulir';
import DialogKonfirmasi from '@/Komponen/Tindakan/DialogKonfirmasi';
import { ItemAksiBaris } from '@/Komponen/Tindakan/MenuAksiBaris';
import { Badge } from '@/Komponen/Ui/badge';
import { Button } from '@/Komponen/Ui/button';
import Tombol from '@/Komponen/Formulir/Tombol';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { BarisBaganAkun, PropsBaganAkun, TipeAkun } from '@/Tipe/Akuntansi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';

const alamat = '/kelola/akuntansi/akun';

type IsianAkun = { Kode: string; Nama: string; Jenis: TipeAkun; Kontra: boolean; KasBank: boolean; UuidInduk: string };
type Formulir = { akun: BarisBaganAkun | null; induk: BarisBaganAkun | null; isian: IsianAkun };

const kolom: KolomTabel<BarisBaganAkun>[] = [
    {
        id: 'Kode',
        accessorKey: 'Kode',
        header: 'Kode',
        meta: { label: 'Kode', prioritas: 'penting', wajib: true, kelasSel: 'whitespace-nowrap font-mono' },
        cell: ({ row }) => (
            // Indentasi pohon: satu langkah per tingkat anak.
            <span style={{ paddingLeft: `${String(row.original.Kedalaman * 1.25)}rem` }}>{row.original.Kode}</span>
        ),
    },
    {
        id: 'Nama',
        accessorKey: 'Nama',
        header: 'Nama akun',
        meta: { label: 'Nama akun', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: akun } }) => (
            <span className="flex flex-wrap items-center gap-1.5">
                <span className="font-semibold break-words text-teks-utama">{akun.Nama}</span>
                {akun.KasBank ? <Badge variant="outline">Kas/bank</Badge> : null}
                {akun.Kontra ? <Badge variant="outline">Kontra</Badge> : null}
            </span>
        ),
    },
    {
        id: 'Jenis',
        accessorKey: 'Jenis',
        header: 'Tipe',
        meta: { label: 'Tipe', prioritas: 'penting' },
        cell: ({ row }) => row.original.LabelJenis,
    },
    {
        id: 'SaldoNormal',
        accessorKey: 'SaldoNormal',
        header: 'Saldo normal',
        meta: { label: 'Saldo normal', prioritas: 'rendah' },
    },
    {
        id: 'PeranDipetakan',
        header: 'Dipakai untuk',
        enableSorting: false,
        meta: { label: 'Dipakai untuk', prioritas: 'rendah', kelasSel: 'text-label text-teks-sekunder' },
        cell: ({ row: { original: akun } }) =>
            [akun.PeranDipetakan.join(', '), akun.AdaJurnal ? 'Sudah ada jurnal (kode & tipe terkunci)' : '']
                .filter(Boolean)
                .join(' | ') || '—',
    },
    {
        id: 'Aktif',
        accessorKey: 'Aktif',
        header: 'Status',
        meta: { label: 'Status', prioritas: 'penting' },
        cell: ({ row }) => (
            <Badge variant={row.original.Aktif ? 'default' : 'outline'}>
                {row.original.Aktif ? 'Aktif' : 'Nonaktif'}
            </Badge>
        ),
    },
];

/** F-13a bagan akun (FIN-01): pohon akun, tambah akun anak, ubah nama/status; akun terpakai hanya dinonaktifkan. */
export default function HalamanBaganAkun({ Akun, OpsiTipe, Izin }: PropsBaganAkun) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const galat = props.errors;
    const [form, AturForm] = useState<Formulir | null>(null);
    const [hapus, AturHapus] = useState<BarisBaganAkun | null>(null);
    const [memproses, AturMemproses] = useState(false);
    const opsi = {
        preserveScroll: true,
        onStart: () => AturMemproses(true),
        onFinish: () => AturMemproses(false),
    };

    const Buka = (akun: BarisBaganAkun | null, induk: BarisBaganAkun | null) =>
        AturForm({
            akun,
            induk,
            isian: akun
                ? {
                      Kode: akun.Kode,
                      Nama: akun.Nama,
                      Jenis: akun.Jenis,
                      Kontra: akun.Kontra,
                      KasBank: akun.KasBank,
                      UuidInduk: '',
                  }
                : {
                      Kode: induk?.Kode ?? '',
                      Nama: '',
                      Jenis: induk?.Jenis ?? 'Beban',
                      Kontra: false,
                      KasBank: induk?.KasBank ?? false,
                      UuidInduk: induk?.Uuid ?? '',
                  },
        });

    const Ubah = (ubah: Partial<IsianAkun>) => {
        if (form !== null) {
            AturForm({ ...form, isian: { ...form.isian, ...ubah } });
        }
    };

    const Simpan = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();

        if (form === null) {
            return;
        }

        const data = { ...form.isian, UuidInduk: form.isian.UuidInduk === '' ? null : form.isian.UuidInduk };
        const selesai = { ...opsi, onSuccess: () => AturForm(null) };

        if (form.akun === null) {
            router.post(alamat, data, selesai);
        } else {
            router.put(`${alamat}/${form.akun.Uuid}`, data, selesai);
        }
    };

    const terkunci = form?.akun?.AdaJurnal === true;
    const tipeTetap = terkunci || form?.induk !== null;
    const bolehKasBank = form !== null && form.isian.Jenis === 'Aset' && !form.isian.Kontra;

    return (
        <TataLetakAplikasi judul="Bagan akun">
            <p className="max-w-3xl text-isi text-teks-sekunder">
                Akun yang dipakai jurnal usaha. Akun yang sudah dipakai hanya bisa dinonaktifkan, tidak dihapus.
            </p>
            {Izin.Kelola ? (
                <AksiHalaman>
                    <Button onClick={() => Buka(null, null)}>Tambah akun</Button>
                </AksiHalaman>
            ) : (
                <PesanHanyaLihat izin="akuntansi.kelola" objek="bagan akun" />
            )}

            <TabelData
                id="akuntansi-bagan-akun"
                label="Bagan akun"
                kolom={kolom}
                sumber={{ mode: 'lokal', data: Akun }}
                ambilIdBaris={(akun) => akun.Uuid}
                cari="Cari kode atau nama akun"
                saring={[
                    {
                        id: 'Jenis',
                        label: 'Tipe',
                        jenis: 'pilihan',
                        opsi: OpsiTipe.map((t) => ({ nilai: t.Nilai, label: t.Label })),
                    },
                    { id: 'Aktif', label: 'Hanya yang aktif', jenis: 'ya' },
                ]}
                labelBaris={(akun) => `untuk akun ${akun.Kode} ${akun.Nama}`}
                {...(Izin.Kelola
                    ? {
                          aksiBaris: (akun: BarisBaganAkun) => (
                              <ItemAksiBaris
                                  aksi={[
                                      ...(akun.Aktif
                                          ? [{ label: 'Tambah akun anak', saatPilih: () => Buka(null, akun) }]
                                          : []),
                                      { label: 'Ubah akun', saatPilih: () => Buka(akun, null) },
                                      {
                                          label: akun.Aktif ? 'Nonaktifkan akun' : 'Aktifkan akun',
                                          saatPilih: () =>
                                              router.put(
                                                  `${alamat}/${akun.Uuid}/status`,
                                                  { Aktif: !akun.Aktif },
                                                  { preserveScroll: true },
                                              ),
                                          bahaya: akun.Aktif,
                                      },
                                      ...(akun.BisaDihapus
                                          ? [{ label: 'Hapus akun', saatPilih: () => AturHapus(akun), bahaya: true }]
                                          : []),
                                  ]}
                              />
                          ),
                      }
                    : {})}
                kosong={{
                    ilustrasi: true,
                    judul: 'Belum ada akun. Terapkan template sektor di Panduan awal untuk membuat bagan akun.',
                }}
            />

            {form !== null ? (
                <DialogFormulir
                    judul={
                        form.akun
                            ? `Ubah akun ${form.akun.Kode}`
                            : form.induk
                              ? `Tambah akun anak ${form.induk.Kode} ${form.induk.Nama}`
                              : 'Tambah akun'
                    }
                    galatUmum={galat.Umum}
                    saatTutup={() => AturForm(null)}
                >
                    <form onSubmit={Simpan} className="flex flex-col gap-4" aria-label="Formulir akun" noValidate>
                        <BidangTeks
                            label="Kode akun"
                            nilai={form.isian.Kode}
                            saatBerubah={(nilai) => Ubah({ Kode: nilai })}
                            galat={galat.Kode}
                            keterangan={
                                terkunci
                                    ? 'Kode terkunci karena akun sudah punya jurnal.'
                                    : `Diawali digit tipe, misal ${OpsiTipe.find((t) => t.Nilai === form.isian.Jenis)?.DigitAwal ?? '1'}-1100.`
                            }
                            kode
                            maxLength={10}
                            disabled={terkunci}
                            required
                        />
                        <BidangTeks
                            label="Nama akun"
                            nilai={form.isian.Nama}
                            saatBerubah={(nilai) => Ubah({ Nama: nilai })}
                            galat={galat.Nama}
                            maxLength={100}
                            required
                        />
                        <BidangPilihan
                            label="Tipe akun"
                            nilai={form.isian.Jenis}
                            opsi={OpsiTipe.map((t) => ({ Nilai: t.Nilai, Label: t.Label }))}
                            saatBerubah={(nilai) =>
                                Ubah({ Jenis: nilai as TipeAkun, KasBank: nilai === 'Aset' && form.isian.KasBank })
                            }
                            galat={galat.Jenis}
                            disabled={tipeTetap}
                            required
                        />
                        {terkunci ? (
                            <p className="text-label text-teks-sekunder">
                                Tipe dan sifat kontra terkunci karena akun sudah punya jurnal.
                            </p>
                        ) : (
                            <KotakCentang
                                label="Akun kontra (saldo normal kebalikan tipenya, misal diskon penjualan)"
                                nilai={form.isian.Kontra}
                                saatBerubah={(nilai) =>
                                    Ubah({ Kontra: nilai, KasBank: nilai ? false : form.isian.KasBank })
                                }
                            />
                        )}
                        {bolehKasBank ? (
                            <KotakCentang
                                label="Akun kas/bank (dipakai transaksi kas & bank)"
                                nilai={form.isian.KasBank}
                                saatBerubah={(nilai) => Ubah({ KasBank: nilai })}
                            />
                        ) : null}
                        {galat.KasBank ? <p className="text-label text-bahaya">{galat.KasBank}</p> : null}
                        <div className="flex flex-wrap justify-end gap-2">
                            <Tombol type="button" varian="sekunder" onClick={() => AturForm(null)}>
                                Batal
                            </Tombol>
                            <Tombol type="submit" memproses={memproses}>
                                Simpan akun
                            </Tombol>
                        </div>
                    </form>
                </DialogFormulir>
            ) : null}

            {hapus !== null ? (
                <DialogKonfirmasi
                    judul={`Hapus akun ${hapus.Kode} ${hapus.Nama}?`}
                    labelAksi="Hapus akun"
                    memproses={memproses}
                    saatBatal={() => AturHapus(null)}
                    saatKonfirmasi={() =>
                        router.delete(`${alamat}/${hapus.Uuid}`, {
                            ...opsi,
                            onFinish: () => {
                                AturMemproses(false);
                                AturHapus(null);
                            },
                        })
                    }
                >
                    Akun ini belum pernah dipakai, jadi bisa dihapus permanen. Akun yang sudah dipakai cukup
                    dinonaktifkan.
                </DialogKonfirmasi>
            ) : null}
        </TataLetakAplikasi>
    );
}
