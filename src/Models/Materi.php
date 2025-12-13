<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
class Materi extends Model {
    protected $table = 'materi';
    public $timestamps = true;
    protected $fillable = [
        'judul_materi','deskripsi','file_materi','kelas_id','dosen_id'
    ];
}
