import { useState } from 'react';
import type { Column, Table } from '@tanstack/react-table';
import {
    ArrowDownIcon,
    ArrowUpIcon,
    Columns3Icon,
    DownloadIcon,
    FilterIcon,
    ListFilterIcon,
    SearchIcon,
} from 'lucide-react';
import type { ReactNode } from 'react';

import TombolPindaiKamera from '@/Komponen/Katalog/TombolPindaiKamera';
import TombolEkspor from '@/Komponen/Laporan/TombolEkspor';
import { Button } from '@/Komponen/Ui/button';
import Tombol from '@/Komponen/Formulir/Tombol';
import { Checkbox } from '@/Komponen/Ui/checkbox';
import { Input } from '@/Komponen/Ui/input';
import { Label } from '@/Komponen/Ui/label';
import { Popover, PopoverContent, PopoverTrigger } from '@/Komponen/Ui/popover';
import { cn } from '@/Komponen/Ui/utils';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/Komponen/Ui/sheet';

import { HitungSaringAktif, TulisUrut } from './KeadaanUrl';
import { ChipSaring, PenyuntingSaring, RingkasSaring } from './Saring';
import type { DefinisiSaring, KeadaanTabel, UrutKolom } from './Tipe';
import { AmbilMeta } from './Tipe';
import type { LebarLayar } from './useLebarLayar';
import PilihanCari from '@/Komponen/Formulir/PilihanCari';
import { CocokkanCari } from '@/Komponen/Formulir/PilihanCari';

type PropsBilahAlat<T> = {
    label: string;
    tabel: Table<T>;
    lebar: LebarLayar;
    keadaan: KeadaanTabel;
    teksCari: string;
    cari: string | false;
    pindaiKamera?: boolean | 'Seri';
    saring: DefinisiSaring[];
    ekspor?: { alamat: string; label?: string; query: string; laporan?: boolean; eksporBesar?: boolean };
    aksiAlat?: ReactNode;
    AturCari: (teks: string) => void;
    AturSaring: (id: string, nilai: string) => void;
    AturUrut: (urut: UrutKolom[]) => void;
    HapusSemua: () => void;
    AturTampilKolom: (kolom: string, tampil: boolean) => void;
    AturUrutanKolom: (urutan: string[]) => void;
    KembalikanKolom: () => void;
};

const kelasTombolAlat = 'h-8 pointer-coarse:h-11 gap-2 border-garis-input text-label font-semibold text-teks-utama';

function TombolSaring({
    definisi,
    nilai,
    AturSaring,
}: {
    definisi: DefinisiSaring;
    nilai: string;
    AturSaring: (id: string, nilai: string) => void;
}) {
    if (definisi.jenis === 'ya') {
        return (
            <Button
                type="button"
                variant={nilai === '1' ? 'default' : 'outline'}
                aria-pressed={nilai === '1'}
                onClick={() => AturSaring(definisi.id, nilai === '1' ? '' : '1')}
                className={nilai === '1' ? 'h-8 pointer-coarse:h-11 text-label font-semibold' : kelasTombolAlat}
            >
                {definisi.labelAktif ?? definisi.label}
            </Button>
        );
    }

    return (
        <Popover>
            <PopoverTrigger asChild>
                <Button type="button" variant="outline" className={kelasTombolAlat}>
                    <ListFilterIcon aria-hidden="true" className="size-4 text-teks-sekunder" />
                    {definisi.label}
                    {nilai !== '' || definisi.nilaiBawaan ? (
                        <span className="max-w-40 truncate font-normal text-teks-sekunder">
                            | {RingkasSaring(definisi, nilai === '' ? (definisi.nilaiBawaan ?? '') : nilai)}
                        </span>
                    ) : null}
                </Button>
            </PopoverTrigger>
            <PopoverContent
                align="start"
                className={cn(
                    'border-garis bg-permukaan p-3',
                    definisi.jenis === 'rentangTanggal' ? 'w-auto max-w-[calc(100vw-2rem)]' : 'w-80',
                )}
            >
                <p className="mb-2 text-label font-semibold text-teks-utama">{definisi.label}</p>
                <PenyuntingSaring
                    definisi={definisi}
                    nilai={nilai}
                    saatBerubah={(baru) => AturSaring(definisi.id, baru)}
                />
                {nilai !== '' ? (
                    <Button
                        type="button"
                        variant="link"
                        className="mt-2 h-auto px-0 text-label"
                        onClick={() => AturSaring(definisi.id, '')}
                    >
                        Hapus saring {definisi.label.toLowerCase()}
                    </Button>
                ) : null}
            </PopoverContent>
        </Popover>
    );
}

/** Atur kolom tampil & urutan (disimpan per pengguna). */
function AturKolom<T>({
    tabel,
    AturTampilKolom,
    AturUrutanKolom,
    KembalikanKolom,
}: Pick<PropsBilahAlat<T>, 'tabel' | 'AturTampilKolom' | 'AturUrutanKolom' | 'KembalikanKolom'>) {
    const kolom = tabel
        .getAllLeafColumns()
        .filter((k) => AmbilMeta(k.columnDef.meta)?.label && !AmbilMeta(k.columnDef.meta)?.wajib);
    const urutan = tabel
        .getAllLeafColumns()
        .map((k) => k.id)
        .filter((id) => kolom.some((k) => k.id === id));

    const [kataKolom, AturKataKolom] = useState('');
    const Pindah = (satu: Column<T>, arah: -1 | 1) => {
        const posisi = urutan.indexOf(satu.id);
        const tujuan = posisi + arah;

        if (tujuan < 0 || tujuan >= urutan.length) {
            return;
        }

        const baru = [...urutan];
        [baru[posisi], baru[tujuan]] = [baru[tujuan] ?? '', baru[posisi] ?? ''];
        AturUrutanKolom(baru);
    };

    return (
        <Popover>
            <PopoverTrigger asChild>
                <Button type="button" variant="outline" className={kelasTombolAlat}>
                    <Columns3Icon aria-hidden="true" className="size-4 text-teks-sekunder" />
                    Atur kolom
                </Button>
            </PopoverTrigger>
            <PopoverContent align="end" className="w-72 border-garis bg-permukaan p-3">
                <p className="mb-2 text-label font-semibold text-teks-utama">Kolom yang ditampilkan</p>
                <div className="mb-2 flex h-8 pointer-coarse:h-11 items-center gap-2 rounded-kontrol border border-garis-input bg-permukaan px-3 focus-within:border-brand focus-within:ring-2 focus-within:ring-brand/40">
                    <SearchIcon aria-hidden="true" className="size-4 shrink-0 text-teks-sekunder" />
                    <input
                        value={kataKolom}
                        onChange={(peristiwa) => AturKataKolom(peristiwa.target.value)}
                        placeholder="Cari kolom…"
                        aria-label="Cari kolom"
                        autoComplete="off"
                        className="h-full w-full bg-transparent text-isi text-teks-utama outline-none placeholder:text-teks-sekunder"
                    />
                </div>
                <ul className="flex max-h-80 flex-col gap-1 overflow-y-auto">
                    {urutan.map((idKolom, indeks) => {
                        const satu = kolom.find((k) => k.id === idKolom);

                        if (!satu) {
                            return null;
                        }

                        const label = AmbilMeta(satu.columnDef.meta)?.label ?? satu.id;

                        if (!CocokkanCari({ Nilai: satu.id, Label: label }, kataKolom)) {
                            return null;
                        }

                        return (
                            <li key={satu.id} className="flex min-h-10 items-center gap-2">
                                <Checkbox
                                    id={`kolom-${satu.id}`}
                                    checked={satu.getIsVisible()}
                                    onCheckedChange={(aktif) => AturTampilKolom(satu.id, aktif === true)}
                                />
                                <Label htmlFor={`kolom-${satu.id}`} className="flex-1 text-isi font-normal">
                                    {label}
                                </Label>
                                <Button
                                    type="button"
                                    size="icon"
                                    variant="ghost"
                                    className="size-8"
                                    disabled={indeks === 0}
                                    aria-label={`Geser kolom ${label} ke atas`}
                                    onClick={() => Pindah(satu, -1)}
                                >
                                    <ArrowUpIcon aria-hidden="true" className="size-4" />
                                </Button>
                                <Button
                                    type="button"
                                    size="icon"
                                    variant="ghost"
                                    className="size-8"
                                    disabled={indeks === urutan.length - 1}
                                    aria-label={`Geser kolom ${label} ke bawah`}
                                    onClick={() => Pindah(satu, 1)}
                                >
                                    <ArrowDownIcon aria-hidden="true" className="size-4" />
                                </Button>
                            </li>
                        );
                    })}
                </ul>
                <Button type="button" variant="link" className="mt-2 h-auto px-0 text-label" onClick={KembalikanKolom}>
                    Kembalikan kolom bawaan
                </Button>
            </PopoverContent>
        </Popover>
    );
}

/** Pilihan urut untuk HP (kepala kolom tidak terlihat di daftar bertumpuk). */
function PilihUrutHp<T>({
    tabel,
    keadaan,
    AturUrut,
}: {
    tabel: Table<T>;
    keadaan: KeadaanTabel;
    AturUrut: (urut: UrutKolom[]) => void;
}) {
    const bisaUrut = tabel.getAllLeafColumns().filter((k) => k.getCanSort() && AmbilMeta(k.columnDef.meta)?.label);

    if (bisaUrut.length === 0) {
        return null;
    }

    return (
        <div className="flex flex-col gap-1">
            <Label htmlFor="tabel-urut-hp" className="text-label font-semibold text-teks-utama">
                Urutkan
            </Label>
            <PilihanCari
                id="tabel-urut-hp"
                label="Urutkan"
                nilai={TulisUrut(keadaan.urut.slice(0, 1))}
                opsi={bisaUrut.flatMap((k) => [
                    { Nilai: k.id, Label: `${AmbilMeta(k.columnDef.meta)?.label ?? k.id} (naik)` },
                    { Nilai: `-${k.id}`, Label: `${AmbilMeta(k.columnDef.meta)?.label ?? k.id} (turun)` },
                ])}
                saatBerubah={(nilai) =>
                    AturUrut(nilai === '' ? [] : [{ id: nilai.replace(/^-/, ''), desc: nilai.startsWith('-') }])
                }
                className="h-11"
            />
        </div>
    );
}

/** Bilah alat `TabelData`: cari, saring (Popover di layar lebar, Sheet di HP), chip saring, atur kolom, ekspor. */
export default function BilahAlat<T>(props: PropsBilahAlat<T>) {
    const { keadaan, saring, lebar, AturSaring } = props;
    const jumlahSaring = HitungSaringAktif(keadaan);
    const adaPencarian = keadaan.cari.trim() !== '';
    const chip = saring.filter((d) => (keadaan.saring[d.id] ?? '') !== '');
    // Sheet saring & urut di HP hanya bila ada yang bisa disaring atau diurutkan (laporan bertingkat tidak punya).
    const adaSaringAtauUrut =
        saring.length > 0 ||
        props.tabel.getAllLeafColumns().some((k) => k.getCanSort() && AmbilMeta(k.columnDef.meta)?.label);

    const kotakCari =
        props.cari === false ? null : (
            <div className="flex min-w-0 flex-1 items-center gap-2 max-sm:basis-full sm:max-w-sm">
                <div className="relative min-w-0 flex-1">
                    <SearchIcon
                        aria-hidden="true"
                        className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-teks-sekunder"
                    />
                    <Input
                        type="search"
                        value={props.teksCari}
                        onChange={(e) => props.AturCari(e.target.value)}
                        placeholder={props.cari}
                        aria-label={`Cari di ${props.label}`}
                        className="h-11 border-garis-input bg-permukaan pl-9 text-isi sm:h-8"
                    />
                </div>
                {props.pindaiKamera === true ? (
                    <TombolPindaiKamera
                        ikonSaja
                        kelasIkon="border-garis-input bg-permukaan"
                        label={`Pindai barcode untuk mencari di ${props.label}`}
                        saatTerbaca={(barcode) => props.AturCari(barcode)}
                    />
                ) : null}
                {props.pindaiKamera === 'Seri' ? (
                    <TombolPindaiKamera
                        ikonSaja
                        kelasIkon="border-garis-input bg-permukaan"
                        jenisKode="Seri"
                        label={`Pindai nomor seri atau IMEI untuk mencari di ${props.label}`}
                        saatTerbaca={(kode) => props.AturCari(kode)}
                    />
                ) : null}
            </div>
        );

    // Laporan (D-43): satu tombol dengan pilihan Excel, CSV, dan cetak/PDF; ekspor data lain tetap satu tautan.
    const ekspor = props.ekspor?.laporan ? (
        <TombolEkspor
            alamat={props.ekspor.alamat}
            query={props.ekspor.query}
            label={props.ekspor.label ?? 'Ekspor'}
            eksporBesar={props.ekspor.eksporBesar === true}
            className={kelasTombolAlat}
        />
    ) : props.ekspor ? (
        <Button asChild variant="outline" className={kelasTombolAlat}>
            <a href={props.ekspor.query === '' ? props.ekspor.alamat : `${props.ekspor.alamat}?${props.ekspor.query}`}>
                <DownloadIcon aria-hidden="true" className="size-4 text-teks-sekunder" />
                {props.ekspor.label ?? 'Ekspor'}
            </a>
        </Button>
    ) : null;

    return (
        <div className="flex flex-col gap-2">
            <div className="flex flex-wrap items-center gap-2">
                {kotakCari}
                {lebar === 'hp' && adaSaringAtauUrut ? (
                    <Sheet>
                        <SheetTrigger asChild>
                            <Button type="button" variant="outline" className={`${kelasTombolAlat} h-11`}>
                                <FilterIcon aria-hidden="true" className="size-4 text-teks-sekunder" />
                                Saring{jumlahSaring > 0 ? ` (${String(jumlahSaring)})` : ''}
                            </Button>
                        </SheetTrigger>
                        <SheetContent side="bottom" className="max-h-[85vh] overflow-y-auto bg-permukaan">
                            <SheetHeader>
                                <SheetTitle className="text-subjudul text-teks-utama">Saring & urutkan</SheetTitle>
                                <SheetDescription className="text-isi text-teks-sekunder">
                                    Perubahan langsung diterapkan ke {props.label.toLowerCase()}.
                                </SheetDescription>
                            </SheetHeader>
                            <div className="flex flex-col gap-5 px-4">
                                <PilihUrutHp tabel={props.tabel} keadaan={keadaan} AturUrut={props.AturUrut} />
                                {saring.map((definisi) => (
                                    <section key={definisi.id} className="flex flex-col gap-2">
                                        {definisi.jenis === 'ya' ? null : (
                                            <h3 className="text-label font-semibold text-teks-utama">
                                                {definisi.label}
                                            </h3>
                                        )}
                                        <PenyuntingSaring
                                            definisi={definisi}
                                            nilai={keadaan.saring[definisi.id] ?? ''}
                                            saatBerubah={(baru) => AturSaring(definisi.id, baru)}
                                        />
                                    </section>
                                ))}
                            </div>
                            <SheetFooter>
                                <Tombol varian="sekunder" onClick={props.HapusSemua}>
                                    Hapus semua saring
                                </Tombol>
                            </SheetFooter>
                        </SheetContent>
                    </Sheet>
                ) : (
                    saring.map((definisi) => (
                        <TombolSaring
                            key={definisi.id}
                            definisi={definisi}
                            nilai={keadaan.saring[definisi.id] ?? ''}
                            AturSaring={AturSaring}
                        />
                    ))
                )}
                <div className="ml-auto flex flex-wrap items-center gap-2">
                    {lebar === 'hp' ? null : (
                        <AturKolom
                            tabel={props.tabel}
                            AturTampilKolom={props.AturTampilKolom}
                            AturUrutanKolom={props.AturUrutanKolom}
                            KembalikanKolom={props.KembalikanKolom}
                        />
                    )}
                    {ekspor}
                    {props.aksiAlat}
                </div>
            </div>
            {chip.length > 0 || adaPencarian ? (
                <div className="flex flex-wrap items-center gap-2" aria-label="Saring aktif">
                    {chip.map((definisi) => (
                        <ChipSaring
                            key={definisi.id}
                            definisi={definisi}
                            nilai={keadaan.saring[definisi.id] ?? ''}
                            saatHapus={() => AturSaring(definisi.id, '')}
                        />
                    ))}
                    <Button type="button" variant="link" className="h-9 px-1 text-label" onClick={props.HapusSemua}>
                        Hapus pencarian & saring
                    </Button>
                </div>
            ) : null}
        </div>
    );
}
