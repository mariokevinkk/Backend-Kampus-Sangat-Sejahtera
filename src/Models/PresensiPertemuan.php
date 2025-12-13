<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class PresensiPertemuan extends Model
{
    protected $table = 'presensi_pertemuan';

    protected $fillable = [
        'kelas_id',
        'dosen_id',
        'pertemuan_ke',
        'tanggal'
    ];

    public function detail()
    {
        return $this->hasMany(PresensiDetail::class, 'pertemuan_id');
    }
}
