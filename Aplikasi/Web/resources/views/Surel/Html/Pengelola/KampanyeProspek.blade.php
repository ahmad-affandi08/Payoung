@extends('Surel.TataLetak')

@section('Judul', $Judul)
@section('Pratinjau', $Paragraf[0] ?? $Judul)

@section('Isi')
    @foreach ($Paragraf as $paragraf)
        <p style="margin:0 0 16px 0; font-family:'Atkinson Hyperlegible Next','Atkinson Hyperlegible',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:16px; line-height:24px; color:#4f6567;">{!! nl2br(e($paragraf)) !!}</p>
    @endforeach

    <p style="margin:24px 0 0 0; font-family:'Atkinson Hyperlegible Next','Atkinson Hyperlegible',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:13px; line-height:20px; color:#4f6567;">Tidak ingin menerima email seperti ini lagi? <a href="{{ $TautanBerhenti }}" style="color:#3b5b5d; text-decoration:underline;">Berhenti berlangganan</a>.</p>
@endsection

@section('CatatanKaki', 'Alamat email Anda kami peroleh dari '.$Sumber.'. Pertanyaan? Balas email ini atau hubungi '.$EmailDukungan.'.')
