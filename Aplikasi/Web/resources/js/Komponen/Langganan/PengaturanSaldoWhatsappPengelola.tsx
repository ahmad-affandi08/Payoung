import { useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

import BidangTeksPanjang from '@/Komponen/Formulir/BidangTeksPanjang';
import BidangUang from '@/Komponen/Formulir/BidangUang';
import Tombol from '@/Komponen/Formulir/Tombol';
import { AmbilGalatUmum, KerangkaForm } from '@/Komponen/Pengelola/Tenant/FormTindakan';
import { FormatRupiah } from '@/Pustaka/Format';

import BarisPengaturanKonsol from './BarisPengaturanKonsol';

export type PengaturanSaldoWhatsapp = { HargaPerPesan: string; TopUpMinimal: string };

const TanpaDesimal = (nilai: string) => nilai.replace(/\.\d+$/, '');

/**
 * D-300: harga per pesan broadcast WhatsApp dan minimal isi saldo. Semua peran Tagihan boleh melihat, hanya Super Admin
 * (izin `tagihan.pengaturan`) yang bisa mengubah. Harga 0 berarti broadcast gratis.
 */
export function PanelSaldoWhatsapp({ pengaturan, boleh }: { pengaturan: PengaturanSaldoWhatsapp; boleh: boolean }) {
    const [mengubah, AturMengubah] = useState(false);
    const gratis = Number(pengaturan.HargaPerPesan) === 0;

    return (
        <BarisPengaturanKonsol
            judul="Saldo WhatsApp tenant"
            aksi={
                boleh ? (
                    <Tombol varian="sekunder" onClick={() => AturMengubah(true)}>
                        Ubah harga dan minimal isi
                    </Tombol>
                ) : undefined
            }
            keterangan="Hanya broadcast yang memotong saldo. Pesan struk, poin, dan laporan karyawan tetap lewat kuota paket."
        >
            <p>
                Broadcast WhatsApp:{' '}
                <strong className="tabular-nums text-teks-utama">
                    {gratis ? 'gratis' : `${FormatRupiah(pengaturan.HargaPerPesan)} per pesan`}
                </strong>
                . Minimal isi saldo{' '}
                <strong className="tabular-nums text-teks-utama">{FormatRupiah(pengaturan.TopUpMinimal)}</strong>.
            </p>
            {mengubah ? <FormUbahSaldo pengaturan={pengaturan} saatSelesai={() => AturMengubah(false)} /> : null}
        </BarisPengaturanKonsol>
    );
}

function FormUbahSaldo({ pengaturan, saatSelesai }: { pengaturan: PengaturanSaldoWhatsapp; saatSelesai: () => void }) {
    const formulir = useForm({
        HargaPerPesan: TanpaDesimal(pengaturan.HargaPerPesan),
        TopUpMinimal: TanpaDesimal(pengaturan.TopUpMinimal),
        Alasan: '',
    });
    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.put('/tagihan/pengaturan/saldo-whatsapp', { preserveScroll: true, onSuccess: saatSelesai });
    };

    return (
        <KerangkaForm
            judul="Ubah pengaturan saldo WhatsApp"
            keterangan={
                <p>Isi 0 pada harga bila broadcast harus gratis. Berlaku untuk pesan dan isi saldo berikutnya.</p>
            }
            labelKirim="Simpan pengaturan"
            memproses={formulir.processing}
            galatUmum={AmbilGalatUmum(formulir.errors)}
            saatKirim={Kirim}
            saatBatal={saatSelesai}
        >
            <BidangUang
                label="Harga per pesan broadcast"
                nilai={formulir.data.HargaPerPesan}
                saatBerubah={(nilai) => formulir.setData('HargaPerPesan', nilai)}
                galat={formulir.errors.HargaPerPesan}
                required
            />
            <BidangUang
                label="Minimal isi saldo"
                nilai={formulir.data.TopUpMinimal}
                saatBerubah={(nilai) => formulir.setData('TopUpMinimal', nilai)}
                galat={formulir.errors.TopUpMinimal}
                required
            />
            <BidangTeksPanjang
                label="Alasan perubahan"
                nilai={formulir.data.Alasan}
                baris={2}
                maksimal={500}
                saatBerubah={(nilai) => formulir.setData('Alasan', nilai)}
                galat={formulir.errors.Alasan}
                required
            />
        </KerangkaForm>
    );
}
