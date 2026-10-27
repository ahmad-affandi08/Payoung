import { Link, router, useForm } from '@inertiajs/react';
import { type FormEvent, useState } from 'react';

import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import { AmbilJenisUmur } from '@/Komponen/Pembelian/AturanPembelian';
import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import KotakCentang from '@/Komponen/Formulir/KotakCentang';
import Tombol from '@/Komponen/Formulir/Tombol';
import { KolomUang } from '@/Komponen/Pembelian/DaftarPembelian';
import TabelData, { type KonteksAksiMassal } from '@/Komponen/TabelData/TabelData';
import type { DefinisiSaring, HasilTabel, KolomTabel } from '@/Komponen/TabelData/Tipe';
import DialogFormulir from '@/Komponen/Tindakan/DialogFormulir';
import { ItemAksiBaris } from '@/Komponen/Tindakan/MenuAksiBaris';
import { Button } from '@/Komponen/Ui/button';
import { DialogFooter } from '@/Komponen/Ui/dialog';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import { FormatRupiah } from '@/Pustaka/Format';
import { FormatTanggal } from '@/Pustaka/FormatWaktu';
import type {
    BarisPiutang,
    PengaturanPengingatPiutang,
    PengingatPiutangTerakhir,
    PropsDaftarPiutang,
    RingkasanPiutang,
} from '@/Tipe/Piutang';

import { FormatHariLewat } from '@/Halaman/Kelola/Pembelian/Hutang/Daftar';
import AksiMassalPiutang from '@/Komponen/Piutang/AksiMassalPiutang';
import DialogHapusBukuPiutang from '@/Komponen/Piutang/DialogHapusBukuPiutang';
import { AlamatPiutang, HalamanDaftarPiutang, LabelStatusPiutang } from '@/Komponen/Piutang/BagianPiutang';

const kolom: KolomTabel<BarisPiutang>[] = [
    {
        id: 'Nomor',
        accessorKey: 'Nomor',
        header: 'Nomor penjualan',
        meta: { label: 'Nomor penjualan', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: p } }) => <span className="font-mono font-semibold break-all">{p.Nomor}</span>,
    },
    {
        id: 'Pelanggan',
        header: 'Pelanggan',
        enableSorting: false,
        meta: { label: 'Pelanggan', prioritas: 'penting' },
        cell: ({ row: { original: p } }) =>
            p.UuidPelanggan ? (
                <Link href={`/kelola/pelanggan/${p.UuidPelanggan}`} className="break-words text-brand underline">
                    {p.NamaPelanggan}
                </Link>
            ) : (
                'Tanpa pelanggan'
            ),
    },
    {
        id: 'Tanggal',
        accessorKey: 'Tanggal',
        header: 'Tanggal',
        meta: { label: 'Tanggal', prioritas: 'rendah', kelasSel: 'whitespace-nowrap' },
        cell: ({ row }) => FormatTanggal(row.original.Tanggal),
    },
    {
        id: 'JatuhTempo',
        accessorKey: 'JatuhTempo',
        header: 'Jatuh tempo',
        meta: { label: 'Jatuh tempo', prioritas: 'penting', kelasSel: 'whitespace-nowrap' },
        cell: ({ row: { original: p } }) => (
            <span className="flex flex-col">
                <span>{FormatTanggal(p.JatuhTempo)}</span>
                <span className="text-keterangan text-teks-sekunder">{FormatHariLewat(p.HariLewat)}</span>
            </span>
        ),
    },
    {
        id: 'Umur',
        header: 'Umur',
        enableSorting: false,
        meta: { label: 'Umur piutang', prioritas: 'penting' },
        cell: ({ row: { original: p } }) => <LabelStatus jenis={AmbilJenisUmur(p.Umur)} teks={p.LabelUmur} />,
    },
    {
        id: 'Status',
        header: 'Status',
        enableSorting: false,
        meta: { label: 'Status', prioritas: 'rendah' },
        cell: ({ row: { original: p } }) => <LabelStatusPiutang status={p.Status} label={p.LabelStatus} />,
    },
    KolomUang('Jumlah', 'Jumlah', (p) => p.Jumlah, true),
    KolomUang('Sisa', 'Sisa piutang', (p) => p.Sisa),
    {
        id: 'Pengingat',
        header: 'Pengingat',
        enableSorting: false,
        meta: { label: 'Pengingat terakhir', prioritas: 'rendah' },
        cell: ({ row: { original: p } }) => <SelPengingat pengingat={p.PengingatTerakhir ?? null} />,
    },
];

const JenisLabelPengingat = {
    Diantrekan: 'netral',
    Terkirim: 'sukses',
    Gagal: 'bahaya',
    Dibatalkan: 'netral',
} as const;

/** D-23 D: kapan & lewat apa pelanggan terakhir diingatkan. */
function SelPengingat({ pengingat }: { pengingat: PengingatPiutangTerakhir | null }) {
    if (pengingat === null) {
        return <span className="text-teks-sekunder">Belum pernah</span>;
    }

    return (
        <span className="flex flex-col items-start gap-1">
            <LabelStatus jenis={JenisLabelPengingat[pengingat.Status]} teks={pengingat.LabelStatus} />
            <span className="text-keterangan whitespace-nowrap text-teks-sekunder">
                {pengingat.Kanal === 'Whatsapp' ? 'WhatsApp' : 'Email'}
                {pengingat.Waktu ? ` | ${FormatTanggal(pengingat.Waktu)}` : ''}
            </span>
        </span>
    );
}

const OpsiHariSebelum = [0, 1, 2, 3, 5, 7, 14].map((h) => ({
    Nilai: String(h),
    Label: h === 0 ? 'Pada hari jatuh tempo' : `${String(h)} hari sebelum jatuh tempo`,
}));

/** D-23 D: pengaturan pengingat piutang otomatis ke pelanggan lewat WhatsApp (D-33). */
function DialogPengingatOtomatis({
    pengaturan,
    saatTutup,
}: {
    pengaturan: PengaturanPengingatPiutang;
    saatTutup: () => void;
}) {
    const formulir = useForm<PengaturanPengingatPiutang>({ ...pengaturan });
    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.put(`${AlamatPiutang}/pengingat-otomatis`, { preserveScroll: true, onSuccess: saatTutup });
    };

    return (
        <DialogFormulir
            judul="Pengingat piutang otomatis"
            keterangan="Pelanggan diingatkan lewat WhatsApp (bila nomor HP ada dan WhatsApp aktif), sekitar pukul 09.00. Setiap pengingat hanya dikirim sekali per nota."
            saatTutup={saatTutup}
            galatUmum={(formulir.errors as Record<string, string | undefined>).Umum}
        >
            <form onSubmit={Kirim} className="flex flex-col gap-4" noValidate>
                <KotakCentang
                    label="Kirim pengingat otomatis"
                    nilai={formulir.data.Aktif}
                    saatBerubah={(nilai) => formulir.setData('Aktif', nilai)}
                />
                <BidangPilihan
                    label="Kirim pengingat"
                    nilai={String(formulir.data.HariSebelum)}
                    opsi={OpsiHariSebelum}
                    saatBerubah={(nilai) => formulir.setData('HariSebelum', Number(nilai))}
                    galat={formulir.errors.HariSebelum}
                    disabled={!formulir.data.Aktif}
                />
                <KotakCentang
                    label="Ingatkan sekali lagi setelah lewat jatuh tempo"
                    nilai={formulir.data.IngatkanSaatLewat}
                    saatBerubah={(nilai) => formulir.setData('IngatkanSaatLewat', nilai)}
                />
                <DialogFooter className="sm:justify-start">
                    <Tombol type="submit" memproses={formulir.processing}>
                        Simpan pengaturan
                    </Tombol>
                    <Tombol varian="sekunder" onClick={saatTutup}>
                        Batal
                    </Tombol>
                </DialogFooter>
            </form>
        </DialogFormulir>
    );
}

function Ringkasan({ ringkasan }: { ringkasan: RingkasanPiutang | undefined }) {
    if (ringkasan === undefined) {
        return null;
    }

    return (
        <section aria-label="Ringkasan umur piutang" className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-6">
            <div className="col-span-2 min-w-0 rounded-panel border border-garis bg-permukaan px-3 py-2 sm:col-span-1">
                <p className="text-label text-teks-sekunder">Total piutang</p>
                <p className="text-subjudul font-semibold tabular-nums">{FormatRupiah(ringkasan.Total)}</p>
            </div>
            {ringkasan.Kelompok.map((k) => (
                <div key={k.Kunci} className="min-w-0 rounded-panel border border-garis bg-permukaan px-3 py-2">
                    <p className="text-label text-teks-sekunder">{k.Label}</p>
                    <p className="font-semibold tabular-nums wrap-anywhere">{FormatRupiah(k.Sisa)}</p>
                    <p className="text-keterangan text-teks-sekunder">{k.Jumlah.toLocaleString('id-ID')} penjualan</p>
                </div>
            ))}
        </section>
    );
}

/** F-12: piutang pelanggan terbuka dari penjualan tempo, umur 0–30/31–60/61–90/>90 hari, dan pintasan pelunasan. */
export default function HalamanDaftarPiutangPelanggan({
    Piutang,
    OpsiUmur,
    OpsiPelanggan,
    Izin,
    Pengingat,
    HariIni,
}: PropsDaftarPiutang) {
    const [aturPengingat, AturAturPengingat] = useState(false);
    const [dihapusBuku, AturDihapusBuku] = useState<BarisPiutang | null>(null);
    const bolehIngatkan = Izin.Ingatkan === true;
    const saring: DefinisiSaring[] = [
        {
            id: 'Umur',
            label: 'Umur piutang',
            jenis: 'pilihanBanyak',
            opsi: OpsiUmur.map((o) => ({ nilai: o.Nilai, label: o.Label })),
        },
        {
            id: 'Pelanggan',
            label: 'Pelanggan',
            jenis: 'pilihan',
            opsi: OpsiPelanggan.map((p) => ({ nilai: p.Uuid, label: p.Nama })),
        },
    ];
    const AksiBaris = (p: BarisPiutang) => [
        ...(Izin.Kelola
            ? [
                  {
                      label: 'Terima pelunasan',
                      saatPilih: () =>
                          router.visit(
                              `${AlamatPiutang}/pelunasan/buat?pelanggan=${p.UuidPelanggan ?? ''}&piutang=${p.Uuid}`,
                          ),
                  },
              ]
            : []),
        ...(bolehIngatkan && p.UuidPelanggan
            ? [
                  {
                      label: 'Kirim pengingat',
                      saatPilih: () =>
                          router.post(`${AlamatPiutang}/${p.Uuid}/pengingat`, {}, { preserveScroll: true }),
                  },
              ]
            : []),
        // E8: hanya piutang yang sudah lewat jatuh tempo boleh dihapus-buku.
        ...(Izin.Kelola && p.HariLewat > 0
            ? [{ label: 'Hapus-buku (tak tertagih)', saatPilih: () => AturDihapusBuku(p) }]
            : []),
        // v3.37: nota tagihan berisi semua piutang terbuka pelanggan ini (tab baru, siap cetak/PDF).
        ...(p.UuidPelanggan
            ? [
                  {
                      label: 'Cetak nota tagihan pelanggan',
                      saatPilih: () => window.open(`${AlamatPiutang}/tagihan/${p.UuidPelanggan ?? ''}`, '_blank'),
                  },
              ]
            : []),
    ];

    return (
        <HalamanDaftarPiutang
            judul="Piutang pelanggan"
            keterangan="Penjualan tempo yang belum lunas, diurutkan dari jatuh tempo terdekat. Terima pelunasan satu atau beberapa penjualan satu pelanggan sekaligus."
            izin={Izin}
            objek="pelunasan piutang"
        >
            <AksiHalaman
                {...(Izin.Kelola
                    ? {
                          utama: (
                              <Button asChild className="h-8 pointer-coarse:h-11">
                                  <Link href={`${AlamatPiutang}/pelunasan/buat`}>Terima pelunasan</Link>
                              </Button>
                          ),
                      }
                    : {})}
                lainnya={[
                    ...(bolehIngatkan && Pengingat
                        ? [
                              {
                                  label: `Pengingat otomatis: ${Pengingat.Aktif ? 'aktif' : 'mati'}`,
                                  saatKlik: () => AturAturPengingat(true),
                              },
                          ]
                        : []),
                    { label: 'Riwayat hapus-buku', href: `${AlamatPiutang}/hapus-buku` },
                ]}
            />
            <TabelData
                id="piutang-pelanggan"
                label="Daftar piutang pelanggan"
                kolom={kolom}
                sumber={{ mode: 'server', alamat: AlamatPiutang, awal: Piutang }}
                ambilIdBaris={(p) => p.Uuid}
                urutBawaan="JatuhTempo"
                ekspor={{ alamat: `${AlamatPiutang}/ekspor`, label: 'Ekspor', laporan: true, eksporBesar: true }}
                cari="Cari nomor penjualan atau nama pelanggan"
                saring={saring}
                ringkasan={(hasil) => (
                    <Ringkasan
                        ringkasan={
                            (hasil as HasilTabel<BarisPiutang, RingkasanPiutang> | undefined)?.Ringkasan ??
                            Piutang.Ringkasan
                        }
                    />
                )}
                labelBaris={(p) => `piutang ${p.Nomor}`}
                {...(bolehIngatkan && Pengingat
                    ? {
                          aksiMassal: (konteks: KonteksAksiMassal<BarisPiutang>) => (
                              <AksiMassalPiutang konteks={konteks} />
                          ),
                      }
                    : {})}
                aksiBaris={(p: BarisPiutang) => <ItemAksiBaris aksi={AksiBaris(p)} />}
                kosong={{
                    ilustrasi: true,
                    judul: 'Tidak ada piutang terbuka. Semua penjualan tempo sudah lunas.',
                }}
            />
            {dihapusBuku ? (
                <DialogHapusBukuPiutang
                    piutang={dihapusBuku}
                    hariIni={HariIni}
                    saatTutup={() => AturDihapusBuku(null)}
                />
            ) : null}
            {aturPengingat && Pengingat ? (
                <DialogPengingatOtomatis pengaturan={Pengingat} saatTutup={() => AturAturPengingat(false)} />
            ) : null}
        </HalamanDaftarPiutang>
    );
}
