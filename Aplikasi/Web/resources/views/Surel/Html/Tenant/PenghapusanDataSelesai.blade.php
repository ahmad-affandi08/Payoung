@extends('Surel.TataLetak')

@section('Judul', 'Bukti penghapusan data')
@section('Pratinjau', 'Data pribadi usaha {{ $NamaUsaha }} sudah dihapus pada {{ $DilaksanakanPada }}.')

@section('Isi')
    <p style="margin:0 0 16px 0; font-family:'Atkinson Hyperlegible Next','Atkinson Hyperlegible',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:16px; line-height:24px; color:#4f6567;">Halo {{ $Nama }},</p>

    <p style="margin:0 0 16px 0; font-family:'Atkinson Hyperlegible Next','Atkinson Hyperlegible',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:16px; line-height:24px; color:#4f6567;">Permintaan Anda sudah dilaksanakan. Data pribadi usaha <strong style="color:#1f3335;">{{ $NamaUsaha }}</strong> sudah dihapus dan akunnya ditutup.</p>

    @include('Surel.Komponen.Rincian', ['Baris' => ['Dilaksanakan pada' => $DilaksanakanPada, 'Kode bukti' => $KodeBukti]])

    <p style="margin:0 0 8px 0; font-family:'Atkinson Hyperlegible Next','Atkinson Hyperlegible',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:14px; line-height:20px; font-weight:bold; color:#1f3335;">Yang dihapus</p>
    @foreach ($Dihapus as $baris)
        <p style="margin:0 0 6px 0; font-family:'Atkinson Hyperlegible Next','Atkinson Hyperlegible',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:16px; line-height:24px; color:#4f6567;">&bull; {{ $baris }}</p>
    @endforeach

    <p style="margin:0 0 8px 0; font-family:'Atkinson Hyperlegible Next','Atkinson Hyperlegible',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:14px; line-height:20px; font-weight:bold; color:#1f3335;">Yang dianonimkan (angka transaksi tetap utuh)</p>
    @foreach ($Dianonimkan as $baris)
        <p style="margin:0 0 6px 0; font-family:'Atkinson Hyperlegible Next','Atkinson Hyperlegible',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:16px; line-height:24px; color:#4f6567;">&bull; {{ $baris }}</p>
    @endforeach

    <p style="margin:0 0 8px 0; font-family:'Atkinson Hyperlegible Next','Atkinson Hyperlegible',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:14px; line-height:20px; font-weight:bold; color:#1f3335;">Yang tetap disimpan</p>
    @foreach ($Dipertahankan as $baris)
        <p style="margin:0 0 6px 0; font-family:'Atkinson Hyperlegible Next','Atkinson Hyperlegible',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:16px; line-height:24px; color:#4f6567;">&bull; {{ $baris }}</p>
    @endforeach
@endsection

@section('CatatanKaki', 'Simpan email ini sebagai bukti. Pertanyaan? Hubungi '.$EmailDukungan.' dengan menyebut kode bukti di atas.')
