Halo {!! $Nama !!},

Permintaan Anda sudah dilaksanakan. Data pribadi usaha {!! $NamaUsaha !!} sudah dihapus dan akunnya ditutup.
Dilaksanakan pada: {!! $DilaksanakanPada !!}
Kode bukti: {!! $KodeBukti !!}

Yang dihapus:
@foreach ($Dihapus as $baris)
- {!! $baris !!}
@endforeach

Yang dianonimkan (angka transaksi tetap utuh):
@foreach ($Dianonimkan as $baris)
- {!! $baris !!}
@endforeach

Yang tetap disimpan:
@foreach ($Dipertahankan as $baris)
- {!! $baris !!}
@endforeach

Simpan email ini sebagai bukti. Pertanyaan? Hubungi {!! $EmailDukungan !!} dengan menyebut kode bukti di atas.
