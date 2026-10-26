import { router, useForm, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import KotakCentang from '@/Komponen/Formulir/KotakCentang';
import Tombol from '@/Komponen/Formulir/Tombol';
import BidangTanggal from '@/Komponen/Pengelola/BidangTanggal';
import DialogFormulir from '@/Komponen/Tindakan/DialogFormulir';
import DialogTinjauan from '@/Komponen/Tindakan/DialogTinjauan';
import TabKatalog from '@/Komponen/Pengelola/TabKatalog';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import { DialogFooter } from '@/Komponen/Ui/dialog';
import { DropdownMenuItem } from '@/Komponen/Ui/dropdown-menu';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatRupiah } from '@/Pustaka/Format';
import { FormatTanggal } from '@/Pustaka/FormatWaktu';
import TataLetakPengelola from '@/TataLetak/TataLetakPengelola';
import { IzinPengelola, CekBebasTinjauan, PunyaIzin, type PropsBersamaPengelola } from '@/Tipe/Pengelola';

type Persetujuan = { Peninjau: string; IdPeninjau: number; Keputusan: 'Setuju' | 'Tolak'; Catatan: string | null };

type Harga = {
    Uuid: string;
    HargaBulanan: string;
    HargaTahunan: string;
    BerlakuMulai: string;
    BerlakuSampai: string | null;
    TerapkanKePelangganLama: boolean;
    Status: 'Draf' | 'MenungguTinjauan' | 'Terbit' | 'Berakhir';
    DaftarIdPenyusun: number[];
    IdPengaju: number | null;
    Persetujuan: Persetujuan[];
};

type PropsHargaPaket = {
    Paket: { Uuid: string; Kode: string; Nama: string; HargaNegosiasi: boolean };
    Harga: Harga[];
    IdPengguna: number;
};

const labelStatus = {
    Draf: { jenis: 'netral', teks: 'Draf' },
    MenungguTinjauan: { jenis: 'peringatan', teks: 'Menunggu tinjauan' },
    Terbit: { jenis: 'sukses', teks: 'Terbit' },
    Berakhir: { jenis: 'netral', teks: 'Berakhir' },
} as const;

const kolom: KolomTabel<Harga>[] = [
    {
        id: 'BerlakuMulai',
        accessorKey: 'BerlakuMulai',
        header: 'Berlaku',
        meta: { label: 'Berlaku', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: harga } }) =>
            `${FormatTanggal(harga.BerlakuMulai)} – ${harga.BerlakuSampai ? FormatTanggal(harga.BerlakuSampai) : 'seterusnya'}`,
    },
    {
        id: 'HargaBulanan',
        accessorKey: 'HargaBulanan',
        header: 'Per bulan',
        meta: { label: 'Per bulan', angka: true, prioritas: 'penting' },
        cell: ({ row }) => FormatRupiah(row.original.HargaBulanan),
    },
    {
        id: 'HargaTahunan',
        accessorKey: 'HargaTahunan',
        header: 'Per tahun',
        meta: { label: 'Per tahun', angka: true, prioritas: 'rendah' },
        cell: ({ row }) => FormatRupiah(row.original.HargaTahunan),
    },
    {
        id: 'PelangganLama',
        header: 'Pelanggan lama',
        enableSorting: false,
        meta: { label: 'Pelanggan lama', prioritas: 'rendah', kelasSel: 'text-teks-sekunder' },
        cell: ({ row }) => (row.original.TerapkanKePelangganLama ? 'Ikut harga baru' : 'Tetap harga lama'),
    },
    {
        id: 'Status',
        accessorKey: 'Status',
        header: 'Status',
        meta: { label: 'Status', prioritas: 'penting' },
        cell: ({ row: { original: harga } }) => (
            <>
                <LabelStatus jenis={labelStatus[harga.Status].jenis} teks={labelStatus[harga.Status].teks} />
                {harga.Persetujuan.map((item) => (
                    <span key={item.IdPeninjau} className="block text-keterangan text-teks-sekunder">
                        {item.Keputusan === 'Setuju' ? 'Disetujui' : 'Ditolak'} {item.Peninjau}
                        {item.Catatan ? `: ${item.Catatan}` : ''}
                    </span>
                ))}
            </>
        ),
    },
];

/** Versi harga paket (P-04, BR-P04.1, BR-P04.5), TabelData D-16. Keuangan mengusulkan, Super Admin menyetujui. */
export default function HalamanHargaPaket({ Paket, Harga, IdPengguna }: PropsHargaPaket) {
    const { props } = usePage<PropsBersamaPengelola>();
    const bolehAjukan = PunyaIzin(props.Pengguna, IzinPengelola.KatalogPaketAjukan);
    const bolehSetujui = PunyaIzin(props.Pengguna, IzinPengelola.KatalogPaketSetujui);
    const [sunting, AturSunting] = useState<Harga | 'baru' | null>(null);
    const [ditinjau, AturDitinjau] = useState<Harga | null>(null);
    const alamat = `/katalog/paket/${Paket.Uuid}/harga`;
    const Ajukan = (harga: Harga) => router.post(`${alamat}/${harga.Uuid}/ajukan`, {}, { preserveScroll: true });
    const bebasTinjauan = CekBebasTinjauan(props.Pengguna);
    const BisaTinjau = (harga: Harga) =>
        bolehSetujui &&
        harga.Status === 'MenungguTinjauan' &&
        (bebasTinjauan ||
            (harga.IdPengaju !== IdPengguna &&
                !harga.DaftarIdPenyusun.includes(IdPengguna) &&
                !harga.Persetujuan.some((item) => item.IdPeninjau === IdPengguna)));

    return (
        <TataLetakPengelola judul={`Harga ${Paket.Nama}`} jejak={[{ label: 'Katalog paket', href: '/katalog/paket' }]}>
            <TabKatalog />
            <Pemberitahuan jenis="info" judul="Aturan harga paket">
                Harga baru hanya berlaku untuk tagihan berikutnya. Bila &quot;terapkan ke pelanggan lama&quot; tidak
                dicentang, langganan yang sudah berjalan tetap memakai harga lamanya. Harga terbit tidak bisa diubah;
                penyusun tidak bisa menyetujui usulannya sendiri.
            </Pemberitahuan>
            {props.errors.Umum ? <Pemberitahuan jenis="bahaya">{props.errors.Umum}</Pemberitahuan> : null}
            {Paket.HargaNegosiasi ? (
                <Pemberitahuan jenis="info" judul="Harga negosiasi">
                    Paket ini tidak punya harga tetap. Harga disepakati per tenant.
                </Pemberitahuan>
            ) : null}

            {sunting !== null ? (
                <FormHarga
                    key={sunting === 'baru' ? 'baru' : sunting.Uuid}
                    alamat={alamat}
                    harga={sunting === 'baru' ? null : sunting}
                    saatSelesai={() => AturSunting(null)}
                />
            ) : null}
            {ditinjau !== null ? (
                <FormTinjauHarga
                    key={ditinjau.Uuid}
                    alamat={alamat}
                    harga={ditinjau}
                    saatSelesai={() => AturDitinjau(null)}
                />
            ) : null}

            <AksiHalaman>
                {bolehAjukan && !Paket.HargaNegosiasi && sunting === null ? (
                    <Tombol onClick={() => AturSunting('baru')}>Usulkan harga baru</Tombol>
                ) : null}
            </AksiHalaman>
            <TabelData
                id="pengelola-katalog-harga-paket"
                label={`Versi harga paket ${Paket.Nama}`}
                kolom={kolom}
                sumber={{ mode: 'lokal', data: Harga }}
                ambilIdBaris={(harga) => harga.Uuid}
                saring={[
                    {
                        id: 'Status',
                        label: 'Status',
                        jenis: 'pilihanBanyak',
                        opsi: (['Draf', 'MenungguTinjauan', 'Terbit', 'Berakhir'] as const).map((status) => ({
                            nilai: status,
                            label: labelStatus[status].teks,
                        })),
                    },
                ]}
                {...(bolehAjukan || bolehSetujui
                    ? {
                          aksiBaris: (harga: Harga) => {
                              const bisaUbah = bolehAjukan && harga.Status === 'Draf';
                              const bisaTinjau = BisaTinjau(harga);

                              if (!bisaUbah && !bisaTinjau) {
                                  return null;
                              }

                              return (
                                  <>
                                      {bisaUbah ? (
                                          <>
                                              <DropdownMenuItem onSelect={() => AturSunting(harga)}>
                                                  Ubah usulan harga
                                              </DropdownMenuItem>
                                              <DropdownMenuItem onSelect={() => Ajukan(harga)}>
                                                  {bebasTinjauan ? 'Terbitkan harga' : 'Ajukan harga'}
                                              </DropdownMenuItem>
                                          </>
                                      ) : null}
                                      {bisaTinjau ? (
                                          <DropdownMenuItem onSelect={() => AturDitinjau(harga)}>
                                              Tinjau harga
                                          </DropdownMenuItem>
                                      ) : null}
                                  </>
                              );
                          },
                      }
                    : {})}
                kosong={{
                    ilustrasi: true,
                    judul: 'Belum ada harga. Usulkan harga pertama agar paket bisa diaktifkan.',
                }}
            />
        </TataLetakPengelola>
    );
}

function FormHarga({ alamat, harga, saatSelesai }: { alamat: string; harga: Harga | null; saatSelesai: () => void }) {
    const formulir = useForm({
        HargaBulanan: harga?.HargaBulanan.replace(/\.00$/, '') ?? '',
        HargaTahunan: harga?.HargaTahunan.replace(/\.00$/, '') ?? '',
        BerlakuMulai: harga?.BerlakuMulai ?? '',
        TerapkanKePelangganLama: harga?.TerapkanKePelangganLama ?? false,
    });

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        const opsi = { preserveScroll: true, onSuccess: saatSelesai };

        if (harga === null) {
            formulir.post(alamat, opsi);
        } else {
            formulir.put(`${alamat}/${harga.Uuid}`, opsi);
        }
    };

    return (
        <DialogFormulir
            judul={harga === null ? 'Usulkan harga baru' : 'Ubah draf harga'}
            saatTutup={saatSelesai}
            lebar="lebar"
            galatUmum={(formulir.errors as Record<string, string | undefined>).Umum}
        >
            <form onSubmit={Kirim} className="grid gap-4 sm:grid-cols-3" noValidate>
                <BidangTeks
                    label="Harga per bulan (Rp)"
                    inputMode="decimal"
                    keterangan="Tanpa titik ribuan, misal 199000."
                    nilai={formulir.data.HargaBulanan}
                    saatBerubah={(nilai) => formulir.setData('HargaBulanan', nilai)}
                    galat={formulir.errors.HargaBulanan}
                    required
                />
                <BidangTeks
                    label="Harga per tahun (Rp)"
                    inputMode="decimal"
                    nilai={formulir.data.HargaTahunan}
                    saatBerubah={(nilai) => formulir.setData('HargaTahunan', nilai)}
                    galat={formulir.errors.HargaTahunan}
                    required
                />
                <BidangTanggal
                    label="Berlaku mulai"
                    nilai={formulir.data.BerlakuMulai}
                    saatBerubah={(nilai) => formulir.setData('BerlakuMulai', nilai)}
                    galat={formulir.errors.BerlakuMulai}
                    required
                />
                <div className="sm:col-span-3">
                    <KotakCentang
                        label="Terapkan juga ke pelanggan lama (tanpa penguncian harga lama)"
                        nilai={formulir.data.TerapkanKePelangganLama}
                        saatBerubah={(nilai) => formulir.setData('TerapkanKePelangganLama', nilai)}
                    />
                </div>
                <DialogFooter className="sm:col-span-3 sm:justify-start">
                    <Tombol type="submit" memproses={formulir.processing}>
                        Simpan draf harga
                    </Tombol>
                    <Tombol varian="sekunder" onClick={saatSelesai}>
                        Batal
                    </Tombol>
                </DialogFooter>
            </form>
        </DialogFormulir>
    );
}

function FormTinjauHarga({ alamat, harga, saatSelesai }: { alamat: string; harga: Harga; saatSelesai: () => void }) {
    const formulir = useForm({ Keputusan: 'Setuju', Catatan: '' });

    const Kirim = (keputusan: 'Setuju' | 'Tolak') => {
        formulir.transform((data) => ({ ...data, Keputusan: keputusan }));
        formulir.post(`${alamat}/${harga.Uuid}/tinjau`, { preserveScroll: true, onSuccess: saatSelesai });
    };

    return (
        <DialogTinjauan
            judul={`Tinjau harga ${FormatRupiah(harga.HargaBulanan)}/bulan mulai ${FormatTanggal(harga.BerlakuMulai)}`}
            deskripsi={
                harga.TerapkanKePelangganLama
                    ? 'Harga ini juga berlaku untuk pelanggan lama pada tagihan berikutnya.'
                    : 'Pelanggan lama tetap memakai harga lamanya.'
            }
            saatTutup={saatSelesai}
            galatUmum={(formulir.errors as Record<string, string | undefined>).Umum}
            aksi={
                <>
                    <Tombol memproses={formulir.processing} onClick={() => Kirim('Setuju')}>
                        Terbitkan harga
                    </Tombol>
                    <Tombol varian="bahaya" disabled={formulir.processing} onClick={() => Kirim('Tolak')}>
                        Tolak harga
                    </Tombol>
                </>
            }
        >
            <BidangTeks
                label="Catatan (wajib bila menolak)"
                nilai={formulir.data.Catatan}
                saatBerubah={(nilai) => formulir.setData('Catatan', nilai)}
                galat={formulir.errors.Catatan}
                maxLength={500}
            />
        </DialogTinjauan>
    );
}
