<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class PresensiDetail extends Model
{
    protected $table = 'presensi_detail';

    protected $fillable = [
        'pertemuan_id',
        'mahasiswa_id',
        'status', // hadir / izin / alfa
        'keterangan'
    ];

    public function mahasiswa()
    {
        return $this->belongsTo(\App\Models\Mahasiswa::class, 'mahasiswa_id');
    }
}
