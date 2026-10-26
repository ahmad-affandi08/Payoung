import { Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

import BidangOutlet from '@/Komponen/Formulir/BidangOutlet';
import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import BidangTeksPanjang from '@/Komponen/Formulir/BidangTeksPanjang';
import BidangUang from '@/Komponen/Formulir/BidangUang';
import BilahAksiForm from '@/Komponen/Formulir/BilahAksiForm';
import Tombol from '@/Komponen/Formulir/Tombol';
import Panel from '@/Komponen/Kelola/Panel';
import PemilihTanggal from '@/Komponen/Tanggal/PemilihTanggal';
import { Button } from '@/Komponen/Ui/button';
import { FormatRupiah } from '@/Pustaka/Format';
import {
    BacaDesimal,
    BulatkanDesimal,
    CekDesimalValid,
    KurangiDesimal,
    SkalaUang,
    TulisDesimal,
} from '@/Pustaka/HitungDesimal';
import { TulisTanggal } from '@/Pustaka/Tanggal';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsBuatAsetTetap } from '@/Tipe/Akuntansi';

import { FormatMasaManfaat } from './Daftar';

const alamat = '/kelola/akuntansi/aset-tetap';

/**
 * Perkiraan penyusutan per bulan (tampilan saja; nilai yang dijurnal dihitung server): (harga − nilai sisa −
 * akumulasi) ÷ masa manfaat, dibulatkan ke bawah 2 desimal. BigInt berskala, tanpa float (aturan #7).
 */
export function PerkiraanPerBulan(
    harga: string,
    nilaiSisa: string,
    akumulasi: string,
    umurBulan: number,
): string | null {
    const teks = [harga || '0', nilaiSisa || '0', akumulasi || '0'];

    if (umurBulan <= 0 || !teks.every(CekDesimalValid)) {
        return null;
    }

    const dasar = BacaDesimal(
        BulatkanDesimal(KurangiDesimal(KurangiDesimal(teks[0] ?? '0', teks[1] ?? '0'), teks[2] ?? '0'), SkalaUang),
    );

    return dasar.nilai > 0n ? TulisDesimal(dasar.nilai / BigInt(umurBulan), SkalaUang) : null;
}

/**
 * Catat aset tetap (FIN-10). Dua asal: **dibeli sekarang** (dibayar dari kas/bank, penyusutan mulai bulan perolehan)
 * atau **sudah dimiliki sebelumnya** (saldo awal: isi akumulasi penyusutan yang sudah berjalan; sisa nilai disusutkan
 * selama sisa masa manfaat mulai bulan ini).
 */
export default function HalamanBuatAsetTetap({ OpsiKelompok, OpsiOutlet, OpsiAkun, UmurMaksimal }: PropsBuatAsetTetap) {
    const hariIni = TulisTanggal(new Date());
    const awal = OpsiKelompok[0];
    const formulir = useForm({
        Outlet: '',
        Nama: '',
        Kelompok: awal?.Nilai ?? 'Kelompok1',
        TanggalPerolehan: hariIni,
        HargaPerolehan: '',
        NilaiSisa: '0',
        UmurBulan: String(awal?.UmurBulan ?? 48),
        SumberDana: 'KasBank',
        AkunKasBank: OpsiAkun[0]?.Uuid ?? '',
        AkumulasiAwal: '0',
        Catatan: '',
    });
    const d = formulir.data;
    const tanah = d.Kelompok === 'Tanah';
    const saldoAwal = d.SumberDana === 'SaldoAwal';
    const perBulan = tanah
        ? null
        : PerkiraanPerBulan(d.HargaPerolehan, d.NilaiSisa, saldoAwal ? d.AkumulasiAwal : '0', Number(d.UmurBulan));

    const GantiKelompok = (nilai: string) => {
        const kelompok = OpsiKelompok.find((k) => k.Nilai === nilai);
        formulir.setData((lama) => ({
            ...lama,
            Kelompok: nilai,
            UmurBulan: String(kelompok?.UmurBulan ?? lama.UmurBulan),
        }));
    };

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.post(alamat);
    };

    return (
        <TataLetakAplikasi judul="Catat aset tetap" jejak={[{ label: 'Aset tetap', href: alamat }]}>
            <form onSubmit={Kirim} className="flex flex-col gap-4" noValidate>
                <Panel>
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <BidangOutlet
                            label="Outlet (opsional)"
                            kosong="Semua outlet / kantor pusat"
                            required={false}
                            nilai={d.Outlet}
                            opsi={OpsiOutlet.map((o) => ({ Nilai: o.Uuid, Label: o.Nama }))}
                            saatBerubah={(nilai) => formulir.setData('Outlet', nilai)}
                            galat={formulir.errors.Outlet}
                        />
                        <BidangTeks
                            label="Nama aset"
                            nilai={d.Nama}
                            saatBerubah={(nilai) => formulir.setData('Nama', nilai)}
                            galat={formulir.errors.Nama}
                            maxLength={150}
                            required
                        />
                        <div>
                            <BidangPilihan
                                label="Kelompok harta"
                                nilai={d.Kelompok}
                                opsi={OpsiKelompok.map((k) => ({ Nilai: k.Nilai, Label: k.Label }))}
                                saatBerubah={GantiKelompok}
                                galat={formulir.errors.Kelompok}
                                required
                            />
                        </div>
                        <PemilihTanggal
                            label="Tanggal perolehan"
                            nilai={d.TanggalPerolehan}
                            saatBerubah={(nilai) => formulir.setData('TanggalPerolehan', nilai)}
                            galat={formulir.errors.TanggalPerolehan}
                            max={hariIni}
                            required
                        />
                        <BidangUang
                            label="Harga perolehan"
                            nilai={d.HargaPerolehan}
                            saatBerubah={(nilai) => formulir.setData('HargaPerolehan', nilai)}
                            galat={formulir.errors.HargaPerolehan}
                            required
                        />
                        {tanah ? null : (
                            <>
                                <BidangTeks
                                    label="Masa manfaat (bulan)"
                                    nilai={d.UmurBulan}
                                    saatBerubah={(nilai) => formulir.setData('UmurBulan', nilai.replace(/\D/g, ''))}
                                    galat={formulir.errors.UmurBulan}
                                    keterangan={`${FormatMasaManfaat(Number(d.UmurBulan))}. Maksimal ${String(UmurMaksimal)} bulan`}
                                    inputMode="numeric"
                                    maxLength={3}
                                    required
                                />
                                <BidangUang
                                    label="Nilai sisa (opsional)"
                                    nilai={d.NilaiSisa}
                                    saatBerubah={(nilai) => formulir.setData('NilaiSisa', nilai)}
                                    galat={formulir.errors.NilaiSisa}
                                />
                            </>
                        )}
                    </div>
                </Panel>

                <Panel judul="Asal aset">
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <BidangPilihan
                            label="Asal"
                            nilai={d.SumberDana}
                            opsi={[
                                { Nilai: 'KasBank', Label: 'Dibeli sekarang (dibayar dari kas/bank)' },
                                { Nilai: 'SaldoAwal', Label: 'Sudah dimiliki sebelum memakai aplikasi (saldo awal)' },
                            ]}
                            saatBerubah={(nilai) => formulir.setData('SumberDana', nilai)}
                            galat={formulir.errors.SumberDana}
                            required
                        />
                        {saldoAwal ? (
                            tanah ? null : (
                                <BidangUang
                                    label="Akumulasi penyusutan sampai bulan lalu"
                                    nilai={d.AkumulasiAwal}
                                    saatBerubah={(nilai) => formulir.setData('AkumulasiAwal', nilai)}
                                    galat={formulir.errors.AkumulasiAwal}
                                />
                            )
                        ) : (
                            <BidangPilihan
                                label="Dibayar dari"
                                nilai={d.AkunKasBank}
                                opsi={OpsiAkun.map((a) => ({ Nilai: a.Uuid, Label: `${a.Kode} ${a.Nama}` }))}
                                saatBerubah={(nilai) => formulir.setData('AkunKasBank', nilai)}
                                galat={formulir.errors.AkunKasBank}
                                required
                            />
                        )}
                        <div className="sm:col-span-2 lg:col-span-3">
                            <BidangTeksPanjang
                                label="Catatan (opsional)"
                                nilai={d.Catatan}
                                saatBerubah={(nilai) => formulir.setData('Catatan', nilai)}
                                maksimal={500}
                                baris={2}
                                galat={formulir.errors.Catatan}
                            />
                        </div>
                    </div>
                    <p className="mt-3 text-isi text-teks-sekunder">
                        {tanah
                            ? 'Tanah tidak disusutkan.'
                            : perBulan === null
                              ? 'Isi harga perolehan untuk melihat perkiraan penyusutan per bulan.'
                              : `Perkiraan penyusutan ${FormatRupiah(perBulan)} per bulan${saldoAwal ? ' untuk sisa masa manfaat' : ''}.`}
                    </p>
                </Panel>

                <BilahAksiForm>
                    <Tombol type="submit" memproses={formulir.processing}>
                        Simpan aset
                    </Tombol>
                    <Button asChild variant="outline">
                        <Link href={alamat}>Batal</Link>
                    </Button>
                </BilahAksiForm>
            </form>
        </TataLetakAplikasi>
    );
}
