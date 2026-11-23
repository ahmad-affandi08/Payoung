import { Link, router, useForm, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import KotakCentang from '@/Komponen/Formulir/KotakCentang';
import Tombol from '@/Komponen/Formulir/Tombol';
import DaftarGalatServer from '@/Komponen/Katalog/DaftarGalatServer';
import PesanHanyaLihat from '@/Komponen/Katalog/PesanHanyaLihat';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import DialogFormulir from '@/Komponen/Tindakan/DialogFormulir';
import { ItemAksiBaris } from '@/Komponen/Tindakan/MenuAksiBaris';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import type { PropsDaftarStasiunDapur, StasiunDapur } from '@/Tipe/Katalog';

const alamat = '/kelola/stasiun-dapur';

const kolom: KolomTabel<StasiunDapur>[] = [
    {
        id: 'Nama',
        accessorKey: 'Nama',
        header: 'Stasiun',
        meta: { label: 'Stasiun', prioritas: 'utama', wajib: true, kelasSel: 'font-semibold text-teks-utama' },
    },
    {
        id: 'Jenis',
        accessorKey: 'Salinan',
        header: 'Jenis',
        meta: { label: 'Jenis', prioritas: 'penting' },
        cell: ({ row }) =>
            row.original.Salinan ? (
                <LabelStatus jenis="netral" teks="Checker (salinan semua tiket)" />
            ) : (
                <span className="text-teks-sekunder">Memasak</span>
            ),
    },
    {
        id: 'Urutan',
        accessorKey: 'Urutan',
        header: 'Urutan',
        meta: { label: 'Urutan tampil', angka: true, prioritas: 'rendah', kelasSel: 'text-teks-sekunder' },
    },
    {
        id: 'Status',
        accessorKey: 'Status',
        header: 'Status',
        meta: { label: 'Status', prioritas: 'penting' },
        cell: ({ row }) =>
            row.original.Status === 'Aktif' ? (
                <LabelStatus jenis="sukses" teks="Aktif" />
            ) : (
                <LabelStatus jenis="netral" teks="Diarsipkan" />
            ),
    },
];

/**
 * F-10a stasiun dapur tingkat tenant (Dapur, Bar, Pastry). Item pesanan dirutekan ke stasiun lewat kategori
 * produknya; kategori tanpa stasiun masuk ke stasiun bawaan (stasiun aktif pertama).
 */
export default function HalamanDaftarStasiunDapur({ Stasiun, Izin }: PropsDaftarStasiunDapur) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const [sunting, AturSunting] = useState<StasiunDapur | 'baru' | null>(null);
    const bawaan = Stasiun.find((s) => s.Status === 'Aktif' && !s.Salinan);

    return (
        <TataLetakAplikasi judul="Stasiun dapur">
            <p className="text-keterangan text-teks-sekunder">
                Pesanan dikirim ke layar dapur (KDS) atau printer per stasiun. Atur stasiun tiap kategori di halaman{' '}
                <Link href="/kelola/kategori" className="font-semibold text-brand underline">
                    kategori produk
                </Link>
                , dan tiap menu di halamannya. Kasir juga bisa mengaturnya langsung dari aplikasi kasir.{' '}
                {bawaan
                    ? `Kategori tanpa stasiun masuk ke ${bawaan.Nama} (stasiun bawaan).`
                    : 'Tambah minimal satu stasiun bila usaha Anda memakai dapur atau bar.'}
            </p>
            {!Izin.Kelola ? <PesanHanyaLihat izin="produk.kelola" objek="stasiun dapur" /> : null}
            <DaftarGalatServer galat={props.errors} kecuali={sunting !== null ? ['Nama', 'Urutan', 'Salinan'] : []} />
            <AksiHalaman>
                {Izin.Kelola ? <Tombol onClick={() => AturSunting('baru')}>Tambah stasiun</Tombol> : null}
            </AksiHalaman>
            {sunting !== null ? (
                <DialogFormulir
                    judul={sunting === 'baru' ? 'Tambah stasiun dapur' : `Ubah stasiun ${sunting.Nama}`}
                    saatTutup={() => AturSunting(null)}
                >
                    <FormStasiun
                        key={sunting === 'baru' ? 'baru' : sunting.Uuid}
                        stasiun={sunting === 'baru' ? null : sunting}
                        saatSelesai={() => AturSunting(null)}
                    />
                </DialogFormulir>
            ) : null}
            <TabelData
                id="katalog-stasiun-dapur"
                label="Daftar stasiun dapur"
                kolom={kolom}
                sumber={{ mode: 'lokal', data: Stasiun }}
                ambilIdBaris={(s) => s.Uuid}
                cari={false}
                labelBaris={(s) => `stasiun ${s.Nama}`}
                {...(Izin.Kelola
                    ? {
                          aksiBaris: (s: StasiunDapur) => (
                              <ItemAksiBaris
                                  aksi={[
                                      { label: 'Ubah', saatPilih: () => AturSunting(s) },
                                      {
                                          label: s.Status === 'Aktif' ? 'Arsipkan' : 'Pulihkan',
                                          bahaya: s.Status === 'Aktif',
                                          saatPilih: () =>
                                              router.post(
                                                  `${alamat}/${s.Uuid}/${s.Status === 'Aktif' ? 'arsipkan' : 'pulihkan'}`,
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
                    judul: 'Belum ada stasiun dapur. Toko tanpa dapur atau bar tidak perlu menambahkannya.',
                }}
            />
        </TataLetakAplikasi>
    );
}

function FormStasiun({ stasiun, saatSelesai }: { stasiun: StasiunDapur | null; saatSelesai: () => void }) {
    const formulir = useForm({
        Nama: stasiun?.Nama ?? '',
        Urutan: String(stasiun?.Urutan ?? 0),
        Salinan: stasiun?.Salinan ?? false,
    });

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        const opsi = { preserveScroll: true, onSuccess: saatSelesai };

        if (stasiun === null) {
            formulir.post(alamat, opsi);
        } else {
            formulir.put(`${alamat}/${stasiun.Uuid}`, opsi);
        }
    };

    return (
        <form onSubmit={Kirim} className="flex flex-col gap-4" noValidate>
            <BidangTeks
                label="Nama stasiun"
                nilai={formulir.data.Nama}
                saatBerubah={(nilai) => formulir.setData('Nama', nilai)}
                galat={formulir.errors.Nama}
                keterangan="Misal Dapur, Bar, atau Pastry"
                maxLength={60}
                autoFocus
                required
            />
            <BidangTeks
                label="Urutan tampil"
                nilai={formulir.data.Urutan}
                saatBerubah={(nilai) => formulir.setData('Urutan', nilai.replace(/\D/g, ''))}
                galat={formulir.errors.Urutan}
                keterangan="Stasiun aktif dengan urutan terkecil menjadi stasiun bawaan"
                inputMode="numeric"
                maxLength={3}
            />
            <KotakCentang
                label="Checker: terima salinan semua tiket di printer"
                nilai={formulir.data.Salinan}
                saatBerubah={(nilai) => formulir.setData('Salinan', nilai)}
            />
            {formulir.errors.Salinan ? (
                <p className="text-keterangan font-semibold text-bahaya">{formulir.errors.Salinan}</p>
            ) : null}
            <p className="text-keterangan text-teks-sekunder">
                Checker mencetak salinan semua menu yang dikirim ke dapur atau bar, untuk dicek sebelum disajikan. Ia
                tidak memasak, jadi tidak dipilih sebagai tujuan kategori atau menu dan tidak punya layar dapur.
            </p>
            <div className="flex flex-wrap gap-2">
                <Tombol type="submit" memproses={formulir.processing}>
                    Simpan stasiun
                </Tombol>
                <Tombol varian="sekunder" onClick={saatSelesai}>
                    Batal
                </Tombol>
            </div>
        </form>
    );
}
