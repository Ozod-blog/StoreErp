<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class TelegramRecipient extends Model {
    protected $fillable = ['user_id','chat_id','label','event_types','is_active'];
    protected $casts = ['event_types'=>'array','is_active'=>'boolean'];
}
