import { useForm } from '@inertiajs/react';
import { useState } from 'react';

import PemilihCariBengkel from '@/Komponen/Bengkel/PemilihCariBengkel';
import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import BidangTeksPanjang from '@/Komponen/Formulir/BidangTeksPanjang';
import KotakCentang from '@/Komponen/Formulir/KotakCentang';
import Tombol from '@/Komponen/Formulir/Tombol';
import BidangJumlah from '@/Komponen/Katalog/BidangJumlah';
import TombolPindaiKamera from '@/Komponen/Katalog/TombolPindaiKamera';
import DialogFormulir from '@/Komponen/Tindakan/DialogFormulir';
import { DialogFooter } from '@/Komponen/Ui/dialog';
import { type JenisUnit, KataUnit, LabelJenisUnit, LabelPengenal, PakaiJenisUnitBawaan } from '@/Pustaka/UnitServis';
import type { Kendaraan } from '@/Tipe/Bengkel';

type PelangganPilihan = { Uuid: string; Nama: string; NoHp: string | null };

type IsianKendaraan = {
    UuidPelanggan: string;
    Jenis: JenisUnit;
    NomorPolisi: string;
    Merek: string;
    Tipe: string;
    Tahun: string;
    Warna: string;
    NomorRangka: string;
    NomorMesin: string;
    KmTerakhir: string;
    Catatan: string;
    Aktif: boolean;
};

export const AlamatKendaraan = '/kelola/bengkel/kendaraan';

/**
 * Tambah/ubah unit servis pelanggan (§9.10, D-142): kendaraan atau perangkat elektronik. Nomor polisi dirapikan server ("ad1234xy" → "AD 1234 XY") dan unik di antara
 * kendaraan aktif; kendaraan yang berganti pemilik diarsipkan supaya nomornya bisa didaftarkan ulang tanpa menghapus
 * riwayat servisnya.
 */
export default function DialogKendaraan({
    kendaraan,
    pelanggan,
    saatTutup,
}: {
    kendaraan: Kendaraan | null;
    /** Pelanggan tetap (dari formulir perintah kerja / detail pelanggan); null = pilih di dialog. */
    pelanggan: { Uuid: string; Nama: string } | null;
    saatTutup: () => void;
}) {
    const [namaPelanggan, AturNamaPelanggan] = useState(kendaraan?.Pelanggan.Nama ?? pelanggan?.Nama ?? '');
    const jenisBawaan = PakaiJenisUnitBawaan();
    const formulir = useForm<IsianKendaraan>({
        UuidPelanggan: kendaraan?.Pelanggan.Uuid ?? pelanggan?.Uuid ?? '',
        Jenis: kendaraan?.Jenis ?? jenisBawaan ?? 'Kendaraan',
        NomorPolisi: kendaraan?.NomorPolisi ?? '',
        Merek: kendaraan?.Merek ?? '',
        Tipe: kendaraan?.Tipe ?? '',
        Tahun: kendaraan?.Tahun === null || kendaraan === null ? '' : String(kendaraan.Tahun),
        Warna: kendaraan?.Warna ?? '',
        NomorRangka: kendaraan?.NomorRangka ?? '',
        NomorMesin: kendaraan?.NomorMesin ?? '',
        KmTerakhir: kendaraan?.KmTerakhir === null || kendaraan === null ? '' : String(kendaraan.KmTerakhir),
        Catatan: kendaraan?.Catatan ?? '',
        Aktif: kendaraan?.Aktif ?? true,
    });
    const d = formulir.data;
    const galat = formulir.errors as Record<string, string | undefined>;
    const perangkat = d.Jenis === 'Perangkat';
    const kata = KataUnit(d.Jenis).toLowerCase();
    const judul =
        kendaraan === null
            ? `Tambah ${jenisBawaan === null ? 'unit servis' : kata}`
            : `Ubah ${kata} ${kendaraan.NomorPolisi ?? kendaraan.Label}`;

    return (
        <DialogFormulir judul={judul} lebar="lebar" saatTutup={saatTutup} galatUmum={galat.Umum}>
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    formulir.transform((isi) => ({
                        ...isi,
                        Tahun: isi.Tahun === '' ? null : Number(isi.Tahun),
                        KmTerakhir: isi.KmTerakhir === '' ? null : Number(isi.KmTerakhir),
                    }));
                    const pilihan = { preserveScroll: true, preserveState: true, onSuccess: saatTutup };

                    if (kendaraan === null) {
                        formulir.post(AlamatKendaraan, pilihan);
                    } else {
                        formulir.put(`${AlamatKendaraan}/${kendaraan.Uuid}`, pilihan);
                    }
                }}
                className="grid gap-4 sm:grid-cols-2"
                noValidate
                aria-label={judul}
            >
                {kendaraan === null && jenisBawaan === null ? (
                    <div className="sm:col-span-2">
                        <BidangPilihan
                            label="Jenis unit"
                            nilai={d.Jenis}
                            opsi={(Object.keys(LabelJenisUnit) as JenisUnit[]).map((j) => ({
                                Nilai: j,
                                Label: LabelJenisUnit[j],
                            }))}
                            saatBerubah={(nilai) => formulir.setData('Jenis', nilai as JenisUnit)}
                        />
                    </div>
                ) : null}
                <div className="sm:col-span-2">
                    {pelanggan === null ? (
                        <PemilihCariBengkel<PelangganPilihan>
                            sumber="pelanggan"
                            label="Pemilik (pelanggan)"
                            placeholder="Pilih pelanggan"
                            buatUrl={(kata) =>
                                `/kelola/bengkel/pelanggan/cari?${new URLSearchParams({ kata }).toString()}`
                            }
                            ambilId={(p) => p.Uuid}
                            ambilJudul={(p) => p.Nama}
                            ambilKeterangan={(p) => p.NoHp}
                            saatPilih={(p) => {
                                formulir.setData('UuidPelanggan', p.Uuid);
                                AturNamaPelanggan(p.Nama);
                            }}
                            nilaiTerpilih={d.UuidPelanggan === '' ? undefined : namaPelanggan}
                            pesanKosong="Belum ada pelanggan aktif. Tambahkan dulu di Pelanggan."
                            galat={galat.UuidPelanggan}
                            wajib
                        />
                    ) : (
                        <p className="text-isi text-teks-utama">
                            Pemilik: <span className="font-semibold">{pelanggan.Nama}</span>
                        </p>
                    )}
                </div>
                <div className="flex items-start gap-2">
                    <div className="min-w-0 flex-1">
                        <BidangTeks
                            label={LabelPengenal(d.Jenis)}
                            nilai={d.NomorPolisi}
                            saatBerubah={(nilai) => formulir.setData('NomorPolisi', nilai)}
                            galat={galat.NomorPolisi}
                            keterangan={
                                perangkat
                                    ? 'Boleh dikosongkan. Tekan *#06# di HP untuk melihat IMEI, atau pindai kodenya di kemasan.'
                                    : 'Contoh: AD 1234 XY. Huruf & spasi dirapikan otomatis.'
                            }
                            kode
                            required={!perangkat}
                            maxLength={perangkat ? 40 : 20}
                        />
                    </div>
                    {perangkat ? (
                        <div className="pt-6">
                            <TombolPindaiKamera
                                ikonSaja
                                jenisKode="Seri"
                                label="Pindai IMEI atau nomor seri perangkat"
                                saatTerbaca={(kode) => formulir.setData('NomorPolisi', kode.slice(0, 40))}
                            />
                        </div>
                    ) : null}
                </div>
                <BidangTeks
                    label="Merek"
                    nilai={d.Merek}
                    saatBerubah={(nilai) => formulir.setData('Merek', nilai)}
                    galat={galat.Merek}
                    required
                    maxLength={50}
                />
                <BidangTeks
                    label="Tipe"
                    nilai={d.Tipe}
                    saatBerubah={(nilai) => formulir.setData('Tipe', nilai)}
                    galat={galat.Tipe}
                    maxLength={80}
                />
                <BidangJumlah
                    label="Tahun"
                    nilai={d.Tahun}
                    saatBerubah={(nilai) => formulir.setData('Tahun', nilai)}
                    galat={galat.Tahun}
                    desimal={0}
                    digitBulat={4}
                />
                <BidangTeks
                    label="Warna"
                    nilai={d.Warna}
                    saatBerubah={(nilai) => formulir.setData('Warna', nilai)}
                    galat={galat.Warna}
                    maxLength={30}
                />
                {perangkat ? null : (
                    <>
                        <BidangJumlah
                            label="KM terakhir"
                            nilai={d.KmTerakhir}
                            saatBerubah={(nilai) => formulir.setData('KmTerakhir', nilai)}
                            galat={galat.KmTerakhir}
                            desimal={0}
                            digitBulat={7}
                            akhiran="km"
                        />
                        <BidangTeks
                            label="Nomor rangka"
                            nilai={d.NomorRangka}
                            saatBerubah={(nilai) => formulir.setData('NomorRangka', nilai)}
                            galat={galat.NomorRangka}
                            kode
                            maxLength={40}
                        />
                        <BidangTeks
                            label="Nomor mesin"
                            nilai={d.NomorMesin}
                            saatBerubah={(nilai) => formulir.setData('NomorMesin', nilai)}
                            galat={galat.NomorMesin}
                            kode
                            maxLength={40}
                        />
                    </>
                )}
                <div className="sm:col-span-2">
                    <BidangTeksPanjang
                        label="Catatan"
                        nilai={d.Catatan}
                        saatBerubah={(nilai) => formulir.setData('Catatan', nilai)}
                        galat={galat.Catatan}
                        maksimal={255}
                    />
                </div>
                {kendaraan !== null ? (
                    <div className="sm:col-span-2">
                        <KotakCentang
                            label={`${KataUnit(d.Jenis)} aktif (hapus centang bila sudah dijual atau berganti pemilik)`}
                            nilai={d.Aktif}
                            saatBerubah={(nilai) => formulir.setData('Aktif', nilai)}
                        />
                    </div>
                ) : null}
                <DialogFooter className="sm:col-span-2 sm:justify-start">
                    <Tombol type="submit" memproses={formulir.processing}>
                        Simpan {jenisBawaan === null && kendaraan === null ? 'unit' : kata}
                    </Tombol>
                    <Tombol type="button" varian="sekunder" onClick={saatTutup}>
                        Batal
                    </Tombol>
                </DialogFooter>
            </form>
        </DialogFormulir>
    );
}
