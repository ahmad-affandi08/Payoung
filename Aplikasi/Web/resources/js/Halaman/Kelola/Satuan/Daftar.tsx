import { router, useForm, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import KotakCentang from '@/Komponen/Formulir/KotakCentang';
import Tombol from '@/Komponen/Formulir/Tombol';
import DaftarGalatServer from '@/Komponen/Katalog/DaftarGalatServer';
import PesanHanyaLihat from '@/Komponen/Katalog/PesanHanyaLihat';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import DialogKonfirmasi from '@/Komponen/Tindakan/DialogKonfirmasi';
import { Badge } from '@/Komponen/Ui/badge';
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
import type { PropsDaftarSatuan } from '@/Tipe/Katalog';

type Satuan = PropsDaftarSatuan['Satuan'][number];

function FormSatuan({ satuan, saatSelesai }: { satuan: Satuan | null; saatSelesai: () => void }) {
    const formulir = useForm({
        Nama: satuan?.Nama ?? '',
        Simbol: satuan?.Simbol ?? '',
        BolehDesimal: satuan?.BolehDesimal ?? false,
    });
    const galat = formulir.errors as Record<string, string | undefined>;

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        const opsi = { preserveScroll: true, onSuccess: saatSelesai };

        if (satuan === null) {
            formulir.post('/kelola/satuan', opsi);
        } else {
            formulir.put(`/kelola/satuan/${satuan.Uuid}`, opsi);
        }
    };

    return (
        <form
            onSubmit={Kirim}
            noValidate
            aria-label={satuan ? `Ubah satuan ${satuan.Nama}` : 'Tambah satuan'}
            className="flex flex-col gap-4"
        >
            <BidangTeks
                label="Nama satuan"
                nilai={formulir.data.Nama}
                saatBerubah={(nilai) => formulir.setData('Nama', nilai)}
                galat={galat.Nama}
                keterangan="Misal Kilogram, Porsi, atau Dus."
                maxLength={50}
                autoFocus
                required
            />
            <BidangTeks
                label="Simbol"
                nilai={formulir.data.Simbol}
                saatBerubah={(nilai) => formulir.setData('Simbol', nilai)}
                galat={galat.Simbol}
                keterangan="Tampil di struk dan tabel, misal kg."
                maxLength={10}
                required
            />
            <div className="flex flex-col gap-1">
                <KotakCentang
                    label="Boleh pecahan (misal 0,5 kg)"
                    nilai={formulir.data.BolehDesimal}
                    saatBerubah={(nilai) => formulir.setData('BolehDesimal', nilai)}
                />
                {galat.BolehDesimal ? (
                    <p className="text-keterangan font-semibold text-bahaya">{galat.BolehDesimal}</p>
                ) : (
                    <p className="text-keterangan text-teks-sekunder">
                        Tidak bisa diubah bila satuan ini sudah menjadi satuan dasar produk.
                    </p>
                )}
            </div>
            <DialogFooter>
                <Tombol type="button" varian="sekunder" onClick={saatSelesai}>
                    Batal
                </Tombol>
                <Tombol type="submit" memproses={formulir.processing}>
                    Simpan satuan
                </Tombol>
            </DialogFooter>
        </form>
    );
}

const kolom: KolomTabel<Satuan>[] = [
    {
        id: 'Nama',
        accessorFn: (item) => `${item.Nama} ${item.Simbol}`,
        header: 'Satuan',
        meta: { label: 'Satuan', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: item } }) => (
            <>
                <span className="font-semibold text-teks-utama">{item.Nama}</span>{' '}
                <span className="text-teks-sekunder">({item.Simbol})</span>
            </>
        ),
    },
    {
        id: 'BolehDesimal',
        accessorKey: 'BolehDesimal',
        header: 'Pecahan',
        meta: { label: 'Boleh pecahan', prioritas: 'penting', kelasSel: 'text-teks-sekunder' },
        cell: ({ row }) => (row.original.BolehDesimal ? 'Boleh' : 'Tidak'),
    },
    {
        id: 'Asal',
        accessorFn: (item) => (item.KodeStandar ? 'Standar' : 'Sendiri'),
        header: 'Asal',
        meta: { label: 'Asal', prioritas: 'rendah', kelasSel: 'text-teks-sekunder' },
        cell: ({ row: { original: item } }) =>
            item.KodeStandar ? (
                <span className="inline-flex items-center gap-2">
                    Standar
                    <Badge variant="secondary" className="font-mono">
                        {item.KodeStandar}
                    </Badge>
                </span>
            ) : (
                'Buatan sendiri'
            ),
    },
    {
        id: 'JumlahProduk',
        accessorKey: 'JumlahProduk',
        header: 'Produk',
        meta: { label: 'Jumlah produk', angka: true, prioritas: 'penting' },
    },
];

/** F-03 satuan ukur tenant: standar (dari referensi) dan buatan sendiri. */
export default function HalamanDaftarSatuan({ Satuan, Izin }: PropsDaftarSatuan) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const [sunting, AturSunting] = useState<Satuan | 'baru' | null>(null);
    const [hapus, AturHapus] = useState<Satuan | null>(null);

    return (
        <TataLetakAplikasi judul="Satuan">
            <p className="text-keterangan text-teks-sekunder">
                Satuan dipakai untuk stok, harga, dan resep. Konversi (misal 1 dus = 24 pcs) diatur per produk.
            </p>
            {!Izin.Kelola ? <PesanHanyaLihat izin="produk.kelola" objek="satuan" /> : null}
            <DaftarGalatServer
                galat={props.errors}
                kecuali={sunting !== null ? ['Nama', 'Simbol', 'BolehDesimal'] : []}
            />
            <AksiHalaman>
                {Izin.Kelola ? (
                    <Button type="button" onClick={() => AturSunting('baru')}>
                        Tambah satuan
                    </Button>
                ) : null}
            </AksiHalaman>
            <Dialog open={sunting !== null} onOpenChange={(buka) => (buka ? undefined : AturSunting(null))}>
                {sunting !== null ? (
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>
                                {sunting === 'baru' ? 'Tambah satuan' : `Ubah satuan ${sunting.Nama}`}
                            </DialogTitle>
                            <DialogDescription>Satuan dipakai untuk stok, harga, dan resep produk.</DialogDescription>
                        </DialogHeader>
                        <FormSatuan
                            key={sunting === 'baru' ? 'baru' : sunting.Uuid}
                            satuan={sunting === 'baru' ? null : sunting}
                            saatSelesai={() => AturSunting(null)}
                        />
                    </DialogContent>
                ) : null}
            </Dialog>
            {hapus !== null ? (
                <DialogKonfirmasi
                    judul={`Hapus satuan ${hapus.Nama}?`}
                    labelAksi="Hapus satuan"
                    saatBatal={() => AturHapus(null)}
                    saatKonfirmasi={() =>
                        router.delete(`/kelola/satuan/${hapus.Uuid}`, {
                            preserveScroll: true,
                            onFinish: () => AturHapus(null),
                        })
                    }
                >
                    Satuan ini belum dipakai produk mana pun.
                </DialogKonfirmasi>
            ) : null}
            <TabelData
                id="katalog-satuan"
                label="Daftar satuan"
                kolom={kolom}
                sumber={{ mode: 'lokal', data: Satuan }}
                ambilIdBaris={(item) => item.Uuid}
                urutBawaan="Nama"
                cari="Cari nama atau simbol satuan"
                saring={[
                    {
                        id: 'Asal',
                        label: 'Asal',
                        jenis: 'pilihan',
                        opsi: [
                            { nilai: 'Standar', label: 'Standar' },
                            { nilai: 'Sendiri', label: 'Buatan sendiri' },
                        ],
                    },
                ]}
                labelBaris={(item) => item.Nama}
                {...(Izin.Kelola
                    ? {
                          aksiBaris: (item: Satuan) => (
                              <>
                                  <DropdownMenuItem onSelect={() => AturSunting(item)}>Ubah satuan</DropdownMenuItem>
                                  {item.JumlahProduk === 0 ? (
                                      <DropdownMenuItem variant="destructive" onSelect={() => AturHapus(item)}>
                                          Hapus satuan
                                      </DropdownMenuItem>
                                  ) : null}
                              </>
                          ),
                      }
                    : {})}
                kosong={{ ilustrasi: true, judul: 'Belum ada satuan. Tambah satuan, misal pcs atau kg.' }}
            />
        </TataLetakAplikasi>
    );
}
