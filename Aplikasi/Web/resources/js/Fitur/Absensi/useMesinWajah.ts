import { useEffect, useSyncExternalStore } from 'react';

import { AmbilStatusMesin, LanggananStatusMesin, MuatMesinWajah, type StatusMesin } from '@/Fitur/Absensi/MesinWajah';

/**
 * Status mesin wajah, dan (bila [aktif]) mulai menyiapkannya begitu halaman terbuka sehingga model sudah siap saat
 * karyawan mengetuk tombol absen (D-186). Gagal memuat tidak melempar: status `galat` tampil di halaman dan pemindai
 * mencoba lagi saat dibuka.
 */
export function useMesinWajah(alamatModel: string, aktif: boolean): StatusMesin {
    const status = useSyncExternalStore(LanggananStatusMesin, AmbilStatusMesin, AmbilStatusMesin);

    useEffect(() => {
        if (aktif) {
            MuatMesinWajah(alamatModel).catch(() => undefined);
        }
    }, [alamatModel, aktif]);

    return status;
}
